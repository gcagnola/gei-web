<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Actividad por CUENTA + ROL + PERÍODO. No escribe ninguna tabla ni decide
 * la baja administrativa de personas/contratos o permisos de Autogestión.
 */
final class ActividadOperativaCuentasPeriodoService
{
    /** @var array<string, array<string, array<string, bool>>> */
    private array $cache = [];

    public function __construct(
        private readonly EvidenciaActividadCobolService $cobol,
        private readonly ActividadArcaKngService $arca,
        private readonly ImpuestosGarantizadosPdfService $impuestos,
    ) {
    }

    /**
     * @return array{PROPIETARIO:array<string,bool>,INQUILINO:array<string,bool>}
     */
    public function cuentas(string $periodo): array
    {
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo)) {
            throw new InvalidArgumentException('Período inválido. Utilizar AAAAMM.');
        }
        if (isset($this->cache[$periodo])) {
            return $this->cache[$periodo];
        }

        // Cache compartida entre peticiones HTTP.
        // Duración limitada: no modifica PostgreSQL.
        return $this->cache[$periodo] = Cache::store('file')->remember(
            'gei:actividad-operativa:cuentas:v2:'.$periodo,
            now()->addMinutes(10),
            function () use ($periodo): array {
            $activas = ['PROPIETARIO' => [], 'INQUILINO' => []];
            $marcar = static function (string $rol, string $cuenta) use (&$activas): void {
                $rol = strtoupper(trim($rol));
                $cuenta = self::normalizarCuenta($cuenta);
                if ($cuenta !== '' && array_key_exists($rol, $activas)) {
                    $activas[$rol][$cuenta] = true;
                }
            };

            // COBOL: prueba de vigencia del cierre. La eventual baja original se
            // conserva; esta evidencia no sobrescribe datos administrativos.
            foreach ($this->cobol->consultarPeriodo($periodo)['cuentas'] as $fila) {
                $marcar((string) $fila['rol'], (string) $fila['cuenta']);
            }

            // ARCA: no confundir destinatario de PDF con permisos de acceso.
            // Acá sólo se acredita actividad de la CUENTA que figura en la fuente.
            $datosArca = $this->arca->consultarPeriodo($periodo);
            foreach (array_keys($datosArca['cuentas']) as $cuenta) {
                $rol = self::dominioPorCuenta((string) $cuenta);
                if ($rol !== null) {
                    $marcar($rol, (string) $cuenta);
                }
            }

            // Liquidación de propietarios: una fila del período es evidencia.
            foreach (DB::table('liquidaciones_propietarios')
                ->where('periodo', $periodo)->distinct()->pluck('cuenta') as $cuenta) {
                $marcar('PROPIETARIO', (string) $cuenta);
            }

            // Impuestos garantizados: no exigir PDF para reconocer la evidencia
            // que el servicio ya sabe identificar.
            foreach ($this->impuestos->cuentasPeriodo($periodo) as $cuenta) {
                $marcar('PROPIETARIO', (string) $cuenta);
            }

            // Movimientos efectivamente fechados en el período. No alcanza con
            // que una importación histórica tenga m.periodo=AAAAMM.
            $desde = substr($periodo, 0, 4).'-'.substr($periodo, 4, 2).'-01';
            $hasta = date('Y-m-t', strtotime($desde));
            foreach (DB::table('cuentas_corrientes_movimientos as m')
                ->join('cuentas_corrientes as c', 'c.id', '=', 'm.cuenta_corriente_id')
                ->where('m.periodo', $periodo)
                ->whereBetween('m.fecha', [$desde, $hasta])
                ->whereIn('c.dominio', ['PROPIETARIO', 'INQUILINO'])
                ->select('c.dominio', 'c.cuenta')->distinct()->cursor() as $fila) {
                $marcar((string) $fila->dominio, (string) $fila->cuenta);
            }

                return $activas;
            }
        );
    }

    public static function normalizarCuenta(string $cuenta): string
    {
        return preg_replace('/\D+/', '', $cuenta) ?? '';
    }

    public static function dominioPorCuenta(string $cuenta): ?string
    {
        return match (substr(self::normalizarCuenta($cuenta), 0, 4)) {
            '1103', '2103' => 'INQUILINO',
            '1202', '2202' => 'PROPIETARIO',
            default => null,
        };
    }
}
