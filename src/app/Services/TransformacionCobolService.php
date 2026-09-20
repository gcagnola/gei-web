<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class TransformacionCobolService
{
    private const ARCHIVO_PROGRESO = 'progreso_migracion.json';
    private const ARCHIVOS_COBOL = [
        'CTACTEPRO.TXT',
        'INQCTACTE.TXT',
        'INQUILINO.TXT',
        'PROPIETAR.TXT',
    ];

    public function __construct(
        private readonly MigracionClientesCobolService $clientes,
        private readonly MigracionInmueblesCobolService $inmuebles,
        private readonly MigracionContratosCobolService $contratos,
        private readonly MigracionCuentasCorrientesCobolService $cuentasCorrientes,
    ) {
    }

    /** @return array<string, mixed> */
    public function ejecutar(string $periodo): array
    {
        $this->validarPeriodo($periodo);
        $this->validarTablaProcesos();
        $loteHash = $this->hashPeriodo($periodo);
        $lock = Cache::store((string) config('gei.exploracion.lock_store', 'file'))
            ->lock('gei:transformacion-cobol', 3600);

        if (! $lock->get()) {
            throw new RuntimeException('Ya hay una actualización de tablas COBOL en proceso.');
        }

        $procesoId = DB::table('web_procesos_transformacion_cobol')->insertGetId([
            'web_periodo' => $periodo,
            'web_lote_hash' => $loteHash,
            'web_estado' => 'PROCESANDO',
            'web_etapa' => 'CLIENTES',
            'web_iniciado_at' => now(),
            'web_created_at' => now(),
            'web_updated_at' => now(),
        ], 'web_id');

        $etapa = 'CLIENTES';

        try {
            $resultado = [];

            $this->guardarProgreso($periodo, 'CLIENTES', 'Actualizando clientes desde PostgreSQL.', 45);
            $resultado['clientes'] = $this->clientes->ejecutar(true);

            $etapa = 'INMUEBLES';
            $this->actualizarEtapa($procesoId, $etapa);
            $this->guardarProgreso($periodo, 'INMUEBLES', 'Actualizando inmuebles desde PostgreSQL.', 52);
            $resultado['inmuebles'] = $this->inmuebles->ejecutar(true);

            $etapa = 'CONTRATOS';
            $this->actualizarEtapa($procesoId, $etapa);
            $this->guardarProgreso($periodo, 'CONTRATOS', 'Actualizando contratos desde PostgreSQL.', 59);
            $resultado['contratos'] = $this->contratos->ejecutar(true);

            $etapa = 'CUENTAS_CORRIENTES';
            $this->actualizarEtapa($procesoId, $etapa);
            $this->guardarProgreso($periodo, 'CUENTAS_CORRIENTES', 'Actualizando cuentas corrientes y movimientos.', 66);
            $resultado['cuentas_corrientes'] = $this->cuentasCorrientes->ejecutar(
                true,
                null,
                function (string $tabla, int $procesados, int $total) use ($periodo): void {
                    $porcentaje = $total > 0
                        ? 66 + (int) floor(min(1, $procesados / $total) * 6)
                        : 66;

                    $this->guardarProgreso(
                        $periodo,
                        'CUENTAS_CORRIENTES',
                        'Procesando '.strtoupper($tabla).'.',
                        $porcentaje,
                        'PROCESANDO',
                        strtoupper($tabla),
                        $procesados,
                        $total
                    );
                }
            );

            $movimientos = $resultado['cuentas_corrientes']['movimientos_creados'] ?? null;
            $actualizados = $resultado['cuentas_corrientes']['movimientos_actualizados'] ?? null;
            $detalleCuentas = 'Cuentas corrientes actualizadas.';
            if ($movimientos !== null || $actualizados !== null) {
                $detalleCuentas .= ' Movimientos creados: '.(int) ($movimientos ?? 0)
                    .'; actualizados: '.(int) ($actualizados ?? 0).'.';
            }
            $this->guardarProgreso($periodo, 'CUENTAS_CORRIENTES', $detalleCuentas, 72);

            DB::table('web_procesos_transformacion_cobol')
                ->where('web_id', $procesoId)
                ->update([
                    'web_estado' => 'FINALIZADO',
                    'web_etapa' => 'COMPLETO',
                    'web_resultado' => json_encode(
                        $resultado,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'web_finalizado_at' => now(),
                    'web_updated_at' => now(),
                ]);

            return $resultado;
        } catch (Throwable $exception) {
            $this->guardarProgreso($periodo, $etapa, 'Error: '.$exception->getMessage(), 70, 'ERROR');

            DB::table('web_procesos_transformacion_cobol')
                ->where('web_id', $procesoId)
                ->update([
                    'web_estado' => 'ERROR',
                    'web_etapa' => $etapa,
                    'web_mensaje_error' => $exception->getMessage(),
                    'web_finalizado_at' => now(),
                    'web_updated_at' => now(),
                ]);

            throw new RuntimeException(
                'Falló la etapa '.$this->etiquetaEtapa($etapa).': '.$exception->getMessage(),
                0,
                $exception
            );
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    public function estado(string $periodo): array
    {
        if (! Schema::hasTable('web_procesos_transformacion_cobol')) {
            return [
                'estado' => 'NO_DISPONIBLE',
                'mensaje' => 'Falta ejecutar la migración de procesos COBOL.',
            ];
        }

        try {
            $loteHash = $this->hashPeriodo($periodo);
        } catch (Throwable) {
            return [
                'estado' => 'PENDIENTE',
                'mensaje' => 'Las tablas definitivas todavía no fueron actualizadas.',
            ];
        }

        $ultimo = DB::table('web_procesos_transformacion_cobol')
            ->where('web_periodo', $periodo)
            ->latest('web_id')
            ->first();

        if ($ultimo === null) {
            return [
                'estado' => 'PENDIENTE',
                'mensaje' => 'Las tablas definitivas todavía no fueron actualizadas.',
            ];
        }

        if ($ultimo->web_estado === 'ERROR') {
            return [
                'estado' => 'ERROR',
                'etapa' => $ultimo->web_etapa,
                'mensaje' => 'Falló la actualización en '.$this->etiquetaEtapa((string) $ultimo->web_etapa).'.',
            ];
        }

        if ($ultimo->web_estado === 'PROCESANDO') {
            return [
                'estado' => 'PROCESANDO',
                'etapa' => $ultimo->web_etapa,
                'mensaje' => 'La actualización figura en proceso.',
            ];
        }

        if (! hash_equals((string) $ultimo->web_lote_hash, $loteHash)) {
            return [
                'estado' => 'MODIFICADO',
                'mensaje' => 'Los archivos COBOL cambiaron desde la última actualización de tablas.',
            ];
        }

        return [
            'estado' => 'OK',
            'mensaje' => 'Los archivos crudos y las tablas definitivas están actualizados.',
            'finalizado_at' => $ultimo->web_finalizado_at,
        ];
    }

    public function hashPeriodo(string $periodo): string
    {
        $this->validarPeriodo($periodo);
        $contexto = hash_init('sha256');

        foreach (self::ARCHIVOS_COBOL as $nombre) {
            $ruta = "liquidaciones/periodos/{$periodo}/cobol/{$nombre}";

            if (! Storage::exists($ruta)) {
                throw new RuntimeException("Falta {$nombre} para actualizar las tablas definitivas.");
            }

            hash_update($contexto, $nombre."\0");
            $archivo = fopen(Storage::path($ruta), 'rb');

            if ($archivo === false) {
                throw new RuntimeException("No se pudo leer {$nombre}.");
            }

            try {
                hash_update_stream($contexto, $archivo);
            } finally {
                fclose($archivo);
            }
        }

        return hash_final($contexto);
    }

    private function actualizarEtapa(int $procesoId, string $etapa): void
    {
        DB::table('web_procesos_transformacion_cobol')
            ->where('web_id', $procesoId)
            ->update([
                'web_etapa' => $etapa,
                'web_updated_at' => now(),
            ]);
    }

    private function guardarProgreso(
        string $periodo,
        string $etapa,
        string $detalle,
        int $porcentaje,
        string $estado = 'PROCESANDO',
        ?string $archivo = null,
        ?int $procesados = null,
        ?int $total = null
    ): void {
        $ruta = "liquidaciones/periodos/{$periodo}/".self::ARCHIVO_PROGRESO;
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

        Storage::put(
            $ruta,
            json_encode(
                array_merge($actual, [
                    'periodo' => $periodo,
                    'estado' => $estado,
                    'etapa' => $etapa,
                    'detalle' => $detalle,
                    'porcentaje' => $porcentaje,
                    'archivo' => $archivo,
                    'procesados' => $procesados,
                    'total' => $total,
                    'actualizado_at' => now()->toIso8601String(),
                ]),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ).PHP_EOL
        );
    }

    private function validarPeriodo(string $periodo): void
    {
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo)) {
            throw new RuntimeException('El período indicado no es válido.');
        }
    }

    private function validarTablaProcesos(): void
    {
        if (! Schema::hasTable('web_procesos_transformacion_cobol')) {
            throw new RuntimeException(
                'Falta crear la tabla de procesos. Ejecutá una vez: gei-artisan migrate.'
            );
        }
    }

    private function etiquetaEtapa(string $etapa): string
    {
        return match ($etapa) {
            'CLIENTES' => 'clientes',
            'INMUEBLES' => 'inmuebles',
            'CONTRATOS' => 'contratos',
            'CUENTAS_CORRIENTES' => 'cuentas corrientes',
            default => mb_strtolower(str_replace('_', ' ', $etapa)),
        };
    }
}
