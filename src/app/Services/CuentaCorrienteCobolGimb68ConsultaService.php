<?php

namespace App\Services;

use App\Services\Portal\PortalCuentaCorrienteService;
use App\Support\Cobol\Gimb68Saldos;
use Illuminate\Support\Collection;

/**
 * Adaptador de lectura para la ficha maestra: usa la MISMA fuente y
 * reconstruccion GIMB68/COCCTE del portal, sin modificar ningun ledger.
 * Exclusivamente SF + cierre PRUEBA en Desarrollo/Test. Para propietarios
 * se usa CTACTEPRO y una PROYECCION identificada; NO GIMB68.
 */
final class CuentaCorrienteCobolGimb68ConsultaService
{
    public function __construct(private readonly PortalCuentaCorrienteService $portal)
    {
    }

    /** @return array{cuentas: Collection<string,array>, incidencias: array<int,string>} */
    public function consultar(Collection $cuentasAutorizadas, string $mes): array
    {
        $cuentas = collect();
        $incidencias = [];

        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/D', $mes)) {
            throw new \InvalidArgumentException('Mes de cuenta corriente invalido.');
        }

        // La ruta ya exige permiso, cliente canonico y sede. Esta comprobacion
        // adicional evita que una llamada directa al servicio use otro rol.
        if (! app()->environment('local', 'testing') ||
            ! (bool) config('portal.cuenta_corriente.web_prueba', false)) {
            return ['cuentas' => $cuentas, 'incidencias' => $incidencias];
        }

        $claves = CuentaCorrienteSaldosClienteService::clavesAutorizadas($cuentasAutorizadas);
        foreach ($claves as $clave) {
            if (! in_array($clave['rol'], ['INQUILINO', 'PROPIETARIO'], true) ||
                ! str_starts_with($clave['cuenta'], $clave['rol'] === 'INQUILINO' ? '1103' : '1202')) {
                continue;
            }
            $cuenta = $clave['cuenta'];
            try {
                $snapshot = $this->portal->consultarEnPrueba($cuenta, $clave['rol']);
                if (($snapshot['fuente'] ?? '') !== 'CIERRE_PRUEBA') {
                    $incidencias[] = "Cuenta {$cuenta}: el cierre no esta en PRUEBA.";
                    continue;
                }
                $saldos = $clave['rol'] === 'INQUILINO'
                    ? Gimb68Saldos::calcular($snapshot['movimientos'], $mes)
                    : CuentaCorrientePropietarioSaldoService::calcular($snapshot['movimientos'], $mes);
                foreach (['movimientos','debitos_anteriores','creditos_anteriores',
                    'saldo_anterior','saldo_actual'] as $campo) {
                    if (! array_key_exists($campo, $saldos)) {
                        throw new \RuntimeException('Estructura GIMB68 incompleta.');
                    }
                }
                $cuentas->put($clave['rol'].'|'.$cuenta, [
                    'rol' => $clave['rol'],
                    'cuenta' => $cuenta,
                    'sede' => 'SF',
                    'fuente' => $snapshot['fuente'],
                    'cierre' => $snapshot['cierre'],
                    'periodo' => $mes,
                    'proyectado' => $clave['rol'] === 'PROPIETARIO',
                    'saldos' => $saldos,
                ]);
            } catch (\Throwable $ex) {
                report($ex);
                // No exponer errores de SQL, rutas ni parametros privados.
                $incidencias[] = "Cuenta {$cuenta}: cierre COBOL no disponible para visualizacion conciliada.";
            }
        }

        return ['cuentas' => $cuentas, 'incidencias' => $incidencias];
    }
}
