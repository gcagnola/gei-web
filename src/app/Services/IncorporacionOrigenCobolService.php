<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Materializa únicamente el origen aprobado, dentro de la transacción de unificación. */
final class IncorporacionOrigenCobolService
{
    public function incorporar(object $cf, int $cliente): array
    {
        if (DB::transactionLevel() < 1 || $cf->sistema_origen !== 'COBOL') {
            throw new DomainException('La incorporación requiere una revisión COBOL transaccional.');
        }
        $rol = match ($cf->entidad_origen) {
            'PROPIETAR' => 'PROPIETARIO', 'INQUILINO' => 'INQUILINO',
            default => throw new DomainException('Entidad COBOL no admitida.'),
        };
        $cuenta = (string) $cf->clave_origen;
        $sede = match (substr($cuenta, 0, 4)) {
            '1202', '1103' => 'SF', '2202', '2103' => 'ST',
            default => throw new DomainException('Prefijo COBOL no reconocido: '.$cuenta),
        };
        if (! preg_match('/^\d{11}$/D', $cuenta) || ($rol === 'PROPIETARIO') !== in_array(substr($cuenta, 0, 4), ['1202','2202'], true)) {
            throw new DomainException('Cuenta COBOL incompatible con el rol.');
        }
        $sucursal = DB::table('sucursales')->where('codigo', $sede)->value('id');
        $rolId = DB::table('roles')->where('codigo', $rol)->value('id');
        if ($sucursal === null || $rolId === null) throw new DomainException('Falta sucursal o rol.');
        $datos = is_string($cf->datos_origen) ? json_decode($cf->datos_origen, true, 512, JSON_THROW_ON_ERROR) : (array) $cf->datos_origen;
        if (($datos['cuenta'] ?? null) !== $cuenta) throw new DomainException('Datos de origen incompatibles.');
        $estado = (string) $cf->estado_origen;
        $activo = match ($estado) {'ACTIVO' => true, 'BAJA' => false, 'DESCONOCIDO' => null, default => throw new DomainException('Estado COBOL desconocido.')};
        $cambios = [];
        $insertar = function (string $tabla, array $fila) use (&$cambios): int {
            $id = DB::table($tabla)->insertGetId([...$fila, 'created_at'=>now(), 'updated_at'=>now()]);
            $cambios[] = ['tabla'=>$tabla,'id'=>$id,'antes'=>null,'despues'=>(array) DB::table($tabla)->where('id',$id)->first()];
            return (int) $id;
        };
        $actualizar = function (string $tabla, object $fila, array $valores) use (&$cambios): void {
            DB::table($tabla)->where('id',$fila->id)->update([...$valores,'updated_at'=>now()]);
            $cambios[] = ['tabla'=>$tabla,'id'=>$fila->id,'antes'=>(array)$fila,'despues'=>(array)DB::table($tabla)->where('id',$fila->id)->first()];
        };
        $origen = DB::table('clientes_origenes')->where('sistema_origen','COBOL')->where('entidad_origen',$cf->entidad_origen)->where('clave_origen',$cuenta)->lockForUpdate()->first();
        if ($origen && (int)$origen->cliente_id !== $cliente) throw new DomainException('El origen pertenece a otro cliente.');
        if (! $origen) $insertar('clientes_origenes', [
            'cliente_id'=>$cliente,'sistema_origen'=>'COBOL','entidad_origen'=>$cf->entidad_origen,'clave_origen'=>$cuenta,
            'estado_origen'=>$estado,'archivo_origen_id'=>$cf->archivo_origen_id,'numero_linea'=>$cf->numero_linea,
            'hash_origen'=>$cf->hash_origen,'datos_origen'=>$cf->datos_origen,'ultimo_importado_at'=>$cf->ultima_deteccion_at,
        ]);
        $filas = DB::table('clientes_cuentas')->where('cuenta',$cuenta)->where('rol',$rol)->lockForUpdate()->get();
        if ($filas->contains(fn($f)=>(int)$f->cliente_id !== $cliente)) throw new DomainException('La cuenta pertenece a otra identidad.');
        $cc = $filas->first();
        if ($cc && $cc->sucursal_id !== null && (int)$cc->sucursal_id !== (int)$sucursal) throw new DomainException('Sucursal de cuenta incompatible.');
        $id = $cc ? (int)$cc->id : $insertar('clientes_cuentas', ['cliente_id'=>$cliente,'cuenta'=>$cuenta,'rol'=>$rol,'activo'=>$activo,'datos_origen'=>$cf->datos_origen,'sucursal_id'=>$sucursal]);
        if ($cc && $cc->sucursal_id === null) $actualizar('clientes_cuentas',$cc,['sucursal_id'=>$sucursal]);
        if (! DB::table('clientes_roles')->where('cliente_id',$cliente)->where('rol_id',$rolId)->exists()) {
            DB::table('clientes_roles')->insert(['cliente_id'=>$cliente,'rol_id'=>$rolId,'created_at'=>now(),'updated_at'=>now()]);
            $cambios[] = ['tabla'=>'clientes_roles','antes'=>null,'despues'=>['cliente_id'=>$cliente,'rol_id'=>$rolId]];
        }
        // Sólo completar enlaces vacíos/compatibles: nunca cambiar saldos, movimientos o titular ajeno.
        foreach (DB::table('cuentas_corrientes')->where('dominio',$rol)->where('cuenta',$cuenta)->lockForUpdate()->get() as $f) {
            if (($f->cliente_id !== null && (int)$f->cliente_id !== $cliente) || ($f->cliente_cuenta_id !== null && (int)$f->cliente_cuenta_id !== $id) || ($f->sucursal_id !== null && (int)$f->sucursal_id !== (int)$sucursal)) {
                throw new DomainException('Cuenta corriente con vinculación incompatible: '.$f->id);
            }
            if ($f->cliente_id === null || $f->cliente_cuenta_id === null || $f->sucursal_id === null) $actualizar('cuentas_corrientes',$f,['cliente_id'=>$cliente,'cliente_cuenta_id'=>$id,'sucursal_id'=>$sucursal]);
        }
        $omitidos = [];
        if ($rol === 'PROPIETARIO') {
            // Los orígenes físicos existentes son la evidencia, no el nombre/domicilio.
            $inmuebles = DB::table('inmuebles_origenes')->where('sistema_origen','COBOL')->where('cuenta_propietario',$cuenta)->orderBy('id')->get();
            foreach ($inmuebles as $o) {
                $i = (int)$o->inmueble_id; $vistos = [];
                do {
                    if (isset($vistos[$i])) throw new DomainException('Ciclo de inmuebles canónicos.');
                    $vistos[$i] = true;
                    $inm = DB::table('inmuebles')->where('id',$i)->lockForUpdate()->first();
                    if (! $inm) throw new DomainException('Inmueble inexistente.');
                    $i = (int)($inm->id_inmueble_canonico ?? $inm->id);
                } while ($i !== (int)$inm->id);
                // C4: no agregar, reactivar ni modificar ninguna titularidad confirmada.
                if (DB::table('inmuebles_propietarios')->whereIn('inmueble_id',array_keys($vistos))->where('origen','GEI_CONFIRMADA')->exists()) {
                    $omitidos[$i] = 'GEI_CONFIRMADA'; continue;
                }
                if (DB::table('inmuebles_propietarios')->where('inmueble_id',$i)->where('cliente_cuenta_id',$id)->exists()) continue;
                $insertar('inmuebles_propietarios', ['inmueble_id'=>$i,'cliente_id'=>$cliente,'cliente_cuenta_id'=>$id,'porcentaje'=>null,'vigencia_desde'=>null,'vigencia_hasta'=>null,'activo'=>true,'origen'=>'COBOL','datos_origen'=>json_encode(['cuenta_propietario'=>$cuenta,'clave_inmueble'=>$o->clave_inmueble,'regla'=>'INQUILINO_CTA_PROPIETARIO_A_CLIENTES_CUENTAS'],JSON_THROW_ON_ERROR)]);
            }
        } else {
            foreach (DB::table('contratos')->where('cuenta_inquilino',$cuenta)->orderBy('id')->get() as $c) {
                if (DB::table('contratos_inquilinos')->where('contrato_id',$c->id)->where('cliente_cuenta_id',$id)->where('rol','TITULAR')->exists()) continue;
                if (DB::table('contratos_inquilinos')->where('contrato_id',$c->id)->where('rol','TITULAR')->exists()) { $omitidos['contrato:'.$c->id]='TITULAR_EXISTENTE'; continue; }
                if (! in_array($c->estado,['ACTIVO','BAJA'],true)) { $omitidos['contrato:'.$c->id]='ESTADO_NO_DEFINIDO'; continue; }
                $insertar('contratos_inquilinos',['contrato_id'=>$c->id,'cliente_id'=>$cliente,'cliente_cuenta_id'=>$id,'rol'=>'TITULAR','vigencia_desde'=>$c->fecha_inicio ?? $c->fecha_contrato,'vigencia_hasta'=>$c->fecha_fin,'activo'=>$c->estado==='ACTIVO','origen'=>'COBOL','datos_origen'=>json_encode(['cuenta_inquilino'=>$cuenta],JSON_THROW_ON_ERROR)]);
            }
        }
        // Expedientes: completar FK, sin aprobar expedientes ni cambiar su estado documental.
        $col = $rol === 'PROPIETARIO' ? 'cuenta_propietario' : 'cuenta_inquilino';
        $fk = $rol === 'PROPIETARIO' ? 'cliente_cuenta_propietario_id' : 'cliente_cuenta_inquilino_id';
        foreach (DB::table('contratos_expedientes')->where($col,$cuenta)->whereNull($fk)->where('sucursal_id',$sucursal)->lockForUpdate()->get() as $f) $actualizar('contratos_expedientes',$f,[$fk=>$id]);
        return ['cuenta_id'=>$id,'cuenta'=>$cuenta,'rol'=>$rol,'cambios'=>$cambios,'omitidos'=>$omitidos];
    }
}
