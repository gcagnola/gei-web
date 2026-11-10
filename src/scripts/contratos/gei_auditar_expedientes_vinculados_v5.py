#!/usr/bin/env python3
"""Auditoría de solo lectura de los expedientes pendientes ya vinculados por cuenta COBOL.

Uso en gei-desarrollo:
    python3 /tmp/gei_auditar_expedientes_vinculados_v5.py

No modifica PostgreSQL, documentos, estados ni permisos. Ignora los nombres.
Produce /tmp/gei-auditoria-expedientes-vinculados-v5.csv
"""
from __future__ import annotations
import collections
import csv
import json
import re
import socket
import subprocess
import sys
from pathlib import Path

SALIDA = Path('/tmp/gei-auditoria-expedientes-vinculados-v5.csv')
PHP = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
if (config('app.env') !== 'local' ||
    (string)config('database.connections.pgsql.host') !== '192.168.50.13' ||
    DB::connection()->getDatabaseName() !== 'gei_db') {
    fwrite(STDERR, "DESTINO INCORRECTO: SOLO DESARROLLO\n"); exit(2);
}
echo json_encode(['tipo'=>'CONTROL','total'=>DB::table('contratos_expedientes')
    ->where('estado_vinculacion','PENDIENTE')
    ->whereNotNull('cliente_cuenta_inquilino_id')->count()], JSON_THROW_ON_ERROR)."\n";
