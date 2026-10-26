<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Diagnóstico CLI de SOLO LECTURA, no expuesto a Autogestión.
 * Una prueba de precisión por tramos, NO una liquidación definitiva.
 * - --referencia usa valores TRANSCRIPTOS del listado COBOL; NO los obtiene de PostgreSQL.
 * - --txt utiliza TXT exportado del clon con secctactei (134 bytes por registro).
 */
final class GeiConciliarGimb38Clon extends Command
{
    protected $signature = 'gei:conciliar-gimb38-clon
        {--cuenta=11032432605 : Cuenta COBOL (11 dígitos)}
        {--fecha=2026-10-20 : Fecha de cálculo AAAA-MM-DD}
        {--referencia : Compara las 10 líneas copiadas de GIMB38 (cuenta 11032432605, 20/10/2026)}
        {--txt= : Ruta del INQCTACTE.TXT exportado del Fedora-clon, dentro de gei-app}
        {--tasas= : Ruta del INQTASAINT de ese clon, dentro de gei-app}
        {--tipo-iva=3 : TIPOIVA COBOL del inquilino; tipo 2 aplica sobretasa de 50% del IVA}';

    protected $description = 'Diagnóstico GIMB38 sin escrituras: precisión por tramos y contraste con clon';

    /** @var array<int,array{string,string,string,string}> Vencimiento, capital, interés impreso, concepto */
    private const REFERENCIA = [
        ['2026-09-07', '600000.00', '176902.00', 'ALQUILER SET. 2026'],
        ['2026-09-10', '13242.39', '3631.94', '9na Cta.2026 T.MUNICIPAL'],
        ['2026-09-10', '41104.23', '11273.52', '4to Bim.2026 AGUAS'],
        ['2026-09-10', '2752.56', '754.94', 'COM.S/REE.IMP.EXP'],
        ['2026-09-10', '1602.33', '439.47', 'COM.S/REE.IMP.EXP + IVA'],
        ['2026-09-10', '4973.61', '1364.11', 'COM.S/REE.IMP.EXP + IVA'],
        ['2026-09-10', '1148.65', '315.04', 'COM.S/REE.IMP.EXP + IVA'],
        ['2026-09-10', '226512.00', '62124.69', 'HONOR. P/INTERMEDIAC.'],
        ['2026-09-10', '22748.39', '6239.12', 'LITORAL GAS 4BIM26'],
        ['2026-09-10', '9493.00', '2603.61', 'SEGURO INCENDIO/R.C.'],
    ];

