<?php

namespace App\Http\Controllers;

use App\Exceptions\MigracionExploracionException;
use App\Services\GeiCoreProcesarPeriodoService;
use App\Services\LiquidacionesPropietariosService;
use App\Services\MigracionExploracionService;
use App\Services\TransformacionCobolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use ZipArchive;

class ImportacionArchivosController extends Controller
{
    private const COBOL = [
        'ctactepro.txt' => 'CTACTEPRO.TXT',
        'inqctacte.txt' => 'INQCTACTE.TXT',
        'inquilino.txt' => 'INQUILINO.TXT',
        'propietar.txt' => 'PROPIETAR.TXT',
    ];

    private const LIQUIDACIONES = [
        'dailoc.sf.txt' => 'dailoc.SF.txt',
        'liquida.sf.txt' => 'liquida.sf.txt',
        'liquida.st.txt' => 'liquida.st.txt',
        'liquidb.sf.txt' => 'liquidb.sf.txt',
        'liquidb.st.txt' => 'liquidb.st.txt',
        'pliqloc.sf.txt' => 'pliqloc.sf.txt',
        'pliqloc.st.txt' => 'pliqloc.st.txt',
    ];

    private const LIQUIDACIONES_OPCIONALES = [
        'dailoc2.sf.txt' => 'dailoc2.SF.txt',
    ];

    private const MESES = [
        'ENERO' => '01',
        'FEBRERO' => '02',
        'MARZO' => '03',
        'ABRIL' => '04',
        'MAYO' => '05',
        'JUNIO' => '06',
        'JULIO' => '07',
        'AGOSTO' => '08',
        'SEPTIEMBRE' => '09',
        'SETIEMBRE' => '09',
        'OCTUBRE' => '10',
        'NOVIEMBRE' => '11',
        'DICIEMBRE' => '12',
    ];

    public function index(
        MigracionExploracionService $migracion,
        TransformacionCobolService $transformacion
    ): View
    {
        $this->crearDirectoriosBase();

        return view('importaciones.index', [
            'periodos' => $this->periodosImportaciones($migracion, $transformacion),
            'meses' => $this->mesesFormulario(),
        ]);
    }

