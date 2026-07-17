<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KngStagingService
{
    public const VERSION_MAPEO = 'kng_dbf_equivalente_v20260716_01';

    public function reconstruir(int $importacionId): array
    {
        $this->asegurarTablas();

        $this->guardarLote($importacionId, 'reconstruyendo', null);

        $resumen = [
            'propietarios' => $this->reconstruirTabla($importacionId, 'propietario', 'web_kng_propietarios', fn ($payload) => [
                'web_cuenta' => (int) ($payload['cuenta'] ?? 0),
                'web_nombre' => (string) ($payload['nombre'] ?? ''),
                'web_domicilio' => (string) ($payload['domicilio'] ?? ''),
                'web_codigo_postal' => (string) ($payload['codigo_postal'] ?? ''),
                'web_localidad' => (string) ($payload['localidad'] ?? ''),
                'web_provincia' => (string) ($payload['provincia'] ?? ''),
                'web_telefono' => (string) ($payload['telefono'] ?? ''),
                'web_cuit' => preg_replace('/\D+/', '', (string) ($payload['identificacion_fiscal'] ?? '')) ?: '',
                'web_personeria_fiscal' => (int) ($payload['personeria_fiscal'] ?? 0),
            ]),
            'inquilinos' => $this->reconstruirTabla($importacionId, 'inquilino', 'web_kng_inquilinos', fn ($payload) => [
                'web_cuenta' => (int) ($payload['cuenta'] ?? 0),
                'web_cuenta_propietario' => (int) ($payload['cuenta_propietario'] ?? 0),
                'web_nombre' => (string) ($payload['nombre'] ?? ''),
                'web_domicilio_inmueble' => (string) ($payload['domicilio_inmueble'] ?? ''),
                'web_domicilio_legal' => (string) ($payload['domicilio_legal'] ?? ''),
                'web_documento' => (string) ($payload['documento'] ?? ''),
                'web_cuit' => preg_replace('/\D+/', '', (string) ($payload['identificacion_fiscal'] ?? '')) ?: '',
                'web_fecha_contrato' => $payload['fecha_contrato'] ?? null,
                'web_fecha_vencimiento' => $payload['fecha_vencimiento'] ?? null,
                'web_fecha_baja' => $payload['fecha_baja'] ?? null,
                'web_omitido_por_baja_antigua' => (bool) ($payload['omitido_por_baja_antigua'] ?? false),
            ]),
            'cuentas_propietarios' => $this->reconstruirTabla($importacionId, 'cuenta_propietario', 'web_kng_cta_propietarios', fn ($payload) => [
                'web_cuenta' => (int) ($payload['cuenta'] ?? 0),
                'web_fecha' => $payload['fecha'] ?? null,
                'web_numero_movimiento' => (string) ($payload['numero_movimiento'] ?? ''),
                'web_concepto' => (string) ($payload['concepto'] ?? ''),
                'web_debe' => (string) ($payload['debe'] ?? 0),
                'web_haber' => (string) ($payload['haber'] ?? 0),
                'web_periodo' => (string) ($payload['periodo'] ?? ''),
            ]),
            'cuentas_inquilinos' => $this->reconstruirTabla($importacionId, 'cuenta_inquilino', 'web_kng_cta_inquilinos', fn ($payload) => [
                'web_cuenta' => (int) ($payload['cuenta'] ?? 0),
                'web_fecha' => $payload['fecha'] ?? null,
                'web_numero_movimiento' => (string) ($payload['numero_movimiento'] ?? ''),
                'web_concepto' => (string) ($payload['concepto'] ?? ''),
                'web_debe' => (string) ($payload['debe'] ?? 0),
                'web_haber' => (string) ($payload['haber'] ?? 0),
                'web_periodo' => (string) ($payload['periodo'] ?? ''),
            ]),
        ];

        $this->guardarLote(
            $importacionId,
            'reconstruido',
            json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $resumen;
    }

    private function reconstruirTabla(int $importacionId, string $tipo, string $tabla, callable $mapear): int
    {
        DB::transaction(function () use ($importacionId, $tabla): void {
            DB::table($tabla)
                ->where('web_importacion_id', $importacionId)
                ->where('web_version_mapeo', self::VERSION_MAPEO)
                ->delete();
        });

        return $this->copiar($importacionId, $tipo, $tabla, $mapear);
    }

    private function guardarLote(int $importacionId, string $estado, ?string $resumen): void
    {
        $datos = [
            'web_estado' => $estado,
            'web_resumen' => $resumen,
            'updated_at' => now(),
        ];

        $actualizados = DB::table('web_kng_lotes')
            ->where('web_importacion_id', $importacionId)
            ->where('web_version_mapeo', self::VERSION_MAPEO)
            ->update($datos);

        if ($actualizados === 0) {
            DB::table('web_kng_lotes')->insert($datos + [
                'web_importacion_id' => $importacionId,
                'web_version_mapeo' => self::VERSION_MAPEO,
                'created_at' => now(),
            ]);
        }
    }

    private function copiar(int $importacionId, string $tipo, string $tabla, callable $mapear): int
    {
        $insertados = 0;
        DB::table('web_importaciones_registros')
            ->where('web_importacion_id', $importacionId)
            ->where('web_tipo', $tipo)
            ->chunkById(1000, function ($registros) use ($importacionId, $tabla, $mapear, &$insertados): void {
                $filas = [];
                foreach ($registros as $registro) {
                    $payload = json_decode((string) $registro->web_payload, true, 512, JSON_THROW_ON_ERROR);
                    $hash = hash('sha256', implode('|', [
                        self::VERSION_MAPEO,
                        $registro->web_archivo,
                        $registro->web_linea,
                        $registro->web_clave,
                        $registro->web_payload,
                    ]));
                    $filas[] = [
                        'web_importacion_id' => $importacionId,
                        'web_registro_staging_id' => $registro->web_id,
                        'web_archivo_origen' => $registro->web_archivo,
                        'web_numero_linea' => $registro->web_linea,
                        'web_hash_linea' => $hash,
                        'web_clave_kng' => (string) $registro->web_clave,
                        'web_orden_origen' => $registro->web_linea,
                        'web_estado_parseo' => 'interpretado',
                        'web_version_mapeo' => self::VERSION_MAPEO,
                        'web_campos_interpretados' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'web_payload_original' => (string) $registro->web_payload,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ] + $mapear($payload);
                }

                if ($filas !== []) {
                    DB::table($tabla)->insert($filas);
                    $insertados += count($filas);
                }
            }, 'web_id');

        return $insertados;
    }

    private function asegurarTablas(): void
    {
        foreach ([
            'web_kng_lotes',
            'web_kng_propietarios',
            'web_kng_inquilinos',
            'web_kng_cta_propietarios',
            'web_kng_cta_inquilinos',
        ] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                throw new \RuntimeException("Falta tabla {$tabla}. Ejecuta migraciones antes de reconstruir KNG.");
            }
        }
    }
}
