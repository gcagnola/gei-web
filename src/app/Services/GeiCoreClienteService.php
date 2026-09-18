<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use PDO;
use Throwable;

final class GeiCoreClienteService
{
    public function periodos(): array
    {
        return array_map(
            static fn ($r): array => [
                'periodo' => (string) $r->periodo,
                'estado' => (string) $r->estado,
            ],
            $this->core()->select(
                "select periodo, estado
                   from gei_core.periodos
                  order by periodo desc"
            )
        );
    }

    public function ultimoPeriodo(): string
    {
        $periodo = $this->core()->selectOne(
            "select max(periodo) as periodo from gei_core.periodos"
        )->periodo ?? null;

        if ($periodo === null) {
            throw new RuntimeException('GeI-Core todavía no tiene períodos procesados.');
        }

        return (string) $periodo;
    }

    public function resumen(string $periodo, string $rol): array
    {
        $rol = $this->rol($rol);
        $bindings = [$periodo];
        $filtroRol = '';

        if ($rol !== 'TODOS') {
            $filtroRol = ' and pp.rol = ?';
            $bindings[] = $rol;
        }

        $fila = $this->core()->selectOne(
            "select
                count(distinct pp.persona_id) as personas,
                count(distinct pp.persona_id) filter (where pp.activo) as activas
             from gei_core.personas_periodos pp
             where pp.periodo = ? {$filtroRol}",
            $bindings
        );

        return [
            'personas' => (int) ($fila->personas ?? 0),
            'activas' => (int) ($fila->activas ?? 0),
        ];
    }

    /**
     * Posibles personas duplicadas activas en un período.
     *
     * Esta consulta es deliberadamente de sólo lectura: no fusiona ni modifica
     * personas. Agrupa candidatos por CUIT normalizado y por nombre normalizado,
     * y consolida grupos idénticos para que la pantalla no muestre duplicados.
     *
     * @return array{grupos: array<int, array<string,mixed>>, total_grupos:int, total_personas:int}
     */
    public function duplicadosActivos(string $periodo, string $buscar = ''): array
    {
        $filas = collect($this->core()->select(
            "select
                p.id,
                p.nombre,
                p.nro_iva,
                p.nro_documento,
                p.domicilio,
                p.localidad,
                p.provincia,
                string_agg(distinct pp.rol, ', ' order by pp.rol) as roles,
                string_agg(distinct pc.cuenta_cobol, ', ' order by pc.cuenta_cobol)
                    filter (where pc.cuenta_cobol is not null) as cuentas
             from gei_core.personas_periodos pp
             join gei_core.personas p
               on p.id = pp.persona_id
              and p.fusionada_en_id is null
             left join gei_core.personas_cuentas_cobol pc
               on pc.persona_id = p.id
             where pp.periodo = ?
               and pp.activo = true
             group by
                p.id, p.nombre, p.nro_iva, p.nro_documento,
                p.domicilio, p.localidad, p.provincia
             order by p.nombre nulls last, p.id",
            [$periodo]
        ));

        $porCuit = [];
        $porNombre = [];

        foreach ($filas as $fila) {
            $cuit = preg_replace('/\\D+/', '', (string) ($fila->nro_iva ?? '')) ?: '';
            if ($cuit !== '' && $cuit !== '0') {
                $porCuit[$cuit][] = $fila;
            }

            $nombre = $this->normalizarNombreDuplicado((string) ($fila->nombre ?? ''));
            if ($nombre !== '') {
                $porNombre[$nombre][] = $fila;
            }
        }

        /** @var array<string,array<string,mixed>> $grupos */
        $grupos = [];

        foreach ($porCuit as $cuit => $miembros) {
            if (count($miembros) < 2) {
                continue;
            }

            $this->agregarGrupoDuplicado($grupos, $miembros, 'CUIT', $cuit);
        }

        foreach ($porNombre as $nombre => $miembros) {
            if (count($miembros) < 2) {
                continue;
            }

            $this->agregarGrupoDuplicado($grupos, $miembros, 'NOMBRE', $nombre);
        }

        $buscar = mb_strtoupper(trim($buscar), 'UTF-8');
        $resultado = array_values($grupos);

        if ($buscar !== '') {
            $resultado = array_values(array_filter(
                $resultado,
                static function (array $grupo) use ($buscar): bool {
                    foreach ($grupo['personas'] as $persona) {
                        $texto = mb_strtoupper(implode(' ', [
                            (string) ($persona->id ?? ''),
                            (string) ($persona->nombre ?? ''),
                            (string) ($persona->nro_iva ?? ''),
                            (string) ($persona->nro_documento ?? ''),
                            (string) ($persona->domicilio ?? ''),
                            (string) ($persona->cuentas ?? ''),
                        ]), 'UTF-8');

                        if (str_contains($texto, $buscar)) {
                            return true;
                        }
                    }

                    return false;
                }
            ));
        }

        usort($resultado, static function (array $a, array $b): int {
            $orden = ['EXACTA' => 0, 'CUIT' => 1, 'NOMBRE_INCOMPLETO' => 2, 'NOMBRE' => 3, 'CONFLICTO' => 4];
            $oa = $orden[$a['tipo']] ?? 99;
            $ob = $orden[$b['tipo']] ?? 99;

            return $oa <=> $ob ?: strcasecmp((string) $a['titulo'], (string) $b['titulo']);
        });

        $ids = [];
        foreach ($resultado as $grupo) {
            foreach ($grupo['personas'] as $persona) {
                $ids[(int) $persona->id] = true;
            }
        }

        return [
            'grupos' => $resultado,
            'total_grupos' => count($resultado),
            'total_personas' => count($ids),
        ];
    }

