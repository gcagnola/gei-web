<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Portal\PortalCuentaCorrienteService;
use App\Support\Cobol\Gimb38InteresDiagnostico;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Una unica consulta/presentacion para Administracion y Autogestion.
 * Los controladores autorizan la cuenta ANTES de llamar a este servicio.
 * Nunca escribe movimientos ni determina pagos: lee la ultima fotografia PUBLICADA.
 */
final class CuentaCorrienteLccinqCompartidaService
{
    public function __construct(private readonly PortalCuentaCorrienteService $cuentas)
    {
    }

    public function consultar(string $cuenta, string $rol, ?string $fecha = null, bool $soloDiagnostico = false): array
    {
        $snapshot = $this->cuentas->consultar($cuenta, $rol);

        return $this->preparar($snapshot, $cuenta, $rol, $fecha, $soloDiagnostico);
    }

    /**
     * $snapshot procede de la misma consulta operativa que usa Autogestion.
     * Metodo publico para pruebas sin consultar PostgreSQL.
     */
    public function preparar(array $snapshot, string $cuenta, string $rol, ?string $fecha = null, bool $soloDiagnostico = false): array
    {
        if (! preg_match('/^[0-9]{11}$/D', $cuenta)
            || ! in_array($rol, ['INQUILINO', 'PROPIETARIO'], true)) {
            throw new RuntimeException('Cuenta o rol no valido.');
        }
        $cierre = (string) ($snapshot['cierre'] ?? '');
        $fecha ??= CarbonImmutable::now('America/Argentina/Cordoba')->toDateString();
        if (! preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $fecha)
            || ! checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
            throw new RuntimeException('Fecha de consulta invalida.');
        }
        if ($cierre !== '' && $fecha < $cierre) {
            throw new RuntimeException('No se puede reconstruir una deuda anterior al ultimo cierre publicado.');
        }
        $esInquilino = $rol === 'INQUILINO';
        $calculador = null;
        $parametrosDisponibles = false;
        $tipoIva = null;
        $nombre = null;
        $domicilio = null;
        if ($esInquilino && $cierre !== '') {
            // Mismo maestro canonico actualizado por la importacion nocturna;
            // no buscar personas por CUIT ni por coincidencia aproximada.
            $origen = DB::table('clientes_origenes')
                ->where('sistema_origen', 'COBOL')
                ->where('entidad_origen', 'INQUILINO')
                ->where('clave_origen', $cuenta)
                ->value('datos_origen');
            $datos = is_string($origen) ? json_decode($origen, true) : (array) $origen;
            $nombre = trim((string) ($datos['nombre'] ?? '')) ?: null;
            $domicilio = DB::table('inmuebles_origenes')
                ->where('sistema_origen', 'COBOL')
                ->where('entidad_origen', 'INQUILINO')
                ->where('clave_origen', $cuenta)
                ->value('direccion_finca');
        }
        if ($esInquilino && $cierre !== '' && ($soloDiagnostico || (bool) config('gei_cuenta_corriente.gimb38_validado', false))) {
            // Unicamente parametros versionados para el mismo cierre publicado;
            // nunca parametros historicos de PRUEBA ni la base fija 202609.
            $ruta = storage_path('app/private/cobol/parametros_cierre/'.$cierre);
            if (is_file($ruta.'/INQTASAINT') && is_file($ruta.'/INQIVA')
                && is_file($ruta.'/INQREDONDE') && is_file($ruta.'/.parametros_ok')) {
                try {
                    $shaPublicado = DB::table('cobol_publicaciones')
                        ->where('fecha_cierre', $cierre)
                        ->where('estado', 'PUBLICADO')->orderByDesc('id')
                        ->value('sha256_exportacion');
                    $shaParametros = trim((string) file_get_contents($ruta.'/.parametros_ok'));
                    if (! is_string($shaPublicado) || ! hash_equals($shaPublicado, $shaParametros)) {
                        throw new RuntimeException('Parametros de una exportacion diferente.');
                    }
                    $calculador = Gimb38InteresDiagnostico::desdeDirectorio($ruta);
                    $tipoIva = $this->tipoIvaOperativo($cuenta);
                    $parametrosDisponibles = $tipoIva !== null;
                } catch (Throwable) {
                    // Nunca simular tasas ni fechas cuando no estan disponibles.
                    $calculador = null;
                }
            }
        }

