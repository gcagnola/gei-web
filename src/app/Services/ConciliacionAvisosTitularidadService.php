<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Concilia identidades materializadas: relación operativa verificada o antecedente histórico. */
final class ConciliacionAvisosTitularidadService
{
    public function conciliar(?string $cuenta = null, bool $confirmar = false, ?int $usuario = null): array
    {
        if ($confirmar && DB::transactionLevel() < 1) {
            throw new DomainException('La conciliación requiere una transacción.');
        }

        $consulta = DB::table('inmuebles_conflictos')
            ->where('estado', 'PENDIENTE')->where('motivo', 'PROPIETARIO_EN_CONFLICTO');
        if ($cuenta !== null) $consulta->where('cuenta_propietario', $cuenta);
        if ($confirmar) $consulta->lockForUpdate();
        $resultados = [];
        foreach ($consulta->orderBy('id')->get() as $aviso) {
            $fila = ['id'=>(int)$aviso->id, 'inmueble_id'=>$aviso->inmueble_id,
                'cuenta'=>$aviso->cuenta_propietario];
            $evidencia = $this->verificar($aviso, $confirmar);
            if (is_string($evidencia)) {
                $resultados[] = $fila + ['resultado'=>$evidencia];
                continue;
            }
            $detalle = json_decode((string)$aviso->detalle, true);
            if (! is_array($detalle)) {
                $resultados[] = $fila + ['resultado'=>'DETALLE_NO_VALIDO'];
                continue;
            }
            if ($confirmar) {
                $fecha = now();
                $detalle['conciliacion_titularidad'] = $evidencia + [
                    'regla'=>'IDENTIDAD_COBOL_RESUELTA_Y_RELACION_VERIFICADA',
                    'usuario_id'=>$usuario, 'fecha'=>$fecha->toIso8601String(),
                ];
                $cambios = DB::table('inmuebles_conflictos')->where('id', $aviso->id)
                    ->where('estado', 'PENDIENTE')->update([
                        'estado'=>'RESUELTO', 'resuelto_at'=>$fecha, 'updated_at'=>$fecha,
                        'detalle'=>json_encode($detalle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]);
                if ($cambios !== 1) throw new DomainException('El aviso cambió durante la conciliación.');
            }
            $resultados[] = $fila + ['resultado'=>$confirmar ? 'RESUELTO' : 'CONCILIABLE'] + $evidencia;
        }
        return $resultados;
    }

    private function verificar(object $aviso, bool $bloquear): array|string
    {
        // El bloqueo global de transformación COBOL queda a cargo del llamador.
        $leer = static function ($q) use ($bloquear) {
            return $bloquear ? $q->lockForUpdate()->get() : $q->get();
        };
        $cuenta = (string)$aviso->cuenta_propietario;
        if (! preg_match('/^(1202|2202)\d{7}$/D', $cuenta)) return 'CUENTA_INVALIDA';
        $identidades = $leer(DB::table('clientes_conflictos')->where('sistema_origen', 'COBOL')
            ->where('entidad_origen', 'PROPIETAR')->where('clave_origen', $cuenta)->orderBy('id'));
        if ($identidades->contains('estado', 'PENDIENTE')) return 'IDENTIDAD_PENDIENTE';
        $resueltas = $identidades->where('estado', 'RESUELTO');
        if ($resueltas->isEmpty()) return 'SIN_IDENTIDAD_RESUELTA';

        $cuentas = $leer(DB::table('clientes_cuentas')->where('cuenta', $cuenta)
            ->where('rol', 'PROPIETARIO')->orderBy('id'));
        if ($cuentas->count() !== 1) return 'CUENTA_NO_UNIVOCA';
        $cc = $cuentas->first();
        $resolucionHistoricaId = null;
        foreach ($resueltas as $r) {
            if ($r->cliente_resuelto_id !== null) {
                if ((int)$r->cliente_resuelto_id !== (int)$cc->cliente_id) return 'IDENTIDAD_INCOMPATIBLE';
                continue;
            }
            // Compatibilidad con decisiones antiguas CREAR_SEPARADO sin cliente_resuelto_id.
            $auditoria = $leer(DB::table('clientes_resoluciones_origen')
                ->where('sistema_origen', 'COBOL')->where('entidad_origen', 'PROPIETAR')
                ->where('clave_origen', $cuenta));
            if ($auditoria->count() !== 1) return 'IDENTIDAD_INCOMPATIBLE';
            $decision = $auditoria->first();
            if ($decision->decision !== 'CREAR_SEPARADO' || (int)$decision->cliente_id !== (int)$cc->cliente_id) {
                return 'IDENTIDAD_INCOMPATIBLE';
            }
            $resolucionHistoricaId = (int)$decision->id_cliente_resolucion_origen;
        }
        $cliente = $leer(DB::table('clientes')->where('id', $cc->cliente_id))->first();
        if (! $cliente || (int)($cliente->id_cliente_canonico ?? $cliente->id) !== (int)$cliente->id) {
            return 'CLIENTE_NO_CANONICO';
        }
        $origen = $leer(DB::table('clientes_origenes')->where('sistema_origen', 'COBOL')
            ->where('entidad_origen', 'PROPIETAR')->where('clave_origen', $cuenta));
        if ($origen->count() !== 1 || (int)$origen->first()->cliente_id !== (int)$cc->cliente_id) {
            return 'ORIGEN_CLIENTE_INCOMPATIBLE';
        }
        if (! $aviso->inmueble_id || ! $aviso->clave_inmueble || ! $aviso->cuenta_inquilino) {
            return 'AVISO_SIN_ORIGEN_COMPLETO';
        }
        $inmueble = $leer(DB::table('inmuebles')->where('id', $aviso->inmueble_id))->first();
        if (! $inmueble || (int)($inmueble->id_inmueble_canonico ?? $inmueble->id) !== (int)$inmueble->id) {
            return 'INMUEBLE_NO_CANONICO';
        }
        if ($inmueble->clave_migracion !== $aviso->clave_inmueble) return 'CLAVE_INMUEBLE_DIFERENTE';
        $fuente = $leer(DB::table('inmuebles_origenes')->where('sistema_origen', 'COBOL')
            ->where('entidad_origen', 'INQUILINO')->where('clave_origen', $aviso->cuenta_inquilino)
            ->where('cuenta_propietario', $cuenta)->where('clave_inmueble', $aviso->clave_inmueble)
            ->where('inmueble_id', $inmueble->id));
        if ($fuente->count() !== 1) return 'ORIGEN_INMUEBLE_NO_COINCIDE';
        $relaciones = $leer(DB::table('inmuebles_propietarios')->where('inmueble_id', $inmueble->id)->orderBy('id'));
        if ($relaciones->contains('origen', 'GEI_CONFIRMADA')) return 'TITULARIDAD_GEI_CONFIRMADA';
        $evidencia = ['cliente_id'=>(int)$cc->cliente_id, 'cliente_cuenta_id'=>(int)$cc->id,
            'conflictos_identidad_ids'=>$resueltas->pluck('id')->map(fn ($id)=>(int)$id)->values()->all(),
            'inmueble_origen_id'=>(int)$fuente->first()->id];
        if ($resolucionHistoricaId !== null) $evidencia['resolucion_origen_id'] = $resolucionHistoricaId;
        // Sin relación operativa: sólo conciliar el antecedente si TODOS los orígenes
        // son inquilinos COBOL de baja de la misma cuenta. Nunca insertar una titularidad.
        if ($relaciones->isEmpty()) {
            $absorbidos = $leer(DB::table('inmuebles')->where('id_inmueble_canonico', $inmueble->id)
                ->where('id', '<>', $inmueble->id));
            $origenes = $leer(DB::table('inmuebles_origenes')->where('inmueble_id', $inmueble->id)->orderBy('id'));
            if ($absorbidos->isEmpty() && $origenes->isNotEmpty() && $origenes->every(fn ($o) =>
                $o->sistema_origen === 'COBOL' && $o->entidad_origen === 'INQUILINO'
                && $o->estado_origen === 'BAJA' && $o->cuenta_propietario === $cuenta)) {
                return $evidencia + ['titularidad_id'=>null,
                    'regla'=>'IDENTIDAD_RESUELTA_ORIGENES_INQUILINOS_DE_BAJA_SIN_TITULARIDAD',
                    'inmueble_origenes_ids'=>$origenes->pluck('id')->map(fn ($id)=>(int)$id)->values()->all()];
            }
        }
        $mismaCuenta = $relaciones->filter(fn ($r) => (int)$r->cliente_cuenta_id === (int)$cc->id);
        if ($mismaCuenta->count() !== 1) return 'SIN_RELACION_COBOL_ACTIVA_UNIVOCA';
        $relacion = $mismaCuenta->filter(fn ($r) => (int)$r->cliente_id === (int)$cc->cliente_id && in_array($r->activo, [true, 1, '1', 't', 'true'], true) && $r->origen === 'COBOL');
        if ($relacion->count() !== 1) return 'SIN_RELACION_COBOL_ACTIVA_UNIVOCA';
        return $evidencia + ['titularidad_id'=>(int)$relacion->first()->id];
    }
}
