<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Para preservar la seguridad no deduce automáticamente una sede de la dirección,
 * CUIT o nombre del propietario. Usa las cuentas de propietarios, los
 * orígenes operativos y los contratos vinculados, contrastándolos con
 * sucursal_id. Las discrepancias quedan reservadas al administrador global.
 */
final class InmuebleMaestroConsultaService
{
    /** @param array<int,string> $sedes @return array<int,string> */
    public function prefijosDeSedes(array $sedes): array
    {
        $prefijos = [];
        foreach ($sedes as $sede) {
            array_push($prefijos, ...match ($sede) {
                'SF' => ['1103', '1202'],
                'ST' => ['2103', '2202'],
                default => [],
            });
        }
        return array_values(array_unique($prefijos));
    }

    /**
     * @param array<int,string> $sedesVisibles
     * @param array<int,string> $prefijos
     * @return array<string,mixed>
     */
    public function consultar(
        array $sedesVisibles,
        array $prefijos,
        string $sede,
        string $estado,
        string $buscar,
        int $seleccionar,
        bool $administradorGlobal
    ): array {
        $alcance = $this->alcance($sedesVisibles, $sede, $administradorGlobal);

        $resumen = (clone $alcance)->selectRaw(
            "count(*) as total, "
            ."count(*) filter (where i.estado = 'ACTIVO') as activos, "
            ."count(*) filter (where i.estado = 'INACTIVO') as inactivos, "
            ."count(*) filter (where i.estado = 'DESCONOCIDO') as desconocidos"
        )->first();

        $sinSede = $administradorGlobal
            ? $this->alcance($sedesVisibles, 'SIN_SEDE', true)->count()
            : null;

        $listado = clone $alcance;
        if ($estado !== 'TODOS') {
            $listado->where('i.estado', $estado);
        }
        // GEI_BUSQUEDA_INMUEBLES_CUENTAS_EXACTAS_20261110
        // Domicilio: todos los términos en la dirección, sin acentos.
        // Cuenta COBOL: únicamente búsqueda explícita por cuenta completa y exacta.
        // ID: búsqueda exacta con prefijo "id:" o "#".
        if ($buscar !== '') {
            $digitos = preg_replace('/\D/', '', $buscar) ?: '';
            if (preg_match('/^(?:id:|#)\s*([0-9]+)$/iD', $buscar, $partesId) === 1) {
                $listado->where('i.id', (int) $partesId[1]);
            } elseif (
                preg_match('/^[0-9\s.\/-]+$/D', $buscar) === 1
                && preg_match('/^(1103|1202|2103|2202)[0-9]{7}$/D', $digitos) === 1
                && $prefijos !== []
            ) {
                // No usar LIKE '%2753%': genera falsos positivos por fragmentos de cuenta.
                $listado->where(static function (Builder $q) use ($digitos, $prefijos): void {
                    $q->whereExists(static function (Builder $sub) use ($digitos, $prefijos): void {
                        $sub->selectRaw('1')->from('inmuebles_propietarios as ip')
                            ->join('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')
                            ->whereColumn('ip.inmueble_id', 'i.id')
                            ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
                            ->where('cc.rol', 'PROPIETARIO')
                            ->where('cc.cuenta', '=', $digitos);
                    })->orWhereExists(static function (Builder $sub) use ($digitos, $prefijos): void {
                        $sub->selectRaw('1')->from('inmuebles_origenes as io')
                            ->whereColumn('io.inmueble_id', 'i.id')
                            ->whereIn(DB::raw('LEFT(io.cuenta_propietario, 4)'), $prefijos)
                            ->where('io.cuenta_propietario', '=', $digitos);
                    })->orWhereExists(static function (Builder $sub) use ($digitos, $prefijos): void {
                        $sub->selectRaw('1')->from('contratos_inmuebles as ci')
                            ->join('contratos as c', 'c.id', '=', 'ci.contrato_id')
                            ->whereColumn('ci.inmueble_id', 'i.id')
                            ->whereIn(DB::raw('LEFT(c.cuenta_propietario, 4)'), $prefijos)
                            ->whereIn(DB::raw('LEFT(c.cuenta_inquilino, 4)'), $prefijos)
                            ->where(static function (Builder $cond) use ($digitos): void {
                                $cond->where('c.cuenta_propietario', '=', $digitos)
                                    ->orWhere('c.cuenta_inquilino', '=', $digitos);
                            });
                    });
                });
            } else {
                // Normalización PHP del texto ingresado y SQL de la dirección.
                // No requiere instalar la extensión PostgreSQL 'unaccent'.
                $normalizado = strtolower(\Illuminate\Support\Str::ascii($buscar));
                $palabras = preg_split('/[^a-z0-9]+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $domicilioSql = "TRANSLATE(LOWER(COALESCE(i.domicilio, '')), 'áéíóúüñ', 'aeiouun')";
                if ($palabras === []) {
                    $listado->whereRaw('1 = 0');
                } elseif (count($palabras) > 1) {
                    // SAN MARTÍN 2753 => todos los tokens deben estar en el domicilio.
                    foreach ($palabras as $palabra) {
                        $listado->whereRaw($domicilioSql.' LIKE ?', ['%'.$palabra.'%']);
                    }
                } else {
                    // Un término: también permitir código de origen, observaciones y partida.
                    $like = '%'.$palabras[0].'%';
                    $listado->where(static function (Builder $q) use ($domicilioSql, $like): void {
                        $q->whereRaw($domicilioSql.' LIKE ?', [$like])
                            ->orWhere('i.codigo_origen', 'ILIKE', $like)
                            ->orWhere('i.observaciones', 'ILIKE', $like)
                            ->orWhereExists(static function (Builder $sub) use ($like): void {
                                $sub->selectRaw('1')->from('inmuebles_partidas as p')
                                    ->whereColumn('p.inmueble_id', 'i.id')
                                    ->where('p.partida', 'ILIKE', $like);
                            });
                    });
                }
            }
        }

        $inmuebles = $listado->select('i.id', 'i.domicilio', 'i.estado',
            'i.codigo_origen', 'i.destino_codigo')
            ->selectRaw($this->sedeEfectivaSql().' as sede_codigo')
            ->orderBy('i.domicilio')->orderBy('i.id')
            ->paginate(40)->withQueryString();

        // Consultas en lote, SOLO para las 40 fincas de la página. No se hace
        // una consulta adicional por fila ni se presupone que exista IP.
        $this->enriquecerListado($inmuebles, $prefijos);

        $detalle = null;
        if ($seleccionar > 0) {
            // No incluir estado/búsqueda en la autorización, sí la sede.
            $detalle = (clone $alcance)
                ->where('i.id', $seleccionar)
                ->select('i.*')
                ->selectRaw($this->sedeEfectivaSql().' as sede_codigo')
                ->first();
            abort_if($detalle === null, 404);
        }

        $partidas = collect();
        $propietarios = collect();
        $contratos = collect();
        if ($detalle !== null) {
            $partidas = DB::table('inmuebles_partidas')
                ->where('inmueble_id', $detalle->id)
                ->select('partida', 'activo', 'vigencia_desde', 'vigencia_hasta')
                ->orderByDesc('activo')->orderBy('partida')->get();

            // El filtro por cuenta autorizada se aplica también a nombres y porcentajes.
            $propietarios = $this->propietariosVisibles((int) $detalle->id, $prefijos);
            $contratos = $this->contratosVisibles((int) $detalle->id, $prefijos);
        }

        return compact('resumen', 'sinSede', 'inmuebles', 'detalle', 'partidas',
            'propietarios', 'contratos');
    }

    /**
     * Sede explícita cuando coincide con las evidencias operativas.
     * Si falta, inferirla únicamente cuando origen, propietarios y ambas
     * cuentas de contratos vinculados no presentan contradicciones.
     */
    private function sedeEfectivaSql(): string
    {
        return "CASE "
            ."WHEN sc.cantidad_sedes > 1 THEN NULL "
            ."WHEN s.codigo IN ('SF', 'ST') "
            ."AND (sc.cantidad_sedes IS NULL OR sc.cantidad_sedes = 0 "
            ."OR sc.sede_inferida = s.codigo) THEN s.codigo "
            ."WHEN s.codigo IS NULL AND sc.cantidad_sedes = 1 THEN sc.sede_inferida "
            ."ELSE NULL END";
    }

    /** @param array<int,string> $sedes */
    private function alcance(array $sedes, string $sede, bool $administradorGlobal): Builder
    {
        // La sede se determina con evidencias de la base operativa. Un inmueble
        // puede no tener registros en inmuebles_propietarios y, sin embargo,
        // tener un origen y contratos correctamente asociados.
        // Se consideran AMBAS cuentas del contrato: si contradicen otra fuente,
        // el inmueble queda pendiente en lugar de ser visible en otra sucursal.
        $evidencias = DB::table('inmuebles_propietarios as ip')
            ->join('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')
            ->where('cc.rol', 'PROPIETARIO')
            ->selectRaw("ip.inmueble_id, CASE LEFT(cc.cuenta, 4) "
                ."WHEN '1202' THEN 'SF' WHEN '2202' THEN 'ST' END AS sede")
            ->unionAll(
                DB::table('inmuebles_origenes as io')
                    ->selectRaw("io.inmueble_id, CASE LEFT(io.cuenta_propietario, 4) "
                        ."WHEN '1202' THEN 'SF' WHEN '2202' THEN 'ST' END AS sede")
            )
            ->unionAll(
                DB::table('contratos_inmuebles as ci')
                    ->join('contratos as c', 'c.id', '=', 'ci.contrato_id')
                    ->selectRaw("ci.inmueble_id, CASE LEFT(c.cuenta_propietario, 4) "
                        ."WHEN '1202' THEN 'SF' WHEN '2202' THEN 'ST' END AS sede")
            )
            ->unionAll(
                DB::table('contratos_inmuebles as ci')
                    ->join('contratos as c', 'c.id', '=', 'ci.contrato_id')
                    ->selectRaw("ci.inmueble_id, CASE LEFT(c.cuenta_inquilino, 4) "
                        ."WHEN '1103' THEN 'SF' WHEN '2103' THEN 'ST' END AS sede")
            );

        // Agrupar TODAS las evidencias válidas: una única sede o ninguna.
        // El CASE de sedeEfectivaSql() también contrasta con sucursal_id.
        $origenSedes = DB::query()->fromSub($evidencias, 'ev')
            ->whereNotNull('ev.sede')
            ->selectRaw('ev.inmueble_id, COUNT(DISTINCT ev.sede) AS cantidad_sedes, '
                .'MIN(ev.sede) AS sede_inferida')
            ->groupBy('ev.inmueble_id');

        $q = DB::table('inmuebles as i')
            ->leftJoin('sucursales as s', 's.id', '=', 'i.sucursal_id')
            ->leftJoinSub($origenSedes, 'sc', 'sc.inmueble_id', '=', 'i.id')
            ->whereNull('i.id_inmueble_canonico');

        $sql = $this->sedeEfectivaSql();
        if ($sede === 'SIN_SEDE') {
            abort_unless($administradorGlobal, 403);
            return $q->whereRaw('('.$sql.') IS NULL');
        }
        return $q->whereIn(DB::raw('('.$sql.')'), $sedes);
    }

    /**
     * Revisión de titularidad: NO confundir domicilio repetido con duplicado.
     * Se compara dentro de la misma sede. Si todos los inmuebles comparten
     * al menos una cuenta COBOL de propietario válida, no se genera aviso.
     * Tampoco se usa nombre, CUIT, identidad histórica ni estado ACTIVO.
     *
     * Las cuentas se obtienen de vínculos directos, orígenes y contratos
     * operativos. Nunca se crean, modifican ni unifican inmuebles.
     *
     * @param array<int,string> $sedes
     * @return array<int,array{domicilio:string,sede:string,items:Collection}>
     */
    public function posiblesDuplicados(array $sedes, string $sede, bool $administradorGlobal): array
    {
        abort_unless($administradorGlobal, 403);

        $base = $this->alcance($sedes, $sede, true)
            ->whereNotNull('i.domicilio_normalizado')
            ->where('i.domicilio_normalizado', '<>', '');

        // Agrupar por domicilio Y sucursal: una calle homónima en SF y ST
        // no es evidencia de duplicación.
        $sedeSql = $this->sedeEfectivaSql();
        $grupos = (clone $base)
            ->select('i.domicilio_normalizado')
            ->selectRaw($sedeSql.' AS sede_codigo')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('i.domicilio_normalizado')
            ->groupByRaw($sedeSql)
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('i.domicilio_normalizado')
            ->get();

        $revisiones = [];

        // Filtrar ANTES de limitar a 60 avisos: los primeros domicilios
        // repetidos pueden estar correctamente relacionados y no requerir revisión.
        foreach ($grupos->chunk(100) as $lote) {
            $domicilios = $lote->pluck('domicilio_normalizado')->unique()->values()->all();
            $inmuebles = (clone $base)
                ->whereIn('i.domicilio_normalizado', $domicilios)
                ->select('i.id', 'i.domicilio', 'i.estado', 'i.domicilio_normalizado')
                ->selectRaw($sedeSql.' AS sede_codigo')
                ->orderBy('i.id')
                ->get()
                ->groupBy(static fn ($i): string => (string) $i->domicilio_normalizado.'|'.(string) $i->sede_codigo);

            $ids = $inmuebles->flatten(1)->pluck('id')->all();
            $cuentas = $this->cuentasPropietarioRevision($ids);

            foreach ($lote as $grupo) {
                $clave = (string) $grupo->domicilio_normalizado.'|'.(string) $grupo->sede_codigo;
                $items = $inmuebles->get($clave, collect());
                $cuentasGrupo = $items->map(static fn ($i): array => $cuentas->get($i->id, []))->all();

                if (! self::requiereRevisionPorCuentas($cuentasGrupo)) {
                    continue;
                }

                foreach ($items as $item) {
                    $item->cuentas_propietario_revision = $cuentas->get($item->id, []);
                }

                $revisiones[] = [
                    'domicilio' => trim((string) $grupo->domicilio_normalizado),
                    'sede' => (string) $grupo->sede_codigo,
                    'items' => $items,
                ];

                if (count($revisiones) >= 60) {
                    break 2;
                }
            }
        }

        return $revisiones;
    }

    /**
     * Regla pura, comprobable sin PostgreSQL: misma cuenta COBOL de
     * propietario presente en todas las fichas = sin aviso.
     * Si falta evidencia o no hay cuenta compartida, se revisa sin modificar datos.
     *
     * @param array<int,array<int,string>> $cuentasDeCadaInmueble
     */
    public static function requiereRevisionPorCuentas(array $cuentasDeCadaInmueble): bool
    {
        if (count($cuentasDeCadaInmueble) < 2) {
            return false;
        }

        $comunes = null;
        foreach ($cuentasDeCadaInmueble as $cuentas) {
            $validas = [];
            foreach ($cuentas as $cuenta) {
                $digitos = preg_replace('/\D/', '', (string) $cuenta) ?: '';
                if (preg_match('/^(1202|2202)\d{7}$/D', $digitos)) {
                    $validas[$digitos] = true;
                }
            }

            if ($validas === []) {
                return true; // Sin cuenta verificable: no ocultar el caso.
            }

            $comunes = $comunes === null
                ? array_keys($validas)
                : array_values(array_intersect($comunes, array_keys($validas)));

            if ($comunes === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtiene cuentas de propietario en lote: sin consultas por fila.
     * No se utilizan personas reconstruidas ni coincidencias por CUIT.
     *
     * @param array<int,int> $ids
     * @return Collection<int,array<int,string>>
     */
    private function cuentasPropietarioRevision(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $fuentes = DB::table('inmuebles_propietarios as ip')
            ->join('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')
            ->whereIn('ip.inmueble_id', $ids)
            ->where('cc.rol', 'PROPIETARIO')
            ->select('ip.inmueble_id', 'cc.cuenta')
            ->unionAll(
                DB::table('inmuebles_origenes as io')
                    ->whereIn('io.inmueble_id', $ids)
                    ->select('io.inmueble_id', 'io.cuenta_propietario as cuenta')
            )
            ->unionAll(
                DB::table('contratos_inmuebles as ci')
                    ->join('contratos as c', 'c.id', '=', 'ci.contrato_id')
                    ->whereIn('ci.inmueble_id', $ids)
                    ->select('ci.inmueble_id', 'c.cuenta_propietario as cuenta')
            );

        return $fuentes->get()->groupBy('inmueble_id')->map(static function (Collection $registros): array {
            return $registros->pluck('cuenta')
                ->map(static fn ($cuenta): string => preg_replace('/\D/', '', (string) $cuenta) ?: '')
                ->filter(static fn (string $cuenta): bool => (bool) preg_match('/^(1202|2202)\d{7}$/D', $cuenta))
                ->unique()->sort()->values()->all();
        });
    }

    /**
     * Incluye cuentas y contratos de la página actual. Sin usar el maestro
     * histórico ni asumir que todos los inmuebles tienen una fila en IP.
     * @param array<int,string> $prefijos
     */
    private function enriquecerListado(\Illuminate\Pagination\LengthAwarePaginator $inmuebles, array $prefijos): void
    {
        $ids = $inmuebles->getCollection()->pluck('id')->all();
        if ($ids === [] || $prefijos === []) {
            return;
        }

        $contratos = DB::table('contratos_inmuebles as ci')
            ->join('contratos as c', 'c.id', '=', 'ci.contrato_id')
            ->whereIn('ci.inmueble_id', $ids)
            ->whereIn(DB::raw('LEFT(c.cuenta_propietario, 4)'), $prefijos)
            ->whereIn(DB::raw('LEFT(c.cuenta_inquilino, 4)'), $prefijos)
            ->select('ci.inmueble_id', 'c.id', 'c.cuenta_propietario',
                'c.cuenta_inquilino', 'c.estado', 'ci.activo')
            ->distinct()->get()->groupBy('inmueble_id');

        $directos = DB::table('inmuebles_propietarios as ip')
            ->join('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')
            ->whereIn('ip.inmueble_id', $ids)
            ->where('cc.rol', 'PROPIETARIO')
            ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
            ->select('ip.inmueble_id', 'cc.cuenta')->distinct()->get()->groupBy('inmueble_id');

        $origenes = DB::table('inmuebles_origenes as io')
            ->whereIn('io.inmueble_id', $ids)
            ->whereIn(DB::raw('LEFT(io.cuenta_propietario, 4)'), $prefijos)
            ->select('io.inmueble_id', 'io.cuenta_propietario as cuenta')
            ->distinct()->get()->groupBy('inmueble_id');

        $inmuebles->getCollection()->transform(static function ($i) use ($contratos, $directos, $origenes) {
            $relacionados = $contratos->get($i->id, collect());
            $propietarios = $directos->get($i->id, collect())->pluck('cuenta')
                ->merge($origenes->get($i->id, collect())->pluck('cuenta'))
                ->merge($relacionados->pluck('cuenta_propietario'))
                ->filter()->unique()->values();
            $inquilinos = $relacionados->pluck('cuenta_inquilino')->filter()->unique()->values();

            $i->cuentas_propietario = $propietarios->all();
            $i->cuentas_inquilino = $inquilinos->all();
            $i->contratos_totales = $relacionados->unique('id')->count();
            $i->contratos_activos = $relacionados
                ->filter(static fn ($c) => $c->estado === 'ACTIVO' && (bool) $c->activo)
                ->unique('id')->count();

            return $i;
        });
    }

    /**
     * Trae propietarios desde el vínculo directo y, si falta, desde el origen
     * o los contratos. La identidad se resuelve SOLO en public.clientes.
     * @param array<int,string> $prefijos
     */
    // GEI_ENLACES_CLIENTES_INMUEBLES_V13: identidad canónica por cuenta COBOL.
    private function propietariosVisibles(int $id, array $prefijos): Collection
    {
        if ($prefijos === []) {
            return collect();
        }

        $directos = DB::table('inmuebles_propietarios as ip')
            ->join('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')
            ->join('clientes as c', 'c.id', '=', 'cc.cliente_id')
            ->leftJoin('clientes as canon', 'canon.id', '=', 'c.id_cliente_canonico')
            ->where('ip.inmueble_id', $id)
            ->where('cc.rol', 'PROPIETARIO')
            ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
            ->select('cc.cuenta', 'ip.porcentaje', 'ip.activo',
                'ip.vigencia_desde', 'ip.vigencia_hasta')
            ->selectRaw('COALESCE(canon.nombre, c.nombre) AS nombre')
            ->selectRaw('COALESCE(c.id_cliente_canonico, c.id) AS cliente_maestro_id')
            ->selectRaw("'Vinculo de propietario' AS fuente")
            ->orderByDesc('ip.activo')->get()->unique('cuenta')->keyBy('cuenta');

        $cuentasOrigen = DB::table('inmuebles_origenes as io')
            ->where('io.inmueble_id', $id)
            ->whereIn(DB::raw('LEFT(io.cuenta_propietario, 4)'), $prefijos)
            ->pluck('io.cuenta_propietario');

        $cuentasContrato = DB::table('contratos_inmuebles as ci')
            ->join('contratos as c', 'c.id', '=', 'ci.contrato_id')
            ->where('ci.inmueble_id', $id)
            ->whereIn(DB::raw('LEFT(c.cuenta_propietario, 4)'), $prefijos)
            ->whereIn(DB::raw('LEFT(c.cuenta_inquilino, 4)'), $prefijos)
            ->pluck('c.cuenta_propietario');

        $candidatas = $cuentasContrato->merge($cuentasOrigen)
            ->filter()->unique()->reject(static fn ($cuenta) => $directos->has($cuenta))->values();

        if ($candidatas->isNotEmpty()) {
            $identidades = DB::table('clientes_cuentas as cc')
                ->join('clientes as c', 'c.id', '=', 'cc.cliente_id')
                ->leftJoin('clientes as canon', 'canon.id', '=', 'c.id_cliente_canonico')
                ->where('cc.rol', 'PROPIETARIO')
                ->whereIn('cc.cuenta', $candidatas->all())
                ->select('cc.cuenta')
                ->selectRaw('COALESCE(canon.nombre, c.nombre) AS nombre')
                ->selectRaw('COALESCE(c.id_cliente_canonico, c.id) AS cliente_maestro_id')
                ->get()->unique('cuenta')->keyBy('cuenta');

            foreach ($candidatas as $cuenta) {
                $directos->put($cuenta, (object) [
                    'cuenta' => $cuenta,
                    'nombre' => $identidades->get($cuenta)?->nombre ?? 'Titular sin cliente operativo vinculado',
                    'cliente_maestro_id' => $identidades->get($cuenta)?->cliente_maestro_id,
                    'porcentaje' => null,
                    'activo' => null,
                    'vigencia_desde' => null,
                    'vigencia_hasta' => null,
                    'fuente' => $cuentasContrato->contains($cuenta) ? 'Cuenta en contrato' : 'Cuenta de origen COBOL',
                ]);
            }
        }

        return $directos->values();
    }

    /**
     * El contrato pertenece a un inmueble que ya pasó el control de sede.
     * Por ello NO se exige un registro en inmuebles_propietarios: esa
     * relación no siempre está importada aunque el contrato sí exista.
     * @param array<int,string> $prefijos
     */
    private function contratosVisibles(int $id, array $prefijos): Collection
    {
        if ($prefijos === []) {
            return collect();
        }

        $contratos = DB::table('contratos_inmuebles as ci')
            ->join('contratos as c', 'c.id', '=', 'ci.contrato_id')
            ->where('ci.inmueble_id', $id)
            ->whereIn(DB::raw('LEFT(c.cuenta_propietario, 4)'), $prefijos)
            ->whereIn(DB::raw('LEFT(c.cuenta_inquilino, 4)'), $prefijos)
            ->select('c.id', 'c.codigo_origen', 'c.cuenta_propietario',
                'c.cuenta_inquilino', 'c.estado', 'c.fecha_inicio', 'c.fecha_fin',
                'ci.activo as relacion_activa')
            ->distinct()->orderByDesc('c.fecha_inicio')->limit(100)->get();

        $cuentas = $contratos->pluck('cuenta_propietario')
            ->merge($contratos->pluck('cuenta_inquilino'))->unique()->values();
        if ($cuentas->isEmpty()) {
            return $contratos;
        }

        $identidades = DB::table('clientes_cuentas as cc')
            ->join('clientes as c', 'c.id', '=', 'cc.cliente_id')
            ->leftJoin('clientes as canon', 'canon.id', '=', 'c.id_cliente_canonico')
            ->whereIn('cc.cuenta', $cuentas->all())
            ->whereIn('cc.rol', ['INQUILINO', 'PROPIETARIO'])
            ->select('cc.cuenta', 'cc.rol')
            ->selectRaw('COALESCE(canon.nombre, c.nombre) AS nombre')
            ->selectRaw('COALESCE(c.id_cliente_canonico, c.id) AS cliente_maestro_id')
            ->get()->keyBy(static fn ($r) => $r->rol.':'.$r->cuenta);

        return $contratos->map(static function ($c) use ($identidades) {
            $c->propietario_nombre = $identidades->get('PROPIETARIO:'.$c->cuenta_propietario)?->nombre ?? 'Sin titular asociado';
            $c->inquilino_nombre = $identidades->get('INQUILINO:'.$c->cuenta_inquilino)?->nombre ?? 'Sin titular asociado';
            $c->propietario_cliente_maestro_id = $identidades->get('PROPIETARIO:'.$c->cuenta_propietario)?->cliente_maestro_id;
            $c->inquilino_cliente_maestro_id = $identidades->get('INQUILINO:'.$c->cuenta_inquilino)?->cliente_maestro_id;
            return $c;
        });
    }
}
