<?php

namespace App\Services;

use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class UnificacionClientesService
{
    private const TIPO = 'CLIENTE';

    private const FKS_ESPERADAS = [
        'clientes',
        'clientes_conflictos',
        'clientes_cuentas',
        'clientes_migracion_conflictos',
        'clientes_origenes',
        'clientes_roles',
        'clientes_resoluciones_origen',
        'contratos_inquilinos',
        'cuentas_corrientes',
        'inmuebles_propietarios',
        'liquidaciones_propietarios',
        'liquidaciones_propietarios_envios',
        'repartos_propietarios',
    ];

    private const CAMPOS_MAESTROS = [
        'tipo_persona',
        'nombre',
        'tipo_documento',
        'numero_documento',
        'cuit',
        'condicion_iva',
        'domicilio',
        'codigo_postal',
        'localidad',
        'provincia',
        'telefono',
        'telefono_alternativo',
        'email',
    ];

    public function listarClasificados(
        string $texto,
        string $vista,
        string $filtroInactivos,
        array $idsRevision,
        int $porPagina = 100
    ): LengthAwarePaginator {
        $texto = trim($texto);
        $idsRevision = array_values(array_unique(array_map('intval', $idsRevision)));

        $consulta = $this->consultaBaseListado();
        $activoSql = $this->sqlClienteActivo();
        $conflictoSql = $this->sqlClienteConConflicto();

        if ($vista === 'activos_revision') {
            // Esta vista representa exclusivamente identidades COBOL pendientes de revisión.
            // Los posibles duplicados de clientes son otro concepto y no se mezclan aquí.
            $consulta->whereRaw("({$activoSql})")
                ->whereRaw("({$conflictoSql})");
        } elseif ($vista === 'inactivos') {
            $consulta->whereRaw("NOT ({$activoSql})");
            if ($filtroInactivos === 'con_conflicto') {
                $consulta->whereRaw("({$conflictoSql})");
            } elseif ($filtroInactivos === 'sin_conflicto') {
                $consulta->whereRaw("NOT ({$conflictoSql})");
            }
        } else {
            $consulta->whereRaw("({$activoSql})")
                ->whereRaw("NOT ({$conflictoSql})");
        }

        $this->aplicarBusqueda($consulta, $texto);

        return $consulta
            ->orderByRaw("TRANSLATE(LOWER(TRIM(c.nombre)), 'áéíóúüñ', 'aeiouun')")
            ->orderBy('c.id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function resumenClasificacion(array $idsRevision): array
    {
        $idsRevision = array_values(array_unique(array_map('intval', $idsRevision)));
        $activoSql = $this->sqlClienteActivo();
        $conflictoSql = $this->sqlClienteConConflicto();

        $base = fn () => DB::table('clientes as c')->whereNull('c.id_cliente_canonico');

        $activos = $base()->whereRaw("({$activoSql})")->count();
        // Una revisión COBOL pendiente no significa que el cliente sea un duplicado.
        $activosRevision = $base()
            ->whereRaw("({$activoSql})")
            ->whereRaw("({$conflictoSql})")
            ->count();
        $inactivos = $base()->whereRaw("NOT ({$activoSql})")->count();
        $inactivosConConflicto = $base()
            ->whereRaw("NOT ({$activoSql})")
            ->whereRaw("({$conflictoSql})")
            ->count();

        return [
            'activos_ok' => max(0, $activos - $activosRevision),
            'activos_revision' => $activosRevision,
            'inactivos' => $inactivos,
            'inactivos_con_conflicto' => $inactivosConConflicto,
            'inactivos_sin_conflicto' => max(0, $inactivos - $inactivosConConflicto),
        ];
    }

    public function candidatos(): Collection
    {
        $pares = collect();

        $agregar = function (int $a, int $b, string $confianza, string $motivo) use (&$pares): void {
            if ($a === $b) {
                return;
            }
            [$a, $b] = $a < $b ? [$a, $b] : [$b, $a];
            $clave = $a.'|'.$b;
            $actual = $pares->get($clave, (object) [
                'id_a' => $a,
                'id_b' => $b,
                'confianza' => $confianza,
                'motivos' => [],
            ]);
            if ($this->pesoConfianza($confianza) > $this->pesoConfianza($actual->confianza)) {
                $actual->confianza = $confianza;
            }
            $actual->motivos = array_values(array_unique([...$actual->motivos, $motivo]));
            $pares->put($clave, $actual);
        };

        foreach (DB::table('clientes as a')
            ->join('clientes as b', function ($join): void {
                $join->on('b.cuit', '=', 'a.cuit')->whereColumn('a.id', '<', 'b.id');
            })
            ->whereNull('a.id_cliente_canonico')->whereNull('b.id_cliente_canonico')
            ->whereNotNull('a.cuit')->whereRaw("BTRIM(a.cuit) <> ''")
            ->select('a.id as a', 'b.id as b', 'a.cuit')->get() as $fila) {
            $agregar((int) $fila->a, (int) $fila->b, 'ALTA', 'Mismo CUIT: '.$fila->cuit);
        }

        foreach (DB::table('clientes as a')
            ->join('clientes as b', function ($join): void {
                $join->on('b.tipo_documento', '=', 'a.tipo_documento')
                    ->on('b.numero_documento', '=', 'a.numero_documento')
                    ->whereColumn('a.id', '<', 'b.id');
            })
            ->whereNull('a.id_cliente_canonico')->whereNull('b.id_cliente_canonico')
            ->whereNotNull('a.numero_documento')->whereRaw("BTRIM(a.numero_documento) <> ''")
            ->select('a.id as a', 'b.id as b', 'a.tipo_documento', 'a.numero_documento')->get() as $fila) {
            $agregar((int) $fila->a, (int) $fila->b, 'ALTA', 'Mismo documento: '.trim(($fila->tipo_documento ?? '').' '.$fila->numero_documento));
        }

        // LE/LC/DNI/DU son tipos documentales históricos compatibles para sugerir identidad.
        // El mismo número con distinto tipo es evidencia fuerte, pero nunca autoriza una fusión automática.
        $documentosHistoricos = DB::table('clientes')
            ->whereNull('id_cliente_canonico')
            ->whereNotNull('numero_documento')
            ->whereRaw("BTRIM(numero_documento) <> ''")
            ->whereRaw("UPPER(BTRIM(COALESCE(tipo_documento, ''))) IN ('DNI', 'DU', 'LE', 'LC')")
            ->select('id', 'tipo_documento', 'numero_documento')
            ->get()
            ->groupBy(fn ($fila): string => $this->numeroDocumentoComparable($fila->numero_documento));

        foreach ($documentosHistoricos as $numero => $grupo) {
            if ($numero === '' || $grupo->count() < 2) {
                continue;
            }
            $grupo = $grupo->values();
            for ($i = 0; $i < $grupo->count(); $i++) {
                for ($j = $i + 1; $j < $grupo->count(); $j++) {
                    $a = $grupo[$i];
                    $b = $grupo[$j];
                    $tipoA = $this->valorComparable($a->tipo_documento ?? '');
                    $tipoB = $this->valorComparable($b->tipo_documento ?? '');
                    if ($tipoA === $tipoB) {
                        continue; // ya fue cubierto por la regla exacta anterior
                    }
                    $agregar(
                        (int) $a->id,
                        (int) $b->id,
                        'ALTA',
                        'Mismo número de documento histórico ('.$tipoA.' / '.$tipoB.'): '.$numero
                    );
                }
            }
        }

        foreach (DB::table('clientes_cuentas as ca')
            ->join('clientes_cuentas as cb', function ($join): void {
                $join->on('cb.cuenta', '=', 'ca.cuenta')
                    ->on('cb.rol', '=', 'ca.rol')
                    ->whereColumn('ca.cliente_id', '<', 'cb.cliente_id');
            })
            ->join('clientes as a', 'a.id', '=', 'ca.cliente_id')
            ->join('clientes as b', 'b.id', '=', 'cb.cliente_id')
            ->whereNull('a.id_cliente_canonico')->whereNull('b.id_cliente_canonico')
            ->select('ca.cliente_id as a', 'cb.cliente_id as b', 'ca.cuenta', 'ca.rol')->distinct()->get() as $fila) {
            $agregar((int) $fila->a, (int) $fila->b, 'ALTA', 'Misma cuenta COBOL '.$fila->rol.': '.$fila->cuenta);
        }

        $decisiones = DB::table('unificaciones_candidatos')
            ->where('tipo', self::TIPO)
            ->get()
            ->keyBy(fn ($f): string => $f->id_registro_a.'|'.$f->id_registro_b);

        return $pares->values()
            ->filter(function ($fila) use ($decisiones): bool {
                $decision = $decisiones->get($fila->id_a.'|'.$fila->id_b);
                return ! in_array($decision?->estado, ['MANTENER_SEPARADOS', 'UNIFICADO'], true);
            })
            ->sortBy([
                fn ($a, $b) => $this->pesoConfianza($b->confianza) <=> $this->pesoConfianza($a->confianza),
                fn ($a, $b) => $a->id_a <=> $b->id_a,
            ])->values();
    }

    public function candidatosBusqueda(Collection $clientes): Collection
    {
        $filas = $clientes->values();
        $cuentas = DB::table('clientes_cuentas')
            ->whereIn('cliente_id', $filas->pluck('id')->all())
            ->get()->groupBy('cliente_id');
        $decisiones = DB::table('unificaciones_candidatos')
            ->where('tipo', self::TIPO)->get()
            ->keyBy(fn ($f): string => $f->id_registro_a.'|'.$f->id_registro_b);
        $salida = collect();

        for ($i = 0; $i < $filas->count(); $i++) {
            for ($j = $i + 1; $j < $filas->count(); $j++) {
                $a = $filas[$i];
                $b = $filas[$j];
                [$idA, $idB] = $a->id < $b->id ? [(int) $a->id, (int) $b->id] : [(int) $b->id, (int) $a->id];
                $decision = $decisiones->get($idA.'|'.$idB);
                if (in_array($decision?->estado, ['MANTENER_SEPARADOS', 'UNIFICADO'], true)) {
                    continue;
                }

                $motivos = [];
                $confianza = null;
                if ($this->valorComparable($a->cuit ?? null) !== '' && $this->valorComparable($a->cuit) === $this->valorComparable($b->cuit ?? null)) {
                    $confianza = 'ALTA';
                    $motivos[] = 'Mismo CUIT';
                }
                $docA = $this->claveDocumento($a);
                $docB = $this->claveDocumento($b);
                if ($docA !== '' && $docA === $docB) {
                    $confianza = 'ALTA';
                    $motivos[] = 'Mismo documento';
                } else {
                    $numeroA = $this->numeroDocumentoComparable($a->numero_documento ?? null);
                    $numeroB = $this->numeroDocumentoComparable($b->numero_documento ?? null);
                    if (
                        $numeroA !== ''
                        && $numeroA === $numeroB
                        && $this->tiposDocumentoHistoricosCompatibles(
                            $a->tipo_documento ?? null,
                            $b->tipo_documento ?? null
                        )
                    ) {
                        $confianza = 'ALTA';
                        $motivos[] = 'Mismo número de documento histórico ('
                            .$this->valorComparable($a->tipo_documento ?? '').' / '
                            .$this->valorComparable($b->tipo_documento ?? '').')';
                    }
                }
                $cuentasA = collect($cuentas->get($a->id, []))->map(fn ($x) => $x->rol.'|'.$x->cuenta)->all();
                $cuentasB = collect($cuentas->get($b->id, []))->map(fn ($x) => $x->rol.'|'.$x->cuenta)->all();
                $comunes = array_values(array_intersect($cuentasA, $cuentasB));
                if ($comunes !== []) {
                    $confianza = 'ALTA';
                    $motivos[] = 'Misma cuenta COBOL';
                }

                $nombreA = $this->normalizarNombre((string) $a->nombre);
                $nombreB = $this->normalizarNombre((string) $b->nombre);
                if ($nombreA !== '' && $nombreB !== '') {
                    if ($nombreA === $nombreB) {
                        $confianza ??= 'MEDIA';
                        $motivos[] = 'Nombre equivalente';
                    } elseif (mb_strlen($nombreA) >= 7 && mb_strlen($nombreB) >= 7) {
                        similar_text($nombreA, $nombreB, $porcentaje);
                        if ($porcentaje >= 92) {
                            $confianza ??= 'BAJA';
                            $motivos[] = 'Nombre similar ('.number_format($porcentaje, 0).'%)';
                        }
                    }
                }

                if ($confianza !== null) {
                    $salida->push((object) [
                        'id_a' => $idA,
                        'id_b' => $idB,
                        'cliente_a' => $a,
                        'cliente_b' => $b,
                        'confianza' => $confianza,
                        'motivos' => array_values(array_unique($motivos)),
                    ]);
                }
            }
        }

        return $salida->sortByDesc(fn ($f) => $this->pesoConfianza($f->confianza))->values();
    }

    public function comparar(int $principalId, int $secundarioId): array
    {
        if ($principalId === $secundarioId) {
            throw new DomainException('El cliente principal y el absorbido deben ser distintos.');
        }
        $principal = $this->cargarCliente($principalId);
        $secundario = $this->cargarCliente($secundarioId);
        $plan = $this->construirPlan($principal, $secundario);

        return compact('principal', 'secundario', 'plan');
    }

    public function unificar(int $principalId, int $secundarioId, ?int $usuarioId): array
    {
        $lock = Cache::store((string) config('gei.exploracion.lock_store', 'file'))
            ->lock('gei:transformacion-cobol', 300);
        if (! $lock->get()) {
            throw new DomainException('Hay una transformación COBOL en curso. La unificación no se ejecutó.');
        }

        try {
            return DB::transaction(function () use ($principalId, $secundarioId, $usuarioId): array {
                $this->validarForeignKeysConocidas();
                $clientes = DB::table('clientes')->whereIn('id', [$principalId, $secundarioId])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if (! $clientes->has($principalId) || ! $clientes->has($secundarioId)) {
                    throw new DomainException('Uno de los clientes seleccionados ya no existe.');
                }
                $principalFila = (array) $clientes->get($principalId);
                $secundarioFila = (array) $clientes->get($secundarioId);
                if ($principalFila['id_cliente_canonico'] !== null) {
                    throw new DomainException('El cliente principal ya fue absorbido por otro cliente.');
                }
                if ($secundarioFila['id_cliente_canonico'] !== null) {
                    throw new DomainException('El cliente secundario ya fue absorbido por otro cliente.');
                }

                foreach (['clientes_roles', 'clientes_cuentas', 'clientes_origenes', 'inmuebles_propietarios', 'contratos_inquilinos', 'cuentas_corrientes', 'liquidaciones_propietarios', 'liquidaciones_propietarios_envios', 'repartos_propietarios', 'clientes_conflictos', 'clientes_migracion_conflictos', 'clientes_resoluciones_origen'] as $tabla) {
                    $columna = match ($tabla) {
                        'clientes_conflictos' => 'cliente_resuelto_id',
                        default => 'cliente_id',
                    };
                    DB::table($tabla)->whereIn($columna, [$principalId, $secundarioId])->lockForUpdate()->get();
                }
                DB::table('clientes_conflictos')
                    ->where(function ($q) use ($principalId, $secundarioId): void {
                        $q->whereRaw("COALESCE(clientes_candidatos, '[]'::jsonb) @> CAST(? AS jsonb)", [json_encode([$principalId], JSON_THROW_ON_ERROR)])
                            ->orWhereRaw("COALESCE(clientes_candidatos, '[]'::jsonb) @> CAST(? AS jsonb)", [json_encode([$secundarioId], JSON_THROW_ON_ERROR)]);
                    })->lockForUpdate()->get();
                DB::table('clientes')->where('id_cliente_canonico', $secundarioId)->lockForUpdate()->get();

                $principal = $this->cargarCliente($principalId);
                $secundario = $this->cargarCliente($secundarioId);
                $plan = $this->construirPlan($principal, $secundario);
                if ($plan['bloqueos'] !== []) {
                    throw new DomainException("La unificación tiene conflictos que requieren revisión manual:\n- ".implode("\n- ", $plan['bloqueos']));
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
                $registrar = function (string $tabla, string $accion, ?int $idRegistro, ?array $antes, ?array $despues, array $detalle = []) use ($idUnificacion, &$orden): void {
                    DB::table('unificaciones_cambios')->insert([
                        'id_unificacion' => $idUnificacion,
                        'orden' => ++$orden,
                        'tabla' => $tabla,
                        'accion' => $accion,
                        'id_registro' => $idRegistro,
                        'datos_antes' => $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                        'datos_despues' => $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
                        'detalle_json' => $detalle === [] ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                };

                if ($plan['campos_completar'] !== []) {
                    $antes = (array) DB::table('clientes')->where('id', $principalId)->first();
                    DB::table('clientes')->where('id', $principalId)->update([...$plan['campos_completar'], 'updated_at' => now()]);
                    $despues = array_merge($antes, $plan['campos_completar']);
                    $registrar('clientes', 'COMPLETADO_DESDE_ABSORBIDO', $principalId, $antes, $despues);
                }

                // Roles: primero quitamos los duplicados exactos, luego movemos el resto.
                foreach ($plan['roles_duplicados'] as $rolId) {
                    $antes = (array) DB::table('clientes_roles')->where('cliente_id', $secundarioId)->where('rol_id', $rolId)->first();
                    DB::table('clientes_roles')->where('cliente_id', $secundarioId)->where('rol_id', $rolId)->delete();
                    $registrar('clientes_roles', 'ELIMINADO_DUPLICADO_EXACTO', null, $antes, null, ['cliente_id' => $secundarioId, 'rol_id' => $rolId]);
                }
                $rolesMover = DB::table('clientes_roles')->where('cliente_id', $secundarioId)->get();
                foreach ($rolesMover as $fila) {
                    $antes = (array) $fila;
                    DB::table('clientes_roles')->where('cliente_id', $secundarioId)->where('rol_id', $fila->rol_id)->update(['cliente_id' => $principalId, 'updated_at' => now()]);
                    $despues = $antes; $despues['cliente_id'] = $principalId;
                    $registrar('clientes_roles', 'REASIGNADO', null, $antes, $despues, ['rol_id' => (int) $fila->rol_id]);
                }

                // Duplicados exactos de relaciones hijas que bloquearían UNIQUE al consolidar cuentas.
                foreach ($plan['duplicados_hijos'] as $duplicado) {
                    $tabla = $duplicado['tabla'];
                    $id = (int) $duplicado['id_secundario'];
                    $antes = (array) DB::table($tabla)->where('id', $id)->first();
                    DB::table($tabla)->where('id', $id)->delete();
                    $registrar($tabla, 'ELIMINADO_DUPLICADO_EXACTO', $id, $antes, null, ['id_registro_conservado' => $duplicado['id_principal']]);
                }

                // Cuentas del secundario: mover o consolidar por (cuenta, rol).
                foreach ($plan['cuentas'] as $accionCuenta) {
                    $idOrigen = (int) $accionCuenta['id_secundario'];
                    if ($accionCuenta['accion'] === 'MOVER') {
                        $antes = (array) DB::table('clientes_cuentas')->where('id', $idOrigen)->first();
                        DB::table('clientes_cuentas')->where('id', $idOrigen)->update(['cliente_id' => $principalId, 'updated_at' => now()]);
                        $despues = $antes; $despues['cliente_id'] = $principalId;
                        $registrar('clientes_cuentas', 'REASIGNADO', $idOrigen, $antes, $despues);
                        $this->reasignarClienteDirectoPorCuenta($idOrigen, $principalId, $registrar);
                        continue;
                    }

                    $idDestino = (int) $accionCuenta['id_principal'];
                    foreach (['inmuebles_propietarios', 'contratos_inquilinos', 'cuentas_corrientes'] as $tabla) {
                        $filas = DB::table($tabla)->where('cliente_cuenta_id', $idOrigen)->orderBy('id')->get();
                        foreach ($filas as $fila) {
                            $antes = (array) $fila;
                            DB::table($tabla)->where('id', $fila->id)->update([
                                'cliente_cuenta_id' => $idDestino,
                                'cliente_id' => $principalId,
                                'updated_at' => now(),
                            ]);
                            $despues = $antes;
                            $despues['cliente_cuenta_id'] = $idDestino;
                            $despues['cliente_id'] = $principalId;
                            $registrar($tabla, 'REASIGNADO_CUENTA_CANONICA', (int) $fila->id, $antes, $despues, ['cliente_cuenta_id_principal' => $idDestino]);
                        }
                    }
                    $antesCuenta = (array) DB::table('clientes_cuentas')->where('id', $idOrigen)->first();
                    DB::table('clientes_cuentas')->where('id', $idOrigen)->delete();
                    $registrar('clientes_cuentas', 'ELIMINADO_DUPLICADO_EXACTO', $idOrigen, $antesCuenta, null, ['id_registro_conservado' => $idDestino]);
                }

                // Relaciones directas restantes por cliente_id.
                foreach (['clientes_origenes', 'clientes_migracion_conflictos', 'liquidaciones_propietarios', 'liquidaciones_propietarios_envios', 'repartos_propietarios', 'clientes_resoluciones_origen'] as $tabla) {
                    $filas = DB::table($tabla)->where('cliente_id', $secundarioId)->orderBy($this->clavePrimariaTabla($tabla))->get();
                    foreach ($filas as $fila) {
                        $pk = $this->clavePrimariaTabla($tabla);
                        $id = (int) $fila->{$pk};
                        $antes = (array) $fila;
                        DB::table($tabla)->where($pk, $id)->update(['cliente_id' => $principalId, ...($this->tieneUpdatedAt($tabla) ? ['updated_at' => now()] : [])]);
                        $despues = $antes; $despues['cliente_id'] = $principalId;
                        $registrar($tabla, 'REASIGNADO', $id, $antes, $despues, ['clave_primaria' => $pk]);
                    }
                }

                foreach (['inmuebles_propietarios', 'contratos_inquilinos', 'cuentas_corrientes'] as $tabla) {
                    $filas = DB::table($tabla)->where('cliente_id', $secundarioId)->orderBy('id')->get();
                    foreach ($filas as $fila) {
                        $antes = (array) $fila;
                        DB::table($tabla)->where('id', $fila->id)->update(['cliente_id' => $principalId, 'updated_at' => now()]);
                        $despues = $antes; $despues['cliente_id'] = $principalId;
                        $registrar($tabla, 'REASIGNADO_CLIENTE', (int) $fila->id, $antes, $despues);
                    }
                }

                $conflictos = DB::table('clientes_conflictos')->where('cliente_resuelto_id', $secundarioId)->orderBy('id')->get();
                foreach ($conflictos as $fila) {
                    $antes = (array) $fila;
                    DB::table('clientes_conflictos')->where('id', $fila->id)->update(['cliente_resuelto_id' => $principalId, 'updated_at' => now()]);
                    $despues = $antes; $despues['cliente_resuelto_id'] = $principalId;
                    $registrar('clientes_conflictos', 'REASIGNADO', (int) $fila->id, $antes, $despues);
                }

                // Los candidatos guardados dentro del JSON también deben dejar de apuntar al absorbido.
                $conflictosCandidatos = DB::table('clientes_conflictos')
                    ->whereRaw("COALESCE(clientes_candidatos, '[]'::jsonb) @> CAST(? AS jsonb)", [json_encode([$secundarioId], JSON_THROW_ON_ERROR)])
                    ->orderBy('id')
                    ->get();
                foreach ($conflictosCandidatos as $fila) {
                    $lista = is_string($fila->clientes_candidatos)
                        ? (json_decode($fila->clientes_candidatos, true) ?: [])
                        : (array) ($fila->clientes_candidatos ?? []);
                    $nueva = [];
                    foreach ($lista as $idCandidato) {
                        $idCandidato = (int) $idCandidato;
                        $nueva[] = $idCandidato === $secundarioId ? $principalId : $idCandidato;
                    }
                    $nueva = array_values(array_unique($nueva));
                    $antes = (array) $fila;
                    DB::table('clientes_conflictos')->where('id', $fila->id)->update([
                        'clientes_candidatos' => json_encode($nueva),
                        'updated_at' => now(),
                    ]);
                    $despues = $antes;
                    $despues['clientes_candidatos'] = $nueva;
                    $registrar('clientes_conflictos', 'REENCADENADO_CANDIDATO', (int) $fila->id, $antes, $despues);
                }

                $aliases = DB::table('clientes')->where('id_cliente_canonico', $secundarioId)->orderBy('id')->get();
                foreach ($aliases as $alias) {
                    $antes = (array) $alias;
                    DB::table('clientes')->where('id', $alias->id)->update(['id_cliente_canonico' => $principalId, 'updated_at' => now()]);
                    $despues = $antes; $despues['id_cliente_canonico'] = $principalId;
                    $registrar('clientes', 'REENCADENADO_CANONICO', (int) $alias->id, $antes, $despues);
                }

                $antesSecundario = (array) DB::table('clientes')->where('id', $secundarioId)->first();
                DB::table('clientes')->where('id', $secundarioId)->update([
                    'id_cliente_canonico' => $principalId,
                    'activo' => false,
                    'updated_at' => now(),
                ]);
                $despuesSecundario = $antesSecundario;
                $despuesSecundario['id_cliente_canonico'] = $principalId;
                $despuesSecundario['activo'] = false;
                $registrar('clientes', 'MARCADO_ABSORBIDO', $secundarioId, $antesSecundario, $despuesSecundario);

                $this->recalcularActividadCanonico($principalId, $registrar);

                [$a, $b] = $principalId < $secundarioId ? [$principalId, $secundarioId] : [$secundarioId, $principalId];
                DB::table('unificaciones_candidatos')->updateOrInsert(
                    ['tipo' => self::TIPO, 'id_registro_a' => $a, 'id_registro_b' => $b],
                    ['estado' => 'UNIFICADO', 'id_usuario_resolucion' => $usuarioId, 'resuelto_at' => now(), 'ultima_deteccion_at' => now(), 'updated_at' => now(), 'created_at' => now()]
                );

                return ['id_unificacion' => $idUnificacion, 'resumen' => $plan['resumen']];
            }, 3);
        } finally {
            $lock->release();
        }
    }

    public function resolverCandidato(int $a, int $b, string $decision, ?int $usuarioId): void
    {
        if (! in_array($decision, ['MANTENER_SEPARADOS', 'CONFLICTIVO'], true)) {
            throw new DomainException('La decisión indicada no es válida.');
        }
        if ($a === $b) {
            throw new DomainException('Los clientes deben ser distintos.');
        }
        [$a, $b] = $a < $b ? [$a, $b] : [$b, $a];
        DB::table('unificaciones_candidatos')->updateOrInsert(
            ['tipo' => self::TIPO, 'id_registro_a' => $a, 'id_registro_b' => $b],
            ['estado' => $decision, 'id_usuario_resolucion' => $usuarioId, 'resuelto_at' => now(), 'ultima_deteccion_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
    }


    public function revisionCobol(int $conflictoId): array
    {
        $conflicto = DB::table('clientes_conflictos')->where('id', $conflictoId)->first();
        if ($conflicto === null) {
            throw new DomainException("No existe la revisión COBOL #{$conflictoId}.");
        }

        $datosOrigen = $this->jsonAArray($conflicto->datos_origen ?? null);
        $detalleConflicto = $this->jsonAArray($conflicto->detalle ?? null);
        $idsCandidatos = collect($this->jsonAArray($conflicto->clientes_candidatos ?? null))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($conflicto->cliente_resuelto_id !== null) {
            $idsCandidatos->push((int) $conflicto->cliente_resuelto_id);
            $idsCandidatos = $idsCandidatos->unique()->values();
        }

        $candidatos = $idsCandidatos->map(function (int $id) use ($datosOrigen): ?object {
            $original = DB::table('clientes')->where('id', $id)->first();
            if ($original === null) {
                return null;
            }

            $canonicoId = $original->id_cliente_canonico === null
                ? (int) $original->id
                : (int) $original->id_cliente_canonico;

            $detalle = $this->cargarCliente($canonicoId);
            $cliente = $detalle['cliente'];

            return (object) [
                'id_original' => (int) $original->id,
                'id_canonico' => $canonicoId,
                'fue_absorbido' => $original->id_cliente_canonico !== null,
                'cliente' => $cliente,
                'roles' => $detalle['roles']->pluck('codigo')->filter()->implode(', '),
                'cuentas' => $detalle['cuentas']->pluck('cuenta')->filter()->unique()->values()->implode(', '),
                'liquidaciones_count' => $detalle['liquidaciones_count'],
                'inmuebles_count' => $detalle['inmuebles']->count(),
                'contratos_count' => $detalle['contratos']->count(),
                'coincidencias' => $this->compararOrigenConCliente($datosOrigen, $cliente),
            ];
        })->filter()->values();

        $otrosConflictos = collect();
        if ($idsCandidatos->isNotEmpty()) {
            $otrosConflictos = DB::table('clientes_conflictos')
                ->where('estado', 'PENDIENTE')
                ->where('id', '<>', $conflictoId)
                ->where(function ($q) use ($idsCandidatos): void {
                    foreach ($idsCandidatos as $id) {
                        $q->orWhere('cliente_resuelto_id', $id)
                            ->orWhereRaw(
                                "COALESCE(clientes_candidatos, '[]'::jsonb) @> CAST(? AS jsonb)",
                                [json_encode([(int) $id], JSON_THROW_ON_ERROR)]
                            );
                    }
                })
                ->orderByDesc('ultima_deteccion_at')
                ->limit(20)
                ->get();
        }

        return [
            'conflicto' => $conflicto,
            'datosOrigen' => $datosOrigen,
            'detalleConflicto' => $detalleConflicto,
            'candidatos' => $candidatos,
            'otrosConflictos' => $otrosConflictos,
        ];
    }

    private function compararOrigenConCliente(array $origen, object $cliente): array
    {
        $campos = [
            'nombre' => 'Nombre',
            'cuit' => 'CUIT',
            'tipo_documento' => 'Tipo documento',
            'numero_documento' => 'Número documento',
            'domicilio' => 'Domicilio',
            'telefono' => 'Teléfono',
        ];

        $salida = [];
        foreach ($campos as $campo => $etiqueta) {
            $valorOrigen = $origen[$campo] ?? null;
            $valorCliente = $cliente->{$campo} ?? null;
            if ($this->esVacio($valorOrigen) && $this->esVacio($valorCliente)) {
                continue;
            }

            $coincide = ! $this->esVacio($valorOrigen)
                && ! $this->esVacio($valorCliente)
                && $this->valorComparable($valorOrigen) === $this->valorComparable($valorCliente);

            if ($campo === 'numero_documento' && ! $coincide) {
                $coincide = $this->numeroDocumentoComparable($valorOrigen) !== ''
                    && $this->numeroDocumentoComparable($valorOrigen)
                    === $this->numeroDocumentoComparable($valorCliente);
            }

            $salida[] = [
                'campo' => $campo,
                'etiqueta' => $etiqueta,
                'origen' => $valorOrigen,
                'cliente' => $valorCliente,
                'coincide' => $coincide,
            ];
        }

        return $salida;
    }

    private function jsonAArray(mixed $valor): array
    {
        if ($valor === null || $valor === '') {
            return [];
        }
        if (is_array($valor)) {
            return $valor;
        }
        if (is_object($valor)) {
            return (array) $valor;
        }
        if (! is_string($valor)) {
            return [];
        }

        try {
            $decodificado = json_decode($valor, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decodificado) ? $decodificado : [];
    }

    public function resolverConflictoImportacion(int $conflictoId, string $decision, ?int $clienteId, ?int $usuarioId): void
    {
        if (! in_array($decision, ['ASOCIAR_EXISTENTE', 'CREAR_SEPARADO'], true)) {
            throw new DomainException('La decisión indicada no es válida.');
        }
        DB::transaction(function () use ($conflictoId, $decision, $clienteId, $usuarioId): void {
            $conflicto = DB::table('clientes_conflictos')->where('id', $conflictoId)->lockForUpdate()->first();
            if ($conflicto === null || $conflicto->estado !== 'PENDIENTE') {
                throw new DomainException('El conflicto ya no existe o ya fue resuelto.');
            }
            $canonico = null;
            if ($decision === 'ASOCIAR_EXISTENTE') {
                if ($clienteId === null) {
                    throw new DomainException('Debe indicar el cliente canónico.');
                }
                $cliente = DB::table('clientes')->where('id', $clienteId)->lockForUpdate()->first();
                if ($cliente === null) {
                    throw new DomainException("No existe el cliente {$clienteId}.");
                }
                $canonico = $cliente->id_cliente_canonico === null ? (int) $cliente->id : (int) $cliente->id_cliente_canonico;
            }

            DB::table('clientes_resoluciones_origen')->updateOrInsert(
                ['sistema_origen' => (string) $conflicto->sistema_origen, 'entidad_origen' => (string) $conflicto->entidad_origen, 'clave_origen' => (string) $conflicto->clave_origen],
                ['decision' => $decision, 'cliente_id' => $canonico, 'usuario_id' => $usuarioId, 'detalle_json' => json_encode(['conflicto_id' => $conflictoId, 'motivo' => $conflicto->motivo], JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('clientes_conflictos')->where('id', $conflictoId)->update([
                'cliente_resuelto_id' => $canonico,
                'estado' => 'RESUELTO',
                'resuelto_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function conflictosPendientes(): Collection
    {
        return DB::table('clientes_conflictos as cf')
            ->where('cf.estado', 'PENDIENTE')
            ->select('cf.*')
            ->orderByDesc('cf.ultima_deteccion_at')
            ->limit(200)
            ->get();
    }

    public function ultimasUnificaciones(): Collection
    {
        return DB::table('unificaciones as u')
            ->leftJoin('usuarios as us', 'us.id', '=', 'u.id_usuario')
            ->leftJoin('clientes as p', 'p.id', '=', 'u.id_registro_principal')
            ->leftJoin('clientes as s', 's.id', '=', 'u.id_registro_absorbido')
            ->where('u.tipo', self::TIPO)
            ->select('u.*', 'us.nombre as usuario_nombre', 'p.nombre as principal_nombre', 's.nombre as absorbido_nombre')
            ->orderByDesc('u.id_unificacion')->limit(100)->get();
    }

    private function consultaBaseListado()
    {
        return DB::table('clientes as c')
            ->whereNull('c.id_cliente_canonico')
            ->select(['c.id', 'c.nombre', 'c.tipo_persona', 'c.tipo_documento', 'c.numero_documento', 'c.cuit', 'c.email', 'c.activo'])
            ->selectRaw("({$this->sqlClienteActivo()}) AS operativo_activo")
            ->selectRaw("({$this->sqlClienteConConflicto()}) AS conflicto_pendiente")
            ->selectSub(function ($q) {
                $q->from('clientes_conflictos as cfr')
                    ->where('cfr.estado', 'PENDIENTE')
                    ->whereRaw("(cfr.cliente_resuelto_id = c.id OR COALESCE(cfr.clientes_candidatos, '[]'::jsonb) @> jsonb_build_array(c.id))")
                    ->selectRaw('COUNT(*)');
            }, 'revisiones_cobol_count')
            ->selectSub(function ($q) {
                $q->from('clientes_conflictos as cfr')
                    ->where('cfr.estado', 'PENDIENTE')
                    ->whereRaw("(cfr.cliente_resuelto_id = c.id OR COALESCE(cfr.clientes_candidatos, '[]'::jsonb) @> jsonb_build_array(c.id))")
                    ->selectRaw('MIN(cfr.id)');
            }, 'revision_cobol_id')
            ->selectSub(function ($q) {
                $q->from('clientes_roles as cr')->join('roles as r', 'r.id', '=', 'cr.rol_id')->whereColumn('cr.cliente_id', 'c.id')->selectRaw("string_agg(DISTINCT r.codigo, ', ' ORDER BY r.codigo)");
            }, 'roles')
            ->selectSub(function ($q) {
                $q->from('clientes_cuentas as cc')->whereColumn('cc.cliente_id', 'c.id')->selectRaw("string_agg(DISTINCT cc.cuenta, ', ' ORDER BY cc.cuenta)");
            }, 'cuentas')
            ->selectSub(function ($q) {
                $q->from('clientes_origenes as co')->whereColumn('co.cliente_id', 'c.id')->where('co.estado_origen', 'ACTIVO')->selectRaw("string_agg(DISTINCT co.clave_origen, ', ' ORDER BY co.clave_origen)");
            }, 'cuentas_activas');
    }

    private function aplicarBusqueda($query, string $texto): void
    {
        if ($texto === '') {
            return;
        }
        $como = '%'.$this->escaparLike($texto).'%';
        $query->where(function ($q) use ($texto, $como): void {
            if (ctype_digit($texto)) {
                $q->orWhere('c.id', (int) $texto);
            }
            $q->orWhereRaw("c.nombre ILIKE ? ESCAPE '!'", [$como])
                ->orWhereRaw("COALESCE(c.cuit, '') ILIKE ? ESCAPE '!'", [$como])
                ->orWhereRaw("COALESCE(c.numero_documento, '') ILIKE ? ESCAPE '!'", [$como])
                ->orWhereRaw("COALESCE(c.email, '') ILIKE ? ESCAPE '!'", [$como])
                ->orWhereExists(function ($sub) use ($como): void {
                    $sub->selectRaw('1')
                        ->from('clientes_cuentas as cb')
                        ->whereColumn('cb.cliente_id', 'c.id')
                        ->whereRaw("cb.cuenta ILIKE ? ESCAPE '!'", [$como]);
                })
                ->orWhereExists(function ($sub) use ($como): void {
                    // En la vista de revisión COBOL la cuenta que originó el
                    // conflicto puede todavía no existir en clientes_cuentas.
                    // Permitimos buscarla directamente por clave_origen, pero
                    // sólo cuando el conflicto pendiente está vinculado a este
                    // cliente como resuelto o candidato.
                    $sub->selectRaw('1')
                        ->from('clientes_conflictos as cfb')
                        ->where('cfb.estado', 'PENDIENTE')
                        ->whereRaw("cfb.clave_origen ILIKE ? ESCAPE '!'", [$como])
                        ->where(function ($rel): void {
                            $rel->whereColumn('cfb.cliente_resuelto_id', 'c.id')
                                ->orWhereRaw(
                                    "COALESCE(cfb.clientes_candidatos, '[]'::jsonb) @> jsonb_build_array(c.id)"
                                );
                        });
                });
        });
    }

    private function sqlClienteActivo(): string
    {
        return "(
            c.activo IS TRUE
            OR EXISTS (
                SELECT 1
                FROM clientes_origenes coa
                WHERE coa.cliente_id = c.id
                  AND coa.estado_origen = 'ACTIVO'
            )
            OR EXISTS (
                SELECT 1
                FROM liquidaciones_propietarios lpa
                WHERE lpa.cliente_id = c.id
                  AND lpa.periodo IN (
                      SELECT DISTINCT lp2.periodo
                      FROM liquidaciones_propietarios lp2
                      WHERE lp2.periodo IS NOT NULL
                      ORDER BY lp2.periodo DESC
                      LIMIT 2
                  )
            )
        )";
    }

    private function sqlClienteConConflicto(): string
    {
        return "EXISTS (SELECT 1 FROM clientes_conflictos cfc WHERE cfc.estado = 'PENDIENTE' AND (cfc.cliente_resuelto_id = c.id OR COALESCE(cfc.clientes_candidatos, '[]'::jsonb) @> jsonb_build_array(c.id)))";
    }

    private function cargarCliente(int $id): array
    {
        $cliente = DB::table('clientes')->where('id', $id)->first();
        if ($cliente === null) {
            throw new DomainException("No existe el cliente {$id}.");
        }
        if ($cliente->id_cliente_canonico !== null) {
            throw new DomainException("El cliente {$id} ya fue absorbido por el cliente {$cliente->id_cliente_canonico}.");
        }

        return [
            'cliente' => $cliente,
            'roles' => DB::table('clientes_roles as cr')->join('roles as r', 'r.id', '=', 'cr.rol_id')->where('cr.cliente_id', $id)->select('cr.rol_id', 'r.codigo', 'r.nombre')->orderBy('r.codigo')->get(),
            'cuentas' => DB::table('clientes_cuentas')->where('cliente_id', $id)->orderBy('rol')->orderBy('cuenta')->get(),
            'origenes' => DB::table('clientes_origenes')->where('cliente_id', $id)->orderBy('entidad_origen')->orderBy('clave_origen')->get(),
            'inmuebles' => DB::table('inmuebles_propietarios as ip')->join('inmuebles as i', 'i.id', '=', 'ip.inmueble_id')->leftJoin('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')->where('ip.cliente_id', $id)->select('ip.*', 'i.domicilio', 'i.estado as inmueble_estado', 'cc.cuenta')->orderBy('i.domicilio')->get(),
            'contratos' => DB::table('contratos_inquilinos as ci')->join('contratos as c', 'c.id', '=', 'ci.contrato_id')->leftJoin('clientes_cuentas as cc', 'cc.id', '=', 'ci.cliente_cuenta_id')->where('ci.cliente_id', $id)->select('ci.*', 'c.cuenta_inquilino', 'c.fecha_inicio', 'c.fecha_fin', 'c.estado as contrato_estado', 'cc.cuenta')->orderByDesc('c.fecha_inicio')->get(),
            'cuentas_corrientes' => DB::table('cuentas_corrientes')->where('cliente_id', $id)->orderBy('dominio')->orderBy('cuenta')->get(),
            'liquidaciones_count' => DB::table('liquidaciones_propietarios')->where('cliente_id', $id)->count(),
            'liquidaciones' => DB::table('liquidaciones_propietarios')->where('cliente_id', $id)->select('id', 'periodo', 'fecha', 'cuenta', 'propietario', 'total')->orderByDesc('periodo')->orderByDesc('id')->limit(20)->get(),
            'envios_count' => DB::table('liquidaciones_propietarios_envios')->where('cliente_id', $id)->count(),
            'repartos' => DB::table('repartos_propietarios')->where('cliente_id', $id)->orderBy('cuenta')->orderBy('beneficiario')->get(),
            'conflictos' => DB::table('clientes_conflictos')
                ->where(function ($q) use ($id): void {
                    $q->where('cliente_resuelto_id', $id)
                        ->orWhereRaw("COALESCE(clientes_candidatos, '[]'::jsonb) @> CAST(? AS jsonb)", [json_encode([$id], JSON_THROW_ON_ERROR)]);
                })
                ->orderByDesc('ultima_deteccion_at')->get(),
            'migracion_conflictos' => DB::table('clientes_migracion_conflictos')->where('cliente_id', $id)->orderByDesc('id')->get(),
            'resoluciones_origen' => DB::table('clientes_resoluciones_origen')->where('cliente_id', $id)->orderBy('entidad_origen')->orderBy('clave_origen')->get(),
            'absorbidos' => DB::table('clientes')->where('id_cliente_canonico', $id)->orderBy('id')->get(),
        ];
    }

    private function construirPlan(array $principal, array $secundario): array
    {
        $p = $principal['cliente'];
        $s = $secundario['cliente'];
        $bloqueos = [];
        $camposCompletar = [];
        $diferencias = [];

        foreach (self::CAMPOS_MAESTROS as $campo) {
            $vp = $p->{$campo} ?? null;
            $vs = $s->{$campo} ?? null;
            if ($this->esVacio($vp) && ! $this->esVacio($vs)) {
                $camposCompletar[$campo] = $vs;
            } elseif (! $this->esVacio($vp) && ! $this->esVacio($vs) && $this->valorComparable($vp) !== $this->valorComparable($vs)) {
                $diferencias[$campo] = ['principal' => $vp, 'secundario' => $vs];
            }
        }

        $rolesPrincipal = $principal['roles']->pluck('rol_id')->map(fn ($x) => (int) $x)->all();
        $rolesDuplicados = $secundario['roles']->pluck('rol_id')->map(fn ($x) => (int) $x)->filter(fn ($x) => in_array($x, $rolesPrincipal, true))->values()->all();

        $cuentasPrincipal = $principal['cuentas']->keyBy(fn ($x): string => strtoupper($x->rol).'|'.$x->cuenta);
        $accionesCuentas = [];
        $duplicadosHijos = [];

        foreach ($secundario['cuentas'] as $cuentaSec) {
            $clave = strtoupper((string) $cuentaSec->rol).'|'.(string) $cuentaSec->cuenta;
            $cuentaPrin = $cuentasPrincipal->get($clave);
            if ($cuentaPrin === null) {
                $accionesCuentas[] = ['accion' => 'MOVER', 'id_secundario' => (int) $cuentaSec->id, 'cuenta' => $cuentaSec->cuenta, 'rol' => $cuentaSec->rol];
                continue;
            }

            foreach (DB::table('inmuebles_propietarios')->where('cliente_cuenta_id', $cuentaSec->id)->whereNull('vigencia_hasta')->get() as $relSec) {
                $relPrin = DB::table('inmuebles_propietarios')->where('inmueble_id', $relSec->inmueble_id)->where('cliente_cuenta_id', $cuentaPrin->id)->whereNull('vigencia_hasta')->first();
                if ($relPrin !== null) {
                    if ($this->relacionesIguales($relPrin, $relSec, ['porcentaje', 'vigencia_desde', 'vigencia_hasta', 'activo', 'origen', 'datos_origen'])) {
                        $duplicadosHijos[] = ['tabla' => 'inmuebles_propietarios', 'id_principal' => (int) $relPrin->id, 'id_secundario' => (int) $relSec->id];
                    } else {
                        $bloqueos[] = "La cuenta {$cuentaSec->cuenta} tiene dos relaciones vigentes distintas con el inmueble {$relSec->inmueble_id}.";
                    }
                }
            }

            foreach (DB::table('contratos_inquilinos')->where('cliente_cuenta_id', $cuentaSec->id)->get() as $relSec) {
                $relPrin = DB::table('contratos_inquilinos')->where('contrato_id', $relSec->contrato_id)->where('cliente_cuenta_id', $cuentaPrin->id)->where('rol', $relSec->rol)->first();
                if ($relPrin !== null) {
                    if ($this->relacionesIguales($relPrin, $relSec, ['rol', 'vigencia_desde', 'vigencia_hasta', 'activo', 'origen', 'datos_origen'])) {
                        $duplicadosHijos[] = ['tabla' => 'contratos_inquilinos', 'id_principal' => (int) $relPrin->id, 'id_secundario' => (int) $relSec->id];
                    } else {
                        $bloqueos[] = "La cuenta {$cuentaSec->cuenta} tiene dos relaciones distintas con el contrato {$relSec->contrato_id}.";
                    }
                }
            }

            $accionesCuentas[] = ['accion' => 'CONSOLIDAR', 'id_principal' => (int) $cuentaPrin->id, 'id_secundario' => (int) $cuentaSec->id, 'cuenta' => $cuentaSec->cuenta, 'rol' => $cuentaSec->rol];
        }

        return [
            'bloqueos' => array_values(array_unique($bloqueos)),
            'campos_completar' => $camposCompletar,
            'diferencias_maestras' => $diferencias,
            'roles_duplicados' => $rolesDuplicados,
            'cuentas' => $accionesCuentas,
            'duplicados_hijos' => $duplicadosHijos,
            'resumen' => [
                'principal' => ['id' => (int) $p->id, 'nombre' => $p->nombre, 'cuit' => $p->cuit],
                'absorbido' => ['id' => (int) $s->id, 'nombre' => $s->nombre, 'cuit' => $s->cuit],
                'origenes' => $secundario['origenes']->count(),
                'cuentas' => $secundario['cuentas']->count(),
                'roles' => $secundario['roles']->count(),
                'inmuebles' => $secundario['inmuebles']->count(),
                'contratos' => $secundario['contratos']->count(),
                'cuentas_corrientes' => $secundario['cuentas_corrientes']->count(),
                'liquidaciones' => $secundario['liquidaciones_count'],
                'envios' => $secundario['envios_count'],
                'repartos' => $secundario['repartos']->count(),
                'duplicados_exactos_eliminados' => count($duplicadosHijos) + count($rolesDuplicados) + count(array_filter($accionesCuentas, fn ($x) => $x['accion'] === 'CONSOLIDAR')),
                'diferencias_maestras' => count($diferencias),
                'bloqueos' => count($bloqueos),
            ],
        ];
    }

    private function reasignarClienteDirectoPorCuenta(int $clienteCuentaId, int $principalId, callable $registrar): void
    {
        foreach (['inmuebles_propietarios', 'contratos_inquilinos', 'cuentas_corrientes'] as $tabla) {
            $filas = DB::table($tabla)->where('cliente_cuenta_id', $clienteCuentaId)->where('cliente_id', '<>', $principalId)->orderBy('id')->get();
            foreach ($filas as $fila) {
                $antes = (array) $fila;
                DB::table($tabla)->where('id', $fila->id)->update(['cliente_id' => $principalId, 'updated_at' => now()]);
                $despues = $antes; $despues['cliente_id'] = $principalId;
                $registrar($tabla, 'REASIGNADO_CLIENTE', (int) $fila->id, $antes, $despues);
            }
        }
    }

    private function recalcularActividadCanonico(int $clienteId, callable $registrar): void
    {
        $origenes = DB::table('clientes_origenes')->where('cliente_id', $clienteId)->pluck('estado_origen');
        if ($origenes->contains('ACTIVO')) {
            $nuevo = true;
        } elseif ($origenes->isNotEmpty() && $origenes->every(fn ($x) => $x === 'BAJA')) {
            $nuevo = false;
        } else {
            return;
        }
        $fila = DB::table('clientes')->where('id', $clienteId)->first();
        if ($fila !== null && (bool) $fila->activo !== $nuevo) {
            $antes = (array) $fila;
            DB::table('clientes')->where('id', $clienteId)->update(['activo' => $nuevo, 'updated_at' => now()]);
            $despues = $antes; $despues['activo'] = $nuevo;
            $registrar('clientes', 'RECALCULADO_ACTIVO', $clienteId, $antes, $despues);
        }
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
  AND destino.relname = 'clientes'
ORDER BY origen.relname
SQL);
        $detectadas = array_map(fn ($f): string => (string) $f->tabla, $tablas);
        sort($detectadas);
        $esperadas = self::FKS_ESPERADAS; sort($esperadas);
        if ($detectadas !== $esperadas) {
            throw new DomainException('Cambió el esquema de relaciones de clientes. No se ejecutó la unificación. FK detectadas: '.implode(', ', $detectadas).'.');
        }
    }

    private function relacionesIguales(object $a, object $b, array $campos): bool
    {
        foreach ($campos as $campo) {
            $va = $a->{$campo} ?? null; $vb = $b->{$campo} ?? null;
            if ($campo === 'datos_origen') {
                $va = $this->normalizarJson($va); $vb = $this->normalizarJson($vb);
            }
            if ((string) ($va ?? '') !== (string) ($vb ?? '')) {
                return false;
            }
        }
        return true;
    }

    private function clavePrimariaTabla(string $tabla): string
    {
        return match ($tabla) {
            'clientes_resoluciones_origen' => 'id_cliente_resolucion_origen',
            default => 'id',
        };
    }

    private function tieneUpdatedAt(string $tabla): bool
    {
        return ! in_array($tabla, ['clientes_roles'], true);
    }

    private function normalizarJson(mixed $valor): string
    {
        if ($valor === null || $valor === '') return '';
        $d = is_string($valor) ? json_decode($valor, true) : $valor;
        if (! is_array($d)) return (string) $valor;
        $this->ordenarRecursivo($d);
        return json_encode($d, JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function ordenarRecursivo(array &$a): void
    {
        if (! array_is_list($a)) ksort($a);
        foreach ($a as &$v) if (is_array($v)) $this->ordenarRecursivo($v);
    }

    private function normalizarNombre(string $valor): string
    {
        $valor = mb_strtoupper(trim($valor), 'UTF-8');
        $valor = strtr($valor, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $valor = preg_replace('/[^A-Z0-9]+/u', ' ', $valor) ?? $valor;
        $partes = array_values(array_filter(preg_split('/\s+/', trim($valor)) ?: []));
        sort($partes, SORT_STRING);
        return implode(' ', $partes);
    }

    private function claveDocumento(object $c): string
    {
        $n = $this->numeroDocumentoComparable($c->numero_documento ?? null);
        if ($n === '') return '';
        return $this->valorComparable($c->tipo_documento ?? '').'|'.$n;
    }

    private function numeroDocumentoComparable(mixed $valor): string
    {
        $texto = trim((string) ($valor ?? ''));
        if ($texto === '') {
            return '';
        }
        $soloDigitos = preg_replace('/\D+/', '', $texto) ?? '';
        return $soloDigitos !== '' ? ltrim($soloDigitos, '0') : $this->valorComparable($texto);
    }

    private function tiposDocumentoHistoricosCompatibles(mixed $a, mixed $b): bool
    {
        $compatibles = ['DNI', 'DU', 'LE', 'LC'];
        $tipoA = $this->valorComparable($a);
        $tipoB = $this->valorComparable($b);
        return in_array($tipoA, $compatibles, true) && in_array($tipoB, $compatibles, true);
    }

    private function valorComparable(mixed $valor): string
    {
        return mb_strtoupper(trim((string) ($valor ?? '')), 'UTF-8');
    }

    private function esVacio(mixed $valor): bool
    {
        return $valor === null || trim((string) $valor) === '';
    }

    private function escaparLike(string $valor): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $valor);
    }

    private function pesoConfianza(?string $c): int
    {
        return match ($c) { 'ALTA' => 3, 'MEDIA' => 2, 'BAJA' => 1, default => 0 };
    }
}