    public function handle(): int
    {
        // Bloqueo preventivo: este comando es solo para desarrollo.
        if (app()->environment('production')) {
            $this->error('No se ejecuta con APP_ENV=production.');
            return self::FAILURE;
        }
        try {
            $cuenta = (string) $this->option('cuenta');
            if (preg_match('/^[0-9]{11}$/D', $cuenta) !== 1) {
                throw new RuntimeException('La cuenta debe tener 11 dígitos.');
            }
            $fechaStr = (string) $this->option('fecha');
            $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaStr);
            if (! $fecha || $fecha->format('Y-m-d') !== $fechaStr) {
                throw new RuntimeException('Fecha inválida, usar AAAA-MM-DD.');
            }
            $tipoIva = (int) $this->option('tipo-iva');
            if ($tipoIva < 1 || $tipoIva > 6) {
                throw new RuntimeException('--tipo-iva debe estar entre 1 y 6.');
            }
            $referencia = (bool) $this->option('referencia');
            $txt = (string) ($this->option('txt') ?: '');
            if (! $referencia && $txt === '') {
                throw new RuntimeException('Indicar --referencia o --txt=/ruta/INQCTACTE.TXT');
            }
            if ($referencia && $txt !== '') {
                throw new RuntimeException('Usar --referencia o --txt, no ambas opciones.');
            }
            if ($referencia && ($cuenta !== '11032432605' || $fechaStr !== '2026-10-20')) {
                throw new RuntimeException('La referencia transcripta corresponde solo a 11032432605 y 2026-10-20.');
            }
            $tasasRuta = (string) ($this->option('tasas') ?: '');
            if (! $referencia && $tasasRuta === '') {
                throw new RuntimeException('Para TXT real del clon también se exige --tasas=INQTASAINT del MISMO clon.');
            }
            $tasas = $tasasRuta === '' ? array_fill(0, 120, 17000) : $this->leerTasas($tasasRuta);
            $filas = $referencia ? $this->filasReferencia() : $this->leerTxt($txt, $cuenta);
            $this->info('CONCILIACION GIMB38 / SOLO CONSULTA');
            $this->line('Cuenta: '.$cuenta.' | Fecha: '.$fecha->format('d/m/Y'));
            $this->warn($referencia
                ? 'FUENTE: 10 movimientos TRANSCRIPTOS DEL LISTADO COBOL. Tasa 17% tomada de lo impreso. NO se leyo PostgreSQL.'
                : 'FUENTE: TXT del clon, no PostgreSQL. Tipo IVA proporcionado: '.$tipoIva.' (verificar con maestro COBOL).');
            $this->line('Cálculo: 30/360, interés contractual por tramos, redondeo SUMA a 3 decimales, WIMPORT a centavos, IVA 21%.');
            $this->line('IVA adicional: tipo 2 agrega sobretasa de 50% del IVA. Factor de redondeo supuesto 1 (verificar en clon).');
            $this->newLine();
            $tabla = [];
            $capital = $interesTotal = $ivaTotal = $sobretasaTotal = $creditos = $errores = 0;
            $debitos = 0;
            foreach ($filas as $fila) {
                $pendiente = $fila['capital_centavos'];
                if ($pendiente === 0) {
                    continue;
                }
                if (! $fila['debito']) {
                    $creditos += $pendiente;
                    continue;
                }
                $debitos++;
                $calc = $this->calcularInteres($pendiente, $fila['vencimiento'], $fecha, $tasas, $tipoIva);
                $capital += $pendiente;
                $interesTotal += $calc['interes'];
                $ivaTotal += $calc['iva'];
                $sobretasaTotal += $calc['sobretasa'];
                $totalInt = $calc['interes'] + $calc['iva'] + $calc['sobretasa'];
                $diferencia = null;
                if ($fila['esperado'] !== null) {
                    $diferencia = $totalInt - $fila['esperado'];
                    if ($diferencia !== 0) {
                        $errores++;
                    }
                }
                $tabla[] = [
                    $fila['concepto'],
                    $fila['vencimiento']?->format('d/m/Y') ?? '-',
                    self::pesos($pendiente),
                    (string) $calc['dias'],
                    $calc['tasas'],
                    self::pesos($calc['interes']),
                    self::pesos($calc['iva'] + $calc['sobretasa']),
                    self::pesos($totalInt),
                    $diferencia === null ? '-' : self::pesos($diferencia),
                ];
            }
            $this->table(['Concepto','Vence','Capital','Días','Tasas','Int.base','IVA/rec.','Int.total','Dif.COBOL'], $tabla);
            $this->line('Débitos analizados: '.$debitos.' | Capital pendiente: '.self::pesos($capital));
            $this->line('Interés base: '.self::pesos($interesTotal).' | IVA: '.self::pesos($ivaTotal)
                .' | Sobretasa: '.self::pesos($sobretasaTotal));
            $this->line('Créditos pendientes (sin intereses): '.self::pesos($creditos));
            $this->info('SUBTOTAL DÉBITOS + INTERESES: '.self::pesos($capital + $interesTotal + $ivaTotal + $sobretasaTotal));
            $this->line('TOTAL NETO PROVISORIO: '.self::pesos($capital + $interesTotal + $ivaTotal + $sobretasaTotal - $creditos));
            if ($referencia) {
                $ok = $debitos === 10 && $capital === 92357716
                    && $interesTotal + $ivaTotal + $sobretasaTotal === 26564844
                    && $errores === 0;
                $this->{$ok ? 'info' : 'error'}($ok
                    ? 'REFERENCIA_COBOL_OK | 10/10 intereses exactos | capital=923577,16 | intereses=265648,44'
                    : 'REFERENCIA_COBOL_DIFIERE | revisar diferencias línea por línea');
                return $ok ? self::SUCCESS : self::FAILURE;
            }
            $this->warn('RESULTADO DE PRUEBA: sin validar contra la totalidad de la impresión COBOL ni el snapshot del clon.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('CONCILIACION_ABORTADA: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    /** @return list<array{capital_centavos:int,vencimiento:?DateTimeImmutable,debito:bool,concepto:string,esperado:?int}> */
    private function filasReferencia(): array
    {
        $filas = [];
        foreach (self::REFERENCIA as [$vto, $capital, $esperado, $concepto]) {
            $filas[] = [
                'capital_centavos' => self::decimalACentavos($capital),
                'vencimiento' => new DateTimeImmutable($vto),
                'debito' => true,
                'concepto' => $concepto,
                'esperado' => self::decimalACentavos($esperado),
            ];
        }
        return $filas;
    }

    /** @return list<array{capital_centavos:int,vencimiento:?DateTimeImmutable,debito:bool,concepto:string,esperado:?int}> */
    private function leerTxt(string $ruta, string $cuenta): array
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new RuntimeException('No se puede leer el TXT exportado: '.$ruta);
        }
        $f = fopen($ruta, 'rb');
        if (! $f) {
            throw new RuntimeException('No se puede abrir el TXT.');
        }
        $filas = [];
        $n = 0;
        try {
            while (($linea = fgets($f)) !== false) {
                $n++;
                $raw = rtrim($linea, "\r\n");
                if (strlen($raw) !== 134) {
                    throw new RuntimeException("Ancho inesperado en línea {$n} de INQCTACTE.TXT: ".strlen($raw));
                }
                if (substr($raw, 0, 11) !== $cuenta) {
                    continue;
                }
                $importe = self::cobolACentavos(substr($raw, 35, 12));
                $abonado = self::cobolACentavos(substr($raw, 59, 12));
                $pendiente = $importe - $abonado;
                if ($pendiente === 0) {
                    continue;
                }
                $fechaVto = substr($raw, 27, 8);
                $vto = null;
                if ($fechaVto !== '00000000') {
                    $vto = DateTimeImmutable::createFromFormat('!Ymd', $fechaVto);
                    if (!$vto || $vto->format('Ymd') !== $fechaVto) {
                        throw new RuntimeException("Vencimiento inválido para cuenta {$cuenta}, línea {$n}.");
                    }
                }
                $filas[] = [
                    'capital_centavos' => $pendiente,
                    'vencimiento' => $vto,
                    'debito' => (int) substr($raw, 19, 2) < 51,
                    'concepto' => trim(substr($raw, 71, 29)).' ['.substr($raw, 19, 2).']',
                    'esperado' => null,
                ];
                if (count($filas) > 210) {
                    throw new RuntimeException('Más de 210 movimientos pendientes: requiere tratamiento especial de GIMB38.');
                }
            }
        } finally {
            fclose($f);
        }
        if ($filas === []) {
            throw new RuntimeException('No hay movimientos pendientes para '.$cuenta.' en el TXT.');
        }
        return $filas;
    }

