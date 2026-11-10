#!/usr/bin/env python3
"""GeI-Desarrollo. Inventaria, MUEVE e indexa TODOS los PDF históricos en PostgreSQL.

No toca Producción, Test, contratos COBOL, .xls, ni permisos de portal.
Los expedientes y documentos nuevos se dejan PENDIENTES hasta vinculación verificable.

Uso desde gei-desarrollo:
  python3 /tmp/gei_importar_pdfs_masivo.py --preparar
  python3 /tmp/gei_importar_pdfs_masivo.py --aplicar --limite 50
  python3 /tmp/gei_importar_pdfs_masivo.py --aplicar
  python3 /tmp/gei_importar_pdfs_masivo.py --verificar

Los manifiestos y el avance se guardan en /tmp con permisos 0600.
Al reejecutar --aplicar se retoma desde donde terminó el último lote confirmado.
"""
from __future__ import annotations
import argparse
import collections
import hashlib
import json
import os
import re
import socket
import subprocess
import sys
import unicodedata
from pathlib import Path, PurePosixPath

BASE_DEFAULT = Path.home() / "dockers/samba-server/privados/contratos"
PLAN_DEFAULT = Path('/tmp/gei-contratos-pdf-masivo-plan.json')
PILOTO = '2026/08/4332/1103-23906-03'
MESES = {
    'ene': '01', 'enero': '01', 'feb': '02', 'febrero': '02',
    'mar': '03', 'marzo': '03', 'abr': '04', 'abril': '04',
    'may': '05', 'mayo': '05', 'jun': '06', 'junio': '06',
    'jul': '07', 'julio': '07', 'ago': '08', 'agosto': '08',
    'sep': '09', 'set': '09', 'septiembre': '09', 'setiembre': '09',
    'oct': '10', 'octubre': '10', 'nov': '11', 'noviembre': '11',
    'dic': '12', 'diciembre': '12',
}


def sha(s: str) -> str:
    return hashlib.sha256(s.encode('utf-8')).hexdigest()


def sha_archivo(p: Path) -> str:
    h = hashlib.sha256()
    with p.open('rb') as f:
        for bloque in iter(lambda: f.read(2 * 1024 * 1024), b''):
            h.update(bloque)
    return h.hexdigest()


def tokens(s: str) -> list[str]:
    s = ''.join(c for c in unicodedata.normalize('NFKD', s.casefold()) if not unicodedata.combining(c))
    return re.findall(r'[a-z0-9]+', s)


def mes_de_ruta(partes: tuple[str, ...]) -> str | None:
    # El mes original viene en el NOMBRE, NO en el ordinal inicial de la carpeta.
    for carpeta in partes[1:-1]:
        for tok in tokens(carpeta):
            if tok in MESES:
                return MESES[tok]
    return None


def numero_de_carpeta(partes: tuple[str, ...]) -> str | None:
    # La primera carpeta es normalmente el MES: "1 ENERO..." NO es contrato N.º 1.
    hay_mes_primera = (len(partes) > 2 and
                       any(t in MESES for t in tokens(partes[1])))
    inicio = 2 if hay_mes_primera else 1
    for carpeta in reversed(partes[inicio:-1]):
        # "1 ER QUINCENA" o "2 DA QUINCENA" tampoco son números de contrato.
        if any(t.startswith('quincen') for t in tokens(carpeta)):
            continue
        m = re.match(r'^\s*0*(\d{1,8})(?!\d)(?:\s|[-_.]|$)', carpeta)
        if m:
            return str(int(m.group(1)))
    return None


def numero_de_pdf(stem: str) -> str | None:
    # Solo si el nombre del PDF ES un número, no si contiene fechas o IDs RELI.
    m = re.fullmatch(r'0*(\d{1,8})', stem.strip())
    return str(int(m.group(1))) if m else None


