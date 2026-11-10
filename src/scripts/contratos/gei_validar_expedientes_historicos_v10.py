#!/usr/bin/env python3
"""GeI-Desarrollo: valida expedientes históricos por cuenta COBOL y hashes PDF.

Reutiliza las 2.894 candidaturas V9 cotejadas contra XLS. No consulta nombres,
no cambia contratos COBOL y no modifica ni mueve archivos.

Uso:
 python3 /tmp/gei_validar_expedientes_historicos_v10.py                 # auditar todo, sin escrituras
 python3 /tmp/gei_validar_expedientes_historicos_v10.py --aplicar --limite 50
 python3 /tmp/gei_validar_expedientes_historicos_v10.py --verificar --limite 50
 python3 /tmp/gei_validar_expedientes_historicos_v10.py --aplicar
 python3 /tmp/gei_validar_expedientes_historicos_v10.py --verificar

--aplicar solo cambia estado_vinculacion a VALIDADO y estado_verificacion de PDF
existentes a VERIFICADO. NO toca visible_inquilino/visible_propietario (Autogestión).
Reanudable e idempotente; transacciones de 50 expedientes.
"""
from __future__ import annotations

import argparse
import collections
import importlib.util
import json
import socket
import subprocess
from pathlib import Path

ORIGEN_V9 = Path('/tmp/gei_aplicar_fechas_documentales_v9.py')
BATCH = 50

