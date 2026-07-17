<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportadorPythonException;
use App\Services\ImportadorPythonService;
use App\Services\MigracionKngGeiPostgresqlService;
use App\Services\ValidacionKngGeiPostgresqlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class ActualizarDbController extends Controller
{
    private const ARCHIVOS_COBOL = [
        'PROPIETAR.TXT',
        'INQUILINO.TXT',
        'CTACTEPRO.TXT',
        'INQCTACTE.TXT',
    ];

    public function __construct(
        private readonly ImportadorPythonService $importadorPython,
        private readonly MigracionKngGeiPostgresqlService $migracionPostgresql,
        private readonly ValidacionKngGeiPostgresqlService $validacionFox
    ) {}

    public function index(): View
    {
        return view('actualizar-db.index', [
            'archivosCobol' => $this->archivosCobol(),
            'resultado' => session('resultado_actualizar_db'),
            'errorEjecucion' => session('error_actualizar_db'),
            'configuracion' => [
                'python' => config('gei.importador.python_bin'),
                'importador' => config('gei.importador.path'),
                'base_dir' => config('gei.importador.base_dir'),
                'cobol' => config('gei.importador.cobol_storage_path'),
                'repositorio_id' => config('gei.importador.repositorio_id'),
                'timeout' => config('gei.importador.timeout'),
            ],
        ]);
    }

    public function validarCobol(): RedirectResponse
    {
        $timeout = (int) config('gei.importador.timeout', 120);
        $repositorioId = (int) config('gei.importador.repositorio_id', 123);
        $faltantes = $this->archivosCobolRequeridosFaltantes();

        if ($faltantes !== []) {
            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => 'Faltan archivos requeridos para validar: '.implode(', ', $faltantes).'. Cargalos desde Archivo / Importar.',
                ]);
        }

        $lock = Cache::store((string) config('gei.importador.lock_store', 'file'))
            ->lock($this->lockKey($repositorioId), $timeout + 30);

        if (! $lock->get()) {
            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => 'Ya hay una validación COBOL en curso. Esperá a que finalice antes de iniciar otra.',
                ]);
        }

        try {
            $resultado = $this->importadorPython->validarCobol($repositorioId);

            return redirect()
                ->route('archivo.actualizar-db')
                ->with('resultado_actualizar_db', $resultado)
                ->with('estado', 'Validación COBOL finalizada.');
        } catch (ImportadorPythonException $exception) {
            Log::error('No se pudo validar COBOL con el importador Python.', [
                'message' => $exception->getMessage(),
                ...$exception->context(),
            ]);

            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => $exception->getMessage(),
                    ...$exception->context(),
                ]);
        } catch (Throwable $exception) {
            Log::error('No se pudo simular la persistencia KNG/GeI en PostgreSQL.', [
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => $exception->getMessage(),
                ]);
        } finally {
            $lock->release();
        }
    }

    public function compararCobol(): RedirectResponse
    {
        $timeout = (int) config('gei.importador.timeout', 120);
        $repositorioId = (int) config('gei.importador.repositorio_id', 123);
        $faltantes = $this->archivosCobolRequeridosFaltantes();

        if ($faltantes !== []) {
            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => 'Faltan archivos requeridos para comparar: '.implode(', ', $faltantes).'. Cargalos desde Archivo / Importar.',
                ]);
        }

        $lock = Cache::store((string) config('gei.importador.lock_store', 'file'))
            ->lock($this->lockKey($repositorioId), $timeout + 30);

        if (! $lock->get()) {
            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => 'Ya hay una comparación COBOL en curso. Esperá a que finalice antes de iniciar otra.',
                ]);
        }

        try {
            $resultado = $this->importadorPython->compararCobol($repositorioId);

            return redirect()
                ->route('archivo.actualizar-db')
                ->with('resultado_actualizar_db', $resultado)
                ->with('estado', 'Comparación COBOL finalizada.');
        } catch (ImportadorPythonException $exception) {
            Log::error('No se pudo comparar COBOL con PostgreSQL.', [
                'message' => $exception->getMessage(),
                ...$exception->context(),
            ]);

            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => $exception->getMessage(),
                    ...$exception->context(),
                ]);
        } finally {
            $lock->release();
        }
    }

    public function validarLoteMigracion(): RedirectResponse
    {
        return $this->ejecutarPipelineMigracion(
            'validación completa',
            fn () => $this->importadorPython->validarLoteMigracion(),
            'Validación completa del lote finalizada.'
        );
    }

    public function importarLoteMigracion(): RedirectResponse
    {
        return $this->ejecutarPipelineMigracion(
            'importación staging',
            fn () => $this->importadorPython->importarLoteMigracion(),
            'Importación staging finalizada.'
        );
    }

    public function reconciliarLoteMigracion(): RedirectResponse
    {
        return $this->ejecutarPipelineMigracion(
            'conciliación',
            fn () => $this->importadorPython->reconciliarLoteMigracion(),
            'Conciliación finalizada.'
        );
    }

    public function simularPersistenciaPostgresql(Request $request): RedirectResponse
    {
        $lock = Cache::store((string) config('gei.importador.lock_store', 'file'))
            ->lock('gei:actualizar-db:validacion-fox', 1800);

        if (! $lock->get()) {
            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => 'Ya hay una validación contra Fox en curso.',
                ]);
        }

        try {
            $componente = (string) $request->input('componente', 'completo');
            $componentes = $componente === 'completo'
                ? ValidacionKngGeiPostgresqlService::COMPONENTES
                : [$componente];
            $importacionId = DB::table('web_importaciones')
                ->where('web_tipo', 'kng_gei')
                ->latest('web_id')
                ->value('web_id');

            if (! $importacionId) {
                return redirect()
                    ->route('archivo.actualizar-db')
                    ->with('error_actualizar_db', [
                        'mensaje' => 'No existe una importación staging kng_gei para validar.',
                    ]);
            }

            $json = $this->validacionFox->validar((int) $importacionId, $componentes);

            return redirect()
                ->route('archivo.actualizar-db')
                ->with('resultado_actualizar_db', [
                    'exit_code' => 0,
                    'stdout' => json_encode($json, JSON_UNESCAPED_UNICODE),
                    'stderr' => '',
                    'json' => [
                        'modo' => 'validar-contra-fox',
                        'estado' => $json['estado'] ?? 'VALIDACION_PARCIAL',
                        'escritura_postgresql' => false,
                        'resultado_validacion_fox' => $json,
                    ],
                ])
                ->with('estado', 'Validación contra importación Fox finalizada.');
        } finally {
            $lock->release();
        }
    }

    private function archivosCobol(): array
    {
        return collect(self::ARCHIVOS_COBOL)
            ->map(fn (string $nombre) => $this->datosArchivo(
                "liquidaciones/cobol/{$nombre}",
                $nombre
            ))
            ->all();
    }

    private function archivosCobolRequeridosFaltantes(): array
    {
        return collect(['PROPIETAR.TXT', 'INQUILINO.TXT'])
            ->reject(fn (string $nombre) => Storage::exists("liquidaciones/cobol/{$nombre}"))
            ->values()
            ->all();
    }

    private function lockKey(int $repositorioId): string
    {
        return "gei:actualizar-db:cobol:{$repositorioId}";
    }

    private function ejecutarPipelineMigracion(
        string $operacion,
        callable $callback,
        string $mensajeOk
    ): RedirectResponse {
        $timeout = (int) config('gei.importador.timeout', 120);
        $lock = Cache::store((string) config('gei.importador.lock_store', 'file'))
            ->lock('gei:actualizar-db:migracion-kng-gei', $timeout + 30);

        if (! $lock->get()) {
            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => 'Ya hay una ejecución de migración en curso. Esperá a que finalice antes de iniciar otra.',
                ]);
        }

        try {
            $resultado = $callback();

            return redirect()
                ->route('archivo.actualizar-db')
                ->with('resultado_actualizar_db', $resultado)
                ->with('estado', $mensajeOk);
        } catch (ImportadorPythonException $exception) {
            Log::error("No se pudo completar la {$operacion} KNG/GeI.", [
                'message' => $exception->getMessage(),
                ...$exception->context(),
            ]);

            return redirect()
                ->route('archivo.actualizar-db')
                ->with('error_actualizar_db', [
                    'mensaje' => $exception->getMessage(),
                    ...$exception->context(),
                ]);
        } finally {
            $lock->release();
        }
    }

    private function datosArchivo(string $ruta, string $nombre): array
    {
        if (! Storage::exists($ruta)) {
            return [
                'nombre' => $nombre,
                'existe' => false,
                'fecha' => null,
                'tamano' => null,
                'sha256' => null,
                'estado' => 'Faltante',
                'estado_clase' => 'text-bg-secondary',
            ];
        }

        $timestamp = Storage::lastModified($ruta);

        return [
            'nombre' => $nombre,
            'existe' => true,
            'fecha' => Carbon::createFromTimestamp($timestamp)->format('d/m/Y H:i'),
            'tamano' => $this->formatearTamano(Storage::size($ruta)),
            'sha256' => hash_file('sha256', Storage::path($ruta)),
            'estado' => in_array($nombre, ['PROPIETAR.TXT', 'INQUILINO.TXT'], true)
                ? 'Listo para validar'
                : 'Pendiente',
            'estado_clase' => in_array($nombre, ['PROPIETAR.TXT', 'INQUILINO.TXT'], true)
                ? 'text-bg-success'
                : 'text-bg-warning',
        ];
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
}
