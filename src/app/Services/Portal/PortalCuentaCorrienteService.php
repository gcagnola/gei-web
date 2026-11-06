<?php

namespace App\Services\Portal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Cuenta corriente única operativa para Administración y Autogestión.
 * Solo consulta gei_db; jamás mezcla movimientos con gei_exploracion.
 * La autorización de acceso a cuentas se controla en los controladores.
 */
final class PortalCuentaCorrienteService
{
    private const MAX_REGISTROS_CUENTA = 10000;

    /** @return array{movimientos: array, periodo_base: string, cierre: ?string, fuente: string, sede: string} */
    public function consultar(string $cuenta, string $rol): array
    {
        if (! preg_match('/^[0-9]{11}$/D', $cuenta)
            || ! in_array($rol, ['PROPIETARIO', 'INQUILINO'], true)) {
            throw new RuntimeException('Cuenta o rol COBOL inválido.');
        }
        $sede = match (substr($cuenta, 0, 4)) {
            '1103', '1202' => 'SF',
            '2103', '2202' => 'ST',
            default => throw new RuntimeException('Prefijo de cuenta COBOL no reconocido.'),
        };
        if (($rol === 'PROPIETARIO' && ! in_array(substr($cuenta, 0, 4), ['1202', '2202'], true))
            || ($rol === 'INQUILINO' && ! in_array(substr($cuenta, 0, 4), ['1103', '2103'], true))) {
            throw new RuntimeException('La cuenta no corresponde al rol solicitado.');
        }

        return DB::transaction(function () use ($cuenta, $rol, $sede): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $filas = DB::table('cuentas_corrientes_movimientos')
                ->where('dominio', $rol)
                ->where('cuenta', $cuenta)
                ->orderBy('fecha_origen')
                ->orderBy('codigo')
                ->orderBy('numero')
                ->limit(self::MAX_REGISTROS_CUENTA + 1)
                ->get([
                    'dominio', 'cuenta', 'fecha', 'fecha_origen', 'periodo', 'codigo', 'numero',
                    'fecha_vencimiento', 'fecha_vencimiento_origen', 'importe', 'debe', 'haber',
                    'importe_penalidad', 'importe_abonado', 'iva', 'no_gravado', 'descripcion',
                    'cuenta_inquilino_referencia', 'liquidado_origen', 'afecta_saldo',
                ]);
            if ($filas->count() > self::MAX_REGISTROS_CUENTA) {
                throw new RuntimeException('La cuenta supera el límite de movimientos para esta vista.');
            }
            $movimientos = $filas->map(static function ($r): array {
                $f = (array) $r;
                $f['fecha'] = $r->fecha === null ? null : substr((string) $r->fecha, 0, 10);
                $f['fecha_vencimiento'] = $r->fecha_vencimiento === null
                    ? null : substr((string) $r->fecha_vencimiento, 0, 10);
                foreach (['importe','debe','haber','importe_penalidad','importe_abonado','iva','no_gravado'] as $campo) {
                    $f[$campo] = $r->{$campo} === null ? null : (string) $r->{$campo};
                }
                $f['afecta_saldo'] = (bool) $r->afecta_saldo;
                $f['tipo_movimiento'] = ! $f['afecta_saldo'] ? 'SIN_EFECTO'
                    : ((float) $r->debe !== 0.0 ? 'DEBITO' : 'CREDITO');
                $f['cuenta'] = (string) $r->cuenta;
                $f['fecha_origen'] = (string) $r->fecha_origen;
                $f['codigo'] = (string) $r->codigo;
                $f['numero'] = (string) $r->numero;
                $f['liquidado'] = $r->liquidado_origen;
                $f['no_iva'] = $f['no_gravado'];
                return $f;
            })->all();

            $fecha = Schema::hasTable('cobol_publicaciones')
                ? DB::table('cobol_publicaciones')->where('estado', 'PUBLICADO')
                    ->orderByDesc('fecha_cierre')->orderByDesc('id')->value('fecha_cierre')
                : null;
            return [
                'movimientos' => $movimientos,
                'periodo_base' => '',
                'cierre' => $fecha === null ? null : substr((string) $fecha, 0, 10),
                'fuente' => $fecha === null ? 'OPERATIVA' : 'CIERRE_PUBLICADO',
                'sede' => $sede,
            ];
        }, 1);
    }

    /** Compatibilidad de interfaz: nunca carga datos de prueba. */
    public function consultarEnPrueba(string $cuenta, string $rol): array
    {
        return $this->consultar($cuenta, $rol);
    }

    /** No se habilita una simulación fiscal como si fuera saldo exigible. */
    public function tipoIvaInquilinoEnPrueba(string $cuenta): array
    {
        throw new RuntimeException('Diagnóstico de IVA no incluido en la cuenta corriente operativa.');
    }
}
