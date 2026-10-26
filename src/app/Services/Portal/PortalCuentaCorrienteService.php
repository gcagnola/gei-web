<?php

namespace App\Services\Portal;

use App\Services\MovimientoCuentaCobolNormalizer;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Consulta de solo lectura: último período histórico + overlay PUBLICADO.
 * IMPORTANTE: no utiliza overlays PRUEBA ni modifica el ledger operativo.
 */
final class PortalCuentaCorrienteService
{
    private const MAX_REGISTROS_CUENTA = 10000;

    public function __construct(private readonly MovimientoCuentaCobolNormalizer $normalizador)
    {
    }

    /**
     * Diagnóstico de cierre PRUEBA: solo consola de desarrollo/testing.
     * La ruta web continúa llamando exclusivamente a consultar().
     * NO PUBLICAR hasta conciliar movimientos, pagos, saldos e intereses.
     *
     * @return array{movimientos:array<int,array<string,mixed>>, periodo_base:string, cierre:?string, fuente:string, sede:string}
     */
    public function consultarEnPrueba(string $cuenta, string $rol): array
    {
        if (! app()->environment('local', 'testing')
            || (! app()->runningInConsole() && ! config('portal.cuenta_corriente.web_prueba', false))) {
            throw new RuntimeException('El cierre PRUEBA solo puede consultarse desde consola de desarrollo.');
        }

        return $this->consultarCuenta($cuenta, $rol, true);
    }

    /** @return array{movimientos:array<int,array<string,mixed>>, periodo_base:string, cierre:?string, fuente:string, sede:string} */
    public function consultar(string $cuenta, string $rol): array
    {
        // Entrada del portal web: nunca consume cierres PRUEBA.
        return $this->consultarCuenta($cuenta, $rol, false);
    }

    /** @return array{movimientos:array<int,array<string,mixed>>, periodo_base:string, cierre:?string, fuente:string, sede:string} */
    private function consultarCuenta(string $cuenta, string $rol, bool $enPrueba): array
    {
        if (! preg_match('/^[0-9]{11}$/D', $cuenta) || ! in_array($rol, ['PROPIETARIO', 'INQUILINO'], true)) {
            throw new RuntimeException('Cuenta o rol COBOL inválido.');
        }

        $sede = match (substr($cuenta, 0, 4)) {
            '1103', '1202' => 'SF',
            '2103', '2202' => 'ST',
            default => throw new RuntimeException('Prefijo de cuenta COBOL no reconocido.'),
        };

        $archivo = $rol === 'PROPIETARIO' ? 'CTACTEPRO' : 'INQCTACTE';
        $tabla = $rol === 'PROPIETARIO' ? 'ctactepro' : 'inqctacte';
        $db = $this->exploracion();

        // Transacción de solo lectura: el control PUBLICADO y sus filas del
        // overlay deben corresponder al mismo snapshot incluso si otro proceso
        // publica un cierre mientras se atiende esta consulta.
        return $db->transaction(function () use ($db, $cuenta, $rol, $sede, $archivo, $tabla, $enPrueba): array {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            return $this->consultarSnapshot($db, $cuenta, $rol, $sede, $archivo, $tabla, $enPrueba);
        });
    }

