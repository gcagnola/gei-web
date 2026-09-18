<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InicializarConfiguracionFacturacionKng extends Command
{
    protected $signature = 'gei:facturacion-inicializar-kng
                            {--perfil=julio-2026 : Perfil histórico a cargar}';

    protected $description =
        'Inicializa la configuración de facturación para regresión contra KNG';

    public function handle(): int
    {
        $perfil = $this->option('perfil');

        if ($perfil !== 'julio-2026') {
            $this->error("Perfil no soportado: {$perfil}");
            return self::FAILURE;
        }

        if (
            DB::table('puntos_venta')->exists()
            || DB::table('numeraciones_comprobantes')->exists()
            || DB::table('configuraciones_facturacion')->exists()
            || DB::table('alicuotas_iva')->exists()
        ) {
            $this->error(
                'Ya existe configuración de facturación. No se modificó nada.'
            );
            return self::FAILURE;
        }

        DB::transaction(function () {
            $ahora = now();

            /*
             * Puntos de venta vigentes para la facturación general
             * de julio 2026.
             */
            foreach ([
                [
                    'numero' => 38,
                    'nombre' => 'Santa Fe - Facturación general',
                    'localidad' => 'Santa Fe',
                ],
                [
                    'numero' => 39,
                    'nombre' => 'Santo Tomé - Facturación general',
                    'localidad' => 'Santo Tomé',
                ],
            ] as $punto) {
                DB::table('puntos_venta')->insert(
                    $punto + [
                        'modalidad' => 'GENERAL',
                        'activo' => true,
                        'historico' => false,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]
                );
            }

            $ids = DB::table('puntos_venta')
                ->pluck('id_punto_venta', 'numero');

            /*
             * Estado inmediatamente ANTERIOR al primer
             * comprobante de julio 2026.
             *
             * 1 = Factura A
             * 3 = NC A
             * 6 = Factura B
             * 8 = NC B
             */
            $numeraciones = [
                38 => [
                    1 => 64919,
                    3 => 1785,
                    6 => 273763,
                    8 => 10535,
                ],
                39 => [
                    1 => 12660,
                    3 => 309,
                    6 => 51597,
                    8 => 2347,
                ],
            ];

            foreach ($numeraciones as $pv => $tipos) {
                foreach ($tipos as $tipo => $proximo) {
                    DB::table('numeraciones_comprobantes')->insert([
                        'id_punto_venta' => $ids[$pv],
                        'tipo_comprobante' => $tipo,
                        'proximo_numero' => $proximo,
                        'activo' => true,
                        'ultimo_numero_arca' => null,
                        'ultima_sincronizacion_arca_at' => null,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }
            }

            DB::table('configuraciones_facturacion')->insert([
                /*
                 * Primer lote real de julio.
                 */
                'proximo_numero_lote' => 1236,

                /*
                 * Parámetros KNG históricos.
                 * Son para regresión; posteriormente se revisarán
                 * antes de convertirlos en reglas fiscales vigentes.
                 */
                'tope_no_gravado' => 1500.00,
                'alicuota_iva_general' => 21.0000,
                'decimales_redondeo' => 2,
                'emitir_nc_locadores' => false,

                'modo_emision' => 'RECE',
                'ambiente_arca' => 'HOMOLOGACION',
                'cuit_emisor' => null,

                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            foreach ([
                3 => ['EXENTA', 0.0000],
                4 => ['BIENES DE USO', 10.5000],
                5 => ['GENERAL', 21.0000],
                6 => ['SERVICIOS', 27.0000],
                8 => ['REDUCIDA', 5.0000],
                9 => ['REDUCIDA A MITAD', 2.5000],
            ] as $codigo => [$nombre, $porcentaje]) {
                DB::table('alicuotas_iva')->insert([
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'porcentaje' => $porcentaje,
                    'activo' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        });

        $this->info(
            'Configuración histórica JULIO 2026 inicializada correctamente.'
        );

        $this->table(
            ['PV', 'Tipo', 'Próximo'],
            [
                [38, 'Factura A', 64919],
                [38, 'NC A', 1785],
                [38, 'Factura B', 273763],
                [38, 'NC B', 10535],
                [39, 'Factura A', 12660],
                [39, 'NC A', 309],
                [39, 'Factura B', 51597],
                [39, 'NC B', 2347],
            ]
        );

        $this->line('Próximo lote: 1236');

        return self::SUCCESS;
    }
}
