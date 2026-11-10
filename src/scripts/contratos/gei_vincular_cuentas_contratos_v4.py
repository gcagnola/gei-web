#!/usr/bin/env python3
"""GeI-Desarrollo: vinculación documental conservadora por cuenta COBOL (sin nombres).

Requiere /tmp/gei_diagnosticar_cruce_excel_pdf_v3.py en el mismo directorio.

  python3 /tmp/gei_vincular_cuentas_contratos_v4.py                  # simulación
  python3 /tmp/gei_vincular_cuentas_contratos_v4.py --aplicar --limite 50
  python3 /tmp/gei_vincular_cuentas_contratos_v4.py --aplicar
  python3 /tmp/gei_vincular_cuentas_contratos_v4.py --verificar

SOLO escribe planilla_fila_id, cuenta_inquilino_planilla, cuenta_inquilino y
cliente_cuenta_inquilino_id de contratos_expedientes PENDIENTES.
NO modifica estado_vinculacion, validado_at, contrato_id, inmueble_id, fechas,
PDF, permisos, ni archivos. No usa nombres ni verifica similitud de nombres.
No da acceso a Autogestión.
"""
from __future__ import annotations

import argparse
import collections
import importlib.util
import json
import re
import socket
import subprocess
import sys
from pathlib import Path

BATCH = 100
V3 = Path(__file__).with_name('gei_diagnosticar_cruce_excel_pdf_v3.py')

