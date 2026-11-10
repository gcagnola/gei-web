#!/usr/bin/env python3
"""Validación documental piloto 4332. Solo GeI-Desarrollo.

python3 /tmp/gei_validar_piloto_4332.py           # revisión sin cambios
python3 /tmp/gei_validar_piloto_4332.py --aplicar # habilita SOLO contrato.pdf

Nunca mueve/copia archivos ni altera contratos COBOL. Otros PDF quedan privados.
"""
import argparse
import subprocess
import sys

PHP = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

try {
    $aplicar = __APLICAR__;
    if (config('app.env') !== 'local'
        || (string) config('database.connections.pgsql.host') !== '192.168.50.13'
        || DB::connection()->getDatabaseName() !== 'gei_db') {
        throw new RuntimeException('DESTINO NO AUTORIZADO: no es GeI-Desarrollo');
    }
    $e = DB::table('contratos_expedientes')->where('id', 1)->first();
    if (!$e || (string)$e->numero_documental !== '4332'
        || (int)$e->contrato_id !== 13403
        || $e->cuenta_inquilino !== '11032390603'
        || $e->cuenta_propietario !== '12020912010'
        || $e->cuenta_inquilino_planilla !== '23906/03'
        || $e->cuit_propietario !== '20108633442'
        || (string)$e->fecha_celebracion !== '2026-08-03'
        || (string)$e->fecha_inicio !== '2026-09-01'
        || (string)$e->fecha_fin !== '2027-08-31') {
        throw new RuntimeException('El expediente 4332 no coincide con el piloto');
    }
    if (!in_array($e->estado_vinculacion, ['PENDIENTE', 'VALIDADO'], true)) {
        throw new RuntimeException('Estado de expediente inesperado');
    }
    $c = DB::table('contratos')->where('id', 13403)->first();
    if (!$c || $c->cuenta_inquilino !== $e->cuenta_inquilino
        || $c->cuenta_propietario !== $e->cuenta_propietario
        || (string)$c->fecha_celebracion !== $e->fecha_celebracion
        || (string)$c->fecha_fin !== $e->fecha_fin || $c->estado !== 'ACTIVO') {
        throw new RuntimeException('El contrato operativo no coincide (no se modifica COBOL)');
    }
    $ci = DB::table('clientes_cuentas')->where('id', $e->cliente_cuenta_inquilino_id)->first();
    if (!$ci || $ci->cuenta !== '11032390603' || $ci->rol !== 'INQUILINO'
        || (int)$ci->cliente_id !== 15710) {
        throw new RuntimeException('Cuenta de inquilino no validada con cliente 15710');
    }
    $cp = DB::table('clientes_cuentas')->where('id', $e->cliente_cuenta_propietario_id)->first();
    if (!$cp || $cp->cuenta !== '12020912010' || $cp->rol !== 'PROPIETARIO') {
        throw new RuntimeException('Cuenta de propietario sin vinculación verificada');
    }
    $prop = DB::table('clientes')->where('id', $cp->cliente_id)->first();
    if (!$prop) throw new RuntimeException('No existe el propietario vinculado');
    $cuitDb = preg_replace('/[^0-9]/', '', (string)($prop->cuit ?? ''));
    if ($cuitDb !== '' && $cuitDb !== '20108633442') {
        throw new RuntimeException("CUIT propietario incompatible con Excel: $cuitDb");
    }
    if ($cuitDb === '') {
        echo "ADVERTENCIA: propietario sin CUIT en clientes; solo coincidencia cuenta COBOL exacta.\n";
    }
    $inmueble = DB::table('contratos_inmuebles')
        ->where('contrato_id', 13403)->where('inmueble_id', $e->inmueble_id)->exists();
    if (!$e->inmueble_id || !$inmueble) {
        throw new RuntimeException('El inmueble no está vinculado de forma unívoca al contrato');
    }
    $fila = DB::table('contratos_planillas_filas')->where('id', $e->planilla_fila_id)->first();
    if (!$fila || $fila->nombre_hoja !== 'DETALLE' || (int)$fila->numero_fila !== 6) {
        throw new RuntimeException('Falta fila DETALLE/6 del Excel');
    }
    $celdas = json_decode($fila->celdas, true, 512, JSON_THROW_ON_ERROR);
    if (count($celdas) !== 20 || (int)($celdas[0]['valor'] ?? -1) !== 4332) {
        throw new RuntimeException('La fila original del Excel no coincide con expediente');
    }
    $p = DB::table('contratos_planillas')->where('id', $fila->planilla_id)->first();
    if (!$p || $p->nombre_original !== 'REG CONTR 1ra QUINC.AGO. 2026.xls') {
        throw new RuntimeException('Planilla original inesperada');
    }
    $base = realpath('/archivo-contratos');
    if ($base === false) throw new RuntimeException('Falta montaje privado en gei-app');
    $xls = realpath($base.'/'.$p->ruta_privada);
    if ($xls === false || !str_starts_with($xls, $base.'/') ||
        hash_file('sha256', $xls) !== trim($p->sha256)) {
        throw new RuntimeException('El Excel no está en el montaje o su SHA-256 difiere');
    }
    $docs = DB::table('contratos_documentos')->where('expediente_id', 1)->get();
    if ($docs->count() !== 4 || $docs->pluck('tipo')->unique()->count() !== 4) {
        throw new RuntimeException('Se esperaban exactamente 4 documentos distintos');
    }
    $esperados = ['CONTRATO'=>'contrato.pdf','AUT'=>'aut.pdf',
                  'HONOR'=>'honor.pdf','PLANILLA'=>'planilla.pdf'];
    foreach ($docs as $doc) {
        $esperado = $esperados[$doc->tipo] ?? null;
        if (!$esperado || $doc->ruta_privada !== '2026/08/4332/1103-23906-03/'.$esperado) {
            throw new RuntimeException("Ruta o tipo inesperado en documento {$doc->id}");
        }
        if (!in_array($doc->estado_verificacion, ['PENDIENTE','VERIFICADO'], true)) {
            throw new RuntimeException("Estado inesperado en documento {$doc->id}");
        }
        $f = realpath($base.'/'.$doc->ruta_privada);
        if ($f === false || !str_starts_with($f, $base.'/')
            || !is_file($f) || !is_readable($f)
            || (int)filesize($f) !== (int)$doc->tamano_bytes
            || hash_file('sha256', $f) !== trim($doc->sha256)) {
            throw new RuntimeException("PDF faltante o hash distinto: {$doc->ruta_privada}");
        }
        echo "PDF_OK {$doc->tipo}: {$doc->ruta_privada}\n";
    }
    echo "CUENTAS_OK Inquilino=11032390603 Propietario=12020912010\n";
    echo "EXCEL_OK DETALLE/6, 20 columnas\n";
    echo "INMUEBLE_OK ID={$e->inmueble_id}\n";
    if (!$aplicar) {
        echo "VALIDACION_LISTA (sin cambios). Repetir con --aplicar para habilitar contrato.pdf.\n";
        exit(0);
    }
    DB::transaction(function() use ($e) {
        DB::table('contratos_expedientes')->where('id', 1)
          ->update(['estado_vinculacion'=>'VALIDADO', 'validado_at'=>now(), 'updated_at'=>now()]);
        DB::table('contratos_documentos')->where('expediente_id', 1)
          ->whereIn('tipo',['CONTRATO','AUT','HONOR','PLANILLA'])
          ->update(['estado_verificacion'=>'VERIFICADO', 'updated_at'=>now()]);
        // Solamente el PDF firmado se publica en el módulo interno del inquilino.
        // AUT/HONOR/PLANILLA quedan invisibles, y Autogestión no tiene ruta aquí.
        DB::table('contratos_documentos')->where('expediente_id', 1)
          ->update(['visible_inquilino'=>false,'visible_propietario'=>false,'updated_at'=>now()]);
        DB::table('contratos_documentos')->where('expediente_id', 1)
          ->where('tipo','CONTRATO')->update(['visible_inquilino'=>true,'updated_at'=>now()]);
    });
    echo "PILOTO_4332_VALIDADO (solo contrato.pdf visible en ficha interna INQUILINO)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "VALIDACION_ERROR: ".$e->getMessage()."\n");
    exit(1);
}
'''


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--aplicar', action='store_true')
    args = ap.parse_args()
    script = PHP.replace('__APLICAR__', 'true' if args.aplicar else 'false')
    result = subprocess.run(['docker','exec','gei-app','php','-r',script], text=True, capture_output=True)
    if result.stdout:
        print(result.stdout, end='')
    if result.stderr:
        print(result.stderr, file=sys.stderr, end='')
    sys.exit(result.returncode)


if __name__ == '__main__':
    main()