    private static function decimalACentavos(string $valor): int
    {
        if (! preg_match('/^([0-9]+)\.([0-9]{2})$/D', $valor, $m)) {
            throw new RuntimeException('Decimal inválido: '.$valor);
        }
        return (int) $m[1] * 100 + (int) $m[2];
    }

    private static function cobolACentavos(string $valor): int
    {
        if (preg_match('/^([0-9]{11})([+-])$/D', $valor, $m) !== 1) {
            throw new RuntimeException('Importe COBOL inválido: '.$valor);
        }
        return (int) $m[1] * ($m[2] === '-' ? -1 : 1);
    }

    /** @return list<int> Tasas de cláusula contractual en milésimas de porcentaje. */
    private function leerTasas(string $ruta): array
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new RuntimeException('No existe INQTASAINT: '.$ruta);
        }
        $lineas = file($ruta, FILE_IGNORE_NEW_LINES);
        if ($lineas === false || count($lineas) !== 120) {
            throw new RuntimeException('INQTASAINT debe tener 120 líneas mensuales.');
        }
        $tasas = [];
        foreach ($lineas as $pos => $linea) {
            if (strlen($linea) !== 41 || !preg_match('/^[0-9]{6}([0-9]{6}[+-]){5}$/D', $linea)) {
                throw new RuntimeException('Formato de INQTASAINT inválido en posición '.($pos + 1));
            }
            $tasaCampo = substr($linea, 6, 7); // Primera cláusula contractual.
            if (substr($tasaCampo, 6, 1) === '-') {
                throw new RuntimeException('Tasa negativa no admitida para esta prueba.');
            }
            $tasas[] = (int) substr($tasaCampo, 0, 6);
        }
        return $tasas;
    }

    /** @return array{interes:int,iva:int,sobretasa:int,dias:int,tasas:string} */
    private function calcularInteres(int $capital, ?DateTimeImmutable $vto, DateTimeImmutable $fecha, array $tasas, int $tipoIva): array
    {
        $cero = ['interes' => 0, 'iva' => 0, 'sobretasa' => 0, 'dias' => 0, 'tasas' => '-'];
        if ($vto === null || $capital === 0) {
            return $cero;
        }
        $tramos = self::tramos30_360($vto, $fecha);
        if ($tramos === []) {
            return $cero;
        }
        if (count($tramos) > count($tasas)) {
            throw new RuntimeException('La deuda supera la ventana de 120 tasas.');
        }
        $interes = 0;
        $etiquetas = [];
        $cantidad = count($tramos);
        foreach ($tramos as $i => $dias) {
            // La posición 1 de tasas es la más reciente; GIMB38 invierte según TOPE/IND1.
            $tasaMili = $tasas[$cantidad - 1 - $i];
            $sumaMilesimasDePeso = self::redondear($capital * $tasaMili * $dias, 300000);
            // SUMA PIC ...V999; WIMPORT PIC ...V99, con redondeo en CADA tramo.
            $interes += self::redondear($sumaMilesimasDePeso, 10);
            $etiquetas[] = number_format($tasaMili / 1000, 1, ',', '').'%';
        }
        $iva = self::redondear($interes * 2100, 10000);
        $sobretasa = $tipoIva === 2 ? self::redondear($iva * 5000, 10000) : 0;
        return [
            'interes' => $interes,
            'iva' => $iva,
            'sobretasa' => $sobretasa,
            'dias' => array_sum($tramos),
            'tasas' => implode('/', $etiquetas),
        ];
    }

    /** @return list<int> Días por mes de mora, en orden antiguo a reciente. */
    private static function tramos30_360(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $dY = (int) $desde->format('Y');
        $dM = (int) $desde->format('m');
        $dD = min(30, (int) $desde->format('d'));
        $hY = (int) $hasta->format('Y');
        $hM = (int) $hasta->format('m');
        $hD = min(30, (int) $hasta->format('d'));
        $dias = ($hY * 360 + $hM * 30 + $hD) - ($dY * 360 + $dM * 30 + $dD);
        if ($dias <= 0) {
            return [];
        }
        if ($dY === $hY && $dM === $hM) {
            return [$dias];
        }
        $tramos = [30 - $dD];
        $restante = $dias - $tramos[0];
        while ($restante > 30) {
            $tramos[] = 30;
            $restante -= 30;
        }
        if ($restante > 0) {
            $tramos[] = $restante;
        }
        return $tramos;
    }

    private static function redondear(int $num, int $den): int
    {
        $positivo = intdiv(abs($num) + intdiv($den, 2), $den);
        return $num < 0 ? -$positivo : $positivo;
    }

    private static function pesos(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $v = abs($centavos);
        return $signo.number_format(intdiv($v, 100), 0, ',', '.').','.str_pad((string) ($v % 100), 2, '0', STR_PAD_LEFT);
    }
}