$ultimo = 0;
do {
    $filas = DB::table('contratos_expedientes as e')
        ->join('clientes_cuentas as cc','cc.id','=','e.cliente_cuenta_inquilino_id')
        ->join('contratos_planillas_filas as f','f.id','=','e.planilla_fila_id')
        ->join('contratos_planillas as p','p.id','=','f.planilla_id')
        ->where('e.estado_vinculacion','PENDIENTE')
        ->whereNotNull('e.cliente_cuenta_inquilino_id')
        ->where('e.id','>',$ultimo)
        ->select('e.id','e.numero_documental','e.anio_archivo','e.mes_archivo',
            'e.cuenta_inquilino','e.cuenta_inquilino_planilla',
            'e.cliente_cuenta_inquilino_id','e.planilla_fila_id',
            'e.fecha_celebracion','e.fecha_inicio','e.fecha_fin',
            'e.contrato_id','e.inmueble_id','e.ruta_relativa_normalizada',
            'cc.cuenta as cuenta_maestra','cc.rol as rol_maestro',
            'f.celdas','f.nombre_hoja','f.numero_fila',
            'p.ruta_origen as planilla_ruta')
        ->orderBy('e.id')->limit(250)->get();
    if ($filas->isEmpty()) break;
    $ids = $filas->pluck('id')->all();
    $docs = DB::table('contratos_documentos')
        ->whereIn('expediente_id',$ids)
        ->select('id','expediente_id','tipo','estado_verificacion','visible_inquilino',
            'ruta_privada','sha256','tamano_bytes')
        ->orderBy('id')->get()->groupBy('expediente_id');
    foreach ($filas as $f) {
        $x = (array)$f;
        $x['documentos'] = $docs->get($f->id, collect())->map(fn($d)=>(array)$d)->all();
        echo json_encode(['tipo'=>'EXP','dato'=>$x], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
    }
    $ultimo = (int)$filas->last()->id;
} while (true);
'''


def main() -> None:
    if socket.gethostname().split('.')[0].lower() != 'gei-desarrollo':
        sys.exit('ERROR: ejecutar únicamente en gei-desarrollo')
    proceso = subprocess.run(['docker','exec','gei-app','php','-r',PHP],
                             capture_output=True, text=True, encoding='utf-8', errors='replace')
    if proceso.returncode:
        sys.exit('ERROR LECTURA: '+(proceso.stderr or proceso.stdout)[:1200])
    registros, total_control = [], None
    for linea in proceso.stdout.splitlines():
        d = json.loads(linea)
        if d['tipo'] == 'CONTROL':
            total_control = int(d['total'])
        elif d['tipo'] == 'EXP':
            registros.append(d['dato'])
        else:
            raise RuntimeError('Respuesta inesperada de PHP')
    if total_control is None or total_control != len(registros):
        sys.exit(f'ERROR: {len(registros)} filas recuperadas, se esperaban {total_control}. No generar diagnóstico parcial.')
    problemas = collections.Counter()
    tipos = collections.Counter()
    tipos_por_caso = collections.Counter()
    fechas_por_formato = collections.Counter()
    casos_con_fechas = 0
    agrupado = collections.Counter()
    ejemplos = collections.defaultdict(list)
    filas_csv = []
    for e in registros:
        id_exp = int(e['id'])
        flag = []
        if e['rol_maestro'] != 'INQUILINO' or e['cuenta_inquilino'] != e['cuenta_maestra']:
            flag.append('CUENTA_MAESTRA_INCOMPATIBLE')
        if str(e['nombre_hoja']).strip().upper() != 'DETALLE':
            flag.append('FILA_NO_DETALLE')
        try:
            celdas = json.loads(e['celdas']) if isinstance(e['celdas'],str) else e['celdas']
            assert isinstance(celdas,list)
        except Exception:
            celdas = []
            flag.append('CELDAS_NO_LEGIBLES')
        fechas = []
        for c in celdas:
            if not isinstance(c,dict):
                continue
            s = str(c.get('valor') or '')
            if (int(c.get('tipo_xlrd') or 0) == 3 and
                re.fullmatch(r'(19|20)\d{2}-\d{2}-\d{2}(?:T.*)?',s)):
                col = int(c.get('columna') or 0)
                fechas.append(f'{col}:{s[:10]}')
                fechas_por_formato[(int(e['anio_archivo'] or 0),len(celdas),col)] += 1
        if fechas:
            casos_con_fechas += 1
        else:
            flag.append('SIN_FECHAS_XLS_DETECTABLES')
        documentos = e['documentos']
        t = [str(d['tipo']) for d in documentos]
        if not documentos:
            flag.append('SIN_DOCUMENTOS')
        if 'CONTRATO' not in t:
            flag.append('SIN_PDF_CONTRATO')
        if t.count('CONTRATO') > 1:
            flag.append('VARIOS_PDF_CONTRATO')
        if any(d['estado_verificacion'] == 'VERIFICADO' or d['visible_inquilino'] for d in documentos):
            flag.append('PDF_PREVIAMENTE_HABILITADO')
        if not e['fecha_inicio'] or not e['fecha_fin']:
            flag.append('VIGENCIA_DOCUMENTAL_INCOMPLETA')
        for tipo in t:
            tipos[tipo] += 1
        for tipo in set(t):
            tipos_por_caso[tipo] += 1
        agrupado[int(e['anio_archivo'] or 0)] += 1
        for f in flag:
            problemas[f] += 1
            if len(ejemplos[f]) < 5:
                ejemplos[f].append(id_exp)
        filas_csv.append({
            'expediente_id':id_exp,'numero':e['numero_documental'],
            'anio':e['anio_archivo'],'mes':e['mes_archivo'],
            'cuenta_cobol':e['cuenta_inquilino'],'cuenta_planilla':e['cuenta_inquilino_planilla'],
            'fila_excel_id':e['planilla_fila_id'],'excel':e['planilla_ruta'],
            'fecha_inicio_bd':e['fecha_inicio'],'fecha_fin_bd':e['fecha_fin'],
            'fechas_excel_columna_valor':' | '.join(fechas),
            'pdf_tipos':' | '.join(sorted(t)),
            'documentos_cantidad':len(documentos),
            'flags':' | '.join(flag),
        })
    campos = list(filas_csv[0]) if filas_csv else [
        'expediente_id','numero','anio','mes','cuenta_cobol','cuenta_planilla','fila_excel_id','excel',
        'fecha_inicio_bd','fecha_fin_bd','fechas_excel_columna_valor','pdf_tipos','documentos_cantidad','flags']
    with SALIDA.open('w',newline='',encoding='utf-8-sig') as archivo:
        wr = csv.DictWriter(archivo,fieldnames=campos)
        wr.writeheader()
        wr.writerows(filas_csv)
    print('===== AUDITORÍA DOCUMENTAL V5 — SOLO LECTURA =====')
    print('Expedientes pendientes con cuenta COBOL vinculada:',len(registros))
    print('Por año:',dict(sorted(agrupado.items())))
    print('Expedientes con PDF por tipo:',dict(sorted(tipos_por_caso.items())))
    print('PDF totales por tipo:',dict(sorted(tipos.items())))
    print('Filas con al menos una fecha XLS tipificada:',casos_con_fechas)
    print('===== CONTROLES =====')
    for categoria,n in problemas.most_common():
        print(f'{categoria}: {n}; ejemplos {ejemplos[categoria]}')
    print('===== COLUMNAS DE FECHA XLS (año, cantidad de columnas, columna de fecha) =====')
    for (anio,ncols,col),n in fechas_por_formato.most_common(25):
        print(f'{anio} | {ncols} columnas | fecha en columna {col}: {n} filas')
    print('CSV:',SALIDA)
    print('AUDITORIA_SOLO_LECTURA_OK; NO SE MODIFICARON TABLAS, ARCHIVOS NI PERMISOS')

if __name__ == '__main__':
    main()