def tipo_pdf(nombre: str, numero: str | None) -> str:
    stem = Path(nombre).stem
    t = tokens(stem)
    if 'reli' in t or (t and t[0] == 'reli'):
        return 'RELI'
    if any(v.startswith('honor') for v in t):
        return 'HONOR'
    if any(v.startswith('autoriz') for v in t) or 'aut' in t:
        return 'AUT'
    if 'planilla' in t:
        return 'PLANILLA'
    if 'anexo' in t or 'adenda' in t:
        return 'ANEXO'
    if ('contrato' in t or 'locacion' in t or 'locativo' in t
            or (numero is not None and numero_de_pdf(stem) == numero)):
        return 'CONTRATO'
    return 'OTRO'


def archivos_pdf(base: Path) -> list[tuple[str, int]]:
    ret = []
    for carpeta, dirs, archivos in os.walk(base, followlinks=False):
        dirs[:] = sorted(d for d in dirs if not (Path(carpeta) / d).is_symlink())
        for nombre in sorted(archivos):
            if not nombre.casefold().endswith('.pdf'):
                continue
            p = Path(carpeta) / nombre
            if p.is_symlink() or not p.is_file():
                raise RuntimeError(f'PDF no regular / enlace simbólico: {p}')
            relativo = p.relative_to(base).as_posix()
            ret.append((relativo, p.stat().st_size))
    return sorted(ret, key=lambda t: t[0].casefold())


def construir_plan(archivos: list[tuple[str, int]]) -> dict:
    # '4332' ya está importado desde la planilla con claves de origen distintas.
    previos = [(r, n) for r, n in archivos if r.startswith(PILOTO + '/')]
    candidatos = [(r, n) for r, n in archivos if not r.startswith(PILOTO + '/')]
    grupos: dict[str, list[dict]] = collections.defaultdict(list)
    for rel, size in candidatos:
        partes = PurePosixPath(rel).parts
        anio = partes[0] if re.fullmatch(r'20\d{2}', partes[0]) else None
        mes = mes_de_ruta(partes) if anio else None
        numero = numero_de_carpeta(partes)
        con_carpeta_numerada = numero is not None
        if numero is None:
            numero = numero_de_pdf(PurePosixPath(rel).stem)
        origen_carpeta = '/'.join(partes[:-1])
        # Un PDF suelto recibe expediente propio; NO agrupar por casualidad otros PDF.
        grupo = ('CARPETA|' + origen_carpeta) if con_carpeta_numerada else ('ARCHIVO|' + rel)
        huella_grupo = sha(grupo)[:12]
        if anio and mes:
            raiz_destino = f'{anio}/{mes}/{numero or "_sin-numero"}/_pendiente-{huella_grupo}'
        else:
            raiz_destino = f'_pendientes/{anio or "sin-anio"}/{mes or "sin-mes"}/{numero or "sin-numero"}/_pendiente-{huella_grupo}'
        grupos[grupo].append({
            'origen': rel, 'bytes': size, 'numero': numero, 'anio': int(anio) if anio else None,
            'mes': int(mes) if mes else None, 'carpeta_origen': origen_carpeta,
            'grupo': grupo, 'destino_carpeta': raiz_destino,
            'tipo': tipo_pdf(partes[-1], numero),
            'exp_key': sha('GEI|EXPEDIENTE_PDF_MASIVO|' + grupo),
            # Igual fórmula de clave DOCUMENTO que el importador piloto.
            'doc_key': sha('GEI|DOCUMENTO|' + rel),
        })
    documentos = []
    for grupo, filas in sorted(grupos.items()):
        tipos = collections.Counter(f['tipo'] for f in filas)
        base_tipo = {'CONTRATO': 'contrato', 'AUT': 'aut', 'HONOR': 'honor',
                     'PLANILLA': 'planilla', 'RELI': 'reli', 'ANEXO': 'anexo', 'OTRO': 'otro'}
        for f in filas:
            nombre = base_tipo[f['tipo']]
            if tipos[f['tipo']] > 1 or f['tipo'] == 'OTRO':
                nombre += '-' + sha(f['origen'])[:10]
            f['destino'] = f['destino_carpeta'] + '/' + nombre + '.pdf'
            documentos.append(f)
    # Colisiones exactas y casefold: abortar, nunca renombrar/mezclar silenciosamente.
    destinos = [d['destino'].casefold() for d in documentos]
    if len(destinos) != len(set(destinos)):
        raise RuntimeError('Plan presenta colisiones de destino: abortado')
    documentos.sort(key=lambda f: (f['destino_carpeta'], f['origen']))
    resumen = {'pdf_encontrados': len(archivos), 'pdf_piloto_ya_normalizados': len(previos),
               'pdf_por_mover': len(documentos), 'expedientes_provisorios': len(grupos),
               'tipos': dict(collections.Counter(d['tipo'] for d in documentos)),
               'sin_mes': sum(d['mes'] is None for d in documentos),
               'sin_numero': sum(d['numero'] is None for d in documentos),
               'bytes_por_mover': sum(d['bytes'] for d in documentos)}
    return {'version': 1, 'piloto': PILOTO, 'resumen': resumen, 'documentos': documentos}


