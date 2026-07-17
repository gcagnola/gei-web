<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReconstruccionLiquidacionesFoxService
{
    private const MESES = [
        'ENERO' => 1,
        'FEBRERO' => 2,
        'MARZO' => 3,
        'ABRIL' => 4,
        'MAYO' => 5,
        'JUNIO' => 6,
        'JULIO' => 7,
        'AGOSTO' => 8,
        'SEPTIEMBRE' => 9,
        'OCTUBRE' => 10,
        'NOVIEMBRE' => 11,
        'DICIEMBRE' => 12,
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cabecerasDesdePliqloc(): array
    {
        $cabeceras = [];

        foreach ($this->archivosPliqloc() as $sede => $archivo) {
            if (! is_file($archivo)) {
                continue;
            }

            foreach (file($archivo, FILE_IGNORE_NEW_LINES) ?: [] as $indice => $linea) {
                $cabecera = $this->parsearLineaPliqloc((string) $linea, $sede, $archivo, $indice + 1);
                if ($cabecera !== null) {
                    $cabeceras[] = $cabecera;
                }
            }
        }

        return $cabeceras;
    }

    /**
     * @return array<string, mixed>
     */
    public function compararCabeceras(): array
    {
        $cabeceras = $this->cabecerasDesdePliqloc();
        $resumen = [
            'fuente' => count($cabeceras),
            'coincidencias_exactas' => 0,
            'coincidencias_normalizadas' => 0,
            'coincidencias_con_diferencias' => 0,
            'no_encontradas' => 0,
            'ambiguas' => 0,
            'detalles' => [],
        ];

        foreach ($cabeceras as $cabecera) {
            $candidatos = DB::table('liquidaciones_de_clientes')
                ->where('numero_de_comprobante', $cabecera['numero_de_comprobante'])
                ->get();

            if ($candidatos->count() === 0) {
                $resumen['no_encontradas']++;
                $resumen['detalles'][] = $this->detalleCabecera($cabecera, 'NO_ENCONTRADO_EN_POSTGRESQL', null, [
                    'numero_de_comprobante' => ['interpretado' => $cabecera['numero_de_comprobante'], 'postgresql' => null],
                ]);
                continue;
            }

            if ($candidatos->count() > 1) {
                $resumen['ambiguas']++;
                $resumen['detalles'][] = $this->detalleCabecera($cabecera, 'AMBIGUO', null, [
                    'numero_de_comprobante' => ['interpretado' => $cabecera['numero_de_comprobante'], 'coincidencias' => $candidatos->count()],
                ]);
                continue;
            }

            $liquidacion = $candidatos->first();
            $diferencias = $this->compararCabecera($cabecera, $liquidacion);
            if ($diferencias === []) {
                $resumen['coincidencias_exactas']++;
                $estado = 'COINCIDE_EXACTAMENTE';
            } else {
                $resumen['coincidencias_con_diferencias']++;
                $estado = 'COINCIDE_CON_DIFERENCIAS';
            }

            $resumen['detalles'][] = $this->detalleCabecera(
                $cabecera,
                $estado,
                (string) $liquidacion->numero_de_liquidacion,
                $diferencias
            );
        }

        return $resumen;
    }

    /**
     * @return array<string, mixed>
     */
    public function compararItems(): array
    {
        $liquidaciones = $this->liquidacionesDesdeListados();
        $resumen = [
            'fuente' => 0,
            'coincidencias_exactas' => 0,
            'coincidencias_normalizadas' => 0,
            'coincidencias_con_diferencias' => 0,
            'no_encontradas' => 0,
            'ambiguas' => 0,
            'detalles' => [],
        ];

        foreach ($liquidaciones as $liquidacion) {
            $cabeceras = DB::table('liquidaciones_de_clientes')
                ->where('numero_de_comprobante', $liquidacion['comprobante'])
                ->get();

            if ($cabeceras->count() !== 1) {
                foreach ($liquidacion['items'] as $item) {
                    $resumen['fuente']++;
                    $estado = $cabeceras->count() === 0 ? 'NO_ENCONTRADO_EN_POSTGRESQL' : 'AMBIGUO';
                    $resumen[$cabeceras->count() === 0 ? 'no_encontradas' : 'ambiguas']++;
                    $resumen['detalles'][] = $this->detalleItem($liquidacion, $item, $estado, null, [
                        'cabecera' => [
                            'valor_interpretado' => $liquidacion['comprobante'],
                            'valor_postgresql' => $cabeceras->count(),
                            'regla_vfp' => 'La cabecera se localiza por numero_de_comprobante reconstruido del listado.',
                        ],
                    ]);
                }

                continue;
            }

            $cabecera = $cabeceras->first();
            $itemsPostgresql = DB::table('liquidaciones_de_clientes_items')
                ->where('numero_de_liquidacion', $cabecera->numero_de_liquidacion)
                ->orderBy('numero_de_item')
                ->get()
                ->values();

            foreach ($liquidacion['items'] as $orden => $item) {
                $resumen['fuente']++;
                $itemPostgresql = $this->resolverItemPostgresql($itemsPostgresql, $item, $orden);

                if ($itemPostgresql === null) {
                    $resumen['no_encontradas']++;
                    $resumen['detalles'][] = $this->detalleItem($liquidacion, $item, 'NO_ENCONTRADO_EN_POSTGRESQL', (string) $cabecera->numero_de_liquidacion, [
                        'item' => [
                            'valor_interpretado' => $item,
                            'valor_postgresql' => null,
                            'regla_vfp' => 'Item reconstruido desde columnas del listado; no existe equivalente por orden/referencia en PostgreSQL.',
                        ],
                    ]);
                    continue;
                }

                $diferencias = $this->compararItem($item, $itemPostgresql, $orden);
                if ($diferencias === []) {
                    $resumen['coincidencias_exactas']++;
                    $estado = 'COINCIDE_EXACTAMENTE';
                } else {
                    $resumen['coincidencias_con_diferencias']++;
                    $estado = 'COINCIDE_CON_DIFERENCIAS';
                }

                $resumen['detalles'][] = $this->detalleItem(
                    $liquidacion,
                    $item,
                    $estado,
                    (string) $itemPostgresql->numero_de_item,
                    $diferencias
                );
            }
        }

        return $resumen;
    }

    /**
     * @return array<string, mixed>
     */
    public function compararDailoc(string $archivoEsperado): array
    {
        $registros = $this->dailocDesdeArchivo($archivoEsperado);
        $resumen = [
            'fuente' => count($registros),
            'coincidencias_exactas' => 0,
            'coincidencias_normalizadas' => 0,
            'coincidencias_con_diferencias' => 0,
            'no_encontradas' => 0,
            'ambiguas' => 0,
            'detalles' => [],
        ];

        foreach ($registros as $registro) {
            if (abs((float) $registro['importe_item']) < 0.005) {
                $resumen['coincidencias_exactas']++;
                $resumen['detalles'][] = $this->detalleDailoc($registro, 'COINCIDE_EXACTAMENTE', null, []);
                continue;
            }

            $candidatos = DB::table('liquidaciones_de_clientes as l')
                ->join('liquidaciones_de_clientes_items as i', 'i.numero_de_liquidacion', '=', 'l.numero_de_liquidacion')
                ->where('l.fecha', '2026-06-19')
                ->where('l.periodo', 'like', 'Junio/2026%')
                ->where('l.nro_cuenta', $registro['nro_cuenta'])
                ->where('i.detalle', 'like', 'Pago Imptos del mes s/detalle%')
                ->get(['i.numero_de_item', 'i.total', 'l.numero_de_comprobante']);

            $coincidentes = $candidatos->filter(fn (object $item): bool => abs((float) $item->total - (float) $registro['importe_item']) <= 0.05)->values();
            if ($coincidentes->count() === 1) {
                $resumen['coincidencias_exactas']++;
                $resumen['detalles'][] = $this->detalleDailoc($registro, 'COINCIDE_EXACTAMENTE', (string) $coincidentes->first()->numero_de_item, []);
                continue;
            }

            if ($coincidentes->count() > 1) {
                $resumen['ambiguas']++;
                $resumen['detalles'][] = $this->detalleDailoc($registro, 'AMBIGUO', null, [
                    'importe_item' => [
                        'valor_interpretado' => $registro['importe_item'],
                        'valor_postgresql' => $coincidentes->count(),
                        'regla_vfp' => 'Dailoc TOTAL tercera columna contra item Pago Imptos del mes s/detalle.',
                    ],
                ]);
                continue;
            }

            if ($candidatos->count() > 0) {
                $resumen['coincidencias_con_diferencias']++;
                $resumen['detalles'][] = $this->detalleDailoc($registro, 'COINCIDE_CON_DIFERENCIAS', null, [
                    'importe_item' => [
                        'valor_interpretado' => $registro['importe_item'],
                        'valor_postgresql' => $candidatos->pluck('total')->implode(', '),
                        'regla_vfp' => 'Dailoc TOTAL tercera columna contra item Pago Imptos del mes s/detalle.',
                    ],
                ]);
                continue;
            }

            $resumen['no_encontradas']++;
            $resumen['detalles'][] = $this->detalleDailoc($registro, 'NO_ENCONTRADO_EN_POSTGRESQL', null, [
                'importe_item' => [
                    'valor_interpretado' => $registro['importe_item'],
                    'valor_postgresql' => null,
                    'regla_vfp' => 'Dailoc TOTAL tercera columna contra item Pago Imptos del mes s/detalle.',
                ],
            ]);
        }

        return $resumen;
    }

    /**
     * @return array<string, string>
     */
    private function archivosPliqloc(): array
    {
        $baseImportador = rtrim((string) config('gei.importador.base_dir'), '/');
        $entrada = $baseImportador.'/entrada/liquidaciones';
        if (is_dir($entrada)) {
            return [
                'SF' => $entrada.'/pliqloc.sf.txt',
                'ST' => $entrada.'/pliqloc.st.txt',
            ];
        }

        $periodos = storage_path('app/private/liquidaciones/periodos');
        $ultimo = collect(glob($periodos.'/*', GLOB_ONLYDIR) ?: [])->sort()->last();

        return [
            'SF' => ($ultimo ?: $periodos).'/pliqloc.sf.txt',
            'ST' => ($ultimo ?: $periodos).'/pliqloc.st.txt',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function archivosListados(): array
    {
        $baseImportador = rtrim((string) config('gei.importador.base_dir'), '/');
        $entrada = $baseImportador.'/entrada/liquidaciones';
        if (is_dir($entrada)) {
            return [
                'liquida.sf.txt' => $entrada.'/liquida.sf.txt',
                'liquidb.sf.txt' => $entrada.'/liquidb.sf.txt',
                'liquida.st.txt' => $entrada.'/liquida.st.txt',
                'liquidb.st.txt' => $entrada.'/liquidb.st.txt',
            ];
        }

        $periodos = storage_path('app/private/liquidaciones/periodos');
        $ultimo = collect(glob($periodos.'/*', GLOB_ONLYDIR) ?: [])->sort()->last();
        $base = $ultimo ?: $periodos;

        return [
            'liquida.sf.txt' => $base.'/liquida.sf.txt',
            'liquidb.sf.txt' => $base.'/liquidb.sf.txt',
            'liquida.st.txt' => $base.'/liquida.st.txt',
            'liquidb.st.txt' => $base.'/liquidb.st.txt',
        ];
    }

    private function archivoLiquidacion(string $nombre): ?string
    {
        $baseImportador = rtrim((string) config('gei.importador.base_dir'), '/');
        $entrada = $baseImportador.'/entrada/liquidaciones';
        if (is_file($entrada.'/'.$nombre)) {
            return $entrada.'/'.$nombre;
        }

        $periodos = storage_path('app/private/liquidaciones/periodos');
        $ultimo = collect(glob($periodos.'/*', GLOB_ONLYDIR) ?: [])->sort()->last();
        $archivo = ($ultimo ?: $periodos).'/'.$nombre;

        return is_file($archivo) ? $archivo : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function liquidacionesDesdeListados(): array
    {
        $liquidaciones = [];

        foreach ($this->archivosListados() as $nombre => $archivo) {
            if (! is_file($archivo)) {
                continue;
            }

            foreach ($this->gruposDePaginas($archivo) as $grupo) {
                $liquidacion = $this->parsearGrupoLiquidacion($grupo, $nombre);
                if ($liquidacion !== null) {
                    $liquidaciones[] = $liquidacion;
                }
            }
        }

        return $liquidaciones;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function gruposDePaginas(string $archivo): array
    {
        $grupos = [];
        $actual = [];
        $claveActual = null;

        foreach ($this->paginasListado($archivo) as $pagina) {
            $texto = implode("\n", $pagina);
            if (! preg_match('/\b[12]202\/\d{5}\/\d{2}\b/', $texto, $cuenta)) {
                continue;
            }

            $posicionCuenta = 0;
            foreach ($pagina as $indice => $linea) {
                if (str_contains($linea, $cuenta[0])) {
                    $posicionCuenta = $indice;
                    break;
                }
            }

            $cola = implode(' ', array_slice($pagina, $posicionCuenta, 3));
            preg_match_all('/\b\d{6}\b/', $cola, $numeros);
            $comprobante = $numeros[0][0] ?? '';
            $clave = $cuenta[0].'|'.$comprobante;

            if ($actual !== [] && $clave !== $claveActual) {
                $grupos[] = $actual;
                $actual = [];
            }

            $actual = array_merge($actual, $pagina);
            $claveActual = $clave;
        }

        if ($actual !== []) {
            $grupos[] = $actual;
        }

        return $grupos;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function paginasListado(string $archivo): array
    {
        $texto = $this->limpiarControl($this->leerCp1252($archivo));
        $texto = str_replace("\r", '', $texto);
        $paginas = [];

        foreach (explode("\f", $texto) as $bloque) {
            $lineas = array_map(fn (string $linea): string => $this->mitad($linea), explode("\n", $bloque));
            $textoPagina = implode("\n", $lineas);
            if (preg_match('/\d{2}\/\d{2}\/20\d{2}/', $textoPagina) && preg_match('/\b[12]202\/\d{5}\/\d{2}\b/', $textoPagina)) {
                $paginas[] = $lineas;
            }
        }

        return $paginas;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailocDesdeArchivo(string $nombre): array
    {
        $archivo = $this->archivoLiquidacion($nombre);
        if ($archivo === null) {
            return [];
        }

        $registros = [];
        $texto = str_replace("\r", '', $this->limpiarControl($this->leerCp1252($archivo)));
        foreach (explode("\f", $texto) as $paginaIndice => $bloque) {
            $lineas = array_map(fn (string $linea): string => $this->mitad($linea), explode("\n", $bloque));
            $textoPagina = implode("\n", $lineas);
            if (! preg_match('/\b([12]202)(\d{5})\/(\d{2})\b/', $textoPagina, $cuenta)) {
                continue;
            }

            foreach ($lineas as $lineaNumero => $linea) {
                if (! preg_match('/TOTAL[^:]*:\s*([0-9.]+,\d{2})\s+([0-9.]+,\d{2})\s+([0-9.]+,\d{2})/', $linea, $totales)) {
                    continue;
                }

                $registros[] = [
                    'archivo' => $nombre,
                    'linea' => $lineaNumero + 1,
                    'pagina' => $paginaIndice + 1,
                    'cuenta_formateada' => "{$cuenta[1]}/{$cuenta[2]}/{$cuenta[3]}",
                    'nro_cuenta' => (int) ($cuenta[1].$cuenta[2].$cuenta[3]),
                    'total_detalle' => $this->formatearDecimal($this->decimalArgentino($totales[1])),
                    'comision' => $this->formatearDecimal($this->decimalArgentino($totales[2])),
                    'importe_item' => $this->formatearDecimal($this->decimalArgentino($totales[3])),
                ];
            }
        }

        return $registros;
    }

    private function leerCp1252(string $archivo): string
    {
        $bytes = file_get_contents($archivo);
        if ($bytes === false) {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }

        return utf8_encode($bytes);
    }

    private function limpiarControl(string $texto): string
    {
        return preg_replace('/[\x00-\x08\x0b\x0e-\x1f\x7f]/', '', $texto) ?? '';
    }

    private function mitad(string $linea): string
    {
        $n = strlen($linea);
        if ($n < 70) {
            return rtrim($linea);
        }

        $centro = intdiv($n, 2);
        for ($corte = max(1, $centro - 8); $corte < min($n, $centro + 9); $corte++) {
            $izquierda = rtrim(substr($linea, 0, $corte));
            $derecha = trim(substr($linea, $corte));
            if (trim($izquierda) !== '' && trim($izquierda) === $derecha) {
                return $izquierda;
            }
        }

        for ($corte = max(1, $centro - 12); $corte < min($n, $centro + 13); $corte++) {
            if (substr($linea, $corte, 4) !== '    ') {
                continue;
            }

            $izquierda = rtrim(substr($linea, 0, $corte));
            $derecha = trim(substr($linea, $corte));
            $prefijo = substr($izquierda, 0, min(25, strlen($izquierda)));
            if ($izquierda !== '' && $derecha !== '' && (str_starts_with($derecha, $prefijo) || str_starts_with($izquierda, substr($derecha, 0, min(25, strlen($derecha)))))) {
                return $izquierda;
            }
        }

        return rtrim($linea);
    }

    /**
     * @param array<int, string> $lineas
     * @return array<string, mixed>|null
     */
    private function parsearGrupoLiquidacion(array $lineas, string $origen): ?array
    {
        $texto = implode("\n", $lineas);
        preg_match_all('/\b\d{2}\/\d{2}\/20\d{2}\b/', $texto, $fechas);
        preg_match('/\b([12]202\/\d{5}\/\d{2})\b/', $texto, $cuenta);
        if (($cuenta[1] ?? '') === '') {
            return null;
        }

        $indiceCuenta = 0;
        foreach ($lineas as $indice => $linea) {
            if (str_contains($linea, $cuenta[1])) {
                $indiceCuenta = $indice;
                break;
            }
        }

        $cola = implode(' ', array_slice($lineas, $indiceCuenta, 3));
        preg_match_all('/\b\d{6}\b/', $cola, $numeros);
        $comprobante = $numeros[0][0] ?? '';
        if ($comprobante === '') {
            return null;
        }

        $items = $this->parsearItemsListado($lineas, $fechas[0][0] ?? '');

        return [
            'origen' => $origen,
            'sede' => str_contains(strtolower($origen), '.st.') ? 'ST' : 'SF',
            'tipo' => str_starts_with(strtolower($origen), 'liquidb') ? 'B' : 'A',
            'fecha' => $fechas[0][0] ?? '',
            'cuenta' => $cuenta[1],
            'nro_cuenta' => (int) str_replace('/', '', $cuenta[1]),
            'comprobante' => (int) $comprobante,
            'items' => $items,
        ];
    }

    /**
     * @param array<int, string> $lineas
     * @return array<int, array<string, mixed>>
     */
    private function parsearItemsListado(array $lineas, string $fechaLiquidacion): array
    {
        $items = [];

        foreach ($lineas as $linea) {
            $padded = str_pad($linea, 114);
            if (preg_match('/Transporte|Total demas co-propietarios|^\s*PESOS|TOTAL/i', $linea)) {
                continue;
            }

            $debe = $this->decimalArgentino(substr($padded, 78, 18));
            $haber = $this->decimalArgentino(substr($padded, 96, 18));
            if (abs($debe) < 0.005 && abs($haber) < 0.005) {
                continue;
            }

            $izquierda = substr($padded, 0, 78);
            if (! preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]/u', $izquierda) && ! preg_match('/\b\d{2}\/\d{2}\/\d{2}\b/', $izquierda)) {
                continue;
            }

            $referenciaPersona = trim(substr($padded, 0, 37));
            $concepto = trim(substr($padded, 37, 41));
            $vencimiento = '';
            if (preg_match('/\b\d{2}\/\d{2}\/\d{2}\b/', $concepto, $match, PREG_OFFSET_CAPTURE)) {
                $vencimiento = $match[0][0];
                $concepto = trim(substr($concepto, 0, $match[0][1]).substr($concepto, $match[0][1] + strlen($match[0][0])));
            }

            $items[] = [
                'orden' => count($items) + 1,
                'nombre' => $referenciaPersona,
                'detalle' => $concepto,
                'vencimiento' => $vencimiento,
                'debe' => $this->formatearDecimal($debe),
                'haber' => $this->formatearDecimal($haber),
                'referencia' => '',
                'numero_detalle' => null,
                'fecha' => null,
            ];
        }

        $referencias = [];
        foreach ($lineas as $linea) {
            if (preg_match_all('/(?<!\d)(\d{6})\s+(\d{2}\/\d{2})(?!\/)/', $linea, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $referencias[] = [$match[1], $match[2]];
                }
            }
        }

        $anio = preg_match('/\/(20\d{2})$/', $fechaLiquidacion, $anioMatch) ? $anioMatch[1] : null;
        foreach ($items as $indice => &$item) {
            if (! isset($referencias[$indice])) {
                continue;
            }

            [$numero, $fecha] = $referencias[$indice];
            $item['numero_detalle'] = (int) $numero;
            $item['referencia'] = $anio ? "{$numero} - {$fecha}/{$anio}" : "{$numero} - {$fecha}";
            $item['fecha'] = $anio ? CarbonImmutable::createFromFormat('d/m/Y', "{$fecha}/{$anio}")->format('Y-m-d') : null;
        }
        unset($item);

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsearLineaPliqloc(string $linea, string $sede, string $archivo, int $lineaNumero): ?array
    {
        $patronInicio = '/^(\d{2}\/\d{2}\/\d{4})\s+([AB])\s+(\d{8})\s+(\d{4}\/\d{5}\/\d{2})\s+(.*)$/';
        $patronFinal = '/^(.*)\s+(\d{11})\s+([0-9.]+,\d{2})(DB)?\s*$/';
        if (! preg_match($patronInicio, $linea, $inicio)) {
            return null;
        }

        if (! preg_match($patronFinal, $inicio[5], $final)) {
            return null;
        }

        $total = $this->decimalArgentino($final[3]);
        if (($final[4] ?? '') === 'DB') {
            $total *= -1;
        }

        return [
            'sede' => $sede,
            'archivo' => basename($archivo),
            'linea' => $lineaNumero,
            'fecha' => CarbonImmutable::createFromFormat('d/m/Y', $inicio[1])->format('Y-m-d'),
            'tipo_comprobante' => $inicio[2],
            'numero_de_comprobante' => (int) $inicio[3],
            'cuenta_formateada' => $inicio[4],
            'nro_cuenta' => (int) str_replace('/', '', $inicio[4]),
            'propietario' => trim($final[1]),
            'condicion_iva' => '',
            'cuit' => $final[2],
            'total' => $total,
        ];
    }

    private function decimalArgentino(string $valor): float
    {
        return (float) str_replace(',', '.', str_replace('.', '', $valor));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function compararCabecera(array $cabecera, object $liquidacion): array
    {
        $diferencias = [];
        $totalPostgresql = $this->totalPostgresqlMasCercano($cabecera, $liquidacion);

        $comparaciones = [
            'fecha' => [$cabecera['fecha'], (string) $liquidacion->fecha],
            'nro_cuenta' => [(string) $cabecera['nro_cuenta'], (string) $liquidacion->nro_cuenta],
            'numero_de_comprobante' => [(string) $cabecera['numero_de_comprobante'], (string) $liquidacion->numero_de_comprobante],
            'total' => [number_format((float) $cabecera['total'], 2, '.', ''), number_format((float) $totalPostgresql, 2, '.', '')],
        ];

        foreach ($comparaciones as $campo => [$interpretado, $postgresql]) {
            if ($campo === 'total' && abs((float) $interpretado - (float) $postgresql) <= 0.05) {
                continue;
            }

            if ($interpretado !== $postgresql) {
                $diferencias[$campo] = [
                    'valor_interpretado' => $interpretado,
                    'valor_postgresql' => $postgresql,
                    'regla_vfp' => 'Planilla pliqloc: comprobante impreso contra numero_de_comprobante; importe contra total/subtotal/total_liquidado mas cercano; tolerancia de redondeo 0,05.',
                ];
            }
        }

        return $diferencias;
    }

    private function totalPostgresqlMasCercano(array $cabecera, object $liquidacion): string
    {
        $interpretado = (float) $cabecera['total'];
        $candidatos = [
            'total' => (float) $liquidacion->total,
            'subtotal' => (float) $liquidacion->subtotal,
            'total_liquidado' => (float) $liquidacion->total_liquidado,
        ];

        $mejorValor = (float) $liquidacion->total;
        $mejorDiferencia = INF;

        foreach ($candidatos as $valor) {
            $diferencia = abs($interpretado - $valor);
            if ($diferencia < $mejorDiferencia) {
                $mejorDiferencia = $diferencia;
                $mejorValor = $valor;
            }
        }

        return number_format($mejorValor, 2, '.', '');
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $itemsPostgresql
     */
    private function resolverItemPostgresql($itemsPostgresql, array $item, int $orden): ?object
    {
        if ($item['numero_detalle'] !== null) {
            $porDetalle = $itemsPostgresql
                ->where('numero_detalle', $item['numero_detalle'])
                ->values();
            if ($porDetalle->count() === 1) {
                return $porDetalle->first();
            }

            return null;
        }

        return $itemsPostgresql->get($orden);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function compararItem(array $item, object $itemPostgresql, int $orden): array
    {
        $diferencias = [];
        $montoInterpretado = (float) $item['debe'] !== 0.0 ? (float) $item['debe'] : (float) $item['haber'];
        $montoBasePostgresql = (float) $itemPostgresql->neto_no_gravado
            + (float) $itemPostgresql->neto_gravado_21
            + (float) $itemPostgresql->neto_gravado_105
            + (float) $itemPostgresql->neto_gravado_27;
        $montoPostgresql = $this->montoItemPostgresqlMasCercano($montoInterpretado, $montoBasePostgresql, (float) $itemPostgresql->total);

        $comparaciones = [
            'orden' => [(string) ($orden + 1), (string) ($orden + 1)],
            'numero_detalle' => [(string) ($item['numero_detalle'] ?? ''), (string) ((int) $itemPostgresql->numero_detalle ?: '')],
            'fecha' => [(string) ($item['fecha'] ?? ''), ((string) $itemPostgresql->fecha === '1900-01-01') ? '' : (string) $itemPostgresql->fecha],
            'detalle' => [$this->normalizarTextoComparacion((string) $item['detalle']), $this->normalizarTextoComparacion((string) $itemPostgresql->detalle)],
            'monto_base' => [number_format($montoInterpretado, 2, '.', ''), number_format($montoPostgresql, 2, '.', '')],
            'tipo' => [$this->normalizarTextoComparacion((float) $item['haber'] !== 0.0 ? 'Crédito' : 'Débito'), $this->normalizarTextoComparacion((string) $itemPostgresql->tipo)],
        ];

        foreach ($comparaciones as $campo => [$interpretado, $postgresql]) {
            if ($campo === 'monto_base' && abs((float) $interpretado - (float) $postgresql) <= 0.05) {
                continue;
            }

            if ($campo === 'detalle' && ($interpretado === '' || str_contains($postgresql, $interpretado) || str_contains($interpretado, $postgresql))) {
                continue;
            }

            if ($interpretado !== $postgresql) {
                $diferencias[$campo] = [
                    'valor_interpretado' => $interpretado,
                    'valor_postgresql' => $postgresql,
                    'regla_vfp' => 'Item reconstruido desde columnas COBOL del listado; monto base comparado contra netos PostgreSQL, IVA separado.',
                ];
            }
        }

        return $diferencias;
    }

    private function montoItemPostgresqlMasCercano(float $interpretado, float $base, float $total): float
    {
        return abs($interpretado - $total) < abs($interpretado - $base) ? $total : $base;
    }

    private function normalizarTextoComparacion(string $texto): string
    {
        $texto = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $texto) ?? ''));

        return str_replace([' + IVA', '/'], ['', ''], $texto);
    }

    private function formatearDecimal(float $valor): string
    {
        return number_format($valor, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function detalleCabecera(array $cabecera, string $estado, ?string $clavePostgresql, array $diferencias): array
    {
        return [
            'archivo' => $cabecera['archivo'],
            'linea' => $cabecera['linea'],
            'clave_interpretada' => $cabecera['tipo_comprobante'].' '.$cabecera['numero_de_comprobante'],
            'clave_postgresql' => $clavePostgresql,
            'estado' => $estado,
            'diferencias' => $diferencias,
            'cabecera' => $cabecera,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detalleItem(array $liquidacion, array $item, string $estado, ?string $clavePostgresql, array $diferencias): array
    {
        return [
            'archivo' => $liquidacion['origen'],
            'linea' => $item['orden'],
            'clave_interpretada' => $liquidacion['comprobante'].'#'.$item['orden'],
            'clave_postgresql' => $clavePostgresql,
            'estado' => $estado,
            'diferencias' => $diferencias,
            'item' => [
                'liquidacion' => [
                    'sede' => $liquidacion['sede'],
                    'tipo' => $liquidacion['tipo'],
                    'cuenta' => $liquidacion['cuenta'],
                    'comprobante' => $liquidacion['comprobante'],
                ],
                'item' => $item,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detalleDailoc(array $registro, string $estado, ?string $clavePostgresql, array $diferencias): array
    {
        return [
            'archivo' => $registro['archivo'],
            'linea' => $registro['linea'],
            'clave_interpretada' => $registro['cuenta_formateada'].'#'.$registro['pagina'],
            'clave_postgresql' => $clavePostgresql,
            'estado' => $estado,
            'diferencias' => $diferencias,
            'registro' => $registro,
        ];
    }
}