    /** @param array<string,array<string,mixed>> $grupos */
    private function agregarGrupoDuplicado(array &$grupos, array $miembros, string $motivo, string $valor): void
    {
        usort($miembros, static fn ($a, $b): int => ((int) $a->id) <=> ((int) $b->id));
        $ids = array_map(static fn ($p): int => (int) $p->id, $miembros);
        $clave = implode('-', $ids);

        if (! isset($grupos[$clave])) {
            $grupos[$clave] = [
                'personas' => $miembros,
                'motivos' => [],
                'valores' => [],
                'tipo' => 'NOMBRE',
                'titulo' => (string) ($miembros[0]->nombre ?? 'Grupo sin nombre'),
            ];
        }

        $grupos[$clave]['motivos'][$motivo] = true;
        $grupos[$clave]['valores'][$motivo] = $valor;

        $cuits = [];
        $nombres = [];
        foreach ($miembros as $persona) {
            $cuit = preg_replace('/\\D+/', '', (string) ($persona->nro_iva ?? '')) ?: '';
            if ($cuit !== '' && $cuit !== '0') {
                $cuits[$cuit] = true;
            }

            $nombre = $this->normalizarNombreDuplicado((string) ($persona->nombre ?? ''));
            if ($nombre !== '') {
                $nombres[$nombre] = true;
            }
        }

        $tieneCuit = isset($grupos[$clave]['motivos']['CUIT']);
        $tieneNombre = isset($grupos[$clave]['motivos']['NOMBRE']);

        if ($tieneCuit && $tieneNombre && count($cuits) === 1 && count($nombres) === 1) {
            $grupos[$clave]['tipo'] = 'EXACTA';
        } elseif ($tieneNombre && count($cuits) > 1) {
            $grupos[$clave]['tipo'] = 'CONFLICTO';
        } elseif ($tieneCuit) {
            $grupos[$clave]['tipo'] = 'CUIT';
        } elseif ($tieneNombre && count($cuits) === 1) {
            $grupos[$clave]['tipo'] = 'NOMBRE_INCOMPLETO';
        } else {
            $grupos[$clave]['tipo'] = 'NOMBRE';
        }
    }

    private function normalizarNombreDuplicado(string $nombre): string
    {
        $nombre = mb_strtoupper(trim($nombre), 'UTF-8');
        $nombre = preg_replace('/\\s+/u', ' ', $nombre) ?? $nombre;

        return trim($nombre);
    }

