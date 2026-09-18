<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportarBeneficiariosFacturacionKng extends Command
{
    protected $signature = 'gei:facturacion-importar-beneficiarios-kng
                            {--desde=2026-07-01}
                            {--hasta=2026-07-31}
                            {--archivo=storage/app/private/coprop_facturacion.csv}
                            {--aplicar : Inserta/actualiza los beneficiarios}';

    protected $description =
        'Importa beneficiarios de facturación individual desde COPROP.DBF/KNG';

    /**
     * Resolución histórica confirmada para la regresión JULIO 2026.
     *
     * La clave es CLIENTES.DBF.ID_CLIENTE.
     *
     * IMPORTANTE:
     * - 1248 queda asociado al cliente agrupado 863, pero se conserva
     *   identificador_origen=1248 y sus datos KNG.
     * - 2246 todavía no tiene cliente GeI resuelto: queda NULL.
     */
    private array $resoluciones = [
        '1009' => 863,
        '1011' => 2624,
        '1083' => 1153,
        '1084' => 3707,
        '1248' => 863,
        '2243' => 2974,
        '2246' => null,
        '2247' => 18768,
    ];

    /**
     * Datos históricos de CLIENTES.DBF necesarios para trazabilidad.
     */
    private array $clientesKng = [
        '1009' => [
            'nombre' => 'ARAGON CARLOS A',
            'documento' => '6304197',
            'cuit' => '20063041972',
        ],
        '1011' => [
            'nombre' => 'ARAGON EDUARDO',
            'documento' => '7676396',
            'cuit' => '20076763969',
        ],
        '1083' => [
            'nombre' => 'BADIA VIVIANA ABARNO DE',
            'documento' => '5699336',
            'cuit' => '27056993369',
        ],
        '1084' => [
            'nombre' => 'BADIA JOSE',
            'documento' => '6238092',
            'cuit' => '20062380927',
        ],
        '1248' => [
            'nombre' => 'ARAGON MARTA BEATRIZ',
            'documento' => '11832467',
            'cuit' => null,
            'observacion' => 'Cliente histórico KNG representado en GeI por cliente agrupado 863',
        ],
        '2243' => [
            'nombre' => 'BOURQUIN MARIO LUIS',
            'documento' => '7977065',
            'cuit' => '20079770656',
        ],
        '2246' => [
            'nombre' => 'BOURQUIN GLENDA IVON',
            'documento' => '27320042',
            'cuit' => '27273200423',
            'observacion' => 'Sin cliente GeI resuelto al momento de la migración',
        ],
        '2247' => [
            'nombre' => 'BOURQUIN GUSTAVO ANDRES',
            'documento' => '23160500',
            'cuit' => '20231605003',
        ],
    ];

    public function handle(): int
    {
        $desde = $this->option('desde');
        $hasta = $this->option('hasta');
        $aplicar = (bool) $this->option('aplicar');

        $archivo = base_path($this->option('archivo'));

        if (!is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");
            return self::FAILURE;
        }

        $this->info(
            $aplicar
                ? 'MODO APLICACION'
                : 'MODO SIMULACION - no se modificará la base'
        );

        $this->line("Período: {$desde} a {$hasta}");
        $this->newLine();

        /*
         * Sólo propietarios INDIVIDUALES que efectivamente tuvieron
         * movimientos en el período solicitado.
         */
        $cuentasPeriodo = DB::table('cuentas_corrientes as cc')
            ->join(
                'cuentas_corrientes_movimientos as m',
                'm.cuenta_corriente_id',
                '=',
                'cc.id'
            )
            ->where('cc.dominio', 'PROPIETARIO')
            ->where('cc.modalidad_facturacion', 'INDIVIDUAL')
            ->whereBetween('m.fecha', [$desde, $hasta])
            ->select(
                'cc.id',
                'cc.cuenta'
            )
            ->distinct()
            ->orderBy('cc.cuenta')
            ->get()
            ->keyBy(fn ($r) => trim((string) $r->cuenta));

        $this->line(
            'Cuentas individuales con movimientos: ' .
            $cuentasPeriodo->count()
        );

        /*
         * Leemos COPROP.
         */
        $fh = fopen($archivo, 'r');

        $header = fgetcsv($fh, null, ',', '"', '\\');

        if (!$header) {
            $this->error('CSV sin encabezado.');
            fclose($fh);
            return self::FAILURE;
        }

        $header = array_map(
            fn ($v) => strtoupper(trim((string) $v)),
            $header
        );

        $filas = [];
        $numeroLinea = 1;

        while (
            ($fila = fgetcsv($fh, null, ',', '"', '\\')) !== false
        ) {
            $numeroLinea++;

            if (count($fila) !== count($header)) {
                $this->warn(
                    "Línea {$numeroLinea}: cantidad de columnas inválida"
                );
                continue;
            }

            $r = array_combine($header, $fila);

            $cuenta = trim((string) ($r['ID_PROP'] ?? ''));

            if ($cuenta === '') {
                continue;
            }

            /*
             * Ignoramos COPROP de propietarios que no son parte
             * de la regresión del período solicitado.
             */
            if (!$cuentasPeriodo->has($cuenta)) {
                continue;
            }

            $idClienteKng = trim(
                (string) ($r['ID_CLIENTE'] ?? '')
            );

            $porcentaje = $this->decimal(
                $r['PORCENTAJE'] ?? null
            );

            if ($idClienteKng === '') {
                $this->warn(
                    "{$cuenta}: fila COPROP sin ID_CLIENTE"
                );
                continue;
            }

            if ($porcentaje === null) {
                $this->warn(
                    "{$cuenta}/{$idClienteKng}: porcentaje inválido"
                );
                continue;
            }

            $filas[$cuenta][] = [
                'id_cliente_kng' => $idClienteKng,
                'porcentaje' => $porcentaje,
                'linea' => $numeroLinea,
            ];
        }

        fclose($fh);

        /*
         * Auditoría.
         */
        $errores = 0;
        $beneficiarios = 0;
        $resueltos = 0;
        $noResueltos = 0;

        foreach ($cuentasPeriodo as $cuenta => $cc) {
            $this->newLine();
            $this->line("Cuenta {$cuenta}");

            $items = $filas[$cuenta] ?? [];

            if (!$items) {
                $this->error('  SIN BENEFICIARIOS COPROP');
                $errores++;
                continue;
            }

            $total = 0.0;

            foreach ($items as $item) {
                $beneficiarios++;

                $idKng = $item['id_cliente_kng'];
                $porcentaje = $item['porcentaje'];

                $clienteId = array_key_exists(
                    $idKng,
                    $this->resoluciones
                )
                    ? $this->resoluciones[$idKng]
                    : null;

                $datos = $this->clientesKng[$idKng] ?? [];

                $nombre = $datos['nombre'] ?? 'SIN DATOS';

                if ($clienteId === null) {
                    $noResueltos++;
                    $estado = 'PENDIENTE';
                } else {
                    $resueltos++;
                    $estado = "GeI #{$clienteId}";
                }

                $this->line(
                    sprintf(
                        '  KNG %-5s | %6.2f%% | %-10s | %s',
                        $idKng,
                        $porcentaje,
                        $estado,
                        $nombre
                    )
                );

                $total += $porcentaje;
            }

            $this->line(
                sprintf('  TOTAL: %.2f%%', $total)
            );

            /*
             * Usamos tolerancia mínima por porcentajes como 33.33/66.67.
             */
            if (abs($total - 100.0) > 0.01) {
                $this->error(
                    sprintf(
                        '  ERROR: porcentajes no suman 100%% (%.4f)',
                        $total
                    )
                );

                $errores++;
            }
        }

        $this->newLine();

        $this->table(
            ['Concepto', 'Cantidad'],
            [
                [
                    'Cuentas individuales período',
                    $cuentasPeriodo->count(),
                ],
                [
                    'Cuentas con COPROP',
                    count($filas),
                ],
                [
                    'Beneficiarios',
                    $beneficiarios,
                ],
                [
                    'Beneficiarios resueltos',
                    $resueltos,
                ],
                [
                    'Beneficiarios pendientes',
                    $noResueltos,
                ],
                [
                    'Errores',
                    $errores,
                ],
            ]
        );

        if ($errores > 0) {
            $this->error(
                'Existen errores. No se realizará ninguna modificación.'
            );

            return self::FAILURE;
        }

        if (!$aplicar) {
            $this->warn('SIMULACION finalizada. No se modificó la base.');
            return self::SUCCESS;
        }

        /*
         * Aplicación.
         */
        DB::transaction(function () use (
            $cuentasPeriodo,
            $filas
        ) {
            $ahora = now();

            foreach ($cuentasPeriodo as $cuenta => $cc) {
                foreach ($filas[$cuenta] as $item) {
                    $idKng = $item['id_cliente_kng'];

                    $clienteId = array_key_exists(
                        $idKng,
                        $this->resoluciones
                    )
                        ? $this->resoluciones[$idKng]
                        : null;

                    $datosKng =
                        $this->clientesKng[$idKng] ?? [];

                    DB::table(
                        'cuentas_facturacion_beneficiarios'
                    )->updateOrInsert(
                        [
                            'cuenta_corriente_id' => $cc->id,
                            'identificador_origen' => $idKng,
                        ],
                        [
                            'cliente_id' => $clienteId,
                            'porcentaje' => $item['porcentaje'],
                            'activo' => true,
                            'origen' => 'MIGRACION_KNG',

                            'datos_origen' => json_encode(
                                [
                                    'sistema' => 'KNG',
                                    'entidad' => 'COPROP',
                                    'id_prop' => $cuenta,
                                    'id_cliente' => $idKng,
                                    'cliente_kng' => $datosKng,
                                    'cliente_gei_resuelto' => $clienteId,
                                    'resolucion' => $this
                                        ->descripcionResolucion(
                                            $idKng,
                                            $clienteId
                                        ),
                                ],
                                JSON_UNESCAPED_UNICODE |
                                JSON_UNESCAPED_SLASHES
                            ),

                            'updated_at' => $ahora,
                            'created_at' => $ahora,
                        ]
                    );
                }
            }
        });

        $this->newLine();
        $this->info(
            'Beneficiarios KNG importados correctamente.'
        );

        return self::SUCCESS;
    }

    private function decimal(mixed $valor): ?float
    {
        if ($valor === null) {
            return null;
        }

        $v = trim((string) $valor);

        if ($v === '') {
            return null;
        }

        $v = str_replace(',', '.', $v);

        if (!is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }

    private function descripcionResolucion(
        string $idKng,
        ?int $clienteId
    ): string {
        if ($idKng === '1248') {
            return 'CLIENTE_KNG_ASOCIADO_A_CLIENTE_GEI_AGRUPADO';
        }

        if ($clienteId === null) {
            return 'PENDIENTE_RESOLUCION_CLIENTE_GEI';
        }

        return 'CLIENTE_GEI_RESUELTO';
    }
}