PHP_PRECHECK = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
if (config('app.env') !== 'local' ||
    (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
    DB::connection()->getDatabaseName() !== 'gei_db') {
    fwrite(STDERR, "ERROR: PostgreSQL no es Desarrollo autorizado\n"); exit(2);
}
foreach (['contratos_expedientes','contratos_documentos','contratos_planillas','contratos_planillas_filas'] as $t) {
    if (!Schema::hasTable($t)) { fwrite(STDERR, "FALTA $t\n"); exit(3); }
}
echo "PRECHECK_DB_OK\n";
'''

PHP_IMPORT = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
    if (config('app.env') !== 'local' ||
        (string)config('database.connections.pgsql.host') !== '192.168.50.13' ||
        DB::connection()->getDatabaseName() !== 'gei_db') {
        throw new RuntimeException('Destino PostgreSQL NO autorizado');
    }
    $lote = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $resultado = DB::transaction(function () use ($lote) {
        $expNuevos = 0; $docNuevos = 0; $docExistentes = 0;
        foreach ($lote as $d) {
            $e = DB::table('contratos_expedientes')->where('clave_origen', $d['exp_key'])->first();
            if (!$e) {
                $idExp = DB::table('contratos_expedientes')->insertGetId([
                    'clave_origen' => $d['exp_key'],
                    'numero_documental' => $d['numero'],
                    'anio_archivo' => $d['anio'], 'mes_archivo' => $d['mes'],
                    'ruta_carpeta_origen' => $d['carpeta_origen'],
                    'ruta_relativa_normalizada' => $d['destino_carpeta'],
                    'estado_vinculacion' => 'PENDIENTE',
                    'observaciones_revision' => 'PDF importado sin vínculo contractual ni cuenta COBOL confirmada. Revisar planillas y documento original.',
                    'importado_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $expNuevos++;
            } else {
                if ((string)($e->ruta_carpeta_origen ?? '') !== $d['carpeta_origen'] ||
                    (string)($e->ruta_relativa_normalizada ?? '') !== $d['destino_carpeta'] ||
                    (string)($e->numero_documental ?? '') !== (string)($d['numero'] ?? '')) {
                    throw new RuntimeException('Expediente con clave previa e identidad incompatible: '.$d['exp_key']);
                }
                $idExp = $e->id;
            }
            $doc = DB::table('contratos_documentos')->where('clave_origen', $d['doc_key'])->first();
            if (!$doc) {
                DB::table('contratos_documentos')->insert([
                    'expediente_id' => $idExp, 'clave_origen' => $d['doc_key'],
                    'tipo' => $d['tipo'], 'nombre_original' => basename($d['origen']),
                    'ruta_origen' => $d['origen'], 'ruta_privada' => $d['destino'],
                    'sha256' => $d['sha256'], 'tamano_bytes' => $d['bytes'],
                    'mime' => 'application/pdf', 'orden' => $d['orden'],
                    'visible_inquilino' => false, 'visible_propietario' => false,
                    'estado_verificacion' => 'PENDIENTE',
                    'metadatos' => json_encode(['importador'=>'gei_importar_pdfs_masivo.py',
                        'cabecera_pdf'=>$d['cabecera_pdf']], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $docNuevos++;
            } else {
                if ((int)$doc->expediente_id !== (int)$idExp ||
                    trim((string)$doc->sha256) !== $d['sha256'] ||
                    (string)$doc->ruta_privada !== $d['destino'] ||
                    (string)$doc->tipo !== $d['tipo']) {
                    throw new RuntimeException('Documento previo incompatible: '.$d['doc_key']);
                }
                $docExistentes++;
            }
        }
        return compact('expNuevos','docNuevos','docExistentes');
    });
    echo "LOTE_OK ".json_encode($resultado, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'LOTE_ERROR: '.$e->getMessage()."\n"); exit(1);
}
'''


PHP_CONTAR = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
if (config('app.env') !== 'local' ||
    (string)config('database.connections.pgsql.host') !== '192.168.50.13' ||
    DB::connection()->getDatabaseName() !== 'gei_db') exit(2);
$masivos = DB::table('contratos_documentos')
    ->whereRaw("metadatos ->> 'importador' = ?", ['gei_importar_pdfs_masivo.py'])
    ->count();
$total = DB::table('contratos_documentos')->count();
echo json_encode(['masivos'=>$masivos,'total_pdf_registrados'=>$total], JSON_THROW_ON_ERROR);
'''


def php_ejecutar(codigo: str, manifiesto: list[dict] | None = None) -> str:
    dato = (json.dumps(manifiesto, ensure_ascii=False).encode('utf8') if manifiesto is not None else None)
    p = subprocess.run(['docker', 'exec', '-i', 'gei-app', 'php', '-r', codigo],
                       input=dato, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stdout = p.stdout.decode('utf8', 'replace').strip()
    stderr = p.stderr.decode('utf8', 'replace').strip()
    if p.returncode:
        raise RuntimeError(f'Falló PHP/DB (código {p.returncode}): {stderr or stdout}')
    return stdout


def escribir_privado(p: Path, datos: dict):
    descriptor = os.open(p, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w', encoding='utf8') as out:
        json.dump(datos, out, ensure_ascii=False, indent=1)
        out.write('\n')


def consultar_progreso(p: Path, plan: dict) -> set[str]:
    completados = set()
    if not p.exists():
        return completados
    with p.open(encoding='utf8') as f:
        for linea in f:
            if not linea.strip():
                continue
            registro = json.loads(linea)
            if registro['version'] != 1 or registro['plan_sha'] != plan['plan_sha']:
                raise RuntimeError('Registro de progreso de otro manifiesto: abortado')
            completados.update(registro['docs'])
    return completados


def precondiciones(base: Path):
    if socket.gethostname().split('.')[0] != 'gei-desarrollo':
        raise RuntimeError(f'Ejecutar solo en gei-desarrollo, no en {socket.gethostname()}')
    if not base.is_dir():
        raise RuntimeError(f'No existe carpeta de contratos: {base}')
    if (base / PILOTO / 'contrato.pdf').is_file() is False:
        raise RuntimeError('No existe el piloto normalizado 4332; verificar directorio base')
    if php_ejecutar(PHP_PRECHECK).strip() != 'PRECHECK_DB_OK':
        raise RuntimeError('No se confirmó conexión a Desarrollo')


def verificar_rutas(base: Path, docs: list[dict]):
    errores = []
    for d in docs:
        src, dst = base / d['origen'], base / d['destino']
        src_ok, dst_ok = src.is_file(), dst.is_file()
        if (src_ok and dst_ok) or (not src_ok and not dst_ok) or src.is_symlink() or dst.is_symlink():
            errores.append((d['origen'], src_ok, dst_ok))
            if len(errores) == 8:
                break
        elif (src if src_ok else dst).stat().st_size != d['bytes']:
            errores.append((d['origen'], 'tamaño cambió', ''))
    if errores:
        raise RuntimeError('Archivos ausentes, duplicados o modificados. Ejemplos: '+repr(errores))


def aplicar(base: Path, plan: dict, progreso: Path, limite: int | None, tam_lote: int):
    precondiciones(base)
    docs = plan['documentos']
    completados = consultar_progreso(progreso, plan)
    pendientes = [d for d in docs if d['doc_key'] not in completados]
    if limite is not None:
        pendientes = pendientes[:limite]
    print(f"PLAN: {len(docs)} PDF | completados confirmados: {len(completados)} | esta ejecución: {len(pendientes)}", flush=True)
    # No se mueve nada si hay una colisión o falta algún archivo del lote solicitado.
    verificar_rutas(base, pendientes)
    moved, new_exps, new_docs = 0, 0, 0
    if not progreso.exists():
        fd = os.open(progreso, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        os.close(fd)
    for start in range(0, len(pendientes), tam_lote):
        paquete = []
        for n, d in enumerate(pendientes[start:start + tam_lote], 1):
            src, dst = base / d['origen'], base / d['destino']
            f = src if src.is_file() else dst
            if not f.is_file() or f.is_symlink():
                raise RuntimeError(f'Falta el PDF de la operación: {d["origen"]}')
            datos = d.copy()
            datos['sha256'] = sha_archivo(f)
            with f.open('rb') as stream:
                datos['cabecera_pdf'] = stream.read(5).startswith(b'%PDF-')
            datos['orden'] = 1
            if src.is_file():
                dst.parent.mkdir(parents=True, exist_ok=True)
                if dst.exists() or dst.is_symlink():
                    raise RuntimeError(f'Colisión en destino: {d["destino"]}')
                os.rename(src, dst)  # MISMO filesystem: no copia ni reemplazo
                moved += 1
                # El directorio original se borra SOLO si quedó vacío.
                try:
                    src.parent.rmdir()
                except OSError:
                    pass
            paquete.append(datos)
        # Si falla PostgreSQL: archivos YA movidos, reejecutar --aplicar (retoma por destino).
        salida = php_ejecutar(PHP_IMPORT, paquete)
        if not salida.startswith('LOTE_OK '):
            raise RuntimeError('Respuesta inesperada al confirmar lote: '+salida)
        resultado = json.loads(salida[len('LOTE_OK '):])
        new_exps += resultado['expNuevos']; new_docs += resultado['docNuevos']
        with progreso.open('a', encoding='utf8') as log:
            log.write(json.dumps({'version':1,'plan_sha':plan['plan_sha'],
                                  'docs':[d['doc_key'] for d in paquete]}, ensure_ascii=False)+'\n')
            log.flush()
            os.fsync(log.fileno())
        print(f"OK {start+len(paquete)}/{len(pendientes)} | movidos={moved} "
              f"| expedientes nuevos={new_exps} | documentos nuevos={new_docs}", flush=True)
    print('IMPORTACION_MASIVA_OK', json.dumps({'lotes_pdf':len(pendientes),
        'movidos':moved,'expedientes_nuevos':new_exps,'documentos_nuevos':new_docs,
        'total_confirmado_en_progreso':len(completados)+len(pendientes)}, ensure_ascii=False))


def verificar(base: Path, plan: dict, progreso: Path):
    precondiciones(base)
    completados = consultar_progreso(progreso, plan)
    lista = [d for d in plan['documentos'] if d['doc_key'] in completados]
    faltantes = [d['destino'] for d in lista if not (base / d['destino']).is_file()]
    siguen_en_origen = [d['origen'] for d in lista if (base / d['origen']).exists()]
    print('===== VERIFICACIÓN =====')
    print('PDF en el manifiesto:', len(plan['documentos']))
    print('PDF con lote confirmado:', len(completados))
    print('PDF pendientes de proceso:', len(plan['documentos'])-len(completados))
    print('Destinos faltantes:', len(faltantes))
    print('Archivos aún en origen, pese a estar confirmados:', len(siguen_en_origen))
    if faltantes or siguen_en_origen:
        print('Ejemplos:', repr((faltantes+siguen_en_origen)[:10]))
        raise RuntimeError('VERIFICACION_INCOMPLETA')
    print('VERIFICACION_FS_OK; los registros insertados se confirmaron por lote en PostgreSQL.')
    conteo = json.loads(php_ejecutar(PHP_CONTAR))
    print('PDF masivos registrados en PostgreSQL:', conteo['masivos'])
    print('Documentos PDF totales en PostgreSQL (incluido piloto):', conteo['total_pdf_registrados'])
    if conteo['masivos'] < len(completados):
        raise RuntimeError('PostgreSQL registra menos documentos masivos que el progreso confirmado')
    if len(completados) == len(plan['documentos']):
        print('TODOS_LOS_PDF_DEL_MANIFIESTO_PROCESADOS')


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument('--preparar', action='store_true')
    g.add_argument('--aplicar', action='store_true')
    g.add_argument('--verificar', action='store_true')
    ap.add_argument('--base', type=Path, default=BASE_DEFAULT)
    ap.add_argument('--plan', type=Path, default=PLAN_DEFAULT)
    ap.add_argument('--limite', type=int, default=None)
    ap.add_argument('--lote', type=int, default=80)
    a = ap.parse_args()
    base = a.base.resolve()
    plan_ruta = a.plan.absolute()
    progreso = plan_ruta.with_suffix('.progreso.jsonl')
    if a.limite is not None and (not a.aplicar or a.limite < 1):
        ap.error('--limite solo se permite con --aplicar y debe ser >0')
    if not (1 <= a.lote <= 500):
        ap.error('--lote debe estar entre 1 y 500')
    if a.preparar:
        precondiciones(base)
        if plan_ruta.exists() or progreso.exists():
            raise RuntimeError('Ya existe un plan/progreso: no se sobrescribe; continuar con --aplicar')
        inventario = archivos_pdf(base)
        # Si se perdió un plan anterior, nunca reclasificar resultados ya movidos.
        if any('_pendiente-' in PurePosixPath(r).parts or r.startswith('_pendientes/')
               for r, _ in inventario):
            raise RuntimeError('Se detectan carpetas de una importación masiva previa; recuperar el plan de /tmp antes de preparar otro')
        plan = construir_plan(inventario)
        plan['base'] = str(base)
        plan['plan_sha'] = sha(json.dumps(plan['documentos'], ensure_ascii=False, sort_keys=True))
        escribir_privado(plan_ruta, plan)
        print('PLAN_PREPARADO:', plan_ruta)
        print(json.dumps(plan['resumen'], ensure_ascii=False, indent=2))
        print('EJEMPLOS DE DESTINO:', [x['destino'] for x in plan['documentos'][:4]])
        print('No se movió ningún archivo ni se modificó PostgreSQL.')
        return
    if not plan_ruta.is_file():
        raise RuntimeError(f'No existe el plan: ejecutar primero --preparar ({plan_ruta})')
    plan = json.loads(plan_ruta.read_text(encoding='utf8'))
    if plan['base'] != str(base) or plan.get('version') != 1:
        raise RuntimeError('Plan incompatible con ubicación de contratos')
    if plan['plan_sha'] != sha(json.dumps(plan['documentos'], ensure_ascii=False, sort_keys=True)):
        raise RuntimeError('El plan fue modificado o está corrupto')
    if a.aplicar:
        aplicar(base, plan, progreso, a.limite, a.lote)
    else:
        verificar(base, plan, progreso)

if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError) as exc:
        sys.exit('ERROR: '+str(exc))