    /** @return array{movimientos:array<int,array<string,mixed>>, periodo_base:string, cierre:?string, fuente:string, sede:string} */
    private function consultarSnapshot(
        Connection $db,
        string $cuenta,
        string $rol,
        string $sede,
        string $archivo,
        string $tabla,
        bool $enPrueba,
    ): array {
        // La base NO debe ser max(periodo): producción incluye importaciones
        // parciales 202610 que no son la base del cierre diario de Santa Fe.
        // Hasta la conciliación, fijar explícitamente el histórico exacto 202609.
        $periodo = trim((string) config('portal.cuenta_corriente.periodo_base', '202609'));
        if (! preg_match('/^[0-9]{6}$/D', $periodo)) {
            throw new RuntimeException('Período base de la cuenta corriente no válido.');
        }

        $archivoId = $db->table('cobol_staging.migraciones_periodos_archivos as pa')
            ->join('cobol_staging.migraciones_periodos as p', 'p.id', '=', 'pa.migracion_id')
            ->where('p.periodo', $periodo)
            ->where('p.estado', 'OK')
            ->where('pa.procesado_en_tabla', $tabla)
            ->orderByDesc('p.finalizada_en')
            ->value('pa.archivo_id');

        if ($archivoId === null) {
            throw new RuntimeException("El período {$periodo} no contiene {$tabla}.");
        }

        $control = $db->table('cobol_staging.actualizacion_ultimo_periodo_control')
            ->where('sede', $sede)
            ->first(['estado', 'periodo_base', 'fecha_cierre']);
        $estado = strtoupper(trim((string) ($control->estado ?? '')));
        $periodoControl = trim((string) ($control->periodo_base ?? ''));

        if ($enPrueba) {
            // Solo la consulta de diagnóstico puede incorporar PRUEBA.
            // Si falta un cierre válido, no devolver silenciosamente el histórico.
            if ($estado !== 'PRUEBA') {
                throw new RuntimeException('No hay cierre COBOL en estado PRUEBA para esta sede.');
            }
            if ($periodoControl !== $periodo) {
                throw new RuntimeException('El cierre PRUEBA no coincide con el período histórico configurado.');
            }
        } elseif ($estado === 'PUBLICADO' && $periodoControl !== $periodo) {
            throw new RuntimeException('La publicación COBOL no coincide con el período base configurado.');
        }

        $aplicarCierre = $estado === 'PUBLICADO' || ($enPrueba && $estado === 'PRUEBA');

        $columnas = $rol === 'PROPIETARIO'
            ? ['cuenta', 'fecha', 'codigo', 'numero', 'importe', 'descripcion', 'inquilino', 'liquidado', 'iva', 'no_iva']
            : ['cuenta', 'fecha', 'codigo', 'numero', 'fecha_vencimiento', 'importe', 'importe_penalidad',
                'importe_abonado', 'descripcion', 'liquidado', 'iva', 'no_iva'];

        $base = $db->table("cobol_staging.{$tabla}")
            ->where('archivo_id', (int) $archivoId)
            ->where('cuenta', $cuenta)
            ->limit(self::MAX_REGISTROS_CUENTA + 1)
            ->get($columnas);

        if ($base->count() > self::MAX_REGISTROS_CUENTA) {
            throw new RuntimeException('La cuenta supera el límite de movimientos para esta vista.');
        }

        $porClave = [];
        foreach ($base as $fila) {
            $clave = (string) $fila->cuenta.(string) $fila->fecha.(string) $fila->codigo.(string) $fila->numero;
            $porClave[$clave] = $fila;
        }

        if ($aplicarCierre) {
            // La clave COBOL es cuenta(11) + fecha(8) + código(2) + número(6).
            // El rango aprovecha el índice PK (sede,archivo,clave_cobol).
            $delta = $db->table('cobol_staging.actualizacion_ultimo_periodo')
                ->where('sede', $sede)
                ->where('archivo', $archivo)
                ->where('periodo_base', $periodo)
                ->whereDate('fecha_cierre', $control->fecha_cierre)
                ->where('clave_cobol', '>=', $cuenta.str_repeat('0', 16))
                ->where('clave_cobol', '<=', $cuenta.str_repeat('9', 16))
                ->limit(self::MAX_REGISTROS_CUENTA + 1)
                ->get(['clave_cobol', 'tipo_cambio', 'sha256_registro', DB::raw("encode(registro_raw, 'hex') AS registro_hex")]);

            if ($delta->count() > self::MAX_REGISTROS_CUENTA) {
                throw new RuntimeException('La cuenta supera el límite de diferencias.');
            }

            foreach ($delta as $cambio) {
                $clave = (string) $cambio->clave_cobol;
                if ($cambio->tipo_cambio === 'BAJA') {
                    unset($porClave[$clave]);
                    continue;
                }
                if (! in_array((string) $cambio->tipo_cambio, ['ALTA', 'MODIFICACION'], true)) {
                    throw new RuntimeException('Tipo de cambio inesperado en el overlay.');
                }
                $raw = hex2bin((string) $cambio->registro_hex);
                if ($raw === false || strlen($raw) !== ($rol === 'PROPIETARIO' ? 112 : 134)
                    || substr($raw, 0, 27) !== $clave
                    || hash('sha256', $raw) !== (string) $cambio->sha256_registro) {
                    throw new RuntimeException('Registro COBOL corrupto en la publicación.');
                }
                $porClave[$clave] = $this->descomponerRegistro($raw, $rol);
            }
        }

        if (count($porClave) > self::MAX_REGISTROS_CUENTA) {
            throw new RuntimeException('La cuenta supera el límite de consulta.');
        }

        ksort($porClave, SORT_STRING);
        $movimientos = [];
        foreach ($porClave as $fila) {
            $movimientos[] = $this->normalizarMovimiento($fila, $rol);
        }

        return [
            'movimientos' => $movimientos,
            'periodo_base' => $periodo,
            'cierre' => $aplicarCierre ? (string) $control->fecha_cierre : null,
            'fuente' => $aplicarCierre ? ($enPrueba ? 'CIERRE_PRUEBA' : 'CIERRE_PUBLICADO') : 'HISTORICO',
            'sede' => $sede,
        ];
    }


