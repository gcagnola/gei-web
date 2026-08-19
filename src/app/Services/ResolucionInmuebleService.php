<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resuelve identidades históricas hacia un inmueble canónico.
 *
 * Regla: una evidencia puede sugerir candidatos, pero sólo una identidad de
 * origen previamente registrada (p.ej. COBOL/INQUILINO/cuenta) o una decisión
 * humana resuelven automáticamente. La clave derivada, partidas, cuenta de
 * propietario y domicilio sólo sirven para detectar candidatos/conflictos.
 */
final class ResolucionInmuebleService
{
    /** @return array{estado:string,inmueble_id:?int,candidatos:list<int>,evidencias:array<string,mixed>} */
    public function resolver(array $datos): array
    {
        $sistema = trim((string) ($datos['sistema_origen'] ?? 'COBOL'));
        $entidad = trim((string) ($datos['entidad_origen'] ?? ''));
        $claveOrigen = trim((string) ($datos['clave_origen'] ?? $datos['cuenta_inquilino'] ?? ''));
        $claveMigracion = trim((string) ($datos['clave_migracion'] ?? $datos['clave_inmueble'] ?? ''));
        $partidas = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            (array) ($datos['partidas'] ?? [])
        ))));
        $cuentaPropietario = trim((string) ($datos['cuenta_propietario'] ?? ''));
        $domicilio = trim((string) ($datos['domicilio_normalizado'] ?? $datos['direccion_normalizada'] ?? ''));

        if ($entidad !== '' && $claveOrigen !== '') {
            $origen = DB::table('inmuebles_origenes')
                ->where('sistema_origen', $sistema)
                ->where('entidad_origen', $entidad)
                ->where('clave_origen', $claveOrigen)
                ->first();

            if ($origen !== null) {
                $id = $this->resolverCanonicoId((int) $origen->inmueble_id);

                return [
                    'estado' => 'RESUELTO',
                    'inmueble_id' => $id,
                    'candidatos' => [$id],
                    'evidencias' => ['regla' => 'ORIGEN_EXACTO'],
                ];
            }
        }

        if ($entidad !== '' && $claveOrigen !== '') {
            $resolucion = DB::table('inmuebles_resoluciones_origen')
                ->where('sistema_origen', $sistema)
                ->where('entidad_origen', $entidad)
                ->where('clave_origen', $claveOrigen)
                ->first();
            if ($resolucion !== null && $resolucion->decision === 'ASOCIAR_EXISTENTE') {
                $id = $this->resolverCanonicoId((int) $resolucion->inmueble_id);

                return [
                    'estado' => 'RESUELTO',
                    'inmueble_id' => $id,
                    'candidatos' => [$id],
                    'evidencias' => ['regla' => 'RESOLUCION_MANUAL_ORIGEN'],
                ];
            }
            if ($resolucion !== null && $resolucion->decision === 'CREAR_SEPARADO') {
                return [
                    'estado' => 'NO_ENCONTRADO',
                    'inmueble_id' => null,
                    'candidatos' => [],
                    'evidencias' => ['regla' => 'RESOLUCION_MANUAL_CREAR_SEPARADO'],
                ];
            }
        }

        $candidatos = [];
        $evidencias = [];

        if ($claveMigracion !== '') {
            $ids = DB::table('inmuebles')
                ->where('clave_migracion', $claveMigracion)
                ->pluck('id')
                ->map(fn ($id): int => $this->resolverCanonicoId((int) $id))
                ->unique()->values()->all();
            foreach ($ids as $id) {
                $candidatos[$id] = true;
            }
            $evidencias['candidatos_por_clave_migracion'] = $ids;
            $evidencias['nota_clave_migracion'] = 'Es cuenta_propietario + domicilio normalizado: evidencia, no identidad.';
        }

        if ($partidas !== []) {
            $ids = DB::table('inmuebles_partidas')
                ->whereIn('partida', $partidas)
                ->whereNull('vigencia_hasta')
                ->pluck('inmueble_id')
                ->map(fn ($id): int => $this->resolverCanonicoId((int) $id))
                ->unique()->values()->all();
            foreach ($ids as $id) {
                $candidatos[$id] = true;
            }
            $evidencias['partidas'] = $partidas;
            $evidencias['candidatos_por_partida'] = $ids;
        }

        if ($cuentaPropietario !== '') {
            $ids = DB::table('inmuebles_origenes')
                ->where('cuenta_propietario', $cuentaPropietario)
                ->pluck('inmueble_id')
                ->map(fn ($id): int => $this->resolverCanonicoId((int) $id))
                ->unique()->values()->all();
            $evidencias['candidatos_por_cuenta_propietario'] = $ids;
            // La cuenta de propietario NO identifica un inmueble por sí sola.
        }

        if ($domicilio !== '') {
            $ids = DB::table('inmuebles')
                ->where('domicilio_normalizado', $domicilio)
                ->pluck('id')
                ->map(fn ($id): int => $this->resolverCanonicoId((int) $id))
                ->unique()->values()->all();
            $evidencias['candidatos_por_domicilio'] = $ids;
        }

        $ids = array_map('intval', array_keys($candidatos));
        sort($ids, SORT_NUMERIC);

        return [
            'estado' => $ids === [] ? 'NO_ENCONTRADO' : 'REQUIERE_REVISION',
            'inmueble_id' => null,
            'candidatos' => $ids,
            'evidencias' => $evidencias,
        ];
    }

    public function resolverCanonicoId(int $id): int
    {
        $visitados = [];
        $actual = $id;

        while (true) {
            if (isset($visitados[$actual])) {
                throw new RuntimeException('Se detectó un ciclo en la canonicalización de inmuebles.');
            }
            $visitados[$actual] = true;

            $fila = DB::table('inmuebles')->where('id', $actual)->first(['id', 'id_inmueble_canonico']);
            if ($fila === null) {
                return $id;
            }
            if ($fila->id_inmueble_canonico === null) {
                return (int) $fila->id;
            }

            $actual = (int) $fila->id_inmueble_canonico;
        }
    }
}
