<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        'dailoc2.sf.txt' => 'dailoc2.SF.txt',
        'dailoc.sf.txt' => 'dailoc.SF.txt',
        'liquida.sf.txt' => 'liquida.sf.txt',
        'liquida.st.txt' => 'liquida.st.txt',
        'liquidb.sf.txt' => 'liquidb.sf.txt',
        'liquidb.st.txt' => 'liquidb.st.txt',
        'pliqloc.sf.txt' => 'pliqloc.sf.txt',
        'pliqloc.st.txt' => 'pliqloc.st.txt',
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

    public function index(): View
    {
        $this->crearDirectoriosBase();

        return view('importaciones.index', [
            'archivosCobol' => $this->archivosCobol(),
            'periodos' => $this->periodosLiquidaciones(),
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

                    continue;
                }

                $liquidaciones[] = $entrada;
                $periodoDetectado = $this->detectarPeriodo($entrada['ruta']);

                if ($periodoDetectado !== null) {
                    $periodosDetectados[$periodoDetectado] = true;
                }
            }

            if ($rechazados !== []) {
                return $this->respuestaError(
                    $request,
                    'archivos',
                    'Hay archivos no reconocidos: '.implode(', ', array_unique($rechazados))
                );
            }

            $periodo = $periodoManual;

            if ($liquidaciones !== []) {
                $detectados = array_keys($periodosDetectados);

                if ($periodo === null && count($detectados) === 1) {
                    $periodo = $detectados[0];
                }

                if ($periodo === null) {
                    return $this->respuestaError(
                        $request,
                        'periodo_mes',
                        'No se pudo detectar un único período. Indicá mes y año para guardar las liquidaciones.'
                    );
                }

                if (count($detectados) > 1 && $periodoManual === null) {
                    return $this->respuestaError(
                        $request,
                        'archivos',
                        'Los archivos contienen períodos distintos: '.implode(', ', $detectados)
                    );
                }
            }

            foreach ($cobol as $entrada) {
                Storage::put(
                    "liquidaciones/cobol/{$entrada['nombre']}",
                    file_get_contents($entrada['ruta'])
                );
            }

            foreach ($liquidaciones as $entrada) {
                Storage::put(
                    "liquidaciones/periodos/{$periodo}/{$entrada['nombre']}",
                    file_get_contents($entrada['ruta'])
                );
            }

            $mensaje = 'Archivos importados: '.count($cobol).' COBOL y '.count($liquidaciones).' de liquidaciones.';
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

    private function crearDirectoriosBase(): void
    {
        Storage::makeDirectory('liquidaciones/cobol');
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

        if (isset(self::LIQUIDACIONES[$clave])) {
            return [
                'tipo' => 'liquidacion',
                'nombre' => self::LIQUIDACIONES[$clave],
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
            Storage::put($rutaTemporal, stream_get_contents($stream));
            fclose($stream);

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

    private function archivosCobol(): array
    {
        return collect(self::COBOL)
            ->values()
            ->map(fn (string $nombre) => $this->datosArchivo(
                "liquidaciones/cobol/{$nombre}",
                $nombre
            ))
            ->all();
    }

    private function periodosLiquidaciones(): array
    {
        return collect(Storage::directories('liquidaciones/periodos'))
            ->map(function (string $directorio) {
                $periodo = basename($directorio);
                $archivos = collect(Storage::files($directorio))
                    ->map(fn (string $archivo) => $this->datosArchivo(
                        $archivo,
                        basename($archivo)
                    ))
                    ->sortBy('nombre')
                    ->values();

                return [
                    'periodo' => $periodo,
                    'etiqueta' => $this->etiquetaPeriodo($periodo),
                    'archivos' => $archivos,
                    'cantidad' => $archivos->count(),
                    'actualizado' => $archivos->pluck('timestamp')->filter()->max(),
                ];
            })
            ->sortByDesc('periodo')
            ->values()
            ->all();
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

    private function detectarPeriodo(string $ruta): ?string
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
}