    /**
     * Endpoint de progreso deliberadamente separado de auth:
     * sólo lee el JSON local y no necesita consultar PostgreSQL.
     * La ruta está protegida con firma Laravel.
     */
    public function progreso(string $periodo): JsonResponse
    {
        $response = $this->respuestaProgreso($periodo);

        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, max-age=0'
        );
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $datos = $request->validate([
            'archivos' => ['required', 'array'],
            'archivos.*' => ['file', 'max:131072'],
            'periodo_mes' => ['required', 'integer', 'between:1,12'],
            'periodo_anio' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $this->crearDirectoriosBase();

        $periodoManual = $this->periodoManual($datos);
        $temporal = 'liquidaciones/tmp/'.Str::uuid()->toString();
        $entradas = [];
        $cobol = [];
        $liquidaciones = [];
        $rechazados = [];
        $fechasPosteriores = [];

        try {
            foreach ($request->file('archivos', []) as $archivo) {
                $nombreOriginal = basename($archivo->getClientOriginalName());

                if ($this->esZip($nombreOriginal)) {
                    $resultadoZip = $this->extraerZip($archivo->getPathname(), $temporal);

                    if ($resultadoZip['error'] !== null) {
                        return $this->respuestaError($request, 'archivos', $resultadoZip['error']);
                    }

                    array_push($entradas, ...$resultadoZip['entradas']);
                    array_push($rechazados, ...$resultadoZip['rechazados']);

                    continue;
                }

                $entradas[] = [
                    'nombre_original' => $nombreOriginal,
                    'ruta' => $archivo->getPathname(),
                ];
            }

            foreach ($entradas as $entrada) {
                $clasificacion = $this->clasificarNombre($entrada['nombre_original']);

                if ($clasificacion === null) {
                    $rechazados[] = $entrada['nombre_original'];

                    continue;
                }

                $entrada['nombre'] = $clasificacion['nombre'];

                if ($clasificacion['tipo'] === 'cobol') {
                    $cobol[] = $entrada;

                    // INQCTACTE puede contener vencimientos posteriores al período.
                    // Es sólo una advertencia: nunca bloquea la carga.
                    if ($entrada['nombre'] === 'INQCTACTE.TXT') {
                        $fechasPosteriores = array_merge(
                            $fechasPosteriores,
                            $this->detectarVencimientosPosterioresInqctacte(
                                $entrada['ruta'],
                                $periodoManual
                            )
                        );
                    }

                    continue;
                }

                $liquidaciones[] = $entrada;
            }

            if ($rechazados !== []) {
                return $this->respuestaError(
                    $request,
                    'archivos',
                    'Hay archivos no reconocidos: '.implode(', ', array_unique($rechazados))
                );
            }

            $periodo = $periodoManual;

            $advertenciaFechas = null;

            if ($fechasPosteriores !== []) {
                $cantidad = count($fechasPosteriores);
                $muestra = collect($fechasPosteriores)
                    ->take(10)
                    ->map(function (array $item): string {
                        return sprintf(
                            'cuenta %s, Nº COBOL %s, línea %d, venc. %s',
                            $item['cuenta_cobol'],
                            $item['numero_cobol'],
                            $item['linea'],
                            $this->formatearFechaCobol($item['fecha_vencimiento'])
                        );
                    })
                    ->implode('; ');

                $advertenciaFechas = sprintf(
                    'Advertencia: INQCTACTE.TXT contiene %d registro(s) con vencimiento posterior a %s. %s%s La importación continuó normalmente.',
                    $cantidad,
                    $this->etiquetaPeriodo($periodo),
                    $muestra,
                    $cantidad > 10 ? '; y '.($cantidad - 10).' más (ver laravel.log).' : '.'
                );

                Log::warning('INQCTACTE con vencimientos posteriores al período importado', [
                    'periodo_seleccionado' => $periodo,
                    'cantidad' => $cantidad,
                    'registros' => $fechasPosteriores,
                ]);
            }

            $rutasGuardadas = [];

            foreach ($cobol as $entrada) {
                $rutaDestino = "liquidaciones/periodos/{$periodo}/cobol/{$entrada['nombre']}";
                $this->guardarArchivoVerificado($entrada['ruta'], $rutaDestino);
                $rutasGuardadas[] = $rutaDestino;
            }

            foreach ($liquidaciones as $entrada) {
                $rutaDestino = "liquidaciones/periodos/{$periodo}/liquidaciones/{$entrada['nombre']}";
                $this->guardarArchivoVerificado($entrada['ruta'], $rutaDestino);
                $rutasGuardadas[] = $rutaDestino;
            }

            foreach ($rutasGuardadas as $rutaGuardada) {
                if (! Storage::exists($rutaGuardada)) {
                    throw new \RuntimeException(
                        "La importación no pudo verificarse: falta {$rutaGuardada}."
                    );
                }
            }

            Log::info('Importación de archivos GeI completada', [
                'periodo' => $periodo,
                'cobol' => count($cobol),
                'liquidaciones' => count($liquidaciones),
                'rutas' => $rutasGuardadas,
            ]);

            $mensaje = 'Período '.$this->etiquetaPeriodo($periodo).': '
                .count($cobol).' COBOL y '
                .count($liquidaciones).' de liquidaciones importados y verificados.';

            if ($advertenciaFechas !== null) {
                $mensaje .= ' '.$advertenciaFechas;
            }
        } catch (\Throwable $exception) {
            report($exception);

            return $this->respuestaError(
                $request,
                'archivos',
                'La importación falló y no puede darse por completada: '.$exception->getMessage()
            );
        } finally {
            Storage::deleteDirectory($temporal);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensaje,
                'redirect' => route('archivo.importar'),
            ]);
        }

