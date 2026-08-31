<?php

namespace App\Http\Controllers;

use App\Exceptions\MigracionExploracionException;
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

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $datos = $request->validate([
            'archivos' => ['required', 'array'],
            'archivos.*' => ['file', 'max:131072'],
            'periodo_mes' => ['nullable', 'required_with:periodo_anio', 'integer', 'between:1,12'],
            'periodo_anio' => ['nullable', 'required_with:periodo_mes', 'integer', 'between:2000,2100'],
        ]);

        $this->crearDirectoriosBase();

        $periodoManual = $this->periodoManual($datos);
        $temporal = 'liquidaciones/tmp/'.Str::uuid()->toString();
        $entradas = [];
        $cobol = [];
        $liquidaciones = [];
        $rechazados = [];
        $periodosDetectados = [];
        $periodosCobolDetectados = [];

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
                    $periodoCobol = $this->detectarPeriodoCobol(
                        $entrada['ruta'],
                        $entrada['nombre']
                    );

                    if ($periodoCobol !== null) {
                        $periodosCobolDetectados[] = $periodoCobol;
                    }

                    continue;
                }

                $liquidaciones[] = $entrada;
                $periodoDetectado = $this->detectarPeriodoLiquidacion($entrada['ruta']);

                if ($periodoDetectado !== null) {
                    $periodosDetectados[$periodoDetectado] = true;
                }
            }

            if ($periodosCobolDetectados !== []) {
                $periodoCobol = max($periodosCobolDetectados);
                $periodosDetectados[$periodoCobol] = true;
            }

            if ($rechazados !== []) {
                return $this->respuestaError(
                    $request,
                    'archivos',
                    'Hay archivos no reconocidos: '.implode(', ', array_unique($rechazados))
                );
            }

            $periodo = $periodoManual;

            $detectados = array_keys($periodosDetectados);

            if ($periodo === null && count($detectados) === 1) {
                $periodo = $detectados[0];
            }

            if (count($detectados) > 1 && $periodoManual === null) {
                return $this->respuestaError(
                    $request,
                    'archivos',
                    'Los archivos contienen períodos distintos: '.implode(', ', $detectados)
                );
            }

            if ($periodo === null) {
                return $this->respuestaError(
                    $request,
                    'periodo_mes',
                    'No se pudo detectar un único período. Indicá mes y año para guardar todos los archivos.'
                );
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
        TransformacionCobolService $transformacion
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

        try {
            $resultadoCrudo = $migracion->migrar($periodo);
        } catch (MigracionExploracionException $exception) {
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

        try {
            $resultadoTablas = $transformacion->ejecutar($periodo);
        } catch (\Throwable $exception) {
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

        $mensaje = $this->mensajeMigracionCompleta(
            $periodo,
            $resultadoCrudo,
            $resultadoTablas
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensaje,
                'redirect' => route('archivo.importar'),
                'resultado' => [
                    'crudos' => $resultadoCrudo,
                    'tablas' => $resultadoTablas,
                ],
            ]);
        }

        return redirect()
            ->route('archivo.importar')
            ->with('estado', $mensaje);
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
        $contenido = file_get_contents($ruta, false, null, 0, 524288);

        if ($contenido === false) {
            return null;
        }

        $meses = implode('|', array_keys(self::MESES));

        if (! preg_match_all("/\\b({$meses})\\s+(?:DE\\s+)?(20\\d{2})\\b/i", $contenido, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $conteo = [];

        foreach ($matches as $match) {
            $mes = self::MESES[mb_strtoupper($match[1])] ?? null;

            if ($mes === null) {
                continue;
            }

            $periodo = $match[2].$mes;
            $conteo[$periodo] = ($conteo[$periodo] ?? 0) + 1;
        }

        if ($conteo === []) {
            return null;
        }

        arsort($conteo);

        return array_key_first($conteo);
    }

    private function periodoManual(array $datos): ?string
    {
        $mes = $datos['periodo_mes'] ?? null;
        $anio = $datos['periodo_anio'] ?? null;

        if ($mes === null && $anio === null) {
            return null;
        }

        if ($mes === null || $anio === null) {
            return null;
        }

        return sprintf('%04d%02d', $anio, $mes);
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