PHP_APLICAR = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
    if (config('app.env') !== 'local' ||
        (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
        DB::connection()->getDatabaseName() !== 'gei_db') {
        throw new RuntimeException('DESTINO NO AUTORIZADO: no es PostgreSQL de GeI-Desarrollo');
    }
    $items = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($items) || count($items) < 1 || count($items) > 100) {
        throw new RuntimeException('Lote inválido');
    }
    $resultado = DB::transaction(function () use ($items) {
        $creados = 0; $existentes = 0; $omitidos = 0;
        foreach ($items as $i) {
            $id = (int) $i['expediente_id'];
            $filaId = (int) $i['fila_id'];
            $cuenta = (string) $i['cuenta'];
            $cuentaPlanilla = (string) $i['cuenta_planilla'];
            $numero = (string) $i['numero'];
            $anio = (int) $i['anio']; $mes = (int) $i['mes'];
            if (!preg_match('/^(1103|2103)[0-9]{7}$/D', $cuenta) ||
                !preg_match('/^[0-9]{1,8}$/D', $numero) ||
                $anio < 2015 || $anio > 2035 || $mes < 1 || $mes > 12) {
                throw new RuntimeException('Datos de identidad inválidos en expediente '.$id);
            }
            // Un mismo string de cuenta nunca autoriza una asociación si en
            // clientes_cuentas existen varias filas candidatas.
            $cuentas = DB::table('clientes_cuentas')
                ->where('rol', 'INQUILINO')->where('cuenta', $cuenta)
                ->get(['id','cliente_id']);
            if ($cuentas->count() !== 1) {
                $omitidos++; continue;
            }
            $cc = $cuentas->first();
            $fila = DB::table('contratos_planillas_filas')
                ->where('id', $filaId)->first();
            if (!$fila || mb_strtoupper(trim($fila->nombre_hoja)) !== 'DETALLE') {
                throw new RuntimeException('Fila DETALLE ausente: '.$filaId);
            }
            $celdas = is_string($fila->celdas)
                ? json_decode($fila->celdas, true, 512, JSON_THROW_ON_ERROR)
                : $fila->celdas;
            if (!is_array($celdas) || count($celdas) < 3) {
                throw new RuntimeException('Celdas insuficientes: '.$filaId);
            }
            $numCelda = $celdas[0]['valor'] ?? null;
            if (!is_numeric($numCelda) || (string)(int)$numCelda !== $numero) {
                throw new RuntimeException('Número de contrato distinto en Excel: '.$filaId);
            }
            $c = trim((string)($celdas[2]['valor'] ?? ''));
            if ($c !== $cuentaPlanilla) {
                throw new RuntimeException('Cuenta abreviada distinta en Excel: '.$filaId);
            }
            $compacta = preg_replace('/\s+/u', '', $c);
            $esAbreviada = preg_match('/^[0-9]{5}[\/\-][0-9]{2}$/D', $compacta) === 1 &&
                str_replace(['/', '-'], '', $compacta) === substr($cuenta, 4);
            $esCompleta = preg_replace('/[^0-9]/', '', $compacta) === $cuenta;
            if (!$esAbreviada && !$esCompleta) {
                throw new RuntimeException('Cuenta COBOL incompatible con columna C del Excel: '.$filaId);
            }
            $exp = DB::table('contratos_expedientes')->where('id', $id)
                ->lockForUpdate()->first();
            if (!$exp || $exp->estado_vinculacion !== 'PENDIENTE' ||
                (int)$exp->anio_archivo !== $anio || (int)$exp->mes_archivo !== $mes ||
                ltrim((string)$exp->numero_documental, '0') !== ltrim($numero,'0')) {
                throw new RuntimeException('Expediente cambió desde diagnóstico: '.$id);
            }
            // Evitar pisar una relación previa divergente.
            foreach (['planilla_fila_id'=>$filaId,
                      'cliente_cuenta_inquilino_id'=>(int)$cc->id,
                      'cuenta_inquilino'=>$cuenta,
                      'cuenta_inquilino_planilla'=>$cuentaPlanilla] as $campo=>$valor) {
                if ($exp->$campo !== null && (string)$exp->$campo !== (string)$valor) {
                    throw new RuntimeException('Conflicto previo '.$campo.' expediente '.$id);
                }
            }
            if ($exp->planilla_fila_id !== null && $exp->cuenta_inquilino !== null &&
                $exp->cliente_cuenta_inquilino_id !== null) {
                $existentes++; continue;
            }
            DB::table('contratos_expedientes')->where('id', $id)->update([
                'planilla_fila_id' => $filaId,
                'cliente_cuenta_inquilino_id' => $cc->id,
                'cuenta_inquilino' => $cuenta,
                'cuenta_inquilino_planilla' => $cuentaPlanilla,
                'updated_at' => now(),
            ]);
            $creados++;
        }
        return ['vinculaciones_nuevas'=>$creados,
                'vinculaciones_ya_existentes'=>$existentes,
                'omitidas_cuenta_duplicada_o_ausente'=>$omitidos];
    });
    echo 'LOTE_OK '.json_encode($resultado, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'LOTE_ERROR '.$e->getMessage()."\n");
    exit(1);
}
'''

PHP_VERIFICAR = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
if (config('app.env') !== 'local' ||
    (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
    DB::connection()->getDatabaseName() !== 'gei_db') {
    fwrite(STDERR, "DESTINO NO AUTORIZADO\n"); exit(2);
}
$f = DB::table('contratos_expedientes as e')
    ->leftJoin('clientes_cuentas as cc','cc.id','=','e.cliente_cuenta_inquilino_id')
    ->where('e.estado_vinculacion','PENDIENTE')
    ->whereNotNull('e.planilla_fila_id')
    ->selectRaw('count(*) as total,
        count(*) filter (where cc.id is not null and cc.rol = \'INQUILINO\'
                 and cc.cuenta = e.cuenta_inquilino) as cuentas_coincidentes,
        count(*) filter (where cc.id is null or cc.rol <> \'INQUILINO\'
                 or cc.cuenta <> e.cuenta_inquilino) as cuentas_incompatibles')
    ->first();
echo 'VERIFICACION_CUENTAS '.json_encode($f, JSON_THROW_ON_ERROR)."\n";
if ((int)$f->cuentas_incompatibles > 0) exit(3);
'''


