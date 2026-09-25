<?php

namespace App\Console\Commands;

use App\Models\Concepto;
use App\Models\ConceptoImputacionCaja;
use App\Models\CuentaCaja;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportarConceptosCaja extends Command
{
    protected $signature = 'gei:importar-conceptos-caja
        {--sccoi=/tmp/SCCOI.TXT : Export secuencial de SCCOI}
        {--sccop=/tmp/SCCOP.TXT : Export secuencial de SCCOP}
        {--sccuent=/tmp/SCCUENT.TXT : Export secuencial de SCCUENT}
        {--simular : Analiza y valida sin grabar cambios}';

    protected $description = 'Importa el plan de cuentas de Caja y las imputaciones de conceptos INQ/PROP desde exports COBOL.';

    public function handle(): int
    {
        try {
            $sccoi = $this->leerLineas((string) $this->option('sccoi'));
            $sccop = $this->leerLineas((string) $this->option('sccop'));
            $sccuent = $this->leerLineas((string) $this->option('sccuent'));

            $cuentas = $this->parsearCuentas($sccuent);
            $inq = $this->parsearSccoi($sccoi);
            $prop = $this->parsearSccop($sccop);

            $this->newLine();
            $this->info('Archivos leídos:');
            $this->line('  SCCOI:   ' . count($inq) . ' registro(s)');
            $this->line('  SCCOP:   ' . count($prop) . ' registro(s)');
            $this->line('  SCCUENT: ' . count($cuentas) . ' registro(s)');

            $auditoria = $this->auditar($cuentas, $inq, $prop);

            $this->mostrarAuditoria($auditoria);

            if ($this->option('simular')) {
                $this->newLine();
                $this->warn('SIMULACIÓN: no se modificó la base de datos.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($cuentas, $inq, $prop) {
                CuentaCaja::query()
                    ->where('origen_cobol', 'SCCUENT')
                    ->update(['activo' => false]);

                foreach ($cuentas as $cuenta) {
                    CuentaCaja::updateOrCreate(
                        ['codigo_cobol' => $cuenta['codigo_cobol']],
                        [
                            'numero_contable' => $cuenta['numero_contable'],
                            'nombre' => $cuenta['nombre'],
                            'subcuenta' => $cuenta['subcuenta'],
                            'activo' => true,
                            'origen_cobol' => 'SCCUENT',
                        ]
                    );
                }

                $mapaCuentas = CuentaCaja::query()
                    ->pluck('id', 'codigo_cobol')
                    ->all();

                $this->guardarImputaciones('INQ', 'SCCOI', $inq, $mapaCuentas);
                $this->guardarImputaciones('PROP', 'SCCOP', $prop, $mapaCuentas);
            });

            $this->newLine();
            $this->info('Importación finalizada correctamente.');

            $this->line('Cuentas activas SCCUENT: ' .
                CuentaCaja::query()->where('origen_cobol', 'SCCUENT')->where('activo', true)->count());

            $this->line('Imputaciones SCCOI: ' .
                ConceptoImputacionCaja::query()->where('origen_cobol', 'SCCOI')->count());

            $this->line('Imputaciones SCCOP: ' .
                ConceptoImputacionCaja::query()->where('origen_cobol', 'SCCOP')->count());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function leerLineas(string $archivo): array
    {
        if (!is_file($archivo) || !is_readable($archivo)) {
            throw new RuntimeException("No se puede leer {$archivo}");
        }

        $lineas = file($archivo, FILE_IGNORE_NEW_LINES);
        if ($lineas === false) {
            throw new RuntimeException("No se pudo abrir {$archivo}");
        }

        return array_values(array_filter($lineas, fn ($linea) => trim((string) $linea) !== ''));
    }

    private function texto(string $valor): string
    {
        $valor = rtrim($valor);

        if ($valor !== '' && !mb_check_encoding($valor, 'UTF-8')) {
            $valor = mb_convert_encoding($valor, 'UTF-8', 'ISO-8859-1');
        }

        return trim($valor);
    }

    private function parsearCuentas(array $lineas): array
    {
        $salida = [];

        foreach ($lineas as $n => $linea) {
            $partes = explode('|', $linea);

            if (count($partes) < 4) {
                throw new RuntimeException('SCCUENT.TXT inválido en línea ' . ($n + 1));
            }

            $codigo = trim($partes[0]);

            if (!preg_match('/^\d{4}$/', $codigo)) {
                throw new RuntimeException("Código de cuenta inválido '{$codigo}' en SCCUENT línea " . ($n + 1));
            }

            $salida[$codigo] = [
                'codigo_cobol' => $codigo,
                'numero_contable' => $this->texto($partes[1]),
                'nombre' => $this->texto($partes[2]),
                'subcuenta' => $this->texto($partes[3]),
            ];
        }

        return array_values($salida);
    }

    private function parsearSccoi(array $lineas): array
    {
        $salida = [];

        foreach ($lineas as $n => $linea) {
            $p = explode('|', $linea);

            if (count($p) < 14) {
                throw new RuntimeException('SCCOI.TXT inválido en línea ' . ($n + 1));
            }

            $codigo = trim($p[0]);

            if (!preg_match('/^\d{2}$/', $codigo)) {
                throw new RuntimeException("Código INQ inválido '{$codigo}' en línea " . ($n + 1));
            }

            $salida[] = [
                'codigo' => $codigo,
                'descripcion' => $this->texto($p[1]),
                'imputaciones' => [
                    ['sede' => 'SF', 'moneda' => 'ARS', 'judicial' => false, 'cuenta' => trim($p[6])],
                    ['sede' => 'ST', 'moneda' => 'ARS', 'judicial' => false, 'cuenta' => trim($p[7])],
                    ['sede' => 'SF', 'moneda' => 'ARS', 'judicial' => true,  'cuenta' => trim($p[8])],
                    ['sede' => 'ST', 'moneda' => 'ARS', 'judicial' => true,  'cuenta' => trim($p[9])],
                    ['sede' => 'SF', 'moneda' => 'USD', 'judicial' => false, 'cuenta' => trim($p[10])],
                    ['sede' => 'ST', 'moneda' => 'USD', 'judicial' => false, 'cuenta' => trim($p[11])],
                    ['sede' => 'SF', 'moneda' => 'USD', 'judicial' => true,  'cuenta' => trim($p[12])],
                    ['sede' => 'ST', 'moneda' => 'USD', 'judicial' => true,  'cuenta' => trim($p[13])],
                ],
            ];
        }

        return $salida;
    }

    private function parsearSccop(array $lineas): array
    {
        $salida = [];

        foreach ($lineas as $n => $linea) {
            $p = explode('|', $linea);

            if (count($p) < 6) {
                throw new RuntimeException('SCCOP.TXT inválido en línea ' . ($n + 1));
            }

            $codigo = trim($p[0]);

            if (!preg_match('/^\d{2}$/', $codigo)) {
                throw new RuntimeException("Código PROP inválido '{$codigo}' en línea " . ($n + 1));
            }

            $salida[] = [
                'codigo' => $codigo,
                'descripcion' => $this->texto($p[1]),
                'imputaciones' => [
                    ['sede' => 'SF', 'moneda' => 'ARS', 'judicial' => false, 'cuenta' => trim($p[2])],
                    ['sede' => 'ST', 'moneda' => 'ARS', 'judicial' => false, 'cuenta' => trim($p[3])],
                    ['sede' => 'SF', 'moneda' => 'USD', 'judicial' => false, 'cuenta' => trim($p[4])],
                    ['sede' => 'ST', 'moneda' => 'USD', 'judicial' => false, 'cuenta' => trim($p[5])],
                ],
            ];
        }

        return $salida;
    }

    private function auditar(array $cuentas, array $inq, array $prop): array
    {
        $codigosCuenta = array_fill_keys(array_column($cuentas, 'codigo_cobol'), true);

        $conceptosDb = Concepto::query()
            ->get(['dominio', 'codigo'])
            ->mapWithKeys(fn ($c) => [$c->dominio . ':' . $c->codigo => true])
            ->all();

        $sinConcepto = [];
        $cuentasFaltantes = [];
        $imputaciones = 0;
        $imputacionesValidas = 0;
        $imputacionesCuentaInexistente = 0;
        $imputacionesCero = 0;

        foreach ([['INQ', $inq], ['PROP', $prop]] as [$dominio, $registros]) {
            foreach ($registros as $registro) {
                if (!isset($conceptosDb[$dominio . ':' . $registro['codigo']])) {
                    $sinConcepto[] = $dominio . ':' . $registro['codigo'] . ' ' . $registro['descripcion'];
                }

                foreach ($registro['imputaciones'] as $imp) {
                    $cuenta = $imp['cuenta'];

                    if ($cuenta === '' || $cuenta === '0000') {
                        $imputacionesCero++;
                        continue;
                    }

                    $imputaciones++;

                    if (!isset($codigosCuenta[$cuenta])) {
                        $cuentasFaltantes[$cuenta] = true;
                        $imputacionesCuentaInexistente++;
                        continue;
                    }

                    $imputacionesValidas++;
                }
            }
        }

        return [
            'imputaciones' => $imputaciones,
            'imputaciones_validas' => $imputacionesValidas,
            'imputaciones_cuenta_inexistente' => $imputacionesCuentaInexistente,
            'imputaciones_cero' => $imputacionesCero,
            'sin_concepto' => $sinConcepto,
            'cuentas_faltantes' => array_keys($cuentasFaltantes),
        ];
    }

    private function mostrarAuditoria(array $a): void
    {
        $this->newLine();
        $this->info('Auditoría previa:');
        $this->line('  Imputaciones no cero: ' . $a['imputaciones']);
        $this->line('  Imputaciones válidas (cuenta existente): ' . $a['imputaciones_validas']);
        $this->line('  Imputaciones omitidas (cuenta inexistente): ' . $a['imputaciones_cuenta_inexistente']);
        $this->line('  Posiciones 0000/vacías: ' . $a['imputaciones_cero']);
        $this->line('  Conceptos inexistentes en PostgreSQL: ' . count($a['sin_concepto']));
        $this->line('  Cuentas referenciadas no encontradas en SCCUENT: ' . count($a['cuentas_faltantes']));

        if ($a['sin_concepto']) {
            $this->warn('Conceptos faltantes:');
            foreach ($a['sin_concepto'] as $item) {
                $this->line('  - ' . $item);
            }
        }

        if ($a['cuentas_faltantes']) {
            $this->warn('Cuentas faltantes en SCCUENT: ' . implode(', ', $a['cuentas_faltantes']));
        }
    }

    private function guardarImputaciones(string $dominio, string $origen, array $registros, array $mapaCuentas): void
    {
        foreach ($registros as $registro) {
            $concepto = Concepto::query()
                ->where('dominio', $dominio)
                ->where('codigo', $registro['codigo'])
                ->first();

            if (!$concepto) {
                continue;
            }

            foreach ($registro['imputaciones'] as $imp) {
                $cuenta = $imp['cuenta'];

                $base = [
                    'concepto_id' => $concepto->id,
                    'sede' => $imp['sede'],
                    'moneda' => $imp['moneda'],
                    'judicial' => $imp['judicial'],
                ];

                if ($cuenta === '' || $cuenta === '0000') {
                    ConceptoImputacionCaja::query()
                        ->where($base)
                        ->where('origen_cobol', $origen)
                        ->delete();
                    continue;
                }

                // SCCOI/SCCOP conservan algunas referencias históricas a cuentas
                // que ya no existen en el maestro operativo SCCUENT. Esas
                // posiciones no se importan como imputaciones válidas.
                if (!isset($mapaCuentas[$cuenta])) {
                    ConceptoImputacionCaja::query()
                        ->where($base)
                        ->where('origen_cobol', $origen)
                        ->delete();
                    continue;
                }

                ConceptoImputacionCaja::updateOrCreate(
                    $base,
                    [
                        'cuenta_caja_codigo' => $cuenta,
                        'cuenta_caja_id' => $mapaCuentas[$cuenta],
                        'origen_cobol' => $origen,
                    ]
                );
            }
        }
    }
}
