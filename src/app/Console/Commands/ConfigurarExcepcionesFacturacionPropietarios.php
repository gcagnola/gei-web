<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigurarExcepcionesFacturacionPropietarios extends Command
{
    protected $signature = 'gei:facturacion-configurar-excepciones-propietarios
                            {--simular : No modifica la base}';

    protected $description = 'Configura las 5 cuentas FACT_IND/COPROP históricas auditadas en KNG';

    private array $configuraciones = [
        '12020742302' => [
            'destinatario_facturacion' => 'COPROPIETARIOS',
            'agrupacion_facturacion' => 'CONSOLIDADA',
            'tratamiento_fiscal' => 'AUTOMATICO',
            'observaciones_facturacion' => 'KNG FACT_IND/COPROP. Julio 2026: consolidada por beneficiario. Revisar preferencia comercial antes de producción.',
        ],
        '12020755605' => [
            'destinatario_facturacion' => 'COPROPIETARIOS',
            'agrupacion_facturacion' => 'POR_MOVIMIENTO',
            'tratamiento_fiscal' => 'AUTOMATICO',
            'observaciones_facturacion' => 'KNG FACT_IND/COPROP. Julio 2026: facturación separada por movimiento y beneficiario. Revisar si corresponde POR_INMUEBLE.',
        ],
        '12020798308' => [
            'destinatario_facturacion' => 'COPROPIETARIOS',
            'agrupacion_facturacion' => 'CONSOLIDADA',
            'tratamiento_fiscal' => 'AUTOMATICO',
            'observaciones_facturacion' => 'KNG FACT_IND/COPROP. Julio 2026: consolidada por beneficiario. Revisar preferencia comercial antes de producción.',
        ],
        '12020829807' => [
            'destinatario_facturacion' => 'COPROPIETARIOS',
            'agrupacion_facturacion' => 'CONSOLIDADA',
            'tratamiento_fiscal' => 'AUTOMATICO',
            'observaciones_facturacion' => 'KNG FACT_IND/COPROP. Julio 2026: consolidada por beneficiario. El tipo fiscal se resuelve por beneficiario.',
        ],
        '12020955508' => [
            'destinatario_facturacion' => 'COPROPIETARIOS',
            'agrupacion_facturacion' => 'POR_MOVIMIENTO',
            'tratamiento_fiscal' => 'AUTOMATICO',
            'observaciones_facturacion' => 'KNG FACT_IND/COPROP. Julio 2026: 40 movimientos facturables 01-20 x 3 beneficiarios = 120 comprobantes. Revisar si corresponde POR_INMUEBLE.',
        ],
    ];

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        $filas = [];

        foreach ($this->configuraciones as $cuenta => $config) {
            $cc = DB::table('cuentas_corrientes')
                ->where('dominio', 'PROPIETARIO')
                ->where('cuenta', $cuenta)
                ->first();

            if (!$cc) {
                $filas[] = [$cuenta, 'NO ENCONTRADA', '', '', ''];
                continue;
            }

            $beneficiarios = DB::table('cuentas_facturacion_beneficiarios')
                ->where('cuenta_corriente_id', $cc->id)
                ->where('activo', true)
                ->count();

            if (!$simular) {
                DB::table('cuentas_corrientes')
                    ->where('id', $cc->id)
                    ->update(array_merge($config, [
                        'facturable' => true,
                        'modalidad_facturacion' => 'INDIVIDUAL',
                        'updated_at' => now(),
                    ]));
            }

            $filas[] = [
                $cuenta,
                $simular ? 'SIMULAR' : 'OK',
                $config['destinatario_facturacion'],
                $config['agrupacion_facturacion'],
                (string) $beneficiarios,
            ];
        }

        $this->table(
            ['Cuenta', 'Estado', 'Destinatario', 'Agrupación', 'Beneficiarios'],
            $filas
        );

        if ($simular) {
            $this->warn('No se modificó la base (--simular).');
        }

        return self::SUCCESS;
    }
}
