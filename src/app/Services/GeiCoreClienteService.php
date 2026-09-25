<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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

    public function resumen(string $periodo, string $rol, array $sedesPermitidas = []): array
    {
        $rol = $this->rol($rol);
        $bindings = [$periodo];
        $filtroRol = '';
        [$filtroSede, $bindingsSede] = $this->sqlFiltroCuentaPorSedes('po.cuenta_cobol', $sedesPermitidas);

        if ($rol !== 'TODOS') {
            $filtroRol = ' and pp.rol = ?';
            $bindings[] = $rol;
        }

        $fila = $this->core()->selectOne(
            "select
                count(distinct pp.persona_id) as personas,
                count(distinct pp.persona_id) filter (where pp.activo) as activas
             from gei_core.personas_periodos pp
             join gei_core.personas_origenes po
               on po.persona_id = pp.persona_id
              and po.periodo = pp.periodo
              and po.rol = pp.rol
             where pp.periodo = ? {$filtroRol} {$filtroSede}",
            [...$bindings, ...$bindingsSede]
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
    public function duplicadosActivos(string $periodo, string $buscar = '', array $sedesPermitidas = []): array
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
               {$this->sqlFiltroCuentaPorSedesTexto('pc.cuenta_cobol', $sedesPermitidas)}
             group by
                p.id, p.nombre, p.nro_iva, p.nro_documento,
                p.domicilio, p.localidad, p.provincia
             order by p.nombre nulls last, p.id",
            [$periodo, ...$this->prefijosCuentaPorSedes($sedesPermitidas)]
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
        int $porPagina = 50,
        array $sedesPermitidas = []
    ): LengthAwarePaginator {
        $rol = $this->rol($rol);
        $estado = in_array($estado, ['activos', 'todos'], true) ? $estado : 'activos';
        $buscar = trim($buscar);
        $prefijosSede = $this->prefijosCuentaPorSedes($sedesPermitidas);

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
            ->when($prefijosSede !== [], fn ($x) => $x->whereIn(
                DB::raw("left(regexp_replace(coalesce(po.cuenta_cobol, ''), '[^0-9]', '', 'g'), 4)"),
                $prefijosSede
            ))
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

    public function detalle(int $personaId, string $periodo, string $tab = 'datos', ?string $mesActividad = null, array $sedesPermitidas = []): array
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

        [$filtroCuenta, $bindingsCuenta] = $this->sqlFiltroCuentaPorSedes('cuenta_cobol', $sedesPermitidas);
        $cuentas = collect($core->select(
            "select id, rol, cuenta_cobol, activa, primer_periodo, ultimo_periodo
               from gei_core.personas_cuentas_cobol
              where persona_id = ? {$filtroCuenta}
              order by rol, cuenta_cobol",
            [$personaId, ...$bindingsCuenta]
        ));

        $rolesPermitidos = $cuentas->pluck('rol')->filter()->unique()->values();
        $roles = $roles->filter(fn ($fila) => $rolesPermitidos->contains($fila->rol))->values();

        [$filtroSedeContrato, $bindingsSedeContrato] = $this->sqlFiltroSedeInmueblePorSedes('ip.sede_codigo', $sedesPermitidas);

        $contratos = collect($core->select(
            "select
                cp.*,
                im.id as inmueble_id,
                im.domicilio_actual,
                pinq.nombre as inquilino_nombre,
                pprop.nombre as propietario_nombre
             from gei_core.contratos_periodos cp
             join gei_core.inmuebles im on im.id = cp.inmueble_id
             join gei_core.inmuebles_periodos ip
               on ip.inmueble_id = cp.inmueble_id
              and ip.periodo = cp.periodo
             left join gei_core.personas pinq on pinq.id = cp.persona_inquilino_id
             left join gei_core.personas pprop on pprop.id = cp.persona_propietario_id
             where cp.periodo = ?
               and (
                    cp.persona_inquilino_id = ?
                    or cp.persona_propietario_id = ?
               )
               {$filtroSedeContrato}
             order by cp.activo desc, im.domicilio_actual nulls last, cp.contrato_id",
            [$periodo, $personaId, $personaId, ...$bindingsSedeContrato]
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
             where cc.persona_id = ? {$filtroCuenta}
             group by
                cc.id, cc.tipo, cc.cuenta_cobol, cc.activa,
                cc.primer_periodo, cc.ultimo_periodo
             order by cc.tipo, cc.cuenta_cobol",
            [$personaId, ...$bindingsCuenta]
        ));

        $mesActividad = $mesActividad ?: now()->format('Ym');
        $ultimosMovimientos = $tab === 'cuenta-corriente'
            ? $this->movimientosMes($personaId, $mesActividad, $sedesPermitidas)
            : collect();

        $incidenciasFechasFuturas = $tab === 'cuenta-corriente'
            ? $this->incidenciasFechasFuturasMes($personaId, $mesActividad, $sedesPermitidas)
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
            'incidenciasFechasFuturas',
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

        $db = DB::connection();
        if (! Schema::connection($db->getName())->hasTable('kng_facturas')) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($cuentas), '?'));
        $params = [...$cuentas, ...$cuentas];
        $filtroMes = '';

        if ($mes !== null) {
            $filtroMes = "and left(coalesce(kf.datos->>'FECHA', ''), 7) = ?";
            $params[] = substr($mes, 0, 4).'-'.substr($mes, 4, 2);
        }

        $sql = "
            select
                kf.datos->>'P_VENTA' as p_venta,
                kf.datos->>'ID_FACTURA' as id_factura,
                nullif(kf.datos->>'FECHA', '') as fecha,
                nullif(kf.datos->>'TOTAL', '') as total,
                nullif(kf.datos->>'GRAVADO', '') as gravado,
                nullif(kf.datos->>'NO_GRAVADO', '') as no_gravado,
                nullif(kf.datos->>'IVA', '') as iva,
                kf.datos->>'LOTE' as lote,
                nullif(kf.datos->>'ID_INQ', '') as id_inq,
                nullif(kf.datos->>'PERCEPCION', '') as percepcion,
                nullif(kf.datos->>'TIPO', '') as tipo,
                nullif(kf.datos->>'CAE', '') as cae,
                nullif(kf.datos->>'VTO_CAE', '') as vto_cae,
                nullif(kf.datos->>'PROPIETA', '') as propieta,
                nullif(kf.datos->>'CTA_ORIG', '') as cta_orig,
                coalesce(
                    nullif(kl.datos->>'DETALLE', ''),
                    nullif(kl.datos->>'DESCRIPCION', ''),
                    nullif(kl.datos->>'DESCRI', '')
                ) as detalle_lote,
                nullif(kl.datos->>'DESDE', '') as lote_desde,
                nullif(kl.datos->>'HASTA', '') as lote_hasta
            from kng_facturas kf
            left join kng_lotes kl
              on kl.eliminado = false
             and coalesce(kl.datos->>'ID_LOTE', kl.datos->>'LOTE') = kf.datos->>'LOTE'
            where kf.eliminado = false
              and (
                    kf.datos->>'ID_INQ' in ({$placeholders})
                 or kf.datos->>'CTA_ORIG' in ({$placeholders})
              )
              {$filtroMes}
            order by
                coalesce(kf.datos->>'FECHA', '') desc,
                nullif(regexp_replace(coalesce(kf.datos->>'LOTE', ''), '[^0-9]', '', 'g'), '')::bigint desc nulls last,
                nullif(regexp_replace(coalesce(kf.datos->>'ID_FACTURA', ''), '[^0-9]', '', 'g'), '')::bigint desc nulls last
            limit 1000
        ";

        try {
            $filas = collect($db->select($sql, $params));
            $pdfPorLote = [];

            return $filas->map(function (object $f) use (&$pdfPorLote): object {
                return $this->asociarPdfKng($f, $pdfPorLote);
            });
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * Vincula una fila de FACTURAS.DBF con el PDF físico ya organizado en lote_*.
     * No usa ID_FACTURA para formar el nombre: ese campo es el identificador interno
     * de KNG, no el número fiscal. El PDF se identifica igual que en el cache viejo:
     * lote + cuenta COBOL + punto de venta, usando TIPO sólo para desempatar.
     *
     * @param array<string, array<string, list<string>>> $cache
     */
    private function asociarPdfKng(object $f, array &$cache): object
    {
        $f->archivo_pdf = null;
        $f->numero_comprobante = null;
        $f->comprobante = null;
        $f->pdf_en_raiz = false;

        $lote = preg_replace('/\\D+/', '', (string) ($f->lote ?? '')) ?: '';
        $puntoVenta = preg_replace('/\\D+/', '', (string) ($f->p_venta ?? '')) ?: '';
        $numero = preg_replace('/\\D+/', '', (string) ($f->id_factura ?? '')) ?: '';
        $cuenta = $this->normalizarCuenta((string) (($f->id_inq ?? '') ?: ($f->cta_orig ?? '')));

        if ($puntoVenta === '' || $numero === '' || $cuenta === '') {
            return $f;
        }

        $prefijo = match ((int) ($f->tipo ?? 0)) {
            1 => 'FA',
            3 => 'CA',
            6 => 'FB',
            8 => 'CB',
            default => null,
        };

        if ($prefijo === null) {
            return $f;
        }

        $archivo = sprintf(
            '%s-%04d-%08d-%011d.pdf',
            $prefijo,
            (int) $puntoVenta,
            (int) $numero,
            (int) $cuenta
        );

        // El nombre sale de FACTURAS.DBF. No ocultamos el enlace por una
        // comprobación de filesystem aquí: el controlador resuelve si está
        // en lote_<nro>/ o todavía suelto en Facturas/.
        $f->archivo_pdf = $archivo;
        $f->comprobante = preg_replace('/-\\d{11}\\.pdf$/i', '', $archivo);
        $f->numero_comprobante = str_pad($numero, 8, '0', STR_PAD_LEFT);

        return $f;
    }

    /** @return array<string, list<string>> */
    private function indexarPdfsLoteKng(string $lote): array
    {
        $root = rtrim((string) config('gei.kng.root', '/archivo-kng'), DIRECTORY_SEPARATOR);
        $dirNombre = (string) config('gei.kng.facturas_dir', 'Facturas');
        $directorio = $root.DIRECTORY_SEPARATOR.$dirNombre.DIRECTORY_SEPARATOR.'lote_'.$lote;

        if (! is_dir($directorio) || ! is_readable($directorio)) {
            return [];
        }

        $indice = [];
        foreach (scandir($directorio, SCANDIR_SORT_ASCENDING) ?: [] as $archivo) {
            if (preg_match('/^([A-Z]{2})-(\d{4})-(\d{8})-(\d{11})\.pdf$/i', $archivo, $m) !== 1) {
                continue;
            }

            $pv = (string) ((int) $m[2]);
            $cuenta = $this->normalizarCuenta($m[4]);
            $indice[$pv.'|'.$cuenta][] = $archivo;
        }

        return $indice;
    }


    public function resolverMesActividad(int $personaId, string $tipo, ?string $mesPreferido = null, array $sedesPermitidas = []): string
    {
        $mesPreferido = $mesPreferido ?: now()->format('Ym');

        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $mesPreferido)) {
            $mesPreferido = now()->format('Ym');
        }

        // Si el mes actual/solicitado tiene datos, lo usamos tal cual.
        if ($this->actividad($personaId, $tipo, $mesPreferido, $sedesPermitidas)->isNotEmpty()) {
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

            [$filtroCuentaMes, $bindingsCuentaMes] = $this->sqlFiltroCuentaPorSedes('cc.cuenta_cobol', $sedesPermitidas);
            $fila = $core->selectOne(
                "select max(substr(({$fechaNormalizada}),1,6)) as mes
                   from gei_core.cuentas_corrientes cc
                   join gei_core.cuentas_corrientes_movimientos m
                     on m.cuenta_corriente_id = cc.id
                  where cc.persona_id = ? {$filtroCuentaMes}
                    and substr(({$fechaNormalizada}),1,6) <= ?",
                [$personaId, ...$bindingsCuentaMes, $mesPreferido]
            );

            return (string) ($fila->mes ?: $mesPreferido);
        }

        [$filtroCuenta, $bindingsCuenta] = $this->sqlFiltroCuentaPorSedes('cuenta_cobol', $sedesPermitidas);
        $cuentas = collect($core->select(
            "select rol, cuenta_cobol
               from gei_core.personas_cuentas_cobol
              where persona_id = ? {$filtroCuenta}",
            [$personaId, ...$bindingsCuenta]
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

        $db = DB::connection();
        if (! Schema::connection($db->getName())->hasTable('kng_facturas')) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($cuentas), '?'));
        $hasta = substr($hastaMes, 0, 4).'-'.substr($hastaMes, 4, 2);
        $params = [...$cuentas, ...$cuentas, $hasta];

        $sql = "
            select max(left(coalesce(datos->>'FECHA', ''), 7)) as mes
              from kng_facturas
             where eliminado = false
               and (
                    datos->>'ID_INQ' in ({$placeholders})
                 or datos->>'CTA_ORIG' in ({$placeholders})
               )
               and left(coalesce(datos->>'FECHA', ''), 7) <= ?
        ";

        try {
            $fila = $db->selectOne($sql, $params);
            $mes = (string) ($fila->mes ?? '');

            return preg_match('/^(19|20)\d{2}-\d{2}$/', $mes)
                ? str_replace('-', '', $mes)
                : null;
        } catch (Throwable) {
            return null;
        }
    }


    public function actividad(int $personaId, string $tipo, string $mes, array $sedesPermitidas = []): Collection
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
            return $this->movimientosMes($personaId, $mes, $sedesPermitidas);
        }

        [$filtroCuenta, $bindingsCuenta] = $this->sqlFiltroCuentaPorSedes('cuenta_cobol', $sedesPermitidas);
        $cuentas = collect($core->select(
            "select rol, cuenta_cobol
               from gei_core.personas_cuentas_cobol
              where persona_id = ? {$filtroCuenta}",
            [$personaId, ...$bindingsCuenta]
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

    /**
     * Movimientos del período importado cuyo movimiento o vencimiento apunta
     * a un mes posterior. Se atan al período de origen, no al mes futuro:
     *
     * - al mirar 09/2026 muestra incidencias cargadas con periodo_origen=202609;
     * - al mirar 11/2026 no muestra las incidencias originadas en septiembre;
     * - al mirar 06/2026 tampoco.
     */
    public function incidenciasFechasFuturasMes(int $personaId, string $mes, array $sedesPermitidas = []): Collection
    {
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $mes)) {
            return collect();
        }

        [$filtroCuenta, $bindingsCuenta] = $this->sqlFiltroCuentaPorSedes('cuenta_cobol', $sedesPermitidas);
        $cuentas = collect($this->core()->select(
            "select cuenta_cobol
               from gei_core.personas_cuentas_cobol
              where persona_id = ? {$filtroCuenta}",
            [$personaId, ...$bindingsCuenta]
        ))
            ->pluck('cuenta_cobol')
            ->map(fn ($cuenta) => trim((string) $cuenta))
            ->filter()
            ->unique()
            ->values();

        if ($cuentas->isEmpty()) {
            return collect();
        }

        $ruta = "liquidaciones/periodos/{$mes}/incidencias_importacion.json";

        if (! Storage::exists($ruta)) {
            return collect();
        }

        try {
            $contenido = json_decode(Storage::get($ruta), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return collect();
        }

        $futuras = $contenido['fechas_futuras_inqctacte'] ?? [];
        $invalidas = $contenido['fechas_invalidas_inqctacte'] ?? [];

        if (! is_array($futuras)) {
            $futuras = [];
        }
        if (! is_array($invalidas)) {
            $invalidas = [];
        }

        $futuras = collect($futuras)
            ->filter(static function ($fila) use ($cuentas, $mes): bool {
                if (! is_array($fila)) {
                    return false;
                }

                $cuenta = trim((string) ($fila['cuenta_cobol'] ?? ''));
                $periodoVencimiento = trim((string) ($fila['periodo_vencimiento'] ?? ''));

                return $cuentas->contains($cuenta)
                    && preg_match('/^(19|20)\d{4}$/', $periodoVencimiento) === 1
                    && $periodoVencimiento > $mes;
            })
            ->map(static function (array $fila): object {
                return (object) [
                    'tipo_incidencia' => 'FECHA_FUTURA',
                    'linea' => (int) ($fila['linea'] ?? 0),
                    'cuenta_cobol' => trim((string) ($fila['cuenta_cobol'] ?? '')),
                    'fecha_movimiento' => trim((string) ($fila['fecha_movimiento'] ?? '')),
                    'codigo' => trim((string) ($fila['codigo'] ?? '')),
                    'numero_cobol' => trim((string) ($fila['numero_cobol'] ?? '')),
                    'fecha_vencimiento' => trim((string) ($fila['fecha_vencimiento'] ?? '')),
                    'periodo_vencimiento' => trim((string) ($fila['periodo_vencimiento'] ?? '')),
                    'motivo' => 'Vencimiento posterior al período',
                ];
            });

        $invalidas = collect($invalidas)
            ->filter(static function ($fila) use ($cuentas): bool {
                if (! is_array($fila)) {
                    return false;
                }

                return $cuentas->contains(trim((string) ($fila['cuenta_cobol'] ?? '')));
            })
            ->map(static function (array $fila): object {
                return (object) [
                    'tipo_incidencia' => 'FECHA_INVALIDA',
                    'linea' => (int) ($fila['linea'] ?? 0),
                    'cuenta_cobol' => trim((string) ($fila['cuenta_cobol'] ?? '')),
                    'fecha_movimiento' => trim((string) ($fila['fecha_movimiento'] ?? '')),
                    'codigo' => trim((string) ($fila['codigo'] ?? '')),
                    'numero_cobol' => trim((string) ($fila['numero_cobol'] ?? '')),
                    'fecha_vencimiento' => trim((string) ($fila['fecha_vencimiento'] ?? '')),
                    'periodo_vencimiento' => '',
                    'motivo' => trim((string) ($fila['motivo'] ?? 'Fecha de vencimiento inválida')),
                ];
            });

        return $futuras
            ->concat($invalidas)
            ->sortBy([
                ['cuenta_cobol', 'asc'],
                ['linea', 'asc'],
            ])
            ->values();
    }

    private function movimientosMes(int $personaId, string $mes, array $sedesPermitidas = []): Collection
    {
        $fechaNormalizada = "case
            when m.fecha_original ~ '^(19|20)[0-9]{6}$' then m.fecha_original
            when m.fecha_original ~ '^[0-9]{4}(19|20)[0-9]{2}$'
                then substr(m.fecha_original,5,4)
                  || substr(m.fecha_original,3,2)
                  || substr(m.fecha_original,1,2)
            else ''
        end";

        [$filtroCuenta, $bindingsCuenta] = $this->sqlFiltroCuentaPorSedes('cc.cuenta_cobol', $sedesPermitidas);

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
             where cc.persona_id = ? {$filtroCuenta}
               and substr(({$fechaNormalizada}),1,6) = ?
             order by ({$fechaNormalizada}), m.codigo, m.numero
             limit 500",
            [$personaId, ...$bindingsCuenta, $mes]
        ));
    }

    /** @param array<int, string> $sedesPermitidas */
    public function personaVisibleEnSedes(int $personaId, string $periodo, array $sedesPermitidas): bool
    {
        $prefijos = $this->prefijosCuentaPorSedes($sedesPermitidas);
        if ($prefijos === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($prefijos), '?'));

        return $this->core()->selectOne(
            "select exists (
                select 1
                  from gei_core.personas_origenes po
                 where po.persona_id = ?
                   and po.periodo = ?
                   and left(regexp_replace(coalesce(po.cuenta_cobol, ''), '[^0-9]', '', 'g'), 4)
                       in ({$placeholders})
            ) as visible",
            [$personaId, $periodo, ...$prefijos]
        )?->visible === true;
    }

    /** @param array<int, string> $sedesPermitidas
     *  @return array<int, string>
     */
    private function prefijosCuentaPorSedes(array $sedesPermitidas): array
    {
        $prefijos = [];

        foreach ($sedesPermitidas as $sede) {
            switch (strtoupper(trim((string) $sede))) {
                case 'SF':
                    $prefijos[] = '1103';
                    $prefijos[] = '1202';
                    break;
                case 'ST':
                    $prefijos[] = '2103';
                    $prefijos[] = '2202';
                    break;
            }
        }

        return array_values(array_unique($prefijos));
    }

    /** @param array<int, string> $sedesPermitidas
     *  @return array{0:string,1:array<int,string>}
     */
    private function sqlFiltroCuentaPorSedes(string $columna, array $sedesPermitidas): array
    {
        $prefijos = $this->prefijosCuentaPorSedes($sedesPermitidas);
        if ($prefijos === []) {
            return [' and 1 = 0', []];
        }

        $placeholders = implode(',', array_fill(0, count($prefijos), '?'));

        return [
            " and left(regexp_replace(coalesce({$columna}, ''), '[^0-9]', '', 'g'), 4) in ({$placeholders})",
            $prefijos,
        ];
    }

    /** @param array<int, string> $sedesPermitidas */
    private function sqlFiltroCuentaPorSedesTexto(string $columna, array $sedesPermitidas): string
    {
        [$sql] = $this->sqlFiltroCuentaPorSedes($columna, $sedesPermitidas);

        return $sql;
    }

    /** @param array<int, string> $sedesPermitidas
     *  @return array{0:string,1:array<int,string>}
     */
    private function sqlFiltroSedeInmueblePorSedes(string $columna, array $sedesPermitidas): array
    {
        $sedes = array_values(array_unique(array_filter(array_map(
            static fn ($sede): string => strtoupper(trim((string) $sede)),
            $sedesPermitidas
        ), static fn (string $sede): bool => in_array($sede, ['SF', 'ST'], true))));

        if ($sedes === []) {
            return [' and 1 = 0', []];
        }

        $placeholders = implode(',', array_fill(0, count($sedes), '?'));

        return [" and {$columna} in ({$placeholders})", $sedes];
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
