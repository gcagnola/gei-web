<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Organización visual exclusivamente de lectura. No cambia ni infiere identidad maestra. */
final class ClienteMaestroInmueblesAlquiladosService
{
    private static function domicilioClave(?string $texto): string
    {
        $normal = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $texto);
        $normal = $normal === false ? (string) $texto : $normal;
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($normal)) ?? '';
    }

    private static function jsonArray(mixed $valor): array
    {
        if (is_array($valor)) return $valor;
        if (is_object($valor)) return (array) $valor;
        if (!is_string($valor) || $valor === '') return [];
        $r = json_decode($valor, true);
        return is_array($r) ? $r : [];
    }

    /** Lee el domicilio DOCUMENTAL de la fila Excel sin usar nombres. */
    private static function domicilioPlanilla(object $fila, ?object $planilla): string
    {
        $datos = self::jsonArray($fila->datos_excel_originales ?? null);
        if (trim((string)($datos['inmueble_domicilio'] ?? '')) !== '') {
            return trim((string)$datos['inmueble_domicilio']);
        }
        if (!$planilla) return '';
        $interpretados = self::jsonArray($planilla->datos_interpretados);
        if (trim((string)($interpretados['inmueble_domicilio'] ?? '')) !== '') {
            return trim((string)$interpretados['inmueble_domicilio']);
        }
        // En los formatos comprobados, la columna anterior a Celebración
        // corresponde al domicilio: 7/8 en formato normal, 6/7 desplazado.
        // Exigir que la fecha de celebración del expediente coincida con esa celda.
        $valores = [];
        foreach (self::jsonArray($planilla->celdas) as $celda) {
            if (isset($celda['columna'])) $valores[(int)$celda['columna']] = (string)($celda['valor'] ?? '');
        }
        $celebracion = (string)($fila->fecha_celebracion ?? '');
        if ($celebracion === '') return '';
        $direccionColumna = null;
        if (($valores[8] ?? '') === $celebracion) $direccionColumna = 7;
        elseif (($valores[7] ?? '') === $celebracion) $direccionColumna = 6;
        if ($direccionColumna === null) return '';
        return trim((string)($valores[$direccionColumna] ?? ''));
    }

    /** @return array{vigentes:Collection,anteriores:Collection,sinInmueble:Collection} */
    public function organizar(Collection $operativos, Collection $documentales): array
    {
        // Estos operativos ya han sido filtrados por cuentas autorizadas/sede
        // por ClienteMaestroRelacionesService.
        $op = $operativos->keyBy('id');
        $grupos = [];
        $porContrato = [];
        $ids = $op->keys()->all();
        if ($ids !== []) {
            $vinculos = DB::table('contratos_inmuebles as ci')
                ->join('inmuebles as i', 'i.id', '=', 'ci.inmueble_id')
                ->whereIn('ci.contrato_id', $ids)
                ->select('ci.contrato_id', 'i.id as inmueble_id', 'i.domicilio')
                ->get();
            foreach ($vinculos as $v) {
                $contrato = $op->get($v->contrato_id);
                if (!$contrato) continue;
                $id = (int)$v->inmueble_id;
                if (!isset($grupos[$id])) {
                    $grupos[$id] = (object)[
                        'inmueble_id' => $id,
                        'domicilio' => (string)$v->domicilio,
                        'cuentas' => collect(), 'propietarios' => collect(),
                        'operativos' => collect(), 'documentales' => collect(),
                        'vigentesDocumentales' => collect(), 'anterioresDocumentales' => collect(),
                        'vigente' => false,
                    ];
                }
                $g = $grupos[$id];
                $g->cuentas->push((string)$contrato->cuenta_inquilino);
                foreach (explode(' · ', (string)($contrato->propietarios ?? '')) as $p) {
                    if (trim($p) !== '') $g->propietarios->push(trim($p));
                }
                $g->operativos->put((int)$contrato->id, $contrato);
                $porContrato[(int)$contrato->id][] = $id;
            }
        }

        $hoy = now('America/Argentina/Cordoba')->toDateString();
        foreach ($grupos as $g) {
            $g->cuentas = $g->cuentas->unique()->values();
            $g->propietarios = $g->propietarios->unique()->values();
            $g->vigente = $g->operativos->contains(static fn ($c) =>
                $c->estado === 'ACTIVO' &&
                ($c->fecha_fin === null || (string)$c->fecha_fin >= $hoy));
        }

        $doc = $documentales->unique('id')->values();
        $filasIds = $doc->pluck('planilla_fila_id')->filter()->unique()->all();
        $filas = $filasIds === [] ? collect() : DB::table('contratos_planillas_filas')
            ->whereIn('id', $filasIds)
            ->get(['id','celdas','datos_interpretados'])->keyBy('id');

        $sin = collect();
        foreach ($doc as $exp) {
            $candidatos = [];
            $cuenta = (string)$exp->cuenta_inquilino;
            // Vinculación explícita con inmueble operativo de la MISMA cuenta.
            $idExp = (int)($exp->inmueble_id ?? 0);
            if ($idExp && isset($grupos[$idExp]) && $grupos[$idExp]->cuentas->contains($cuenta)) {
                $candidatos = [$idExp];
            } elseif ($exp->contrato_id && isset($porContrato[(int)$exp->contrato_id])) {
                $candidatos = array_values(array_filter($porContrato[(int)$exp->contrato_id],
                    static fn ($id) => $grupos[$id]->cuentas->contains($cuenta)));
            } else {
                $dom = self::domicilioPlanilla($exp, $filas->get($exp->planilla_fila_id));
                $clave = self::domicilioClave($dom);
                if ($clave !== '') {
                    foreach ($grupos as $id => $g) {
                        if ($g->cuentas->contains($cuenta) && self::domicilioClave($g->domicilio) === $clave) {
                            $candidatos[] = $id;
                        }
                    }
                }
            }
            $candidatos = array_unique($candidatos);
            if (count($candidatos) === 1) {
                $grupos[reset($candidatos)]->documentales->push($exp);
            } else {
                // No relacionar por cuenta sola, nombre, CUIT, fecha o dirección de GARANTE.TXT.
                $sin->push($exp);
            }
        }
        $vigentes = collect(); $anteriores = collect();
        foreach ($grupos as $g) {
            $g->documentales = $g->documentales->sortByDesc('fecha_inicio')->values();
            $g->vigentesDocumentales = $g->documentales->filter(static fn ($e) =>
                $e->fecha_inicio && $e->fecha_fin && $e->fecha_inicio <= $hoy && $e->fecha_fin >= $hoy)->values();
            $g->anterioresDocumentales = $g->documentales->reject(static fn ($e) =>
                $g->vigentesDocumentales->contains('id', $e->id))->values();
            if ($g->vigentesDocumentales->isNotEmpty()) $g->vigente = true;
            ($g->vigente ? $vigentes : $anteriores)->push($g);
        }
        return [
            'vigentes' => $vigentes->sortBy('domicilio')->values(),
            'anteriores' => $anteriores->sortBy('domicilio')->values(),
            'sinInmueble' => $sin->sortByDesc('fecha_inicio')->values(),
            'sinInmuebleVigentes' => $sin->filter(static fn ($e) =>
                $e->fecha_inicio && $e->fecha_fin && $e->fecha_inicio <= $hoy && $e->fecha_fin >= $hoy)->sortByDesc('fecha_inicio')->values(),
            'sinInmuebleAnteriores' => $sin->reject(static fn ($e) =>
                $e->fecha_inicio && $e->fecha_fin && $e->fecha_inicio <= $hoy && $e->fecha_fin >= $hoy)->sortByDesc('fecha_inicio')->values(),
        ];
    }
}