        return redirect()
            ->route('archivo.importar')
            ->with('estado', $mensaje);
    }

    public function migrar(
        Request $request,
        string $periodo,
        MigracionExploracionService $migracion,
        TransformacionCobolService $transformacion,
        GeiCoreProcesarPeriodoService $geiCore,
        LiquidacionesPropietariosService $liquidaciones
    ): RedirectResponse|JsonResponse {
        $faltantes = $this->archivosObligatoriosFaltantes($periodo);

        if ($faltantes !== []) {
            $mensaje = 'No se puede migrar el período porque faltan: '
                .implode(', ', $faltantes).'.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $mensaje,
                ], 422);
            }

            return redirect()
                ->route('archivo.importar')
                ->withErrors(['migracion' => $mensaje]);
        }

        $this->guardarProgreso($periodo, [
            'estado' => 'PROCESANDO',
            'etapa' => 'PREPARANDO',
            'detalle' => 'Preparando la migración del período.',
            'porcentaje' => 2,
            'archivo' => null,
            'procesados' => null,
            'total' => null,
        ]);

        try {
            $resultadoCrudo = $migracion->migrar($periodo);
        } catch (MigracionExploracionException $exception) {
            $this->guardarProgreso($periodo, [
                'estado' => 'ERROR',
                'etapa' => 'MIGRACION_CRUDOS',
                'detalle' => $exception->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            return redirect()
                ->route('archivo.importar')
                ->withErrors([
                    'migracion' => $exception->getMessage(),
                ]);
        }

        $this->guardarProgreso($periodo, [
            'estado' => 'PROCESANDO',
            'etapa' => 'TABLAS_GEI_WEB',
            'detalle' => 'Actualizando tablas operativas de GeI-Web.',
            'porcentaje' => 40,
            'archivo' => null,
            'procesados' => null,
            'total' => null,
        ]);

        try {
            $resultadoTablas = $transformacion->ejecutar($periodo);
        } catch (\Throwable $exception) {
            $this->guardarProgreso($periodo, [
                'estado' => 'ERROR',
                'etapa' => 'TABLAS_GEI_WEB',
                'detalle' => $exception->getMessage(),
            ]);

            $mensaje = sprintf(
                'Los archivos crudos del período %s se migraron correctamente, pero falló la actualización de las tablas definitivas: %s. Podés reintentar el mismo período sin duplicar datos.',
                $this->etiquetaPeriodo($periodo),
                $exception->getMessage()
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $mensaje,
                    'resultado' => [
                        'crudos' => $resultadoCrudo,
                        'tablas' => null,
                    ],
                ], 422);
            }

            return redirect()
                ->route('archivo.importar')
                ->withErrors(['migracion' => $mensaje]);
        }

        // GeI-Core se actualiza con el mismo período ya migrado a cobol_staging.
        // Es una etapa deliberadamente NO bloqueante: gei_db ya quedó actualizado
        // y una falla en GeI-Core no debe impedir el circuito operativo ni las
        // liquidaciones de propietarios/impuestos garantizados.
        $resultadoCore = null;
        $errorCore = null;

        $this->guardarProgreso($periodo, [
            'estado' => 'PROCESANDO',
            'etapa' => 'GEI_CORE',
            'detalle' => 'Actualizando GeI-Core.',
            'porcentaje' => 74,
            'archivo' => null,
            'procesados' => null,
            'total' => null,
        ]);

        try {
            $resultadoCore = $geiCore->procesar($periodo);
        } catch (\Throwable $exception) {
            report($exception);
            $errorCore = $exception->getMessage();

            $this->guardarProgreso($periodo, [
                'estado' => 'PROCESANDO',
                'etapa' => 'GEI_CORE',
                'detalle' => 'GeI-Core terminó con advertencia: '.$errorCore,
                'porcentaje' => 82,
            ]);

            Log::warning('GeI-Core no pudo procesar el período luego de actualizar gei_db.', [
                'periodo' => $periodo,
                'error' => $errorCore,
            ]);
        }

        $mensaje = $this->mensajeMigracionCompleta(
            $periodo,
            $resultadoCrudo,
            $resultadoTablas
        );

        if ($resultadoCore !== null) {
            $mensaje .= sprintf(
                ' GeI-Core: %s.',
                (string) ($resultadoCore['estado'] ?? 'procesado')
            );
        } elseif ($errorCore !== null) {
            $mensaje .= ' GeI-Core no pudo actualizarse; la actualización operativa de gei_db quedó completada y las liquidaciones no quedan bloqueadas.';
        }

        $this->guardarProgreso($periodo, [
            'estado' => 'PROCESANDO',
            'etapa' => 'LIQUIDACIONES_PROPIETARIOS',
            'detalle' => 'Procesando liquidaciones de propietarios e impuestos garantizados.',
            'porcentaje' => 84,
            'archivo' => null,
            'procesados' => null,
            'total' => null,
        ]);

        try {
            $resultadoLiquidaciones = $liquidaciones->procesar($periodo, null);
        } catch (\Throwable $exception) {
            report($exception);

            $this->guardarProgreso($periodo, [
                'estado' => 'ERROR',
                'etapa' => 'LIQUIDACIONES_PROPIETARIOS',
                'detalle' => $exception->getMessage(),
            ]);

            $mensajeError = sprintf(
                'La base del período %s quedó migrada y actualizada, pero falló el procesamiento obligatorio de liquidaciones/impuestos: %s',
                $this->etiquetaPeriodo($periodo),
                $exception->getMessage()
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $mensajeError,
                    'resultado' => [
                        'crudos' => $resultadoCrudo,
                        'tablas' => $resultadoTablas,
                        'gei_core' => $resultadoCore,
                        'gei_core_error' => $errorCore,
                        'liquidaciones' => null,
                    ],
                ], 422);
            }

            return redirect()
                ->route('archivo.importar')
                ->withErrors(['migracion' => $mensajeError]);
        }

        $mensaje .= sprintf(
            ' Liquidaciones: %d insertadas, %d actualizadas, %d omitidas; %d PDF de propietarios y %d PDF de impuestos garantizados.',
            (int) ($resultadoLiquidaciones['insertadas'] ?? 0),
            (int) ($resultadoLiquidaciones['actualizadas'] ?? 0),
            (int) ($resultadoLiquidaciones['omitidas'] ?? 0),
            (int) ($resultadoLiquidaciones['pdf_generados'] ?? 0),
            (int) ($resultadoLiquidaciones['pdf_impuestos_garantizados_generados'] ?? 0)
        );

        $this->guardarProgreso($periodo, [
            'estado' => 'FINALIZADO',
            'etapa' => 'COMPLETO',
            'detalle' => 'Migración y actualización finalizadas.',
            'porcentaje' => 100,
            'archivo' => null,
            'procesados' => null,
            'total' => null,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensaje,
                'redirect' => route('archivo.importar'),
                'resultado' => [
                    'crudos' => $resultadoCrudo,
                    'tablas' => $resultadoTablas,
                    'gei_core' => $resultadoCore,
                    'gei_core_error' => $errorCore,
                    'liquidaciones' => $resultadoLiquidaciones,
                ],
            ]);
        }

        return redirect()
            ->route('archivo.importar')
            ->with('estado', $mensaje);
    }

    private function respuestaProgreso(string $periodo): JsonResponse
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            return response()->json([
                'estado' => 'ERROR',
                'detalle' => 'Período inválido.',
            ], 422);
        }

        $ruta = $this->rutaProgreso($periodo);

        if (! Storage::exists($ruta)) {
            return response()->json([
                'estado' => 'PENDIENTE',
                'etapa' => 'PREPARANDO',
                'detalle' => 'Esperando el inicio del proceso.',
                'porcentaje' => 0,
                'archivo' => null,
                'procesados' => null,
                'total' => null,
            ]);
        }

        try {
            $datos = json_decode(Storage::get($ruta), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return response()->json([
                'estado' => 'PROCESANDO',
                'etapa' => 'PREPARANDO',
                'detalle' => 'Actualizando información de progreso.',
                'porcentaje' => null,
            ]);
        }

        return response()->json(is_array($datos) ? $datos : []);
    }

    private function guardarProgreso(string $periodo, array $cambios): void
    {
        $ruta = $this->rutaProgreso($periodo);
        $actual = [];

        if (Storage::exists($ruta)) {
            try {
                $leido = json_decode(Storage::get($ruta), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($leido)) {
                    $actual = $leido;
                }
            } catch (\Throwable) {
                $actual = [];
            }
        }

        $datos = array_merge($actual, $cambios, [
            'periodo' => $periodo,
            'actualizado_at' => now()->toIso8601String(),
        ]);

        Storage::put(
            $ruta,
            json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    private function rutaProgreso(string $periodo): string
    {
        return "liquidaciones/periodos/{$periodo}/progreso_migracion.json";
    }

    private function crearDirectoriosBase(): void
    {
        Storage::makeDirectory('liquidaciones/periodos');
        Storage::makeDirectory('liquidaciones/tmp');
    }

    private function clasificarNombre(string $nombre): ?array
    {
        $clave = mb_strtolower(basename($nombre));

        if (isset(self::COBOL[$clave])) {
            return [
                'tipo' => 'cobol',
                'nombre' => self::COBOL[$clave],
            ];
        }

        if (isset(self::LIQUIDACIONES[$clave]) || isset(self::LIQUIDACIONES_OPCIONALES[$clave])) {
            return [
                'tipo' => 'liquidacion',
                'nombre' => self::LIQUIDACIONES[$clave] ?? self::LIQUIDACIONES_OPCIONALES[$clave],
            ];
        }

        return null;
    }

    private function esZip(string $nombre): bool
    {
        return mb_strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) === 'zip';
    }

    private function extraerZip(string $rutaZip, string $temporal): array
    {
        $zip = new ZipArchive();

        if ($zip->open($rutaZip) !== true) {
            return [
                'entradas' => [],
                'rechazados' => [],
                'error' => 'No se pudo abrir el archivo ZIP.',
            ];
        }

        $entradas = [];
        $rechazados = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombreInterno = $zip->getNameIndex($i);

            if ($nombreInterno === false || str_ends_with($nombreInterno, '/')) {
                continue;
            }

            if ($this->debeIgnorarEntradaZip($nombreInterno)) {
                continue;
            }

            $nombreOriginal = basename($nombreInterno);
            $clasificacion = $this->clasificarNombre($nombreOriginal);

            if ($clasificacion === null) {
                $rechazados[] = $nombreOriginal;

                continue;
            }

            $stream = $zip->getStream($nombreInterno);

            if ($stream === false) {
                $zip->close();

                return [
                    'entradas' => [],
                    'rechazados' => [],
                    'error' => "No se pudo leer {$nombreOriginal} dentro del ZIP.",
                ];
            }

            $rutaTemporal = "{$temporal}/".Str::uuid()->toString().'-'.$clasificacion['nombre'];
            $contenido = stream_get_contents($stream);
            fclose($stream);

            if ($contenido === false || ! Storage::put($rutaTemporal, $contenido) || ! Storage::exists($rutaTemporal)) {
                $zip->close();

                return [
                    'entradas' => [],
                    'rechazados' => [],
                    'error' => "No se pudo guardar temporalmente {$nombreOriginal} extraído del ZIP.",
                ];
            }

            $entradas[] = [
                'nombre_original' => $nombreOriginal,
                'ruta' => Storage::path($rutaTemporal),
            ];
        }

        $zip->close();

        if ($entradas === [] && $rechazados === []) {
            return [
                'entradas' => [],
                'rechazados' => [],
                'error' => 'El ZIP no contiene archivos reconocidos.',
            ];
        }

        return [
            'entradas' => $entradas,
            'rechazados' => $rechazados,
            'error' => null,
        ];
    }

    private function debeIgnorarEntradaZip(string $nombre): bool
    {
        $normalizado = str_replace('\\', '/', $nombre);
        $base = basename($normalizado);

        return str_starts_with($normalizado, '__MACOSX/')
            || str_starts_with($base, '.');
    }

    private function guardarArchivoVerificado(string $rutaOrigen, string $rutaDestino): void
    {
        $contenido = @file_get_contents($rutaOrigen);

        if ($contenido === false) {
            throw new \RuntimeException("No se pudo leer el archivo temporal {$rutaOrigen}.");
        }

        if (! Storage::put($rutaDestino, $contenido)) {
            throw new \RuntimeException("Storage::put devolvió false para {$rutaDestino}.");
        }

        if (! Storage::exists($rutaDestino)) {
            throw new \RuntimeException("El archivo no existe luego de guardarlo: {$rutaDestino}.");
        }

        $tamanoEsperado = strlen($contenido);
        $tamanoGuardado = Storage::size($rutaDestino);

        if ($tamanoGuardado !== $tamanoEsperado) {
            Storage::delete($rutaDestino);

            throw new \RuntimeException(
                "El archivo {$rutaDestino} quedó incompleto: {$tamanoGuardado} bytes de {$tamanoEsperado}."
            );
        }
    }

    private function respuestaError(
        Request $request,
        string $campo,
        string $mensaje
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensaje,
            ], 422);
        }

        return back()
            ->withInput()
            ->withErrors([
                $campo => $mensaje,
            ]);
    }

    private function periodosImportaciones(
        MigracionExploracionService $migracion,
        TransformacionCobolService $transformacion
    ): array
    {
        return collect(Storage::directories('liquidaciones/periodos'))
            ->map(function (string $directorio) use ($migracion, $transformacion) {
                $periodo = basename($directorio);
                $archivosCobol = collect(self::COBOL)
                    ->values()
                    ->map(fn (string $nombre) => $this->datosArchivo(
                        "{$directorio}/cobol/{$nombre}",
                        $nombre
                    ));

                $archivosLiquidaciones = collect(self::LIQUIDACIONES)
                    ->values()
                    ->map(fn (string $nombre) => $this->datosArchivoCompatible(
                        "{$directorio}/liquidaciones/{$nombre}",
                        "{$directorio}/{$nombre}",
                        $nombre
                    ));

                $archivosOpcionales = collect(self::LIQUIDACIONES_OPCIONALES)
                    ->values()
                    ->map(function (string $nombre) use ($directorio) {
                        return array_merge(
                            $this->datosArchivoCompatible(
                                "{$directorio}/liquidaciones/{$nombre}",
                                "{$directorio}/{$nombre}",
                                $nombre
                            ),
                            ['opcional' => true]
                        );
                    })
                    ->where('existe', true)
                    ->values();

                $archivosObligatorios = $archivosCobol->concat($archivosLiquidaciones);
                $todosLosArchivos = $archivosObligatorios->concat($archivosOpcionales);
                $cantidadObligatorios = $archivosObligatorios
                    ->where('existe', true)
                    ->count();
                $completo = $cantidadObligatorios === $archivosObligatorios->count();
                $estadoMigracion = $migracion->estado($periodo);
                $estadoTablas = $transformacion->estado($periodo);

                return [
                    'periodo' => $periodo,
                    'etiqueta' => $this->etiquetaPeriodo($periodo),
                    'archivos_cobol' => $archivosCobol->all(),
                    'archivos_liquidaciones' => $archivosLiquidaciones
                        ->concat($archivosOpcionales)
                        ->all(),
                    'cantidad_obligatorios' => $cantidadObligatorios,
                    'total_obligatorios' => $archivosObligatorios->count(),
                    'cantidad_opcionales' => $archivosOpcionales
                        ->where('existe', true)
                        ->count(),
                    'completo' => $completo,
                    'actualizado' => $todosLosArchivos->pluck('timestamp')->filter()->max(),
                    'migracion' => [
                        ...$estadoMigracion,
                        'disponible' => $completo
                            && ($estadoMigracion['disponible'] ?? false),
                    ],
                    'tablas' => $estadoTablas,
                ];
            })
            ->sortByDesc('periodo')
            ->values()
            ->all();
    }

    private function archivosObligatoriosFaltantes(string $periodo): array
    {
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo)) {
            return ['período inválido'];
        }

        $base = "liquidaciones/periodos/{$periodo}";
        $faltantes = [];

        foreach (self::COBOL as $nombre) {
            if (! Storage::exists("{$base}/cobol/{$nombre}")) {
                $faltantes[] = $nombre;
            }
        }

        foreach (self::LIQUIDACIONES as $nombre) {
            $rutaActual = "{$base}/liquidaciones/{$nombre}";
            $rutaAnterior = "{$base}/{$nombre}";

            if (! Storage::exists($rutaActual) && ! Storage::exists($rutaAnterior)) {
                $faltantes[] = $nombre;
            }
        }

        return $faltantes;
    }

    private function datosArchivoCompatible(
        string $rutaActual,
        string $rutaAnterior,
        string $nombre
    ): array {
        if (Storage::exists($rutaActual)) {
            return $this->datosArchivo($rutaActual, $nombre);
        }

        return $this->datosArchivo($rutaAnterior, $nombre);
    }

    private function datosArchivo(string $ruta, string $nombre): array
    {
        if (! Storage::exists($ruta)) {
            return [
                'nombre' => $nombre,
                'existe' => false,
                'fecha' => null,
                'tamano' => null,
                'timestamp' => null,
            ];
        }

        $timestamp = Storage::lastModified($ruta);

        return [
            'nombre' => $nombre,
            'existe' => true,
            'fecha' => Carbon::createFromTimestamp($timestamp)->format('d/m/Y H:i'),
            'tamano' => $this->formatearTamano(Storage::size($ruta)),
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Busca vencimientos posteriores al período seleccionado en INQCTACTE.TXT.
     * Es sólo informativo: nunca bloquea la importación.
     *
     * Layout COBOL:
     *   0..10  cuenta
     *  11..18  fecha movimiento
     *  19..20  código
     *  21..26  número COBOL
     *  27..34  fecha vencimiento
     *
     * @return list<array{
     *   linea:int,
     *   cuenta_cobol:string,
     *   fecha_movimiento:string,
     *   codigo:string,
     *   numero_cobol:string,
     *   fecha_vencimiento:string,
     *   periodo_vencimiento:string
     * }>
     */
    private function detectarVencimientosPosterioresInqctacte(
        string $ruta,
        string $periodoSeleccionado
    ): array {
        $archivo = fopen($ruta, 'rb');

        if ($archivo === false) {
            return [];
        }

        $resultado = [];
        $numeroLinea = 0;

        try {
            while (($linea = fgets($archivo)) !== false) {
                $numeroLinea++;

                $cuenta = trim(substr($linea, 0, 11));
                $fechaMovimiento = substr($linea, 11, 8);
                $codigo = trim(substr($linea, 19, 2));
                $numeroCobol = trim(substr($linea, 21, 6));
                $fechaVencimiento = substr($linea, 27, 8);

                if (! $this->esFechaCobolValida($fechaVencimiento)) {
                    continue;
                }

                $periodoVencimiento = substr($fechaVencimiento, 0, 6);

                if ($periodoVencimiento <= $periodoSeleccionado) {
                    continue;
                }

                $resultado[] = [
                    'linea' => $numeroLinea,
                    'cuenta_cobol' => $cuenta,
                    'fecha_movimiento' => $fechaMovimiento,
                    'codigo' => $codigo,
                    'numero_cobol' => $numeroCobol,
                    'fecha_vencimiento' => $fechaVencimiento,
                    'periodo_vencimiento' => $periodoVencimiento,
                ];
            }
        } finally {
            fclose($archivo);
        }

        return $resultado;
    }

    private function formatearFechaCobol(string $fecha): string
    {
        if (! $this->esFechaCobolValida($fecha)) {
            return $fecha;
        }

        return substr($fecha, 6, 2).'/'.substr($fecha, 4, 2).'/'.substr($fecha, 0, 4);
    }

    private function detectarPeriodoCobol(string $ruta, string $nombre): ?string
    {
        $posicionFecha = match ($nombre) {
            'CTACTEPRO.TXT', 'INQCTACTE.TXT' => 11,
            'PROPIETAR.TXT' => 159,
            // INQUILINO.TXT no contiene una fecha que permita determinar el período.
            default => null,
        };

        if ($posicionFecha === null) {
            return null;
        }

        $archivo = fopen($ruta, 'rb');

        if ($archivo === false) {
            return null;
        }

        $ultimaFecha = null;

        try {
            while (($linea = fgets($archivo)) !== false) {
                $fecha = substr($linea, $posicionFecha, 8);

                if (! $this->esFechaCobolValida($fecha)) {
                    continue;
                }

                if ($ultimaFecha === null || $fecha > $ultimaFecha) {
                    $ultimaFecha = $fecha;
                }
            }
        } finally {
            fclose($archivo);
        }

        return $ultimaFecha === null
            ? null
            : substr($ultimaFecha, 0, 6);
    }

    private function esFechaCobolValida(string $fecha): bool
    {
        // Valor erróneo conocido de INQCTACTE.TXT.
        if ($fecha === '22200612' || ! preg_match('/^\d{8}$/', $fecha)) {
            return false;
        }

        $anio = (int) substr($fecha, 0, 4);
        $mes = (int) substr($fecha, 4, 2);
        $dia = (int) substr($fecha, 6, 2);

        return $anio >= 2000
            && $anio <= 2100
            && checkdate($mes, $dia, $anio);
    }

    private function detectarPeriodoLiquidacion(string $ruta): ?string
    {
        $archivo = fopen($ruta, 'rb');

        if ($archivo === false) {
            return null;
        }

        $meses = implode('|', array_keys(self::MESES));
        $periodoMaximo = null;

        try {
            while (($linea = fgets($archivo)) !== false) {
                if (! preg_match_all(
                    "/\\b({$meses})\\s+(?:DE\\s+)?(20\\d{2})\\b/ui",
                    $linea,
                    $matches,
                    PREG_SET_ORDER
                )) {
                    continue;
                }

                foreach ($matches as $match) {
                    $mes = self::MESES[mb_strtoupper($match[1])] ?? null;

                    if ($mes === null) {
                        continue;
                    }

                    $periodo = $match[2].$mes;

                    if ($periodoMaximo === null || $periodo > $periodoMaximo) {
                        $periodoMaximo = $periodo;
                    }
                }
            }
        } finally {
            fclose($archivo);
        }

        return $periodoMaximo;
    }

    private function periodoManual(array $datos): string
    {
        return sprintf(
            '%04d%02d',
            (int) $datos['periodo_anio'],
            (int) $datos['periodo_mes']
        );
    }

    private function etiquetaPeriodo(string $periodo): string
    {
        if (! preg_match('/^(20\d{2})(\d{2})$/', $periodo, $partes)) {
            return $periodo;
        }

        return "{$partes[2]}/{$partes[1]}";
    }

    private function formatearTamano(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.').' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return $bytes.' B';
    }

    private function mesesFormulario(): array
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }

    /**
     * @param array<string, mixed> $crudos
     * @param array<string, mixed> $tablas
     */
    private function mensajeMigracionCompleta(
        string $periodo,
        array $crudos,
        array $tablas
    ): string {
        $clientes = $tablas['clientes'] ?? [];
        $inmuebles = $tablas['inmuebles'] ?? [];
        $contratos = $tablas['contratos'] ?? [];
        $cuentas = $tablas['cuentas_corrientes'] ?? [];

        return sprintf(
            'Período %s listo. Crudos: %d cargados y %d omitidos. Tablas: %d clientes creados, %d actualizados; %d inmuebles creados, %d actualizados; %d contratos creados, %d actualizados; %d movimientos creados, %d actualizados.',
            $this->etiquetaPeriodo($periodo),
            (int) ($crudos['registros_cargados'] ?? 0),
            (int) ($crudos['registros_omitidos'] ?? 0),
            (int) ($clientes['clientes_creados'] ?? 0),
            (int) ($clientes['clientes_actualizados'] ?? 0),
            (int) ($inmuebles['inmuebles_creados'] ?? 0),
            (int) ($inmuebles['inmuebles_actualizados'] ?? 0),
            (int) ($contratos['contratos_creados'] ?? 0),
            (int) ($contratos['contratos_actualizados'] ?? 0),
            (int) ($cuentas['movimientos_creados'] ?? 0),
            (int) ($cuentas['movimientos_actualizados'] ?? 0)
        );
    }
}