    public function listar(
        string $periodo,
        string $rol,
        string $estado,
        string $buscar,
        int $porPagina = 50
    ): LengthAwarePaginator {
        $rol = $this->rol($rol);
        $estado = in_array($estado, ['activos', 'todos'], true) ? $estado : 'activos';
        $buscar = trim($buscar);

        $q = $this->core()->table('gei_core.personas_periodos as pp')
            ->join('gei_core.personas as p', 'p.id', '=', 'pp.persona_id')
            ->leftJoin('gei_core.personas_origenes as po', function ($join): void {
                $join->on('po.persona_id', '=', 'pp.persona_id')
                    ->on('po.rol', '=', 'pp.rol')
                    ->on('po.periodo', '=', 'pp.periodo');
            })
            ->where('pp.periodo', $periodo)
            ->when($rol !== 'TODOS', fn ($x) => $x->where('pp.rol', $rol))
            ->when($estado === 'activos', fn ($x) => $x->where('pp.activo', true))
            ->when($buscar !== '', function ($x) use ($buscar): void {
                $like = '%'.$buscar.'%';
                $x->where(function ($s) use ($like): void {
                    $s->whereRaw("coalesce(p.nombre, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(p.nro_iva, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(p.nro_documento, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(p.domicilio, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(po.cuenta_cobol, '') ilike ?", [$like]);
                });
            })
            ->groupBy([
                'p.id', 'p.nombre', 'p.nro_iva', 'p.nro_documento',
                'p.domicilio', 'p.localidad', 'p.provincia',
            ])
            ->selectRaw(
                "p.id, p.nombre, p.nro_iva, p.nro_documento,
                 p.domicilio, p.localidad, p.provincia,
                 bool_or(pp.activo) as activo,
                 string_agg(distinct pp.rol, ', ' order by pp.rol) as roles,
                 string_agg(
                    distinct po.cuenta_cobol,
                    ', ' order by po.cuenta_cobol
                 ) filter (where po.cuenta_cobol is not null) as cuentas"
            )
            ->orderByRaw('p.nombre nulls last')
            ->orderBy('p.id');

        return $q->paginate($porPagina)->withQueryString();
    }