def load_v3():
    if not V3.is_file():
        raise RuntimeError(f'Falta {V3}; debe estar junto a este script')
    spec = importlib.util.spec_from_file_location('gei_diagnostico_v3', V3)
    if spec is None or spec.loader is None:
        raise RuntimeError('No se pudo cargar el diagnóstico V3')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def planificar(v3):
    planillas, filas, expedientes, cuentas = v3.obtener()
    idx_filas = collections.defaultdict(list)
    for fila in filas:
        ruta = planillas.get(int(fila['planilla_id']))
        if ruta is None:
            raise RuntimeError(f'Planilla no encontrada: {fila["planilla_id"]}')
        valores = v3.leer_celdas(fila['celdas'])
        num = v3.numero(valores[0]) if valores else None
        anio, mes = v3.periodo_xls(ruta)
        if not num or not anio or not mes:
            continue
        # El índice incluye TODAS las filas con período y número, incluso las
        # que carecen de cuenta. De lo contrario una coincidencia duplicada
        # sin cuenta podría hacer parecer única una clave ambigua.
        idx_filas[(anio, mes, num)].append((fila, valores))
    idx_exps = collections.Counter()
    for exp in expedientes:
        n = v3.numero(exp.get('numero_documental'))
        if exp.get('anio_archivo') and exp.get('mes_archivo') and n:
            idx_exps[(int(exp['anio_archivo']), int(exp['mes_archivo']), n)] += 1
    plan, ambiguos = [], 0
    for exp in expedientes:
        a, m = exp.get('anio_archivo'), exp.get('mes_archivo')
        num = v3.numero(exp.get('numero_documental'))
        if not a or not m or not num:
            continue
        clave = (int(a), int(m), num)
        candidatos = idx_filas.get(clave, [])
        if len(candidatos) != 1 or idx_exps[clave] != 1:
            if len(candidatos) > 1 or idx_exps[clave] > 1:
                ambiguos += 1
            continue
        fila, valores = candidatos[0]
        todas, _ = v3.cuentas_candidatas(valores, cuentas)
        if len(todas) != 1 or len(valores) < 3:
            continue
        columna_c, _ = v3.cuentas_candidatas([valores[2]], cuentas)
        if columna_c != todas:
            continue
        cuenta = todas[0]
        plan.append({
            'expediente_id': int(exp['id']), 'fila_id': int(fila['id']),
            'cuenta': cuenta, 'cuenta_planilla': str(valores[2]).strip(),
            'numero': num, 'anio': int(a), 'mes': int(m),
        })
    ids = [x['expediente_id'] for x in plan]
    filas_ids = [x['fila_id'] for x in plan]
    if len(ids) != len(set(ids)) or len(filas_ids) != len(set(filas_ids)):
        raise RuntimeError('El plan presenta expedientes o filas duplicadas')
    plan.sort(key=lambda x: x['expediente_id'])
    return plan, len(expedientes), ambiguos


def php_run(script: str, items=None):
    proc = subprocess.run(['docker','exec','-i','gei-app','php','-r',script],
                          input=json.dumps(items,ensure_ascii=False) if items is not None else None,
                          capture_output=True, text=True, encoding='utf-8', errors='replace')
    if proc.returncode:
        raise RuntimeError((proc.stderr or proc.stdout or 'PHP falló')[:1600])
    return proc.stdout.strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--aplicar', action='store_true')
    parser.add_argument('--limite', type=int, default=0)
    parser.add_argument('--verificar', action='store_true')
    args = parser.parse_args()
    if socket.gethostname().split('.')[0].lower() != 'gei-desarrollo':
        raise RuntimeError('Ejecutar únicamente en gei-desarrollo')
    if args.verificar:
        print(php_run(PHP_VERIFICAR))
        return
    if args.limite < 0:
        raise RuntimeError('Límite inválido')
    v3 = load_v3()
    plan, pendientes, ambiguos = planificar(v3)
    print('===== PLAN DE VINCULACION POR CUENTA COBOL =====')
    print('Expedientes pendientes:', pendientes)
    print('Candidatos aptos para la etapa de cuenta:', len(plan))
    print('Sin correspondencia única o sin cuenta válida en columna C:', pendientes-len(plan))
    print('Ejemplos:', plan[:3])
    print('No se utilizan nombres, CUIT, similitud ni coincidencia de domicilio.')
    print('No se aprueban expedientes ni se habilita ningún PDF.')
    if not args.aplicar:
        print('SIMULACION_OK; sin escrituras. Para aplicar: --aplicar --limite 50')
        return
    seleccion = plan[:args.limite] if args.limite else plan
    cuenta = collections.Counter()
    for i in range(0, len(seleccion), BATCH):
        lote = seleccion[i:i+BATCH]
        out = php_run(PHP_APLICAR, lote)
        print(f'{i+len(lote)}/{len(seleccion)} {out}', flush=True)
        r = json.loads(out.split('LOTE_OK ',1)[1])
        cuenta.update(r)
    print('VINCULACION_CUENTAS_OK', json.dumps(dict(cuenta), ensure_ascii=False))
    print('Todos los expedientes afectados conservan estado PENDIENTE.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as exc:
        sys.exit('ERROR: '+str(exc))