        $debitos = [];
        $creditos = [];
        $totalDebitos = $totalCreditos = $totalIntereses = 0;
        $interesesCompletos = $esInquilino && $parametrosDisponibles;
        foreach (($snapshot['movimientos'] ?? []) as $m) {
            if (! is_array($m) || ! ($m['afecta_saldo'] ?? false)) {
                continue;
            }
            $importe = self::centavos($m['importe'] ?? '0.00');
            $abonado = self::centavos($m['importe_abonado'] ?? '0.00');
            $pendiente = $importe - $abonado;
            if ($pendiente < 0) {
                throw new RuntimeException('Movimiento con abonado mayor al importe original.');
            }
            if ($pendiente === 0) {
                continue;
            }
            $tipo = (string) ($m['tipo_movimiento'] ?? '');
            if (! in_array($tipo, ['DEBITO', 'CREDITO'], true)) {
                continue;
            }
            $vence = (string) ($m['fecha_vencimiento'] ?? '');
            $dias = self::dias360($vence, $fecha);
            $fila = [
                'codigo' => (string) ($m['codigo'] ?? ''),
                'numero' => (string) ($m['numero'] ?? ''),
                'detalle' => trim((string) ($m['descripcion'] ?? '')),
                'fecha' => (string) ($m['fecha'] ?? ''),
                'vence' => $vence,
                'importe' => $pendiente,
                'dias' => $dias,
                'tasa' => null,
                'interes' => null,
                'interes_base' => null,
                'iva_interes' => null,
            ];
            if ($tipo === 'CREDITO') {
                $totalCreditos += $pendiente;
                $fila['dias'] = null;
                $creditos[] = $fila;
                continue;
            }
            $totalDebitos += $pendiente;
            if ($esInquilino && $dias === 0) {
                // Interes cero es correcto si todavia no vencio, independientemente de las tasas.
                $fila['interes'] = 0;
                $fila['interes_base'] = 0;
                $fila['iva_interes'] = 0;
            } elseif ($esInquilino && $calculador !== null && $tipoIva !== null && $dias !== null) {
                try {
                    $r = $calculador->calcular($pendiente, $vence, $fecha, $tipoIva);
                    $fila['dias'] = (int) $r['dias'];
                    $fila['interes_base'] = (int) $r['interes'];
                    $fila['iva_interes'] = (int) $r['iva'] + (int) $r['sobretasa'];
                    $fila['interes'] = (int) $r['adicional'];
                    $milesimas = array_unique(array_column($r['tramos'], 'tasa_milesimas'));
                    if (count($milesimas) === 1) {
                        $fila['tasa'] = number_format(((int) reset($milesimas)) / 1000, 1, ',', '.');
                    } elseif (count($milesimas) > 1) {
                        $fila['tasa'] = 'variable';
                    }
                } catch (Throwable) {
                    $interesesCompletos = false;
                }
            } elseif ($esInquilino) {
                $interesesCompletos = false;
            }
            if ($fila['interes'] !== null) {
                $totalIntereses += $fila['interes'];
            }
            $debitos[] = $fila;
        }

        // LCCINQ/GIMB38 muestra primero los conceptos DEBITO y despues CREDITO.
        // Dentro de cada grupo la referencia visible es CODIGO -> FECHA DE OPERACION
        // -> NUMERO (NO la fecha de vencimiento, que se usa para intereses).
        // La secuencia reproduce el caso de control 11032187705 al 06/11/2026.
        $ordenar = static function (array $a, array $b): int {
            $clave = static fn (array $m): string =>
                str_pad(trim((string) ($m['codigo'] ?? '')), 2, '0', STR_PAD_LEFT).'|'
                .((string) ($m['fecha'] ?: '9999-12-31')).'|'
                .str_pad(trim((string) ($m['numero'] ?? '')), 6, '0', STR_PAD_LEFT);
            return strcmp($clave($a), $clave($b));
        };
        usort($debitos, $ordenar);
        usort($creditos, $ordenar);

        return [
            'cuenta' => $cuenta,
            'rol' => $rol,
            'nombre' => $nombre,
            'domicilio' => $domicilio,
            'sede' => (string) ($snapshot['sede'] ?? ''),
            'cierre' => $cierre,
            'fecha_calculo' => $fecha,
            'fuente' => (string) ($snapshot['fuente'] ?? ''),
            'debitos' => $debitos,
            'creditos' => $creditos,
            'total_debitos' => $totalDebitos,
            'total_creditos' => $totalCreditos,
            'intereses_completos' => $interesesCompletos,
            'total_intereses' => $interesesCompletos ? $totalIntereses : null,
            'deuda_total' => $interesesCompletos ? $totalDebitos + $totalIntereses - $totalCreditos : null,
            'saldo_sin_intereses' => $totalDebitos - $totalCreditos,
            'tipo_iva' => $tipoIva,
            'solo_diagnostico' => $soloDiagnostico,
        ];
    }

    private function tipoIvaOperativo(string $cuenta): ?int
    {
        $json = DB::table('clientes_origenes')
            ->where('sistema_origen', 'COBOL')
            ->where('entidad_origen', 'INQUILINO')
            ->where('clave_origen', $cuenta)
            ->value('datos_origen');
        $datos = is_string($json) ? json_decode($json, true) : (array) $json;
        $valor = trim((string) ($datos['tipo_iva_origen'] ?? ''));
        return preg_match('/^[0-6]$/D', $valor) ? (int) $valor : null;
    }

    public static function centavos(mixed $valor): int
    {
        $texto = trim((string) ($valor ?? '0.00'));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $texto, $m)) {
            throw new RuntimeException('Importe no numerico en cuenta COBOL.');
        }
        $n = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$n : $n;
    }

    /** Dias de interes convencional COBOL 30/360; no inventa fechas de vencimiento. */
    public static function dias360(string $vencimiento, string $fecha): ?int
    {
        if (! preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $vencimiento)
            || ! checkdate((int) substr($vencimiento, 5, 2), (int) substr($vencimiento, 8, 2), (int) substr($vencimiento, 0, 4))) {
            return null;
        }
        $numero = static fn (string $s): int => ((int) substr($s, 0, 4)) * 360
            + ((int) substr($s, 5, 2)) * 30 + min(30, (int) substr($s, 8, 2));
        return max(0, $numero($fecha) - $numero($vencimiento));
    }
}