    public function detalle(int $personaId, string $periodo, string $tab = 'datos', ?string $mesActividad = null): array
    {
        $core = $this->core();

        $persona = $core->selectOne(
            "select *
               from gei_core.personas
              where id = ?
                and fusionada_en_id is null",
            [$personaId]
        );

        if ($persona === null) {
            throw new RuntimeException('No existe el cliente GeI-Core solicitado.');
        }

        $roles = collect($core->select(
            "select rol, activo, primer_periodo, ultimo_periodo
               from gei_core.personas_roles
              where persona_id = ?
              order by rol",
            [$personaId]
        ));

        $cuentas = collect($core->select(
            "select id, rol, cuenta_cobol, activa, primer_periodo, ultimo_periodo
               from gei_core.personas_cuentas_cobol
              where persona_id = ?
              order by rol, cuenta_cobol",
            [$personaId]
        ));

        $contratos = collect($core->select(
            "select
                cp.*,
                im.id as inmueble_id,
                im.domicilio_actual,
                pinq.nombre as inquilino_nombre,
                pprop.nombre as propietario_nombre
             from gei_core.contratos_periodos cp
             join gei_core.inmuebles im on im.id = cp.inmueble_id
             left join gei_core.personas pinq on pinq.id = cp.persona_inquilino_id
             left join gei_core.personas pprop on pprop.id = cp.persona_propietario_id
             where cp.periodo = ?
               and (
                    cp.persona_inquilino_id = ?
                    or cp.persona_propietario_id = ?
               )
             order by cp.activo desc, im.domicilio_actual nulls last, cp.contrato_id",
            [$periodo, $personaId, $personaId]
        ));

        $idsContratos = $contratos->pluck('contrato_id')->unique()->values()->all();
        $historialContratos = collect();

        if ($idsContratos !== []) {
            $placeholders = implode(',', array_fill(0, count($idsContratos), '?'));
            $historialContratos = collect($core->select(
                "select *
                   from gei_core.contratos_periodos
                  where contrato_id in ({$placeholders})
                  order by contrato_id, periodo desc",
                $idsContratos
            ))->groupBy('contrato_id');
        }

        $partidasPorInmueble = collect();
        $idsInmuebles = $contratos->pluck('inmueble_id')->filter()->unique()->values()->all();

        if ($idsInmuebles !== []) {
            $placeholders = implode(',', array_fill(0, count($idsInmuebles), '?'));
            $partidasPorInmueble = collect($core->select(
                "select inmueble_id, partida
                   from gei_core.inmuebles_partidas
                  where inmueble_id in ({$placeholders})
                  order by inmueble_id, partida",
                $idsInmuebles
            ))->groupBy('inmueble_id');
        }

        $cuentasCorrientes = collect($core->select(
            "select
                cc.id,
                cc.tipo,
                cc.cuenta_cobol,
                cc.activa,
                cc.primer_periodo,
                cc.ultimo_periodo,
                count(m.id)::bigint as movimientos
             from gei_core.cuentas_corrientes cc
             left join gei_core.cuentas_corrientes_movimientos m
               on m.cuenta_corriente_id = cc.id
             where cc.persona_id = ?
             group by
                cc.id, cc.tipo, cc.cuenta_cobol, cc.activa,
                cc.primer_periodo, cc.ultimo_periodo
             order by cc.tipo, cc.cuenta_cobol",
            [$personaId]
        ));

        $mesActividad = $mesActividad ?: now()->format('Ym');
        $ultimosMovimientos = $tab === 'cuenta-corriente'
            ? $this->movimientosMes($personaId, $mesActividad)
            : collect();

        $cuentasPropietario = $cuentas
            ->where('rol', 'PROPIETARIO')
            ->pluck('cuenta_cobol')
            ->map(fn ($v): string => $this->normalizarCuenta((string) $v))
            ->filter()->unique()->values()->all();

        $todasLasCuentas = $cuentas
            ->pluck('cuenta_cobol')
            ->map(fn ($v): string => $this->normalizarCuenta((string) $v))
            ->filter()->unique()->values()->all();

        $liquidaciones = in_array($tab, ['liquidaciones', 'impuestos'], true)
            ? $this->liquidaciones($persona, $cuentasPropietario, $mesActividad)
            : collect();
        $impuestos = $tab === 'impuestos'
            ? $liquidaciones->filter(fn ($l): bool => (bool) $l->impuestos_pdf_disponible)->values()
            : collect();
        $facturas = $tab === 'facturas'
            ? $this->facturasKng($todasLasCuentas, $mesActividad)
            : collect();
        $arca = collect();

        return compact(
            'persona',
            'roles',
            'cuentas',
            'contratos',
            'historialContratos',
            'partidasPorInmueble',
            'cuentasCorrientes',
            'ultimosMovimientos',
            'liquidaciones',
            'impuestos',
            'facturas',
            'arca'
        );
    }

    private function liquidaciones(object $persona, array $cuentas, ?string $mes = null): Collection
    {
        $db = DB::connection();
        if (! Schema::connection($db->getName())->hasTable('liquidaciones_propietarios')) {
            return collect();
        }

        $cuit = preg_replace('/\D+/', '', (string) ($persona->nro_iva ?? '')) ?: '';

        $query = $db->table('liquidaciones_propietarios')
            ->where(function ($q) use ($cuentas, $cuit): void {
                if ($cuentas !== []) {
                    $q->whereIn(DB::raw("regexp_replace(coalesce(cuenta,''), '[^0-9]', '', 'g')"), $cuentas);
                }

                if ($cuit !== '') {
                    if ($cuentas !== []) {
                        $q->orWhereRaw(
                            "regexp_replace(coalesce(cuit,''), '[^0-9]', '', 'g') = ?",
                            [$cuit]
                        );
                    } else {
                        $q->whereRaw(
                            "regexp_replace(coalesce(cuit,''), '[^0-9]', '', 'g') = ?",
                            [$cuit]
                        );
                    }
                }
            })
            ->when($mes !== null, fn ($q) => $q->where('periodo', $mes))
            ->select([
                'id', 'periodo', 'fecha', 'cuenta', 'cuenta_impresa',
                'comprobante', 'numero_interno', 'propietario',
                'total_final', 'estado', 'pdf_ruta',
            ])
            ->orderByDesc('periodo')
            ->orderByDesc('numero_interno')
            ->limit(100);

        if ($cuentas === [] && $cuit === '') {
            return collect();
        }

        return $query->get()->map(function ($l): object {
            $l->pdf_disponible =
                $l->estado === 'PDF_GENERADO'
                && ! empty($l->pdf_ruta)
                && Storage::disk('liquidaciones')->exists((string) $l->pdf_ruta);

            // La existencia exacta del PDF de impuestos la resuelve la ruta
            // vigente del LiquidacionPropietarioController. Lo ofrecemos cuando
            // existe liquidación generada; el controlador conserva la regla real.
            $l->impuestos_pdf_disponible = $l->pdf_disponible;

            return $l;
        });
    }