    /**
     * TIPOIVA reconstruido desde historico + cierre PRUEBA.
     * Exclusivo para diagnosticos en consola de desarrollo.
     */
    public function tipoIvaInquilinoEnPrueba(string $cuenta): array
    {
        if (! app()->environment('local', 'testing')
            || (! app()->runningInConsole() && ! config('portal.cuenta_corriente.web_prueba', false))) {
            throw new RuntimeException(
                'Consulta de TIPOIVA solo disponible en desarrollo.'
            );
        }

        if (! preg_match('/^(1103|1202)[0-9]{7}$/D', $cuenta)) {
            throw new RuntimeException('Cuenta COBOL SF invalida.');
        }

        $db = $this->exploracion();

        return $db->transaction(function () use ($db, $cuenta): array {
            $db->statement(
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'
            );

            $periodo = trim((string) config(
                'portal.cuenta_corriente.periodo_base',
                '202609'
            ));

            $control = $db
                ->table('cobol_staging.actualizacion_ultimo_periodo_control')
                ->where('sede', 'SF')
                ->first(['estado', 'periodo_base', 'fecha_cierre']);

            if (!$control
                || strtoupper(trim($control->estado)) !== 'PRUEBA'
                || trim($control->periodo_base) !== $periodo) {
                throw new RuntimeException(
                    'No existe cierre PRUEBA compatible.'
                );
            }

            $archivoId = $db
                ->table('cobol_staging.migraciones_periodos_archivos as a')
                ->join(
                    'cobol_staging.migraciones_periodos as p',
                    'p.id',
                    '=',
                    'a.migracion_id'
                )
                ->where('p.periodo', $periodo)
                ->where('p.estado', 'OK')
                ->where('a.procesado_en_tabla', 'inquilino')
                ->orderByDesc('p.finalizada_en')
                ->value('a.archivo_id');

            if ($archivoId === null) {
                throw new RuntimeException(
                    'No existe maestro INQUILINO historico.'
                );
            }

            $historico = $db
                ->table('cobol_staging.inquilino')
                ->where('archivo_id', $archivoId)
                ->where('cta_inquilino', $cuenta)
                ->first(['linea_original']);

            $tipo = $historico
                ? mb_substr($historico->linea_original, 526, 1, 'UTF-8')
                : null;

            $fuente = 'HISTORICO';

            $cambio = $db
                ->table('cobol_staging.actualizacion_ultimo_periodo')
                ->where('sede', 'SF')
                ->where('archivo', 'INQUILINO')
                ->where('periodo_base', $periodo)
                ->whereDate('fecha_cierre', $control->fecha_cierre)
                ->where('clave_cobol', $cuenta)
                ->first([
                    'tipo_cambio',
                    'sha256_registro',
                    DB::raw("encode(registro_raw, 'hex') AS registro_hex")
                ]);

            if ($cambio) {
                if ($cambio->tipo_cambio === 'BAJA') {
                    throw new RuntimeException('Inquilino dado de baja.');
                }

                if (! in_array(
                    $cambio->tipo_cambio,
                    ['ALTA', 'MODIFICACION'],
                    true
                )) {
                    throw new RuntimeException('Cambio COBOL desconocido.');
                }

                if (($cambio->tipo_cambio === 'ALTA') === ($historico !== null)) {
                    throw new RuntimeException(
                        'Alta/modificacion inconsistente con historico.'
                    );
                }

                $raw = hex2bin($cambio->registro_hex);

                if ($raw === false
                    || strlen($raw) !== 560
                    || substr($raw, 0, 11) !== $cuenta
                    || ! hash_equals(
                        strtolower((string) $cambio->sha256_registro),
                        hash('sha256', $raw)
                    )) {
                    throw new RuntimeException(
                        'Registro INQUILINO corrupto.'
                    );
                }

                $tipo = substr($raw, 526, 1);
                $fuente = 'CIERRE_PRUEBA';
            }

            if ($tipo === null || ! preg_match('/^[1-6]$/D', $tipo)) {
                throw new RuntimeException('TIPOIVA invalido.');
            }

            return [
                'cuenta' => $cuenta,
                'tipo_iva' => (int) $tipo,
                'fuente' => $fuente,
                'cierre' => (string) $control->fecha_cierre,
            ];
        });
    }

