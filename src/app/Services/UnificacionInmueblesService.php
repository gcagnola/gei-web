<?php

namespace App\Services;

use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UnificacionInmueblesService
{
    private const TIPO = 'INMUEBLE';

    private const FKS_ESPERADAS = [
        'contratos_inmuebles',
        'inmuebles', // id_inmueble_canonico
        'inmuebles_conflictos',
        'inmuebles_origenes',
        'inmuebles_partidas',
        'inmuebles_propietarios',
        'inmuebles_resoluciones_origen',
    ];

    public function buscar(string $texto = '', bool $incluirInactivos = false): Collection
    {
        $texto = trim($texto);

        $consulta = DB::table('inmuebles as i')
            ->whereNull('i.id_inmueble_canonico')
            ->select([
                'i.id',
                'i.clave_migracion',
                'i.codigo_origen',
                'i.domicilio',
                'i.domicilio_normalizado',
                'i.estado',
            ])
            ->selectSub(function ($query) {
                $query->from('inmuebles_origenes as io')
                    ->whereColumn('io.inmueble_id', 'i.id')
                    ->selectRaw("string_agg(DISTINCT io.cuenta_propietario, ', ' ORDER BY io.cuenta_propietario)");
            }, 'cuentas_propietario')
            ->selectSub(function ($query) {
                $query->from('inmuebles_origenes as io')
                    ->whereColumn('io.inmueble_id', 'i.id')
                    ->selectRaw("string_agg(DISTINCT io.clave_origen, ', ' ORDER BY io.clave_origen)");
            }, 'cuentas_inquilino')
            ->selectSub(function ($query) {
                $query->from('inmuebles_propietarios as ip')
                    ->whereColumn('ip.inmueble_id', 'i.id')
                    ->whereNull('ip.vigencia_hasta')
                    ->selectRaw('count(*)');
            }, 'propietarios_vigentes')
            ->selectSub(function ($query) {
                $query->from('contratos_inmuebles as ci')
                    ->whereColumn('ci.inmueble_id', 'i.id')
                    ->where('ci.activo', true)
                    ->selectRaw('count(*)');
            }, 'contratos_activos');

        if (! $incluirInactivos) {
            $consulta->where('i.estado', 'ACTIVO');
        }

        if ($texto !== '') {
            $como = '%'.$this->escaparLike($texto).'%';
            $consulta->where(function ($query) use ($texto, $como): void {
                if (ctype_digit($texto)) {
                    $query->orWhere('i.id', (int) $texto);
                }

                $query
                    ->orWhereRaw("i.domicilio ILIKE ? ESCAPE '!'", [$como])
                    ->orWhereRaw("i.domicilio_normalizado ILIKE ? ESCAPE '!'", [$como])
                    ->orWhereRaw("COALESCE(i.codigo_origen, '') ILIKE ? ESCAPE '!'", [$como])
                    ->orWhereExists(function ($sub) use ($como): void {
                        $sub->selectRaw('1')
                            ->from('inmuebles_origenes as io_busqueda')
                            ->whereColumn('io_busqueda.inmueble_id', 'i.id')
                            ->where(function ($origen) use ($como): void {
                                $origen
                                    ->whereRaw("io_busqueda.clave_origen ILIKE ? ESCAPE '!'", [$como])
                                    ->orWhereRaw("io_busqueda.cuenta_propietario ILIKE ? ESCAPE '!'", [$como])
                                    ->orWhereRaw("io_busqueda.direccion_finca ILIKE ? ESCAPE '!'", [$como]);
                            });
                    });
            });
        }

        return $consulta
            ->orderByRaw('LOWER(i.domicilio)')
            ->orderBy('i.id')
            ->limit(80)
            ->get();
    }

    /**
     * Lista inmuebles según la vista operativa de la herramienta.
     *
     * - activos_ok: activos canónicos sin conflicto pendiente ni candidato de duplicado.
     * - activos_revision: activos canónicos con conflicto pendiente y/o candidato de duplicado.
     * - inactivos: inmuebles inactivos canónicos; puede filtrarse por conflicto.
     *
     * Los absorbidos (id_inmueble_canonico no nulo) no aparecen en estas vistas.
     */
    public function listarClasificados(
        string $texto,
        string $vista,
        string $filtroInactivos,
        array $idsActivosRevision,
        int $porPagina = 100
    ): LengthAwarePaginator {
        $texto = trim($texto);
        $idsActivosRevision = array_values(array_unique(array_map('intval', $idsActivosRevision)));

        $consulta = DB::table('inmuebles as i')
            ->whereNull('i.id_inmueble_canonico')
            ->select([
                'i.id',
                'i.clave_migracion',
                'i.codigo_origen',
                'i.domicilio',
                'i.domicilio_normalizado',
                'i.estado',
            ])
            ->selectSub(function ($query) {
                $query->from('inmuebles_origenes as io')
                    ->whereColumn('io.inmueble_id', 'i.id')
                    ->selectRaw("string_agg(DISTINCT io.cuenta_propietario, ', ' ORDER BY io.cuenta_propietario)");
            }, 'cuentas_propietario')
            ->selectSub(function ($query) {
                $query->from('inmuebles_origenes as io')
                    ->whereColumn('io.inmueble_id', 'i.id')
                    ->selectRaw("string_agg(DISTINCT io.clave_origen, ', ' ORDER BY io.clave_origen)");
            }, 'cuentas_inquilino')
            ->selectSub(function ($query) {
                $query->from('inmuebles_origenes as io')
                    ->whereColumn('io.inmueble_id', 'i.id')
                    ->where('io.estado_origen', 'ACTIVO')
                    ->selectRaw("string_agg(DISTINCT io.clave_origen, ', ' ORDER BY io.clave_origen)");
            }, 'cuentas_inquilino_activas')
            ->selectSub(function ($query) {
                $query->from('inmuebles_propietarios as ip')
                    ->whereColumn('ip.inmueble_id', 'i.id')
                    ->whereNull('ip.vigencia_hasta')
                    ->selectRaw('count(*)');
            }, 'propietarios_vigentes')
            ->selectSub(function ($query) {
                $query->from('inmuebles_propietarios as ip')
                    ->join('clientes as c', 'c.id', '=', 'ip.cliente_id')
                    ->whereColumn('ip.inmueble_id', 'i.id')
                    ->whereNull('ip.vigencia_hasta')
                    ->selectRaw("string_agg(DISTINCT c.nombre, ' / ' ORDER BY c.nombre)");
            }, 'propietarios_nombres')
            ->selectSub(function ($query) {
                $query->from('contratos_inmuebles as ci')
                    ->whereColumn('ci.inmueble_id', 'i.id')
                    ->where('ci.activo', true)
                    ->selectRaw('count(*)');
            }, 'contratos_activos')
            ->selectSub(function ($query) {
                $query->from('inmuebles_partidas as ipar')
                    ->whereColumn('ipar.inmueble_id', 'i.id')
                    ->whereNull('ipar.vigencia_hasta')
                    ->selectRaw("string_agg(DISTINCT ipar.partida, ', ' ORDER BY ipar.partida)");
            }, 'partidas_vigentes')
            ->selectSub(function ($query) {
                $query->from('inmuebles_conflictos as ic')
                    ->whereColumn('ic.inmueble_id', 'i.id')
                    ->where('ic.estado', 'PENDIENTE')
                    ->selectRaw('count(*)');
            }, 'conflictos_pendientes')
            ->selectSub(function ($query) {
                $query->from('inmuebles_conflictos as ic')
                    ->whereColumn('ic.inmueble_id', 'i.id')
                    ->where('ic.estado', 'PENDIENTE')
                    ->selectRaw("string_agg(DISTINCT ic.motivo, ', ' ORDER BY ic.motivo)");
            }, 'motivos_conflicto');

        $existeConflicto = function ($sub): void {
            $sub->selectRaw('1')
                ->from('inmuebles_conflictos as ic_filtro')
                ->whereColumn('ic_filtro.inmueble_id', 'i.id')
                ->where('ic_filtro.estado', 'PENDIENTE');
        };

        if ($vista === 'activos_revision') {
            $consulta->where('i.estado', 'ACTIVO')
                ->where(function ($query) use ($idsActivosRevision, $existeConflicto): void {
                    if ($idsActivosRevision !== []) {
                        $query->whereIn('i.id', $idsActivosRevision)
                            ->orWhereExists($existeConflicto);
                    } else {
                        $query->whereExists($existeConflicto);
                    }
                });
        } elseif ($vista === 'inactivos') {
            $consulta->where('i.estado', 'INACTIVO');

            if ($filtroInactivos === 'con_conflicto') {
                $consulta->whereExists($existeConflicto);
            } elseif ($filtroInactivos === 'sin_conflicto') {
                $consulta->whereNotExists($existeConflicto);
            }
        } else {
            $consulta->where('i.estado', 'ACTIVO')
                ->whereNotExists($existeConflicto);

            if ($idsActivosRevision !== []) {
                $consulta->whereNotIn('i.id', $idsActivosRevision);
            }
        }

        $this->aplicarBusqueda($consulta, $texto);

        return $consulta
            ->orderByRaw('LOWER(i.domicilio)')
            ->orderBy('i.id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Conteos de las tres vistas operativas.
     */
    public function resumenClasificacion(array $idsActivosRevision): array
    {
        $idsActivosRevision = array_values(array_unique(array_map('intval', $idsActivosRevision)));

        $activosConConflicto = DB::table('inmuebles as i')
            ->join('inmuebles_conflictos as ic', function ($join): void {
                $join->on('ic.inmueble_id', '=', 'i.id')
                    ->where('ic.estado', '=', 'PENDIENTE');
            })
            ->whereNull('i.id_inmueble_canonico')
            ->where('i.estado', 'ACTIVO')
            ->distinct()
            ->pluck('i.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $idsRevision = array_values(array_unique(array_merge($idsActivosRevision, $activosConConflicto)));

        $activosTotal = DB::table('inmuebles')
            ->whereNull('id_inmueble_canonico')
            ->where('estado', 'ACTIVO')
            ->count();

        $inactivosTotal = DB::table('inmuebles')
            ->whereNull('id_inmueble_canonico')
            ->where('estado', 'INACTIVO')
            ->count();

        $inactivosConConflicto = DB::table('inmuebles as i')
            ->join('inmuebles_conflictos as ic', function ($join): void {
                $join->on('ic.inmueble_id', '=', 'i.id')
                    ->where('ic.estado', '=', 'PENDIENTE');
            })
            ->whereNull('i.id_inmueble_canonico')
            ->where('i.estado', 'INACTIVO')
            ->distinct()
            ->count('i.id');

        return [
            'activos_ok' => max(0, $activosTotal - count($idsRevision)),
            'activos_revision' => count($idsRevision),
            'inactivos' => $inactivosTotal,
            'inactivos_con_conflicto' => $inactivosConConflicto,
            'inactivos_sin_conflicto' => max(0, $inactivosTotal - $inactivosConConflicto),
            'unificados' => DB::table('inmuebles')->whereNotNull('id_inmueble_canonico')->count(),
            'conflictos_sin_inmueble' => DB::table('inmuebles_conflictos')
                ->where('estado', 'PENDIENTE')
                ->whereNull('inmueble_id')
                ->count(),
        ];
    }

    public function conflictosPendientesPorInmuebles(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }

        return $this->consultaConflictosPendientes()
            ->whereIn('ic.inmueble_id', $ids)
            ->orderByDesc('ic.ultima_deteccion_at')
            ->get();
    }

    public function conflictosPendientesSinInmueble(): Collection
    {
        return $this->consultaConflictosPendientes()
            ->whereNull('ic.inmueble_id')
            ->orderByDesc('ic.ultima_deteccion_at')
            ->limit(100)
            ->get();
    }

    private function aplicarBusqueda($consulta, string $texto): void
    {
        if ($texto === '') {
            return;
        }

        $como = '%'.$this->escaparLike($texto).'%';
        $consulta->where(function ($query) use ($texto, $como): void {
            if (ctype_digit($texto)) {
                $query->orWhere('i.id', (int) $texto);
            }

            $query
                ->orWhereRaw("i.domicilio ILIKE ? ESCAPE '!'", [$como])
                ->orWhereRaw("i.domicilio_normalizado ILIKE ? ESCAPE '!'", [$como])
                ->orWhereRaw("COALESCE(i.codigo_origen, '') ILIKE ? ESCAPE '!'", [$como])
                ->orWhereExists(function ($sub) use ($como): void {
                    $sub->selectRaw('1')
                        ->from('inmuebles_origenes as io_busqueda')
                        ->whereColumn('io_busqueda.inmueble_id', 'i.id')
                        ->where(function ($origen) use ($como): void {
                            $origen
                                ->whereRaw("io_busqueda.clave_origen ILIKE ? ESCAPE '!'", [$como])
                                ->orWhereRaw("io_busqueda.cuenta_propietario ILIKE ? ESCAPE '!'", [$como])
                                ->orWhereRaw("io_busqueda.direccion_finca ILIKE ? ESCAPE '!'", [$como]);
                        });
                });
        });
    }

    /**
     * Sugiere pares dentro del resultado de una búsqueda manual.
     *
     * Esta comparación usa una normalización deliberadamente más tolerante
     * (puntuación, abreviaturas de piso/oficina y ceros a la izquierda) sólo
     * para ayudar al usuario a encontrar candidatos. No modifica
     * domicilio_normalizado ni autoriza una unificación automática.
     */
    public function candidatosBusqueda(Collection $resultados): Collection
    {
        if ($resultados->count() < 2) {
            return collect();
        }

        $grupos = [];
        foreach ($resultados as $fila) {
            $firma = $this->normalizarDomicilioParaSugerencia((string) ($fila->domicilio ?? ''));
            if ($firma === '') {
                continue;
            }

            $grupos[$firma][] = (int) $fila->id;
        }

        $pares = [];
        foreach ($grupos as $firma => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);

            $cantidad = count($ids);
            for ($i = 0; $i < $cantidad; $i++) {
                for ($j = $i + 1; $j < $cantidad; $j++) {
                    $pares[] = [$ids[$i], $ids[$j], $firma];
                }
            }
        }

        if ($pares === []) {
            return collect();
        }

        $decisiones = DB::table('unificaciones_candidatos')
            ->where('tipo', self::TIPO)
            ->get()
            ->keyBy(fn ($fila): string => $fila->id_registro_a.'|'.$fila->id_registro_b);

        $resultado = collect();

        foreach ($pares as [$idA, $idB, $domicilioComparable]) {
            $a = $resultados->firstWhere('id', $idA);
            $b = $resultados->firstWhere('id', $idB);
            if ($a === null || $b === null) {
                continue;
            }

            $cuentas = DB::table('inmuebles_origenes as oa')
                ->join('inmuebles_origenes as ob', 'oa.cuenta_propietario', '=', 'ob.cuenta_propietario')
                ->where('oa.inmueble_id', $idA)
                ->where('ob.inmueble_id', $idB)
                ->pluck('oa.cuenta_propietario')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $partidas = DB::table('inmuebles_partidas as pa')
                ->join('inmuebles_partidas as pb', 'pa.partida', '=', 'pb.partida')
                ->where('pa.inmueble_id', $idA)
                ->where('pb.inmueble_id', $idB)
                ->whereNull('pa.vigencia_hasta')
                ->whereNull('pb.vigencia_hasta')
                ->pluck('pa.partida')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $domicilioNormalizadoCompartido = trim((string) ($a->domicilio_normalizado ?? '')) !== ''
                && (string) $a->domicilio_normalizado === (string) $b->domicilio_normalizado
                    ? (string) $a->domicilio_normalizado
                    : null;

            $evidencia = [
                'domicilio_normalizado_compartido' => $domicilioNormalizadoCompartido,
                'domicilio_comparable_compartido' => $domicilioComparable,
                'cuentas_compartidas' => array_values($cuentas),
                'partidas_compartidas' => array_values($partidas),
            ];
            $firmaEvidencia = $this->firmaEvidencia($evidencia);
            $clave = $idA.'|'.$idB;
            $decision = $decisiones->get($clave);

            if ($decision !== null
                && $decision->estado === 'MANTENER_SEPARADOS'
                && hash_equals((string) ($decision->firma_evidencia ?? ''), $firmaEvidencia)) {
                continue;
            }

            // Una misma unidad escrita de forma equivalente es una señal fuerte
            // para revisión, pero nunca una identidad. Cuenta/partida sólo suben
            // la confianza; una cuenta distinta no descarta el candidato porque
            // puede representar un cambio histórico de propietario.
            $confianza = $partidas !== []
                ? 'ALTA'
                : ($cuentas !== [] ? 'ALTA' : 'MEDIA');

            $motivos = ['Domicilio equivalente para sugerencia'];
            if ($partidas !== []) {
                $motivos[] = 'partida vigente compartida';
            }
            if ($cuentas !== []) {
                $motivos[] = 'cuenta COBOL de propietario compartida';
            } else {
                $motivos[] = 'propietario distinto o sin cuenta compartida: revisar historia';
            }

            $resultado->push((object) [
                'id_a' => $idA,
                'domicilio_a' => $a->domicilio,
                'estado_a' => $a->estado,
                'id_b' => $idB,
                'domicilio_b' => $b->domicilio,
                'estado_b' => $b->estado,
                'domicilio_comparable' => $domicilioComparable,
                'cuentas_compartidas' => implode(', ', $cuentas),
                'partidas_compartidas' => implode(', ', $partidas),
                'confianza' => $confianza,
                'firma_evidencia' => $firmaEvidencia,
                'estado_decision' => $decision?->estado,
                'motivo' => implode('; ', $motivos).'.',
            ]);
        }

        $ordenConfianza = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2];

        return $resultado->sortBy(function ($fila) use ($ordenConfianza): string {
            $rango = $ordenConfianza[$fila->confianza] ?? 9;

            return sprintf(
                '%d|%s|%010d|%010d',
                $rango,
                mb_strtolower((string) $fila->domicilio_comparable),
                $fila->id_a,
                $fila->id_b
            );
        })->values();
    }

    /**
     * Agrupa visualmente los candidatos de una búsqueda por la lectura comparable
     * del domicilio. Es sólo presentación: las decisiones y unificaciones siguen
     * operando entre dos IDs elegidos explícitamente por el usuario.
     */
    public function agruparCandidatosBusqueda(Collection $candidatos): Collection
    {
        if ($candidatos->isEmpty()) {
            return collect();
        }

        $rangos = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2];
        $grupos = [];

        foreach ($candidatos as $candidato) {
            $clave = trim((string) ($candidato->domicilio_comparable ?? ''));
            if ($clave === '') {
                $clave = 'PAR|'.min((int) $candidato->id_a, (int) $candidato->id_b)
                    .'|'.max((int) $candidato->id_a, (int) $candidato->id_b);
            }

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'domicilio_comparable' => (string) ($candidato->domicilio_comparable ?? ''),
                    'confianza' => (string) $candidato->confianza,
                    'items' => [],
                    'cuentas' => [],
                    'partidas' => [],
                    'motivos' => [],
                    'tiene_conflictivo' => false,
                ];
            }

            if (($rangos[$candidato->confianza] ?? 9) < ($rangos[$grupos[$clave]['confianza']] ?? 9)) {
                $grupos[$clave]['confianza'] = (string) $candidato->confianza;
            }

            foreach ([
                [(int) $candidato->id_a, (string) $candidato->domicilio_a, (string) $candidato->estado_a],
                [(int) $candidato->id_b, (string) $candidato->domicilio_b, (string) $candidato->estado_b],
            ] as [$id, $domicilio, $estado]) {
                $grupos[$clave]['items'][$id] = (object) [
                    'id' => $id,
                    'domicilio' => $domicilio,
                    'estado' => $estado,
                ];
            }

            foreach (array_filter(array_map('trim', explode(',', (string) $candidato->cuentas_compartidas))) as $cuenta) {
                $grupos[$clave]['cuentas'][$cuenta] = true;
            }
            foreach (array_filter(array_map('trim', explode(',', (string) $candidato->partidas_compartidas))) as $partida) {
                $grupos[$clave]['partidas'][$partida] = true;
            }
            if (trim((string) $candidato->motivo) !== '') {
                $grupos[$clave]['motivos'][trim((string) $candidato->motivo)] = true;
            }
            $grupos[$clave]['tiene_conflictivo'] = $grupos[$clave]['tiene_conflictivo']
                || $candidato->estado_decision === 'CONFLICTIVO';
        }

        $resultado = collect();
        foreach ($grupos as $grupo) {
            $items = array_values($grupo['items']);
            usort($items, fn ($a, $b): int => $a->id <=> $b->id);

            if (count($items) < 2) {
                continue;
            }

            $cuentas = array_keys($grupo['cuentas']);
            $partidas = array_keys($grupo['partidas']);
            sort($cuentas, SORT_STRING);
            sort($partidas, SORT_STRING);

            $resultado->push((object) [
                'domicilio_comparable' => $grupo['domicilio_comparable'],
                'confianza' => $grupo['confianza'],
                'items' => collect($items),
                'cuentas_compartidas' => implode(', ', $cuentas),
                'partidas_compartidas' => implode(', ', $partidas),
                'motivos' => implode(' ', array_keys($grupo['motivos'])),
                'tiene_conflictivo' => (bool) $grupo['tiene_conflictivo'],
            ]);
        }

        return $resultado->sortBy(function ($grupo) use ($rangos): string {
            $rango = $rangos[$grupo->confianza] ?? 9;
            return sprintf('%d|%s', $rango, mb_strtolower((string) $grupo->domicilio_comparable));
        })->values();
    }

    /**
     * Candidatos activos/canónicos. Se sugieren por domicilio exacto normalizado
     * y/o partida vigente compartida. Nunca se unifican automáticamente.
     */
    public function candidatos(): Collection
    {
        $pares = [];

        $porDomicilio = DB::table('inmuebles as a')
            ->join('inmuebles as b', function ($join): void {
                $join->on('a.domicilio_normalizado', '=', 'b.domicilio_normalizado')
                    ->whereColumn('a.id', '<', 'b.id');
            })
            ->whereNull('a.id_inmueble_canonico')
            ->whereNull('b.id_inmueble_canonico')
            ->where('a.estado', 'ACTIVO')
            ->where('b.estado', 'ACTIVO')
            ->whereRaw("BTRIM(a.domicilio_normalizado) <> ''")
            ->select(['a.id as id_a', 'b.id as id_b'])
            ->limit(250)
            ->get();

        $porPartida = DB::table('inmuebles_partidas as pa')
            ->join('inmuebles_partidas as pb', function ($join): void {
                $join->on('pa.partida', '=', 'pb.partida')
                    ->whereColumn('pa.inmueble_id', '<', 'pb.inmueble_id');
            })
            ->join('inmuebles as a', 'a.id', '=', 'pa.inmueble_id')
            ->join('inmuebles as b', 'b.id', '=', 'pb.inmueble_id')
            ->whereNull('pa.vigencia_hasta')
            ->whereNull('pb.vigencia_hasta')
            ->whereNull('a.id_inmueble_canonico')
            ->whereNull('b.id_inmueble_canonico')
            ->where('a.estado', 'ACTIVO')
            ->where('b.estado', 'ACTIVO')
            ->select(['a.id as id_a', 'b.id as id_b'])
            ->distinct()
            ->limit(250)
            ->get();

        foreach ($porDomicilio->concat($porPartida) as $fila) {
            $a = min((int) $fila->id_a, (int) $fila->id_b);
            $b = max((int) $fila->id_a, (int) $fila->id_b);
            $pares[$a.'|'.$b] = [$a, $b];
        }

        // Segunda pasada sólo para sugerencias: detecta escrituras equivalentes
        // del mismo domicilio/unidad entre inmuebles activos. No cambia ninguna
        // clave ni dato persistido.
        $activos = DB::table('inmuebles')
            ->whereNull('id_inmueble_canonico')
            ->where('estado', 'ACTIVO')
            ->select(['id', 'domicilio'])
            ->get();

        $gruposComparables = [];
        foreach ($activos as $inmuebleActivo) {
            $firmaComparable = $this->normalizarDomicilioParaSugerencia((string) $inmuebleActivo->domicilio);
            if ($firmaComparable !== '') {
                $gruposComparables[$firmaComparable][] = (int) $inmuebleActivo->id;
            }
        }

        $paresComparablesAgregados = 0;
        foreach ($gruposComparables as $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            $cantidadIds = count($ids);
            if ($cantidadIds < 2) {
                continue;
            }

            for ($i = 0; $i < $cantidadIds; $i++) {
                for ($j = $i + 1; $j < $cantidadIds; $j++) {
                    $a = $ids[$i];
                    $b = $ids[$j];
                    $pares[$a.'|'.$b] = [$a, $b];
                    $paresComparablesAgregados++;

                    if ($paresComparablesAgregados >= 500) {
                        break 2;
                    }
                }
            }
        }

        $decisiones = DB::table('unificaciones_candidatos')
            ->where('tipo', self::TIPO)
            ->get()
            ->keyBy(fn ($fila): string => $fila->id_registro_a.'|'.$fila->id_registro_b);

        $resultado = collect();
        foreach ($pares as $clave => [$idA, $idB]) {
            $a = DB::table('inmuebles')->where('id', $idA)->first();
            $b = DB::table('inmuebles')->where('id', $idB)->first();
            if ($a === null || $b === null) {
                continue;
            }

            $cuentas = DB::table('inmuebles_origenes as oa')
                ->join('inmuebles_origenes as ob', 'oa.cuenta_propietario', '=', 'ob.cuenta_propietario')
                ->where('oa.inmueble_id', $idA)
                ->where('ob.inmueble_id', $idB)
                ->pluck('oa.cuenta_propietario')->filter()->unique()->sort()->values()->all();

            $partidas = DB::table('inmuebles_partidas as pa')
                ->join('inmuebles_partidas as pb', 'pa.partida', '=', 'pb.partida')
                ->where('pa.inmueble_id', $idA)
                ->where('pb.inmueble_id', $idB)
                ->whereNull('pa.vigencia_hasta')
                ->whereNull('pb.vigencia_hasta')
                ->pluck('pa.partida')->filter()->unique()->sort()->values()->all();

            $mismoDomicilio = trim((string) $a->domicilio_normalizado) !== ''
                && (string) $a->domicilio_normalizado === (string) $b->domicilio_normalizado;

            $domicilioComparableA = $this->normalizarDomicilioParaSugerencia((string) $a->domicilio);
            $domicilioComparableB = $this->normalizarDomicilioParaSugerencia((string) $b->domicilio);
            $domicilioComparableCompartido = $domicilioComparableA !== ''
                && $domicilioComparableA === $domicilioComparableB
                    ? $domicilioComparableA
                    : null;

            $confianza = ($domicilioComparableCompartido !== null && ($partidas !== [] || $cuentas !== []))
                ? 'ALTA'
                : (($domicilioComparableCompartido !== null || $partidas !== []) ? 'MEDIA' : 'BAJA');

            $evidencia = [
                'domicilio_normalizado_compartido' => $mismoDomicilio ? (string) $a->domicilio_normalizado : null,
                'domicilio_comparable_compartido' => $domicilioComparableCompartido,
                'cuentas_compartidas' => array_values($cuentas),
                'partidas_compartidas' => array_values($partidas),
            ];
            $firma = $this->firmaEvidencia($evidencia);
            $decision = $decisiones->get($clave);

            if ($decision !== null
                && $decision->estado === 'MANTENER_SEPARADOS'
                && hash_equals((string) ($decision->firma_evidencia ?? ''), $firma)) {
                continue;
            }

            $resultado->push((object) [
                'id_a' => $idA,
                'domicilio_a' => $a->domicilio,
                'id_b' => $idB,
                'domicilio_b' => $b->domicilio,
                'domicilio_normalizado' => $mismoDomicilio ? $a->domicilio_normalizado : null,
                'cuentas_compartidas' => implode(', ', $cuentas),
                'partidas_compartidas' => implode(', ', $partidas),
                'confianza' => $confianza,
                'firma_evidencia' => $firma,
                'estado_decision' => $decision?->estado,
                'motivo' => $partidas !== []
                    ? 'Comparten partida vigente'.($domicilioComparableCompartido !== null ? ' y domicilio equivalente.' : '.')
                    : ($domicilioComparableCompartido !== null && $cuentas !== []
                        ? 'Domicilio equivalente y cuenta COBOL de propietario compartida.'
                        : ($domicilioComparableCompartido !== null
                            ? 'Domicilio equivalente para sugerencia. Revisar manualmente.'
                            : 'Evidencia compartida. Revisar manualmente.')),
            ]);
        }

        $ordenConfianza = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2];

        return $resultado->sortBy(function ($fila) use ($ordenConfianza): string {
            $rango = $ordenConfianza[$fila->confianza] ?? 9;

            return sprintf('%d|%s|%010d|%010d', $rango, mb_strtolower((string) $fila->domicilio_a), $fila->id_a, $fila->id_b);
        })->take(100)->values();
    }

    public function ultimasUnificaciones(): Collection
    {
        return DB::table('unificaciones as u')
            ->leftJoin('usuarios as usr', 'usr.id', '=', 'u.id_usuario')
            ->leftJoin('inmuebles as p', 'p.id', '=', 'u.id_registro_principal')
            ->leftJoin('inmuebles as a', 'a.id', '=', 'u.id_registro_absorbido')
            ->where('u.tipo', self::TIPO)
            ->select([
                'u.id_unificacion',
                'u.id_registro_principal',
                'u.id_registro_absorbido',
                'u.estado',
                'u.created_at',
                'usr.nombre as usuario_nombre',
                'p.domicilio as principal_domicilio',
                'a.domicilio as absorbido_domicilio',
            ])
            ->orderByDesc('u.id_unificacion')
            ->limit(20)
            ->get();
    }

    public function conflictosPendientes(): Collection
    {
        return $this->consultaConflictosPendientes()
            ->orderByDesc('ic.ultima_deteccion_at')
            ->limit(100)
            ->get();
    }

    private function consultaConflictosPendientes()
    {
        return DB::table('inmuebles_conflictos as ic')
            ->leftJoin('inmuebles as i', 'i.id', '=', 'ic.inmueble_id')
            ->leftJoin('inmuebles_resoluciones_origen as ro', function ($join): void {
                $join->on('ro.clave_origen', '=', 'ic.cuenta_inquilino')
                    ->where('ro.sistema_origen', '=', 'COBOL')
                    ->where('ro.entidad_origen', '=', 'INQUILINO');
            })
            ->where('ic.estado', 'PENDIENTE')
            ->select([
                'ic.id',
                'ic.inmueble_id',
                'ic.cuenta_inquilino',
                'ic.cuenta_propietario',
                'ic.motivo',
                'ic.detalle',
                'ic.detectado_at',
                'ic.ultima_deteccion_at',
                'i.domicilio',
                'i.estado as inmueble_estado',
                'i.id_inmueble_canonico',
                'ro.decision as resolucion_decision',
                'ro.inmueble_id as resolucion_inmueble_id',
            ]);
    }

    /** @return array<string, mixed> */
    public function comparar(int $principalId, int $secundarioId): array
    {
        if ($principalId === $secundarioId) {
            throw new DomainException('El inmueble principal y el absorbido deben ser distintos.');
        }

        $principal = $this->cargarInmueble($principalId);
        $secundario = $this->cargarInmueble($secundarioId);
        $plan = $this->construirPlan($principal, $secundario);

        return compact('principal', 'secundario', 'plan');
    }

    /** @return array{id_unificacion:int, resumen:array<string,mixed>} */
    public function unificar(int $principalId, int $secundarioId, ?int $usuarioId): array
    {
        $lock = Cache::store((string) config('gei.exploracion.lock_store', 'file'))
            ->lock('gei:transformacion-cobol', 300);

        if (! $lock->get()) {
            throw new DomainException(
                'Hay una transformación COBOL en curso. La unificación no se ejecutó.'
            );
        }

        try {
            return DB::transaction(function () use ($principalId, $secundarioId, $usuarioId): array {
                $this->validarForeignKeysConocidas();

                $inmuebles = DB::table('inmuebles')
                    ->whereIn('id', [$principalId, $secundarioId])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if (! $inmuebles->has($principalId) || ! $inmuebles->has($secundarioId)) {
                    throw new DomainException('Uno de los inmuebles seleccionados ya no existe.');
                }

                $principalFila = (array) $inmuebles->get($principalId);
                $secundarioFila = (array) $inmuebles->get($secundarioId);

                if ($principalId === $secundarioId) {
                    throw new DomainException('El inmueble principal y el absorbido deben ser distintos.');
                }
                if ($principalFila['id_inmueble_canonico'] !== null) {
                    throw new DomainException('El inmueble principal ya fue absorbido por otro inmueble.');
                }
                if ($secundarioFila['id_inmueble_canonico'] !== null) {
                    throw new DomainException('El inmueble secundario ya fue absorbido por otro inmueble.');
                }

                // Bloqueamos las relaciones susceptibles de cambiar para que la previsualización
                // calculada dentro de la transacción sea la que efectivamente se ejecuta.
                foreach (['inmuebles_origenes', 'inmuebles_propietarios', 'inmuebles_partidas', 'contratos_inmuebles', 'inmuebles_conflictos', 'inmuebles_resoluciones_origen'] as $tabla) {
                    DB::table($tabla)
                        ->whereIn('inmueble_id', [$principalId, $secundarioId])
                        ->lockForUpdate()
                        ->get();
                }
                DB::table('inmuebles')
                    ->where('id_inmueble_canonico', $secundarioId)
                    ->lockForUpdate()
                    ->get();

                $principal = $this->cargarInmueble($principalId);
                $secundario = $this->cargarInmueble($secundarioId);
                $plan = $this->construirPlan($principal, $secundario);

                if ($plan['bloqueos'] !== []) {
                    throw new DomainException(
                        "La unificación tiene conflictos que requieren decisión manual:\n- ".
                        implode("\n- ", $plan['bloqueos'])
                    );
                }

                $idUnificacion = (int) DB::table('unificaciones')->insertGetId([
                    'tipo' => self::TIPO,
                    'id_registro_principal' => $principalId,
                    'id_registro_absorbido' => $secundarioId,
                    'id_usuario' => $usuarioId,
                    'estado' => 'APLICADA',
                    'detalle_json' => json_encode($plan['resumen'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'id_unificacion');

                $orden = 0;
                $registrar = function (
                    string $tabla,
                    string $accion,
                    ?int $idRegistro,
                    ?array $antes,
                    ?array $despues,
                    array $detalle = []
                ) use ($idUnificacion, &$orden): void {
                    $orden++;
                    DB::table('unificaciones_cambios')->insert([
                        'id_unificacion' => $idUnificacion,
                        'orden' => $orden,
                        'tabla' => $tabla,
                        'accion' => $accion,
                        'id_registro' => $idRegistro,
                        'datos_antes' => $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                        'datos_despues' => $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
                        'detalle_json' => $detalle === [] ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                };

                foreach ($plan['duplicados_exactos'] as $duplicado) {
                    $tabla = $duplicado['tabla'];
                    $clavePrimaria = $this->clavePrimariaTabla($tabla);
                    $id = (int) $duplicado['id_secundario'];
                    $antes = (array) DB::table($tabla)->where($clavePrimaria, $id)->first();
                    DB::table($tabla)->where($clavePrimaria, $id)->delete();
                    $registrar($tabla, 'ELIMINADO_DUPLICADO_EXACTO', $id, $antes, null, [
                        'id_registro_conservado' => $duplicado['id_principal'],
                        'clave_primaria' => $clavePrimaria,
                    ]);
                }

                foreach (['inmuebles_origenes', 'inmuebles_propietarios', 'inmuebles_partidas', 'contratos_inmuebles', 'inmuebles_conflictos', 'inmuebles_resoluciones_origen'] as $tabla) {
                    $clavePrimaria = $this->clavePrimariaTabla($tabla);
                    $filas = DB::table($tabla)
                        ->where('inmueble_id', $secundarioId)
                        ->orderBy($clavePrimaria)
                        ->get();

                    foreach ($filas as $fila) {
                        $idFila = (int) $fila->{$clavePrimaria};
                        $antes = (array) $fila;
                        DB::table($tabla)->where($clavePrimaria, $idFila)->update([
                            'inmueble_id' => $principalId,
                            ...($this->tieneUpdatedAt($tabla) ? ['updated_at' => now()] : []),
                        ]);
                        $despues = $antes;
                        $despues['inmueble_id'] = $principalId;
                        $registrar($tabla, 'REASIGNADO', $idFila, $antes, $despues, [
                            'clave_primaria' => $clavePrimaria,
                        ]);
                    }
                }

                $aliases = DB::table('inmuebles')
                    ->where('id_inmueble_canonico', $secundarioId)
                    ->orderBy('id')
                    ->get();
                foreach ($aliases as $alias) {
                    $antes = (array) $alias;
                    DB::table('inmuebles')->where('id', $alias->id)->update([
                        'id_inmueble_canonico' => $principalId,
                        'updated_at' => now(),
                    ]);
                    $despues = $antes;
                    $despues['id_inmueble_canonico'] = $principalId;
                    $registrar('inmuebles', 'REENCADENADO_CANONICO', (int) $alias->id, $antes, $despues);
                }

                $antesSecundario = (array) DB::table('inmuebles')->where('id', $secundarioId)->first();
                DB::table('inmuebles')->where('id', $secundarioId)->update([
                    'id_inmueble_canonico' => $principalId,
                    'estado' => 'INACTIVO',
                    'updated_at' => now(),
                ]);
                $despuesSecundario = $antesSecundario;
                $despuesSecundario['id_inmueble_canonico'] = $principalId;
                $despuesSecundario['estado'] = 'INACTIVO';
                $registrar('inmuebles', 'MARCADO_ABSORBIDO', $secundarioId, $antesSecundario, $despuesSecundario);

                $parA = min($principalId, $secundarioId);
                $parB = max($principalId, $secundarioId);
                DB::table('unificaciones_candidatos')->updateOrInsert(
                    [
                        'tipo' => self::TIPO,
                        'id_registro_a' => $parA,
                        'id_registro_b' => $parB,
                    ],
                    [
                        'estado' => 'UNIFICADO',
                        'id_usuario_resolucion' => $usuarioId,
                        'resuelto_at' => now(),
                        'ultima_deteccion_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                return [
                    'id_unificacion' => $idUnificacion,
                    'resumen' => $plan['resumen'],
                ];
            }, 3);
        } finally {
            $lock->release();
        }
    }

    public function resolverConflictoImportacion(
        int $conflictoId,
        string $decision,
        ?int $inmuebleId,
        ?int $usuarioId
    ): void {
        if (! in_array($decision, ['ASOCIAR_EXISTENTE', 'CREAR_SEPARADO'], true)) {
            throw new DomainException('La decisión de conflicto indicada no es válida.');
        }

        DB::transaction(function () use ($conflictoId, $decision, $inmuebleId, $usuarioId): void {
            $conflicto = DB::table('inmuebles_conflictos')
                ->where('id', $conflictoId)
                ->lockForUpdate()
                ->first();

            if ($conflicto === null) {
                throw new DomainException('El conflicto ya no existe.');
            }
            if ($conflicto->estado !== 'PENDIENTE') {
                throw new DomainException('El conflicto ya fue resuelto.');
            }
            if (trim((string) $conflicto->cuenta_inquilino) === '') {
                throw new DomainException('El conflicto no contiene una cuenta de inquilino utilizable como identidad COBOL.');
            }

            $idCanonico = null;
            if ($decision === 'ASOCIAR_EXISTENTE') {
                if ($inmuebleId === null) {
                    throw new DomainException('Debe indicar el inmueble al que se asociará la identidad COBOL.');
                }
                $inmueble = DB::table('inmuebles')->where('id', $inmuebleId)->lockForUpdate()->first();
                if ($inmueble === null) {
                    throw new DomainException("No existe el inmueble {$inmuebleId}.");
                }
                if ($inmueble->id_inmueble_canonico !== null) {
                    throw new DomainException(
                        "El inmueble {$inmuebleId} fue absorbido por {$inmueble->id_inmueble_canonico}. Seleccione el canónico."
                    );
                }
                $idCanonico = (int) $inmueble->id;
            } elseif ($inmuebleId !== null) {
                throw new DomainException('Para crear/mantener separado no debe indicar un inmueble existente.');
            }

            $detalleActual = $this->decodificarJson($conflicto->detalle ?? null);
            if (! is_array($detalleActual)) {
                $detalleActual = ['detalle_original' => $detalleActual];
            }
            $detalleActual['resolucion_manual'] = [
                'decision' => $decision,
                'inmueble_id' => $idCanonico,
                'usuario_id' => $usuarioId,
                'fecha' => now()->toIso8601String(),
            ];

            DB::table('inmuebles_resoluciones_origen')->updateOrInsert(
                [
                    'sistema_origen' => 'COBOL',
                    'entidad_origen' => 'INQUILINO',
                    'clave_origen' => (string) $conflicto->cuenta_inquilino,
                ],
                [
                    'decision' => $decision,
                    'inmueble_id' => $idCanonico,
                    'usuario_id' => $usuarioId,
                    'detalle_json' => json_encode([
                        'conflicto_id' => $conflictoId,
                        'cuenta_propietario' => $conflicto->cuenta_propietario,
                        'clave_inmueble' => $conflicto->clave_inmueble,
                        'motivo' => $conflicto->motivo,
                    ], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('inmuebles_conflictos')->where('id', $conflictoId)->update([
                'estado' => 'RESUELTO',
                'detalle' => json_encode($detalleActual, JSON_UNESCAPED_UNICODE),
                'resuelto_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function resolverCandidato(
        int $idA,
        int $idB,
        string $estado,
        ?int $usuarioId
    ): void {
        if (! in_array($estado, ['MANTENER_SEPARADOS', 'CONFLICTIVO'], true)) {
            throw new DomainException('La decisión indicada no es válida.');
        }
        if ($idA === $idB) {
            throw new DomainException('Los inmuebles deben ser distintos.');
        }

        $a = min($idA, $idB);
        $b = max($idA, $idB);
        $comparacion = $this->comparar($a, $b);
        $cuentasA = $comparacion['principal']['origenes']
            ->pluck('cuenta_propietario')->filter()->unique()->sort()->values()->all();
        $cuentasB = $comparacion['secundario']['origenes']
            ->pluck('cuenta_propietario')->filter()->unique()->sort()->values()->all();
        $cuentasCompartidas = array_values(array_intersect($cuentasA, $cuentasB));
        sort($cuentasCompartidas, SORT_STRING);
        $partidasA = $comparacion['principal']['partidas']->whereNull('vigencia_hasta')->pluck('partida')->filter()->unique()->sort()->values()->all();
        $partidasB = $comparacion['secundario']['partidas']->whereNull('vigencia_hasta')->pluck('partida')->filter()->unique()->sort()->values()->all();
        $partidasCompartidas = array_values(array_intersect($partidasA, $partidasB));
        sort($partidasCompartidas, SORT_STRING);
        $domicilioA = (string) $comparacion['principal']['inmueble']->domicilio_normalizado;
        $domicilioB = (string) $comparacion['secundario']['inmueble']->domicilio_normalizado;
        $domicilioComparableA = $this->normalizarDomicilioParaSugerencia(
            (string) $comparacion['principal']['inmueble']->domicilio
        );
        $domicilioComparableB = $this->normalizarDomicilioParaSugerencia(
            (string) $comparacion['secundario']['inmueble']->domicilio
        );

        $firma = $this->firmaEvidencia([
            'domicilio_normalizado_compartido' => $domicilioA !== '' && $domicilioA === $domicilioB ? $domicilioA : null,
            'domicilio_comparable_compartido' => $domicilioComparableA !== ''
                && $domicilioComparableA === $domicilioComparableB
                    ? $domicilioComparableA
                    : null,
            'cuentas_compartidas' => $cuentasCompartidas,
            'partidas_compartidas' => $partidasCompartidas,
        ]);

        DB::table('unificaciones_candidatos')->updateOrInsert(
            [
                'tipo' => self::TIPO,
                'id_registro_a' => $a,
                'id_registro_b' => $b,
            ],
            [
                'firma_evidencia' => $firma,
                'estado' => $estado,
                'id_usuario_resolucion' => $usuarioId,
                'resuelto_at' => now(),
                'ultima_deteccion_at' => now(),
                'detalle_json' => json_encode([
                    'domicilio_a' => $comparacion['principal']['inmueble']->domicilio,
                    'domicilio_b' => $comparacion['secundario']['inmueble']->domicilio,
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /** @return array<string, mixed> */
    private function cargarInmueble(int $id): array
    {
        $inmueble = DB::table('inmuebles')->where('id', $id)->first();
        if ($inmueble === null) {
            throw new DomainException("No existe el inmueble {$id}.");
        }

        return [
            'inmueble' => $inmueble,
            'origenes' => DB::table('inmuebles_origenes')
                ->where('inmueble_id', $id)
                ->orderBy('entidad_origen')
                ->orderBy('clave_origen')
                ->get(),
            'propietarios' => DB::table('inmuebles_propietarios as ip')
                ->join('clientes as c', 'c.id', '=', 'ip.cliente_id')
                ->join('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')
                ->where('ip.inmueble_id', $id)
                ->select([
                    'ip.*',
                    'c.nombre as cliente_nombre',
                    'c.cuit as cliente_cuit',
                    'cc.cuenta as cuenta_propietario',
                    'cc.rol as cuenta_rol',
                ])
                ->orderByRaw('ip.vigencia_hasta IS NULL DESC')
                ->orderBy('c.nombre')
                ->get(),
            'partidas' => DB::table('inmuebles_partidas')
                ->where('inmueble_id', $id)
                ->orderByRaw('vigencia_hasta IS NULL DESC')
                ->orderBy('partida')
                ->get(),
            'contratos' => DB::table('contratos_inmuebles as ci')
                ->join('contratos as c', 'c.id', '=', 'ci.contrato_id')
                ->where('ci.inmueble_id', $id)
                ->select([
                    'ci.*',
                    'c.clave_migracion as contrato_clave_migracion',
                    'c.cuenta_inquilino',
                    'c.cuenta_propietario',
                    'c.fecha_inicio',
                    'c.fecha_fin',
                    'c.estado as contrato_estado',
                ])
                ->orderByDesc('ci.activo')
                ->orderByDesc('c.fecha_inicio')
                ->get(),
            'conflictos' => DB::table('inmuebles_conflictos')
                ->where('inmueble_id', $id)
                ->orderByRaw("CASE WHEN estado = 'PENDIENTE' THEN 0 ELSE 1 END")
                ->orderByDesc('ultima_deteccion_at')
                ->get(),
            'resoluciones_origen' => DB::table('inmuebles_resoluciones_origen')
                ->where('inmueble_id', $id)
                ->orderBy('sistema_origen')
                ->orderBy('entidad_origen')
                ->orderBy('clave_origen')
                ->get(),
            'absorbidos' => DB::table('inmuebles')
                ->where('id_inmueble_canonico', $id)
                ->orderBy('id')
                ->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function construirPlan(array $principal, array $secundario): array
    {
        $p = $principal['inmueble'];
        $s = $secundario['inmueble'];
        $bloqueos = [];
        $duplicados = [];

        if ($p->id_inmueble_canonico !== null) {
            $bloqueos[] = "El inmueble principal {$p->id} ya está absorbido por {$p->id_inmueble_canonico}.";
        }
        if ($s->id_inmueble_canonico !== null) {
            $bloqueos[] = "El inmueble secundario {$s->id} ya está absorbido por {$s->id_inmueble_canonico}.";
        }

        foreach ($secundario['propietarios'] as $fila) {
            if ($fila->vigencia_hasta !== null) {
                continue;
            }
            $existente = $principal['propietarios']->first(fn ($pFila) =>
                $pFila->vigencia_hasta === null
                && (int) $pFila->cliente_cuenta_id === (int) $fila->cliente_cuenta_id
            );
            if ($existente === null) {
                continue;
            }
            if ($this->relacionesIguales($existente, $fila, [
                'cliente_id', 'cliente_cuenta_id', 'porcentaje', 'vigencia_desde',
                'vigencia_hasta', 'activo', 'origen',
            ])) {
                $duplicados[] = [
                    'tabla' => 'inmuebles_propietarios',
                    'id_principal' => (int) $existente->id,
                    'id_secundario' => (int) $fila->id,
                ];
            } else {
                $bloqueos[] = 'Propietario vigente en ambos inmuebles para la cuenta '.$fila->cuenta_propietario.
                    ' con datos diferentes (porcentaje/vigencia/origen).';
            }
        }

        foreach ($secundario['partidas'] as $fila) {
            if ($fila->vigencia_hasta !== null) {
                continue;
            }
            $existente = $principal['partidas']->first(fn ($pFila) =>
                $pFila->vigencia_hasta === null && (string) $pFila->partida === (string) $fila->partida
            );
            if ($existente === null) {
                continue;
            }
            if ($this->relacionesIguales($existente, $fila, [
                'partida', 'vigencia_desde', 'vigencia_hasta', 'activo', 'origen',
            ])) {
                $duplicados[] = [
                    'tabla' => 'inmuebles_partidas',
                    'id_principal' => (int) $existente->id,
                    'id_secundario' => (int) $fila->id,
                ];
            } else {
                $bloqueos[] = 'Partida vigente '.$fila->partida.' presente en ambos inmuebles con datos diferentes.';
            }
        }

        foreach ($secundario['contratos'] as $fila) {
            $existente = $principal['contratos']->first(fn ($pFila) =>
                (int) $pFila->contrato_id === (int) $fila->contrato_id
            );
            if ($existente === null) {
                continue;
            }
            if ($this->relacionesIguales($existente, $fila, [
                'contrato_id', 'vigencia_desde', 'vigencia_hasta', 'activo', 'origen',
            ])) {
                $duplicados[] = [
                    'tabla' => 'contratos_inmuebles',
                    'id_principal' => (int) $existente->id,
                    'id_secundario' => (int) $fila->id,
                ];
            } else {
                $bloqueos[] = 'El contrato '.$fila->contrato_clave_migracion.
                    ' está asociado a ambos inmuebles con datos de relación diferentes.';
            }
        }

        $idsDuplicadosPorTabla = collect($duplicados)
            ->groupBy('tabla')
            ->map(fn ($filas) => $filas->pluck('id_secundario')->map(fn ($id) => (int) $id)->all());

        $resumen = [
            'principal' => [
                'id' => (int) $p->id,
                'domicilio' => $p->domicilio,
                'estado' => $p->estado,
            ],
            'absorbido' => [
                'id' => (int) $s->id,
                'domicilio' => $s->domicilio,
                'estado' => $s->estado,
            ],
            'traslados' => [
                'origenes' => $secundario['origenes']->count(),
                'propietarios' => $secundario['propietarios']->whereNotIn('id', $idsDuplicadosPorTabla->get('inmuebles_propietarios', []))->count(),
                'partidas' => $secundario['partidas']->whereNotIn('id', $idsDuplicadosPorTabla->get('inmuebles_partidas', []))->count(),
                'contratos' => $secundario['contratos']->whereNotIn('id', $idsDuplicadosPorTabla->get('contratos_inmuebles', []))->count(),
                'conflictos' => $secundario['conflictos']->count(),
                'resoluciones_origen' => $secundario['resoluciones_origen']->count(),
                'aliases_previos' => $secundario['absorbidos']->count(),
            ],
            'duplicados_exactos_eliminados' => count($duplicados),
            'bloqueos' => count($bloqueos),
        ];

        return [
            'bloqueos' => array_values(array_unique($bloqueos)),
            'duplicados_exactos' => $duplicados,
            'resumen' => $resumen,
        ];
    }

    private function validarForeignKeysConocidas(): void
    {
        $tablas = DB::select(<<<'SQL'
SELECT DISTINCT origen.relname AS tabla
FROM pg_constraint c
JOIN pg_class destino ON destino.oid = c.confrelid
JOIN pg_namespace nd ON nd.oid = destino.relnamespace
JOIN pg_class origen ON origen.oid = c.conrelid
JOIN pg_namespace no ON no.oid = origen.relnamespace
WHERE c.contype = 'f'
  AND nd.nspname = 'public'
  AND no.nspname = 'public'
  AND destino.relname = 'inmuebles'
ORDER BY origen.relname
SQL);

        $detectadas = array_map(fn ($fila): string => (string) $fila->tabla, $tablas);
        sort($detectadas);
        $esperadas = self::FKS_ESPERADAS;
        sort($esperadas);

        if ($detectadas !== $esperadas) {
            throw new DomainException(
                'Cambió el esquema de relaciones de inmuebles. No se ejecutó la unificación. '.
                'FK detectadas: '.implode(', ', $detectadas).'.'
            );
        }
    }

    private function relacionesIguales(object $a, object $b, array $campos): bool
    {
        foreach ($campos as $campo) {
            $va = $a->{$campo} ?? null;
            $vb = $b->{$campo} ?? null;

            if ($campo === 'datos_origen') {
                $va = $this->normalizarJson($va);
                $vb = $this->normalizarJson($vb);
            }

            if ((string) ($va ?? '') !== (string) ($vb ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function decodificarJson(mixed $valor): mixed
    {
        if (is_string($valor)) {
            $decodificado = json_decode($valor, true);

            return json_last_error() === JSON_ERROR_NONE ? $decodificado : $valor;
        }

        return $valor ?? [];
    }

    private function normalizarJson(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        $decodificado = is_string($valor) ? json_decode($valor, true) : $valor;
        if (! is_array($decodificado)) {
            return (string) $valor;
        }
        $this->ksortRecursivo($decodificado);

        return json_encode($decodificado, JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function ksortRecursivo(array &$datos): void
    {
        if (! array_is_list($datos)) {
            ksort($datos);
        }
        foreach ($datos as &$valor) {
            if (is_array($valor)) {
                $this->ksortRecursivo($valor);
            }
        }
    }

    private function clavePrimariaTabla(string $tabla): string
    {
        return match ($tabla) {
            'inmuebles_resoluciones_origen' => 'id_inmueble_resolucion_origen',
            default => 'id',
        };
    }

    private function tieneUpdatedAt(string $tabla): bool
    {
        return in_array($tabla, [
            'inmuebles_origenes',
            'inmuebles_propietarios',
            'inmuebles_partidas',
            'contratos_inmuebles',
            'inmuebles_conflictos',
            'inmuebles_resoluciones_origen',
        ], true);
    }

    private function firmaEvidencia(array $datos): string
    {
        $this->ksortRecursivo($datos);

        return hash('sha256', json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Normalización sólo para sugerencias de posibles duplicados.
     *
     * Ejemplos que deben converger:
     * - P.3 OFIC.08 / P.3 OFIC. 8 / P.3 OFIC.8
     * - 2507-1 P-OF 10 / 2507 P.1 OF.10
     * - P.1 OFIC 1 Y 13 / PISO 1 OFIC 1 Y 13
     *
     * No se guarda en la base ni reemplaza domicilio_normalizado.
     */
    private function normalizarDomicilioParaSugerencia(string $domicilio): string
    {
        $valor = mb_strtoupper(trim($domicilio), 'UTF-8');
        if ($valor === '') {
            return '';
        }

        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if ($transliterado !== false) {
            $valor = $transliterado;
        }

        // Forma histórica: "2507-1 P-OF 10" = número 2507, piso 1, oficina 10.
        $valor = preg_replace(
            '/(\d{3,6})\s*-\s*(\d{1,2})\s*P\s*-\s*OF(?:IC(?:INA)?)?/i',
            '$1 PISO $2 OFICINA ',
            $valor
        ) ?? $valor;

        // Piso: P.3 / P 3 / P-3 / PISO 3.
        $valor = preg_replace(
            '/\b(?:PISO|P)\s*[\.\-]?\s*0*(\d+)\b/i',
            ' PISO $1 ',
            $valor
        ) ?? $valor;

        // Oficina: OF 8 / OF.08 / OFIC. 8 / OFICINA 8.
        $valor = preg_replace_callback(
            '/\bOF(?:IC(?:INA)?)?\s*[\.\-]?\s*0*(\d+)\b/i',
            static fn (array $m): string => ' OFICINA '.((string) ((int) $m[1])).' ',
            $valor
        ) ?? $valor;

        // Normalizar números adicionales de una oficina compuesta:
        // "OFICINA 01 Y 013" -> "OFICINA 1 Y 13".
        $valor = preg_replace_callback(
            '/\bOFICINA\s+(\d+)(?:\s+Y\s+0*(\d+))?/i',
            static function (array $m): string {
                $resultado = 'OFICINA '.((string) ((int) $m[1]));
                if (isset($m[2]) && $m[2] !== '') {
                    $resultado .= ' Y '.((string) ((int) $m[2]));
                }

                return $resultado;
            },
            $valor
        ) ?? $valor;

        // La puntuación restante no define identidad física para esta sugerencia.
        $valor = preg_replace('/[^\pL\pN]+/u', ' ', $valor) ?? $valor;
        $valor = preg_replace('/\s+/u', ' ', trim($valor)) ?? trim($valor);

        return $valor;
    }

    private function escaparLike(string $valor): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $valor);
    }
}