    private function facturasKng(array $cuentas, ?string $mes = null): Collection
    {
        $cuentas = collect($cuentas)
            ->map(fn ($v): string => $this->normalizarCuenta((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($cuentas === []) {
            return collect();
        }

        $dbPath = (string) config('kng.cache_db');

        if ($dbPath === '' || ! is_file($dbPath) || ! is_readable($dbPath)) {
            return collect();
        }

        try {
            $pdo = new PDO('sqlite:'.$dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
        } catch (Throwable) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($cuentas), '?'));

        /*
         * Importante:
         * - no recorremos el filesystem en cada request;
         * - archivos_pdf fue indexado previamente por el proceso KNG;
         * - el mes visible se filtra en SQLite; otros meses se piden por AJAX;
         * - evitamos cargar todo el historial al abrir Cliente 360.
         */
        $sql = "
            select
                f.p_venta,
                f.id_factura,
                f.fecha,
                f.total,
                f.gravado,
                f.no_gravado,
                f.iva,
                f.lote,
                f.id_inq,
                f.percepcion,
                f.tipo,
                f.cae,
                f.vto_cae,
                f.propieta,
                f.cta_orig,
                l.detalle as detalle_lote,
                l.desde as lote_desde,
                l.hasta as lote_hasta,
                p.archivo as archivo_pdf,
                p.tipo_archivo,
                p.numero as numero_comprobante
            from facturas f
            left join lotes l
              on l.id_lote = f.lote
            left join archivos_pdf p
              on p.lote = f.lote
             and p.cuenta_cobol = cast(
                    case
                        when f.id_inq is not null and f.id_inq <> 0 then f.id_inq
                        else f.cta_orig
                    end
                    as text
                 )
             and p.punto_venta = f.p_venta
            where (cast(f.id_inq as text) in ({$placeholders})
               or cast(f.cta_orig as text) in ({$placeholders}))
            %MES_FILTRO%
            order by
                case when f.fecha is null then '' else f.fecha end desc,
                f.lote desc,
                f.id_factura desc,
                p.numero desc
            limit 1000
        ";

        $mesFiltro = '';
        $params = [...$cuentas, ...$cuentas];
        if ($mes !== null) {
            $mesSqlite = substr($mes, 0, 4).'-'.substr($mes, 4, 2);
            $mesFiltro = "and substr(coalesce(f.fecha,''),1,7) = ?";
            $params[] = $mesSqlite;
        }
        $sql = str_replace('%MES_FILTRO%', $mesFiltro, $sql);

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return collect($stmt->fetchAll())->map(function ($f): object {
                $f->comprobante = $f->archivo_pdf
                    ? preg_replace('/-\d{11}\.pdf$/i', '', (string) $f->archivo_pdf)
                    : null;

                return $f;
            });
        } catch (Throwable) {
            return collect();
        }
    }


    public function resolverMesActividad(int $personaId, string $tipo, ?string $mesPreferido = null): string
    {
        $mesPreferido = $mesPreferido ?: now()->format('Ym');

        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $mesPreferido)) {
            $mesPreferido = now()->format('Ym');
        }

        // Si el mes actual/solicitado tiene datos, lo usamos tal cual.
        if ($this->actividad($personaId, $tipo, $mesPreferido)->isNotEmpty()) {
            return $mesPreferido;
        }

        $core = $this->core();
        $persona = $core->selectOne(
            "select * from gei_core.personas where id = ? and fusionada_en_id is null",
            [$personaId]
        );

