<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GeiCoreConsultaService
{
    public function periodos(): array
    {
        $db = $this->conexion();

        return array_map(
            static fn ($r): array => [
                'periodo' => (string) $r->periodo,
                'estado' => (string) $r->estado,
                'fecha_ultima_liquidacion' => $r->fecha_ultima_liquidacion === null
                    ? null
                    : (string) $r->fecha_ultima_liquidacion,
            ],
            $db->select(
                "select periodo, estado, fecha_ultima_liquidacion
                   from gei_core.periodos
                  order by periodo desc"
            )
        );
    }

    public function ultimoPeriodo(): string
    {
        $periodo = $this->conexion()->selectOne(
            "select max(periodo) as periodo from gei_core.periodos"
        )->periodo ?? null;

        if ($periodo === null) {
            throw new RuntimeException(
                'GeI-Core todavía no tiene períodos procesados.'
            );
        }

        return (string) $periodo;
    }

    public function personasResumen(string $periodo, string $rol): array
    {
        $db = $this->conexion();
        $rol = $this->validarRolPersona($rol);

        $bindings = [$periodo];
        $filtroRol = '';
        if ($rol !== 'TODOS') {
            $filtroRol = ' and pp.rol = ?';
            $bindings[] = $rol;
        }

        $fila = $db->selectOne(
            "select
                count(distinct pp.persona_id) as personas,
                count(distinct pp.persona_id) filter (where pp.activo) as activas,
                count(distinct po.rol || ':' || po.cuenta_cobol) as cuentas,
                count(distinct po.rol || ':' || po.cuenta_cobol)
                    filter (where po.activo_en_periodo) as cuentas_activas
             from gei_core.personas_periodos pp
             left join gei_core.personas_origenes po
               on po.persona_id = pp.persona_id
              and po.periodo = pp.periodo
              and po.rol = pp.rol
             where pp.periodo = ? {$filtroRol}",
            $bindings
        );

        $multi = $db->selectOne(
            "select count(*) as total
             from (
                select pp.persona_id
                from gei_core.personas_periodos pp
                join gei_core.personas_origenes po
                  on po.persona_id = pp.persona_id
                 and po.periodo = pp.periodo
                 and po.rol = pp.rol
                where pp.periodo = ? {$filtroRol}
                group by pp.persona_id
                having count(distinct po.rol || ':' || po.cuenta_cobol) > 1
             ) x",
            $bindings
        );

        return [
            'personas' => (int) ($fila->personas ?? 0),
            'activas' => (int) ($fila->activas ?? 0),
            'cuentas' => (int) ($fila->cuentas ?? 0),
            'cuentas_activas' => (int) ($fila->cuentas_activas ?? 0),
            'multiples_cuentas' => (int) ($multi->total ?? 0),
        ];
    }

    public function personas(
        string $periodo,
        string $rol,
        string $estado,
        string $buscar,
        int $porPagina = 50
    ): LengthAwarePaginator {
        $db = $this->conexion();
        $rol = $this->validarRolPersona($rol);
        $estado = in_array($estado, ['activos', 'todos'], true) ? $estado : 'activos';
        $buscar = trim($buscar);

        $q = $db->table('gei_core.personas_periodos as pp')
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
                    $s->whereRaw('p.nombre ilike ?', [$like])
                        ->orWhereRaw("coalesce(p.nro_iva, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(p.nro_documento, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(p.domicilio, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(p.localidad, '') ilike ?", [$like])
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
                 string_agg(
                    distinct pp.rol,
                    ', ' order by pp.rol
                 ) as roles,
                 string_agg(
                    distinct po.cuenta_cobol,
                    ', ' order by po.cuenta_cobol
                 ) filter (where po.cuenta_cobol is not null) as cuentas_cobol,
                 count(distinct po.rol || ':' || po.cuenta_cobol)::integer as cantidad_cuentas"
            )
            ->orderByRaw('p.nombre nulls last')
            ->orderBy('p.id');

        return $q->paginate($porPagina)->withQueryString();
    }

    public function personaDetalle(int $id, string $periodo): array
    {
        $db = $this->conexion();

        $persona = $db->selectOne(
            "select *
               from gei_core.personas
              where id = ?
                and fusionada_en_id is null",
            [$id]
        );

        if ($persona === null) {
            throw new RuntimeException('No existe la persona solicitada.');
        }

        $roles = $db->select(
            "select rol, primer_periodo, ultimo_periodo, activo
               from gei_core.personas_roles
              where persona_id = ?
              order by rol",
            [$id]
        );

        $cuentas = $db->select(
            "select rol, cuenta_cobol, primer_periodo, ultimo_periodo, activa
               from gei_core.personas_cuentas_cobol
              where persona_id = ?
              order by rol, cuenta_cobol",
            [$id]
        );

        $contratos = $db->select(
            "select
                cp.contrato_id,
                cp.periodo,
                cp.activo,
                cp.cuenta_inquilino_cobol,
                cp.cuenta_propietario_cobol,
                i.domicilio_actual,
                case
                    when cp.persona_inquilino_id = ? then 'INQUILINO'
                    when cp.persona_propietario_id = ? then 'PROPIETARIO'
                    else 'RELACIONADO'
                end as rol_contrato
             from gei_core.contratos_periodos cp
             join gei_core.inmuebles i on i.id = cp.inmueble_id
             where cp.periodo = ?
               and (
                    cp.persona_inquilino_id = ?
                    or cp.persona_propietario_id = ?
               )
             order by cp.activo desc, i.domicilio_actual nulls last, cp.contrato_id",
            [$id, $id, $periodo, $id, $id]
        );

        return compact('persona', 'roles', 'cuentas', 'contratos');
    }

    public function inmueblesResumen(string $periodo): array
    {
        $db = $this->conexion();

        $fila = $db->selectOne(
            "select
                count(*) as inmuebles,
                count(*) filter (where ip.activo) as activos,
                sum(ip.cantidad_contratos)::bigint as contratos
             from gei_core.inmuebles_periodos ip
             where ip.periodo = ?",
            [$periodo]
        );

        $partidas = $db->selectOne(
            "select count(distinct p.inmueble_id) as con_partidas
               from gei_core.inmuebles_periodos ip
               join gei_core.inmuebles_partidas p on p.inmueble_id = ip.inmueble_id
              where ip.periodo = ?",
            [$periodo]
        );

        return [
            'inmuebles' => (int) ($fila->inmuebles ?? 0),
            'activos' => (int) ($fila->activos ?? 0),
            'contratos' => (int) ($fila->contratos ?? 0),
            'con_partidas' => (int) ($partidas->con_partidas ?? 0),
        ];
    }

    public function inmuebles(
        string $periodo,
        string $estado,
        string $buscar,
        int $porPagina = 50
    ): LengthAwarePaginator {
        $db = $this->conexion();
        $estado = in_array($estado, ['activos', 'todos'], true) ? $estado : 'activos';
        $buscar = trim($buscar);

        $q = $db->table('gei_core.inmuebles_periodos as ip')
            ->join('gei_core.inmuebles as i', 'i.id', '=', 'ip.inmueble_id')
            ->leftJoin('gei_core.contratos_periodos as cp', function ($join) use ($periodo): void {
                $join->on('cp.inmueble_id', '=', 'i.id')
                    ->where('cp.periodo', '=', $periodo);
            })
            ->where('ip.periodo', $periodo)
            ->when($estado === 'activos', fn ($x) => $x->where('ip.activo', true))
            ->when($buscar !== '', function ($x) use ($buscar): void {
                $like = '%'.$buscar.'%';
                $x->where(function ($s) use ($like): void {
                    $s->whereRaw("coalesce(i.domicilio_actual, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(cp.cuenta_inquilino_cobol, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(cp.cuenta_propietario_cobol, '') ilike ?", [$like])
                        ->orWhereExists(function ($sq) use ($like): void {
                            $sq->selectRaw('1')
                                ->from('gei_core.inmuebles_partidas as pt')
                                ->whereColumn('pt.inmueble_id', 'i.id')
                                ->whereRaw('pt.partida ilike ?', [$like]);
                        });
                });
            })
            ->groupBy([
                'i.id', 'i.domicilio_actual', 'ip.activo', 'ip.cantidad_contratos',
            ])
            ->selectRaw(
                "i.id,
                 i.domicilio_actual,
                 ip.activo,
                 ip.cantidad_contratos,
                 count(distinct cp.contrato_id) filter (where cp.activo)::integer as contratos_activos,
                 string_agg(
                    distinct cp.cuenta_propietario_cobol,
                    ', ' order by cp.cuenta_propietario_cobol
                 ) filter (where cp.cuenta_propietario_cobol is not null) as cuentas_propietario,
                 string_agg(
                    distinct cp.cuenta_inquilino_cobol,
                    ', ' order by cp.cuenta_inquilino_cobol
                 ) filter (where cp.cuenta_inquilino_cobol is not null) as cuentas_inquilino"
            )
            ->orderByRaw('i.domicilio_actual nulls last')
            ->orderBy('i.id');

        return $q->paginate($porPagina)->withQueryString();
    }



    /**
     * Posibles inmuebles duplicados presentes y activos en un período.
     *
     * Cada inmueble puede validarse individualmente como único frente al
     * conjunto actual de candidatos. La validación permanece mientras ese
     * conjunto no cambie; si aparece otro candidato, vuelve a quedar pendiente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inmueblesDuplicados(string $periodo, string $buscar = ''): array
    {
        $db = $this->conexion();
        $buscar = trim($buscar);

        $filas = $db->table('gei_core.inmuebles_periodos as ip')
            ->join('gei_core.inmuebles as i', 'i.id', '=', 'ip.inmueble_id')
            ->leftJoin('gei_core.contratos_periodos as cp', function ($join) use ($periodo): void {
                $join->on('cp.inmueble_id', '=', 'i.id')
                    ->where('cp.periodo', '=', $periodo);
            })
            ->leftJoin('gei_core.inmuebles_partidas as pt', 'pt.inmueble_id', '=', 'i.id')
            ->where('ip.periodo', $periodo)
            ->where('ip.activo', true)
            ->when($buscar !== '', function ($q) use ($buscar): void {
                $like = '%'.$buscar.'%';
                $q->where(function ($s) use ($like): void {
                    $s->whereRaw("coalesce(i.domicilio_actual, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(cp.cuenta_propietario_cobol, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(cp.cuenta_inquilino_cobol, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(pt.partida, '') ilike ?", [$like]);
                });
            })
            ->groupBy(['i.id', 'i.domicilio_actual', 'ip.cantidad_contratos'])
            ->selectRaw(
                "i.id,
                 i.domicilio_actual,
                 ip.cantidad_contratos,
                 count(distinct cp.contrato_id) filter (where cp.activo)::integer as contratos_activos,
                 string_agg(distinct cp.cuenta_propietario_cobol, ', ' order by cp.cuenta_propietario_cobol)
                    filter (where cp.cuenta_propietario_cobol is not null) as cuentas_propietario,
                 string_agg(distinct cp.cuenta_inquilino_cobol, ', ' order by cp.cuenta_inquilino_cobol)
                    filter (where cp.cuenta_inquilino_cobol is not null) as cuentas_inquilino,
                 string_agg(distinct pt.partida, ', ' order by pt.partida)
                    filter (where pt.partida is not null) as partidas"
            )
            ->orderByRaw('i.domicilio_actual nulls last')
            ->orderBy('i.id')
            ->get();

        $grupos = [];
        foreach ($filas as $fila) {
            $firma = $this->claveCalleAlturaParaSugerencia((string) ($fila->domicilio_actual ?? ''));
            if ($firma === '') {
                continue;
            }

            if (! isset($grupos[$firma])) {
                $grupos[$firma] = [
                    'firma' => $firma,
                    'items' => [],
                    'cuentas_propietario' => [],
                    'partidas' => [],
                ];
            }

            $grupos[$firma]['items'][] = $fila;

            foreach ($this->separarLista((string) ($fila->cuentas_propietario ?? '')) as $cuenta) {
                $grupos[$firma]['cuentas_propietario'][$cuenta] = ($grupos[$firma]['cuentas_propietario'][$cuenta] ?? 0) + 1;
            }
            foreach ($this->separarLista((string) ($fila->partidas ?? '')) as $partida) {
                $grupos[$firma]['partidas'][$partida] = ($grupos[$firma]['partidas'][$partida] ?? 0) + 1;
            }
        }

        $validaciones = $this->validacionesInmuebles();
        $resultado = [];

        foreach ($grupos as $grupo) {
            if (count($grupo['items']) < 2) {
                continue;
            }

            $idsGrupo = array_map(static fn ($i): int => (int) $i->id, $grupo['items']);
            sort($idsGrupo, SORT_NUMERIC);

            $items = [];
            $pendientes = 0;
            foreach ($grupo['items'] as $fila) {
                $otros = array_values(array_filter(
                    $idsGrupo,
                    static fn (int $id): bool => $id !== (int) $fila->id
                ));
                $hash = hash('sha256', implode(',', $otros));
                $clave = ((int) $fila->id).':'.$hash;
                $validado = isset($validaciones[$clave]);

                if (! $validado) {
                    $pendientes++;
                }

                $items[] = (object) array_merge((array) $fila, [
                    'revision_hash' => $hash,
                    'revision_validado' => $validado,
                    'revision_validado_at' => $validaciones[$clave]->validado_at ?? null,
                ]);
            }

            $cuentasCompartidas = array_keys(array_filter(
                $grupo['cuentas_propietario'],
                static fn (int $cantidad): bool => $cantidad >= 2
            ));
            $partidasCompartidas = array_keys(array_filter(
                $grupo['partidas'],
                static fn (int $cantidad): bool => $cantidad >= 2
            ));
            sort($cuentasCompartidas, SORT_STRING);
            sort($partidasCompartidas, SORT_STRING);

            $resultado[] = [
                'firma' => $grupo['firma'],
                'items' => $items,
                'cuentas_propietario' => $cuentasCompartidas,
                'partidas' => $partidasCompartidas,
                'confianza' => $partidasCompartidas !== [] || $cuentasCompartidas !== [] ? 'ALTA' : 'MEDIA',
                'motivo' => $partidasCompartidas !== []
                    ? 'Misma calle y altura, con partida compartida.'
                    : ($cuentasCompartidas !== []
                        ? 'Misma calle y altura, con cuenta de propietario compartida.'
                        : 'Misma calle y altura. Revisar piso, unidad, propietario y contratos.'),
                'pendientes' => $pendientes,
                'resuelto' => $pendientes === 0,
            ];
        }

        usort($resultado, static function (array $a, array $b): int {
            $rango = ['ALTA' => 0, 'MEDIA' => 1];
            return [
                $a['resuelto'] ? 1 : 0,
                $rango[$a['confianza']] ?? 9,
                $a['firma'],
            ] <=> [
                $b['resuelto'] ? 1 : 0,
                $rango[$b['confianza']] ?? 9,
                $b['firma'],
            ];
        });

        return $resultado;
    }

    /** @return array{grupos:int,grupos_pendientes:int,inmuebles_pendientes:int} */
    public function inmueblesDuplicadosResumen(string $periodo): array
    {
        $grupos = $this->inmueblesDuplicados($periodo);

        return [
            'grupos' => count($grupos),
            'grupos_pendientes' => count(array_filter(
                $grupos,
                static fn (array $g): bool => ! $g['resuelto']
            )),
            'inmuebles_pendientes' => array_sum(array_map(
                static fn (array $g): int => (int) $g['pendientes'],
                $grupos
            )),
        ];
    }

    public function validarInmuebleComoUnico(int $inmuebleId, string $periodo, ?int $usuarioId): void
    {
        $grupo = $this->buscarGrupoDuplicadoDelInmueble($inmuebleId, $periodo);
        if ($grupo === null) {
            throw new RuntimeException('El inmueble ya no tiene candidatos activos para revisar.');
        }

        $otros = array_values(array_filter(
            array_map(static fn ($i): int => (int) $i->id, $grupo['items']),
            static fn (int $id): bool => $id !== $inmuebleId
        ));
        sort($otros, SORT_NUMERIC);
        $hash = hash('sha256', implode(',', $otros));

        $this->conexion()->table('gei_core.inmuebles_validaciones')->updateOrInsert(
            [
                'inmueble_id' => $inmuebleId,
                'candidatos_hash' => $hash,
            ],
            [
                'candidatos_ids' => json_encode($otros, JSON_THROW_ON_ERROR),
                'periodo_referencia' => $periodo,
                'usuario_id' => $usuarioId,
                'validado_at' => now(),
            ]
        );
    }

    public function deshacerValidacionInmueble(int $inmuebleId, string $periodo): void
    {
        $grupo = $this->buscarGrupoDuplicadoDelInmueble($inmuebleId, $periodo);
        if ($grupo === null) {
            return;
        }

        $otros = array_values(array_filter(
            array_map(static fn ($i): int => (int) $i->id, $grupo['items']),
            static fn (int $id): bool => $id !== $inmuebleId
        ));
        sort($otros, SORT_NUMERIC);
        $hash = hash('sha256', implode(',', $otros));

        $this->conexion()->table('gei_core.inmuebles_validaciones')
            ->where('inmueble_id', $inmuebleId)
            ->where('candidatos_hash', $hash)
            ->delete();
    }

    /** @return array<string, mixed>|null */
    private function buscarGrupoDuplicadoDelInmueble(int $inmuebleId, string $periodo): ?array
    {
        foreach ($this->inmueblesDuplicados($periodo) as $grupo) {
            foreach ($grupo['items'] as $item) {
                if ((int) $item->id === $inmuebleId) {
                    return $grupo;
                }
            }
        }

        return null;
    }

    /** @return array<string, object> */
    private function validacionesInmuebles(): array
    {
        $db = $this->conexion();
        $existe = $db->selectOne("select to_regclass('gei_core.inmuebles_validaciones') as tabla")->tabla ?? null;
        if ($existe === null) {
            return [];
        }

        $resultado = [];
        foreach ($db->table('gei_core.inmuebles_validaciones')->get() as $fila) {
            $resultado[((int) $fila->inmueble_id).':'.(string) $fila->candidatos_hash] = $fila;
        }

        return $resultado;
    }

    public function inmuebleDetalle(int $id, string $periodo): array
    {
        $db = $this->conexion();

        $inmueble = $db->selectOne(
            "select i.*, ip.activo as activo_periodo, ip.cantidad_contratos
               from gei_core.inmuebles i
               join gei_core.inmuebles_periodos ip
                 on ip.inmueble_id = i.id
                and ip.periodo = ?
              where i.id = ?",
            [$periodo, $id]
        );

        if ($inmueble === null) {
            throw new RuntimeException(
                'El inmueble no está presente en el período seleccionado.'
            );
        }

        $partidas = $db->select(
            "select partida, primer_periodo, ultimo_periodo
               from gei_core.inmuebles_partidas
              where inmueble_id = ?
              order by partida",
            [$id]
        );

        $contratos = $db->select(
            "select
                cp.*,
                pi.nombre as inquilino_nombre,
                pp.nombre as propietario_nombre,
                c.fecha_inicio_original,
                c.fecha_contrato_original,
                c.fecha_vencimiento_original,
                c.fecha_baja_original
             from gei_core.contratos_periodos cp
             join gei_core.contratos c on c.id = cp.contrato_id
             left join gei_core.personas pi on pi.id = cp.persona_inquilino_id
             left join gei_core.personas pp on pp.id = cp.persona_propietario_id
             where cp.periodo = ?
               and cp.inmueble_id = ?
             order by cp.activo desc, cp.cuenta_inquilino_cobol",
            [$periodo, $id]
        );

        return compact('inmueble', 'partidas', 'contratos');
    }

    public function cuentasResumen(string $tipo): array
    {
        $db = $this->conexion();
        $tipo = $this->validarTipoCuenta($tipo);

        $fila = $db->selectOne(
            "select
                count(*) as cuentas,
                count(*) filter (where activa) as activas
             from gei_core.cuentas_corrientes
             where tipo = ?",
            [$tipo]
        );

        $mov = $db->selectOne(
            "select count(*) as movimientos
               from gei_core.cuentas_corrientes_movimientos m
               join gei_core.cuentas_corrientes c on c.id = m.cuenta_corriente_id
              where c.tipo = ?",
            [$tipo]
        );

        return [
            'cuentas' => (int) ($fila->cuentas ?? 0),
            'activas' => (int) ($fila->activas ?? 0),
            'movimientos' => (int) ($mov->movimientos ?? 0),
        ];
    }

    public function cuentas(
        string $tipo,
        string $estado,
        string $buscar,
        int $porPagina = 50
    ): LengthAwarePaginator {
        $db = $this->conexion();
        $tipo = $this->validarTipoCuenta($tipo);
        $estado = in_array($estado, ['activas', 'todas'], true) ? $estado : 'activas';
        $buscar = trim($buscar);

        $q = $db->table('gei_core.cuentas_corrientes as c')
            ->leftJoin('gei_core.personas as p', 'p.id', '=', 'c.persona_id')
            ->leftJoin('gei_core.cuentas_corrientes_movimientos as m', 'm.cuenta_corriente_id', '=', 'c.id')
            ->where('c.tipo', $tipo)
            ->when($estado === 'activas', fn ($x) => $x->where('c.activa', true))
            ->when($buscar !== '', function ($x) use ($buscar): void {
                $like = '%'.$buscar.'%';
                $x->where(function ($s) use ($like): void {
                    $s->whereRaw('c.cuenta_cobol ilike ?', [$like])
                        ->orWhereRaw("coalesce(p.nombre, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(p.nro_iva, '') ilike ?", [$like])
                        ->orWhereRaw("coalesce(p.nro_documento, '') ilike ?", [$like]);
                });
            })
            ->groupBy([
                'c.id', 'c.tipo', 'c.cuenta_cobol', 'c.activa',
                'c.primer_periodo', 'c.ultimo_periodo',
                'c.persona_id', 'c.contrato_id',
                'p.nombre', 'p.nro_iva', 'p.nro_documento',
            ])
            ->selectRaw(
                "c.id, c.tipo, c.cuenta_cobol, c.activa,
                 c.primer_periodo, c.ultimo_periodo,
                 c.persona_id, c.contrato_id,
                 p.nombre, p.nro_iva, p.nro_documento,
                 count(m.id)::bigint as movimientos,
                 min(m.fecha_original) as primera_fecha,
                 max(m.fecha_original) as ultima_fecha"
            )
            ->orderByRaw('p.nombre nulls last')
            ->orderBy('c.cuenta_cobol');

        return $q->paginate($porPagina)->withQueryString();
    }

    public function cuentaDetalle(int $id, string $orden = 'desc'): array
    {
        $db = $this->conexion();
        $orden = strtolower($orden) === 'asc' ? 'asc' : 'desc';

        $cuenta = $db->selectOne(
            "select
                c.*,
                p.nombre,
                p.nro_iva,
                p.nro_documento,
                ct.inmueble_id,
                i.domicilio_actual
             from gei_core.cuentas_corrientes c
             left join gei_core.personas p on p.id = c.persona_id
             left join gei_core.contratos ct on ct.id = c.contrato_id
             left join gei_core.inmuebles i on i.id = ct.inmueble_id
             where c.id = ?",
            [$id]
        );

        if ($cuenta === null) {
            throw new RuntimeException('No existe la cuenta corriente solicitada.');
        }

        $direccion = $orden === 'asc' ? 'asc' : 'desc';

        $movimientos = $db->select(
            "select
                fecha_original,
                codigo,
                numero,
                fecha_vencimiento_original,
                importe,
                importe_penal,
                importe_abonado,
                descripcion,
                cuenta_inquilino_relacionada,
                liquidado,
                iva,
                no_iva,
                primer_periodo,
                ultimo_periodo
             from gei_core.cuentas_corrientes_movimientos
             where cuenta_corriente_id = ?
             order by
                case
                    -- Formato habitual actual: AAAAMMDD.
                    when fecha_original ~ '^(19|20)[0-9]{6}$'
                        then fecha_original
                    -- Compatibilidad con registros históricos DDMMAAAA.
                    when fecha_original ~ '^[0-9]{4}(19|20)[0-9]{2}$'
                        then substr(fecha_original, 5, 4)
                          || substr(fecha_original, 3, 2)
                          || substr(fecha_original, 1, 2)
                    else fecha_original
                end {$direccion},
                codigo {$direccion},
                numero {$direccion}
             limit 150",
            [$id]
        );

        return compact('cuenta', 'movimientos', 'orden');
    }

    private function validarRolPersona(string $rol): string
    {
        $rol = strtoupper(trim($rol));

        return in_array($rol, ['PROPIETARIO', 'INQUILINO', 'TODOS'], true)
            ? $rol
            : 'PROPIETARIO';
    }

    private function validarTipoCuenta(string $tipo): string
    {
        $tipo = strtoupper(trim($tipo));

        return in_array($tipo, ['PROPIETARIO', 'INQUILINO'], true)
            ? $tipo
            : 'PROPIETARIO';
    }


    /** @return array<int, string> */
    private function separarLista(string $valor): array
    {
        if (trim($valor) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $valor))));
    }

    /**
     * Normalización exclusivamente para sugerir posibles duplicados.
     * No reemplaza ninguna dirección almacenada en GeI-Core.
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

        $valor = preg_replace(
            '/(\\d{3,6})\\s*-\\s*(\\d{1,2})\\s*P\\s*-\\s*OF(?:IC(?:INA)?)?/i',
            '$1 PISO $2 OFICINA ',
            $valor
        ) ?? $valor;

        $valor = preg_replace('/\\b(?:PISO|P)\\s*[\\.\\-]?\\s*0*(\\d+)\\b/i', ' PISO $1 ', $valor) ?? $valor;

        $valor = preg_replace_callback(
            '/\\bOF(?:IC(?:INA)?)?\\s*[\\.\\-]?\\s*0*(\\d+)\\b/i',
            static fn (array $m): string => ' OFICINA '.((string) ((int) $m[1])).' ',
            $valor
        ) ?? $valor;

        $valor = preg_replace_callback(
            '/\\bOFICINA\\s+(\\d+)(?:\\s+Y\\s+0*(\\d+))?/i',
            static function (array $m): string {
                $resultado = 'OFICINA '.((string) ((int) $m[1]));
                if (isset($m[2]) && $m[2] !== '') {
                    $resultado .= ' Y '.((string) ((int) $m[2]));
                }

                return $resultado;
            },
            $valor
        ) ?? $valor;

        $valor = preg_replace('/[^\\pL\\pN]+/u', ' ', $valor) ?? $valor;
        $valor = preg_replace('/\\s+/u', ' ', trim($valor)) ?? trim($valor);

        return $valor;
    }


    /**
     * Obtiene una clave amplia de calle + altura para sugerir duplicados.
     * Ignora piso/unidad/oficina a propósito: esos datos se revisan luego
     * dentro del grupo y no implican por sí solos que dos fincas sean iguales.
     */
    private function claveCalleAlturaParaSugerencia(string $domicilio): string
    {
        $valor = $this->normalizarDomicilioParaSugerencia($domicilio);
        if ($valor === '') {
            return '';
        }

        // Unifica abreviaturas frecuentes de nombres de calles.
        $reemplazos = [
            '/\\bAV\\b/u' => 'AVENIDA',
            '/\\bAVDA\\b/u' => 'AVENIDA',
            '/\\bGRAL\\b/u' => 'GENERAL',
            '/\\bPTE\\b/u' => 'PRESIDENTE',
            '/\\bGDOR\\b/u' => 'GOBERNADOR',
            '/\\bBV\\b/u' => 'BOULEVARD',
        ];
        foreach ($reemplazos as $patron => $reemplazo) {
            $valor = preg_replace($patron, $reemplazo, $valor) ?? $valor;
        }

        $tokens = preg_split('/\\s+/u', trim($valor)) ?: [];
        if ($tokens === []) {
            return '';
        }

        // La altura se toma como el último número de al menos 2 dígitos.
        // Esto evita confundir, por ejemplo, el "25" de "25 DE MAYO" con
        // la altura cuando luego existe un número de puerta.
        $indiceAltura = null;
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if (preg_match('/^\\d{2,5}$/', $tokens[$i]) === 1) {
                $indiceAltura = $i;
                break;
            }
        }

        if ($indiceAltura === null || $indiceAltura === 0) {
            return '';
        }

        $altura = (string) ((int) $tokens[$indiceAltura]);
        if ($altura === '0') {
            return '';
        }

        $calleTokens = array_slice($tokens, 0, $indiceAltura);
        $calle = trim(implode(' ', $calleTokens));
        if ($calle === '') {
            return '';
        }

        return $calle.' | '.$altura;
    }

    private function conexion(): Connection
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

        $db = DB::connection('gei_exploracion');

        if (($db->selectOne("select to_regclass('gei_core.personas') as tabla")->tabla ?? null) === null) {
            throw new RuntimeException(
                'No existe GeI-Core. Ejecutá primero gei:core-procesar-periodo.'
            );
        }

        return $db;
    }
}