PHP = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
    if (config('app.env') !== 'local' ||
        (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
        DB::connection()->getDatabaseName() !== 'gei_db') {
        throw new RuntimeException('Destino prohibido: no es GeI-Desarrollo');
    }
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $modo = $input['modo'] ?? null;
    $filas = $input['filas'] ?? null;
    if (!in_array($modo, ['auditar', 'aplicar', 'verificar'], true) ||
        !is_array($filas) || count($filas) < 1 || count($filas) > 50) {
        throw new RuntimeException('Modo o lote inválido');
    }
    $accion = function () use ($filas, $modo): array {
        $r = ['aptos' => 0, 'nuevos' => 0, 'ya_validados' => 0,
              'pendientes' => 0, 'pdf_verificados' => 0];
        $base = realpath('/archivo-contratos');
        if (!$base || !is_dir($base)) throw new RuntimeException('Falta montaje de contratos');
        foreach ($filas as $dato) {
            $id = (int) ($dato['id'] ?? 0);
            if (!$id) throw new RuntimeException('ID de expediente inválido');
            $consulta = DB::table('contratos_expedientes')->where('id', $id);
            if ($modo === 'aplicar') $consulta->lockForUpdate();
            $e = $consulta->first();
            if (!$e || !in_array($e->estado_vinculacion, ['PENDIENTE','VALIDADO'], true)) {
                throw new RuntimeException('Estado inválido exp '.$id);
            }
            foreach (['celebracion'=>'fecha_celebracion','inicio'=>'fecha_inicio','fin'=>'fecha_fin'] as $p=>$c) {
                if (substr((string)$e->$c, 0, 10) !== (string)$dato[$p]) {
                    throw new RuntimeException('Fecha distinta en exp '.$id.' campo '.$c);
                }
            }
            if ((string)$e->cuenta_inquilino !== (string)$dato['cuenta'] ||
                (string)$e->numero_documental !== (string)$dato['numero'] ||
                (int)$e->planilla_fila_id !== (int)$dato['fila_id'] ||
                !$e->cliente_cuenta_inquilino_id) {
                throw new RuntimeException('Cuenta o vínculo XLS distintos exp '.$id);
            }
            $cuenta = (string)$e->cuenta_inquilino;
            if (!preg_match('/^(1103|2103)[0-9]{7}$/D', $cuenta)) {
                throw new RuntimeException('Cuenta COBOL malformada exp '.$id);
            }
            $cc = DB::table('clientes_cuentas')->where('id', $e->cliente_cuenta_inquilino_id)->first();
            if (!$cc || $cc->rol !== 'INQUILINO' || (string)$cc->cuenta !== $cuenta) {
                throw new RuntimeException('Cuenta maestra incompatible exp '.$id);
            }
            $f = DB::table('contratos_planillas_filas')->where('id', $e->planilla_fila_id)->first();
            if (!$f || strtoupper(trim($f->nombre_hoja)) !== 'DETALLE' ||
                (int)$f->numero_fila !== (int)$dato['fila_excel']) {
                throw new RuntimeException('Fila DETALLE incompatible exp '.$id);
            }
            $plan = DB::table('contratos_planillas')->where('id', $f->planilla_id)->first();
            if (!$plan || (string)$plan->ruta_origen !== (string)$dato['excel_origen']) {
                throw new RuntimeException('Planilla incompatible exp '.$id);
            }
            $celdas = is_string($f->celdas)
                ? json_decode($f->celdas, true, 512, JSON_THROW_ON_ERROR) : $f->celdas;
            if (!is_array($celdas) || !is_numeric($celdas[0]['valor'] ?? null) ||
                (ltrim((string)(int)$celdas[0]['valor'], '0') ?: '0') !==
                (ltrim((string)$e->numero_documental, '0') ?: '0')) {
                throw new RuntimeException('Número Excel incompatible exp '.$id);
            }
            if ($e->contrato_id !== null) {
                $co = DB::table('contratos')->where('id', $e->contrato_id)->first();
                if (!$co || (string)$co->cuenta_inquilino !== $cuenta) {
                    throw new RuntimeException('Contrato COBOL incompatible exp '.$id);
                }
            }
            if ($e->contrato_id !== null && $e->inmueble_id !== null &&
                !DB::table('contratos_inmuebles')->where('contrato_id', $e->contrato_id)
                   ->where('inmueble_id', $e->inmueble_id)->exists()) {
                throw new RuntimeException('Inmueble incompatible exp '.$id);
            }
            $docs = DB::table('contratos_documentos')->where('expediente_id', $id)
                ->orderBy('id')->get();
            if ($docs->where('tipo', 'CONTRATO')->count() !== 1) {
                throw new RuntimeException('PDF principal no único exp '.$id);
            }
            foreach ($docs as $doc) {
                if ((string)$doc->mime !== 'application/pdf' ||
                    !in_array($doc->estado_verificacion, ['PENDIENTE','VERIFICADO'], true)) {
                    throw new RuntimeException('Tipo/estado PDF incorrecto '.$doc->id);
                }
                $rel = str_replace('\\', '/', (string)$doc->ruta_privada);
                if ($rel === '' || str_starts_with($rel,'/') || strpos($rel,"\0") !== false ||
                    preg_match('~(^|/)\.\.?(/|$)~', $rel) ||
                    strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'pdf') {
                    throw new RuntimeException('Ruta insegura doc '.$doc->id);
                }
                $ruta = realpath($base.'/'.$rel);
                if ($ruta === false || !str_starts_with($ruta, $base.'/') ||
                    !is_file($ruta) || !is_readable($ruta) ||
                    (int)filesize($ruta) !== (int)$doc->tamano_bytes ||
                    !hash_equals(trim((string)$doc->sha256), hash_file('sha256', $ruta))) {
                    throw new RuntimeException('PDF falta o no coincide SHA256 doc '.$doc->id);
                }
                $fh = fopen($ruta, 'rb');
                if (!$fh) throw new RuntimeException('No se puede abrir doc '.$doc->id);
                $firma = fread($fh, 5); fclose($fh);
                if ($firma !== '%PDF-') throw new RuntimeException('Cabecera no PDF doc '.$doc->id);
            }
            $r['aptos']++;
            $r['pdf_verificados'] += count($docs);
            $docsPendientes = $docs->where('estado_verificacion', 'PENDIENTE')->pluck('id')->all();
            if ($e->estado_vinculacion === 'VALIDADO' && !$docsPendientes) {
                $r['ya_validados']++;
            } elseif ($modo === 'aplicar') {
                DB::table('contratos_expedientes')->where('id', $id)->update([
                    'estado_vinculacion' => 'VALIDADO', 'validado_at' => $e->validado_at ?: now(),
                    'updated_at' => now(),
                ]);
                if ($docsPendientes) {
                    DB::table('contratos_documentos')->whereIn('id', $docsPendientes)->update([
                        'estado_verificacion' => 'VERIFICADO', 'updated_at' => now(),
                    ]);
                }
                $r['nuevos']++;
            } else {
                $r['pendientes']++;
            }
        }
        return $r;
    };
    $res = $modo === 'aplicar' ? DB::transaction($accion) : $accion();
    echo 'LOTE_OK '.json_encode($res, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exc) {
    fwrite(STDERR, 'LOTE_ERROR '.$exc->getMessage()."\n");
    exit(1);
}
'''


def plan_v9() -> list[dict]:
    if not ORIGEN_V9.is_file():
        raise RuntimeError('No se encontró V9 en /tmp; no reconstruir candidaturas de forma distinta')
    spec = importlib.util.spec_from_file_location('gei_fechas_v9', ORIGEN_V9)
    if spec is None or spec.loader is None:
        raise RuntimeError('No se pudo cargar V9')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    plan, excluidos = mod.planificar()
    if len(plan) != 2894 or excluidos:
        raise RuntimeError('Las 2894 candidaturas V9 cambiaron o quedaron excluidas')
    return plan


def php_lote(modo: str, filas: list[dict]) -> dict:
    p = subprocess.run(['docker', 'exec', '-i', 'gei-app', 'php', '-r', PHP],
                       input=json.dumps({'modo': modo, 'filas': filas}, ensure_ascii=False),
                       capture_output=True, text=True, encoding='utf-8', errors='replace')
    if p.returncode or not p.stdout.strip().startswith('LOTE_OK '):
        raise RuntimeError((p.stderr or p.stdout or 'Fallo desconocido en PHP')[:1000])
    return json.loads(p.stdout.strip()[len('LOTE_OK '):])


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--aplicar', action='store_true')
    parser.add_argument('--verificar', action='store_true')
    parser.add_argument('--limite', type=int, default=0)
    args = parser.parse_args()
    if args.aplicar and args.verificar:
        parser.error('Usar --aplicar O --verificar')
    if args.limite < 0:
        parser.error('El límite debe ser mayor o igual a cero')
    if socket.gethostname().split('.')[0].lower() != 'gei-desarrollo':
        raise RuntimeError('Ejecutar exclusivamente en gei-desarrollo')
    plan = plan_v9()
    seleccion = plan[:args.limite] if args.limite else plan
    modo = 'aplicar' if args.aplicar else ('verificar' if args.verificar else 'auditar')
    print('===== VALIDACIÓN DE EXPEDIENTES V10 =====', flush=True)
    print('Total cotejado contra V9:', len(plan), '| En esta ejecución:', len(seleccion), flush=True)
    print('Criterio de identidad: cuenta COBOL; sin comparación de nombres.', flush=True)
    print('Verifica SHA-256 y cabecera de TODOS los PDF de cada expediente.', flush=True)
    print('Visibilidad de Autogestión sin cambios; no mueve archivos.', flush=True)
    acumulado = collections.Counter()
    for i in range(0, len(seleccion), BATCH):
        lote = seleccion[i:i+BATCH]
        try:
            r = php_lote(modo, lote)
        except Exception as exc:
            raise RuntimeError(f'Error en lote {i + 1}..{i + len(lote)}, '
                               f'expedientes {[x["id"] for x in lote]}: {exc}') from exc
        acumulado.update(r)
        if args.aplicar or (i + len(lote)) % 500 == 0 or i + len(lote) == len(seleccion):
            print(f'{i + len(lote)}/{len(seleccion)} LOTE_OK {json.dumps(r)}', flush=True)
    if modo == 'verificar' and acumulado['pendientes']:
        raise RuntimeError('Verificación: aún existen expedientes pendientes')
    print(('VALIDACION_HISTORICA_OK' if args.aplicar else
           'VERIFICACION_HISTORICA_OK' if args.verificar else 'AUDITORIA_HISTORICA_OK'),
          json.dumps(dict(acumulado), ensure_ascii=False), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        raise SystemExit('ERROR: ' + str(e))