        if ($persona === null) {
            throw new RuntimeException('No existe el cliente GeI-Core solicitado.');
        }

        if ($tipo === 'cuenta-corriente') {
            $fechaNormalizada = "case
                when m.fecha_original ~ '^(19|20)[0-9]{6}$' then m.fecha_original
                when m.fecha_original ~ '^[0-9]{4}(19|20)[0-9]{2}$'
                    then substr(m.fecha_original,5,4)
                      || substr(m.fecha_original,3,2)
                      || substr(m.fecha_original,1,2)
                else ''
            end";

            $fila = $core->selectOne(
                "select max(substr(({$fechaNormalizada}),1,6)) as mes
                   from gei_core.cuentas_corrientes cc
                   join gei_core.cuentas_corrientes_movimientos m
                     on m.cuenta_corriente_id = cc.id
                  where cc.persona_id = ?
                    and substr(({$fechaNormalizada}),1,6) <= ?",
                [$personaId, $mesPreferido]
            );

            return (string) ($fila->mes ?: $mesPreferido);
        }

        $cuentas = collect($core->select(
            "select rol, cuenta_cobol
               from gei_core.personas_cuentas_cobol
              where persona_id = ?",
            [$personaId]
        ));

        if ($tipo === 'facturas') {
            $todas = $cuentas->pluck('cuenta_cobol')
                ->map(fn ($v) => $this->normalizarCuenta((string) $v))
                ->filter()->unique()->values()->all();

            return $this->ultimoMesFacturasKng($todas, $mesPreferido) ?: $mesPreferido;
        }

        if (in_array($tipo, ['liquidaciones', 'impuestos'], true)) {
            $propietario = $cuentas->where('rol', 'PROPIETARIO')->pluck('cuenta_cobol')
                ->map(fn ($v) => $this->normalizarCuenta((string) $v))
                ->filter()->unique()->values()->all();

            return $this->ultimoMesLiquidaciones($persona, $propietario, $mesPreferido) ?: $mesPreferido;
        }