    private function exploracion(): Connection
    {
        // Misma conexión de GeiCoreClienteService, con nombre aislado para portal.
        $config = config('database.connections.pgsql');
        if (! is_array($config)) {
            throw new RuntimeException('La conexión PostgreSQL no está configurada.');
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $campo) {
            $valor = config('gei.exploracion.'.$campo);
            if ($valor === null) {
                throw new RuntimeException('La conexión de exploración no está configurada.');
            }
            $config[$campo] = $valor;
        }
        $config['search_path'] = 'public';
        config(['database.connections.portal_gei_exploracion' => $config]);
        return DB::connection('portal_gei_exploracion');
    }

    private function descomponerRegistro(string $raw, string $rol): object
    {
        // Posiciones verificadas por el layout de exportación original: clave 27.
        $fila = [
            'cuenta' => substr($raw, 0, 11),
            'fecha' => substr($raw, 11, 8),
            'codigo' => substr($raw, 19, 2),
            'numero' => substr($raw, 21, 6),
        ];
        if ($rol === 'PROPIETARIO') {
            $fila += [
                'importe' => substr($raw, 27, 12),
                'descripcion' => $this->textoOriginal(substr($raw, 39, 40)),
                'inquilino' => substr($raw, 79, 11),
                'liquidado' => substr($raw, 90, 1),
                'iva' => substr($raw, 91, 10),
                'no_iva' => substr($raw, 101, 10),
            ];
        } else {
            $fila += [
                'fecha_vencimiento' => substr($raw, 27, 8),
                'importe' => substr($raw, 35, 12),
                'importe_penalidad' => substr($raw, 47, 12),
                'importe_abonado' => substr($raw, 59, 12),
                'descripcion' => $this->textoOriginal(substr($raw, 71, 40)),
                'liquidado' => substr($raw, 111, 1),
                'iva' => substr($raw, 114, 10),
                'no_iva' => substr($raw, 124, 10),
            ];
        }
        return (object) $fila;
    }

    private function textoOriginal(string $texto): string
    {
        // Se debe verificar la página de códigos de la instalación antes de publicar.
        return iconv('ISO-8859-1', 'UTF-8', $texto) ?: '';
    }

    /** @return array<string,mixed> */
    private function normalizarMovimiento(object $fila, string $rol): array
    {
        $datos = $rol === 'PROPIETARIO'
            ? $this->normalizador->propietario($fila)
            : $this->normalizador->inquilino($fila);

        // Ajuste de presentación: el signo negativo revierte debe/haber.
        // Los saldos definitivos requieren conciliación contable adicional.
        $importe = (string) $datos['importe'];
        $negativo = str_starts_with($importe, '-');
        $centavos = $this->centavos($importe);
        $codigo = (string) $datos['codigo'];
        $esDebe = $rol === 'PROPIETARIO' ? ((int) $codigo >= 21) : ((int) $codigo <= 50);
        if ($negativo) {
            $esDebe = ! $esDebe;
        }
        $afecta = (bool) $datos['afecta_saldo'];
        $datos['debe'] = $afecta && $esDebe ? $this->moneda($centavos) : '0.00';
        $datos['haber'] = $afecta && ! $esDebe ? $this->moneda($centavos) : '0.00';
        $datos['tipo_movimiento'] = ! $afecta ? 'SIN_EFECTO' : ($esDebe ? 'DEBITO' : 'CREDITO');
        return $datos;
    }

    private function centavos(string $importe): int
    {
        if (! preg_match('/^-?([0-9]+)\.([0-9]{2})$/D', $importe, $m)) {
            throw new RuntimeException('Importe COBOL inválido.');
        }
        return (int) $m[1] * 100 + (int) $m[2];
    }

    private function moneda(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