        return $mesPreferido;
    }

    private function ultimoMesLiquidaciones(object $persona, array $cuentas, string $hastaMes): ?string
    {
        $db = DB::connection();
        if (! Schema::connection($db->getName())->hasTable('liquidaciones_propietarios')) {
            return null;
        }

        $cuit = preg_replace('/\D+/', '', (string) ($persona->nro_iva ?? '')) ?: '';
        if ($cuentas === [] && $cuit === '') {
            return null;
        }

        $fila = $db->table('liquidaciones_propietarios')
            ->where(function ($q) use ($cuentas, $cuit): void {
                if ($cuentas !== []) {
                    $q->whereIn(DB::raw("regexp_replace(coalesce(cuenta,''), '[^0-9]', '', 'g')"), $cuentas);
                }

                if ($cuit !== '') {
                    if ($cuentas !== []) {
                        $q->orWhereRaw(
                            "regexp_replace(coalesce(cuit,''), '[^0-9]', '', 'g') = ?",
                            [$cuit]
                        );
                    } else {
                        $q->whereRaw(
                            "regexp_replace(coalesce(cuit,''), '[^0-9]', '', 'g') = ?",
                            [$cuit]
                        );
                    }
                }
            })
            ->where('periodo', '<=', $hastaMes)
            ->selectRaw('max(periodo) as mes')
            ->first();

        return isset($fila->mes) && preg_match('/^(19|20)\d{4}$/', (string) $fila->mes)
            ? (string) $fila->mes
            : null;
    }

    private function ultimoMesFacturasKng(array $cuentas, string $hastaMes): ?string
    {
        $cuentas = collect($cuentas)
            ->map(fn ($v): string => $this->normalizarCuenta((string) $v))
            ->filter()->unique()->values()->all();

        if ($cuentas === []) {
            return null;
        }

        $dbPath = (string) config('kng.cache_db');
        if ($dbPath === '' || ! is_file($dbPath) || ! is_readable($dbPath)) {
            return null;
        }

        try {
            $pdo = new PDO('sqlite:'.$dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
        } catch (Throwable) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($cuentas), '?'));
        $hastaSqlite = substr($hastaMes, 0, 4).'-'.substr($hastaMes, 4, 2);
        $params = [...$cuentas, ...$cuentas, $hastaSqlite];

        $sql = "
            select max(substr(coalesce(fecha,''),1,7)) as mes
              from facturas
             where (cast(id_inq as text) in ({$placeholders})
                or cast(cta_orig as text) in ({$placeholders}))
               and substr(coalesce(fecha,''),1,7) <= ?
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $fila = $stmt->fetch();
            $mes = is_object($fila) ? (string) ($fila->mes ?? '') : '';

            return preg_match('/^(19|20)\d{2}-\d{2}$/', $mes)
                ? str_replace('-', '', $mes)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function actividad(int $personaId, string $tipo, string $mes): Collection
    {
        $core = $this->core();
        $persona = $core->selectOne(
            "select * from gei_core.personas where id = ? and fusionada_en_id is null",
            [$personaId]
        );

        if ($persona === null) {
            throw new RuntimeException('No existe el cliente GeI-Core solicitado.');
        }

        if ($tipo === 'cuenta-corriente') {
            return $this->movimientosMes($personaId, $mes);
        }

        $cuentas = collect($core->select(
            "select rol, cuenta_cobol
               from gei_core.personas_cuentas_cobol
              where persona_id = ?",
            [$personaId]
        ));

        if ($tipo === 'facturas') {
            return $this->facturasKng(
                $cuentas->pluck('cuenta_cobol')->map(fn ($v) => $this->normalizarCuenta((string) $v))->filter()->unique()->values()->all(),
                $mes
            );
        }

        $liquidaciones = $this->liquidaciones(
            $persona,
            $cuentas->where('rol', 'PROPIETARIO')->pluck('cuenta_cobol')->map(fn ($v) => $this->normalizarCuenta((string) $v))->filter()->unique()->values()->all(),
            $mes
        );

        return $tipo === 'impuestos'
            ? $liquidaciones->filter(fn ($l): bool => (bool) $l->impuestos_pdf_disponible)->values()
            : $liquidaciones;
    }

    private function movimientosMes(int $personaId, string $mes): Collection
    {
        $fechaNormalizada = "case
            when m.fecha_original ~ '^(19|20)[0-9]{6}$' then m.fecha_original
            when m.fecha_original ~ '^[0-9]{4}(19|20)[0-9]{2}$'
                then substr(m.fecha_original,5,4)
                  || substr(m.fecha_original,3,2)
                  || substr(m.fecha_original,1,2)
            else ''
        end";

        return collect($this->core()->select(
            "select
                cc.tipo,
                cc.cuenta_cobol,
                m.fecha_original,
                m.codigo,
                m.numero,
                m.descripcion,
                m.importe,
                m.liquidado
             from gei_core.cuentas_corrientes cc
             join gei_core.cuentas_corrientes_movimientos m
               on m.cuenta_corriente_id = cc.id
             where cc.persona_id = ?
               and substr(({$fechaNormalizada}),1,6) = ?
             order by ({$fechaNormalizada}), m.codigo, m.numero
             limit 500",
            [$personaId, $mes]
        ));
    }

    private function rol(string $rol): string
    {
        $rol = strtoupper(trim($rol));

        return in_array($rol, ['TODOS', 'PROPIETARIO', 'INQUILINO'], true)
            ? $rol
            : 'TODOS';
    }

    private function normalizarCuenta(string $cuenta): string
    {
        return preg_replace('/\D+/', '', $cuenta) ?: '';
    }

    private function core(): Connection
    {
        $base = config('database.connections.pgsql');

        if (! is_array($base)) {
            throw new RuntimeException('No existe la conexión PostgreSQL base.');
        }

        $base['host'] = config('gei.exploracion.host');
        $base['port'] = config('gei.exploracion.port');
        $base['database'] = config('gei.exploracion.database');
        $base['username'] = config('gei.exploracion.username');
        $base['password'] = config('gei.exploracion.password');
        $base['search_path'] = 'public';

        config(['database.connections.gei_exploracion' => $base]);
        DB::purge('gei_exploracion');

        return DB::connection('gei_exploracion');
    }
}
