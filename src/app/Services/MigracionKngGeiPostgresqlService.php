<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;

class MigracionKngGeiPostgresqlService
{
    private const FECHA_NULA = '1900-01-01';
    public const MAPPING_VERSION = 'kng_gei_pgsql_v20260715_02';
    public const COMPONENTES = ['clientes', 'movimientos', 'liquidaciones', 'items', 'dailoc'];

    /**
     * @var array<string, array<string, bool>>
     */
    private array $columnasPorTabla = [];

    public function aplicar(int $importacionId, bool $confirmar = false, array $componentes = self::COMPONENTES): array
    {
        $componentes = $this->normalizarComponentes($componentes);
        $resumen = [
            'importacion_id' => $importacionId,
            'confirmado' => $confirmar,
            'mapping_version' => self::MAPPING_VERSION,
            'componentes' => $componentes,
            'propietarios' => ['insertados' => 0, 'actualizados' => 0, 'omitidos' => 0],
            'inquilinos' => ['insertados' => 0, 'actualizados' => 0, 'omitidos' => 0],
            'movimientos_propietarios' => ['insertados' => 0, 'omitidos' => 0],
            'movimientos_inquilinos' => ['insertados' => 0, 'omitidos' => 0],
            'liquidaciones' => ['insertadas' => 0, 'actualizadas' => 0, 'omitidas' => 0],
            'items_liquidaciones' => ['insertados' => 0, 'omitidos' => 0],
            'dailoc' => ['procesados' => 0, 'omitidos' => 0],
            'registros_staging' => $this->conteosStaging($importacionId),
            'advertencias' => [],
            'errores' => [],
        ];

        if (! $confirmar) {
            $this->simularDesdeStaging($importacionId, $resumen, $componentes);

            return $resumen;
        }

        $preflight = $this->preflight($importacionId, $componentes);
        if ($preflight['estado'] === 'NO_APTO_PARA_CONFIRMAR') {
            $resumen['errores'][] = [
                'codigo' => 'preflight_no_apto',
                'mensaje' => 'La migracion no esta apta para confirmar con los componentes solicitados.',
                'preflight' => $preflight,
            ];

            return $resumen;
        }

        $callback = function () use ($importacionId, $componentes, &$resumen): void {
            if (in_array('clientes', $componentes, true)) {
                $this->procesarRegistros(
                    $importacionId,
                    'propietario',
                    function ($registro) use (&$resumen): void {
                        $this->aplicarPropietario($registro, $resumen);
                    }
                );

                $this->procesarRegistros(
                    $importacionId,
                    'inquilino',
                    function ($registro) use (&$resumen): void {
                        $this->aplicarInquilino($registro, $resumen);
                    }
                );
            }

            if (in_array('movimientos', $componentes, true)) {
                $this->procesarRegistros(
                    $importacionId,
                    'cuenta_propietario',
                    function ($registro) use (&$resumen): void {
                        $this->aplicarMovimiento($registro, 'propietario', $resumen);
                    }
                );

                $this->procesarRegistros(
                    $importacionId,
                    'cuenta_inquilino',
                    function ($registro) use (&$resumen): void {
                        $this->aplicarMovimiento($registro, 'inquilino', $resumen);
                    }
                );
            }

            if (in_array('liquidaciones', $componentes, true)) {
                $this->procesarRegistros(
                    $importacionId,
                    'liquidacion_%',
                    function ($registro) use (&$resumen, $componentes): void {
                        $this->aplicarLiquidacion($registro, $resumen, in_array('items', $componentes, true));
                    },
                    like: true
                );
            }
        };

        DB::transaction($callback);

        return $resumen;
    }

    public function preflight(int $importacionId, array $componentes = self::COMPONENTES): array
    {
        $componentes = $this->normalizarComponentes($componentes);
        $bloqueantes = [];
        $advertencias = [];
        $tablas = [
            'web_importaciones',
            'web_importaciones_registros',
            'web_migraciones_aplicaciones',
            'clientes',
            'movimientos_de_cuentas',
            'liquidaciones_de_clientes',
            'liquidaciones_de_clientes_items',
            'liquidaciones_impuestos_servicios',
        ];

        foreach ($tablas as $tabla) {
            if (! Schema::hasTable($tabla)) {
                $bloqueantes[] = "Falta tabla {$tabla}.";
            }
        }

        $importacion = Schema::hasTable('web_importaciones')
            ? DB::table('web_importaciones')->where('web_id', $importacionId)->first()
            : null;

        if (! $importacion) {
            $bloqueantes[] = "No existe web_importaciones.web_id={$importacionId}.";
        }

        $conteos = Schema::hasTable('web_importaciones_registros')
            ? $this->conteosStaging($importacionId)
            : [];
        $itemsDisponibles = $this->contarLiquidacionesConItems($importacionId);
        $liquidacionesIncompletas = $this->contarLiquidacionesIncompletas($importacionId);
        $dailocDisponibles = (int) (($conteos['liquidacion_dailoc'] ?? 0) + ($conteos['dailoc'] ?? 0));

        if (in_array('liquidaciones', $componentes, true) && $liquidacionesIncompletas > 0) {
            $bloqueantes[] = "El lote staging contiene {$liquidacionesIncompletas} cabeceras de liquidacion sin fecha o comprobante confiable.";
        }

        if (in_array('items', $componentes, true) && $itemsDisponibles === 0) {
            $bloqueantes[] = 'El lote staging no contiene items estructurados para liquidaciones_de_clientes_items.';
        }

        if (in_array('dailoc', $componentes, true) && $dailocDisponibles === 0) {
            $bloqueantes[] = 'El lote staging no contiene registros dailoc funcionales para liquidaciones_impuestos_servicios.';
        }

        if (! in_array('items', $componentes, true)) {
            $advertencias[] = 'La ejecucion solicitada no incluye items de liquidacion.';
        }

        if (! in_array('dailoc', $componentes, true)) {
            $advertencias[] = 'La ejecucion solicitada no incluye dailoc/impuestos y servicios.';
        }

        $estado = $bloqueantes !== []
            ? 'NO_APTO_PARA_CONFIRMAR'
            : ($advertencias !== [] ? 'APTO_CON_ADVERTENCIAS' : 'APTO_PARA_CONFIRMAR');

        return [
            'estado' => $estado,
            'importacion_id' => $importacionId,
            'conexion' => [
                'host' => config('database.connections.'.config('database.default').'.host'),
                'database' => config('database.connections.'.config('database.default').'.database'),
                'driver' => config('database.default'),
            ],
            'mapping_version' => self::MAPPING_VERSION,
            'componentes' => $componentes,
            'conteos_staging' => $conteos,
            'items_disponibles' => $itemsDisponibles,
            'liquidaciones_incompletas' => $liquidacionesIncompletas,
            'dailoc_disponibles' => $dailocDisponibles,
            'bloqueantes' => $bloqueantes,
            'advertencias' => $advertencias,
        ];
    }

    private function simularDesdeStaging(int $importacionId, array &$resumen, array $componentes): void
    {
        if (in_array('clientes', $componentes, true)) {
            $propietariosExistentes = $this->contarClientesExistentes($importacionId, 'propietario', 'id_prop', true);
            $propietariosInmuebleUnico = $this->contarPropietariosConInmuebleUnicoSinIdProp($importacionId);
            $propietariosInmuebleConflictivo = $this->contarPropietariosConInmuebleConflictivoSinIdProp($importacionId);
            $propietariosConLiquidaciones = $this->contarPropietariosConLiquidacionesSinClienteNiInmueble($importacionId);
            $inquilinosExistentes = $this->contarClientesExistentes($importacionId, 'inquilino', 'id_inq', false);
            $inquilinosContratoUnico = $this->contarInquilinosConContratoUnicoSinIdInq($importacionId);
            $inquilinosContratoConflictivo = $this->contarInquilinosConContratoConflictivoSinIdInq($importacionId);

            $resumen['propietarios']['actualizados'] = $propietariosExistentes + $propietariosInmuebleUnico;
            $resumen['propietarios']['omitidos'] = $propietariosConLiquidaciones + $propietariosInmuebleConflictivo;
            $resumen['propietarios']['insertados'] =
                max(
                    0,
                    (int) ($resumen['registros_staging']['propietario'] ?? 0)
                    - $propietariosExistentes
                    - $propietariosInmuebleUnico
                    - $propietariosInmuebleConflictivo
                    - $propietariosConLiquidaciones
                );
            $resumen['inquilinos']['actualizados'] = $inquilinosExistentes + $inquilinosContratoUnico;
            $resumen['inquilinos']['omitidos'] = $inquilinosContratoConflictivo;
            $resumen['inquilinos']['insertados'] =
                max(
                    0,
                    (int) ($resumen['registros_staging']['inquilino'] ?? 0)
                    - $inquilinosExistentes
                    - $inquilinosContratoUnico
                    - $inquilinosContratoConflictivo
                );
        }

        if (in_array('movimientos', $componentes, true)) {
            $resumen['movimientos_propietarios']['insertados'] =
                (int) ($resumen['registros_staging']['cuenta_propietario'] ?? 0);
            $resumen['movimientos_inquilinos']['insertados'] =
                (int) ($resumen['registros_staging']['cuenta_inquilino'] ?? 0);
        }

        if (in_array('liquidaciones', $componentes, true)) {
            $resumen['liquidaciones']['insertadas'] = collect($resumen['registros_staging'])
                ->filter(fn ($cantidad, $tipo) => str_starts_with((string) $tipo, 'liquidacion_'))
                ->sum();
        }

        if (in_array('items', $componentes, true)) {
            $resumen['items_liquidaciones']['insertados'] = $this->contarItemsEnPayload($importacionId);
        }
    }

    private function contarClientesExistentes(int $importacionId, string $tipo, string $columna, bool $soloPropietarios): int
    {
        $query = DB::table('web_importaciones_registros as r')
            ->join('clientes as c', DB::raw("CAST(r.web_clave AS numeric)"), '=', "c.{$columna}")
            ->where('r.web_importacion_id', $importacionId)
            ->where('r.web_tipo', $tipo);

        if ($soloPropietarios) {
            $query->where('c.id_inq', 0);
        }

        return (int) $query->distinct('r.web_id')->count('r.web_id');
    }

    private function contarPropietariosConInmuebleUnicoSinIdProp(int $importacionId): int
    {
        $query = DB::table('web_importaciones_registros as r')
            ->join('inmuebles_propietarios as ip', DB::raw("CAST(r.web_clave AS numeric)"), '=', 'ip.id_prop')
            ->join('clientes as c', 'c.codigo_cliente', '=', 'ip.codigo_cliente')
            ->where('r.web_importacion_id', $importacionId)
            ->where('r.web_tipo', 'propietario')
            ->where('c.id_prop', 0)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('clientes as c2')
                    ->whereRaw('c2.id_prop = CAST(r.web_clave AS numeric)')
                    ->where('c2.id_inq', 0);
            })
            ->selectRaw('r.web_id, COUNT(DISTINCT ip.codigo_cliente) as clientes')
            ->groupBy('r.web_id')
            ->havingRaw('COUNT(DISTINCT ip.codigo_cliente) = 1');

        return (int) DB::query()->fromSub($query, 'x')->count();
    }

    private function contarPropietariosConInmuebleConflictivoSinIdProp(int $importacionId): int
    {
        $query = DB::table('web_importaciones_registros as r')
            ->join('inmuebles_propietarios as ip', DB::raw("CAST(r.web_clave AS numeric)"), '=', 'ip.id_prop')
            ->join('clientes as c', 'c.codigo_cliente', '=', 'ip.codigo_cliente')
            ->where('r.web_importacion_id', $importacionId)
            ->where('r.web_tipo', 'propietario')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('clientes as c2')
                    ->whereRaw('c2.id_prop = CAST(r.web_clave AS numeric)')
                    ->where('c2.id_inq', 0);
            })
            ->selectRaw('r.web_id, COUNT(DISTINCT ip.codigo_cliente) as clientes, SUM(CASE WHEN c.id_prop <> 0 THEN 1 ELSE 0 END) as con_otro_id')
            ->groupBy('r.web_id')
            ->havingRaw('COUNT(DISTINCT ip.codigo_cliente) > 1 OR SUM(CASE WHEN c.id_prop <> 0 THEN 1 ELSE 0 END) > 0');

        return (int) DB::query()->fromSub($query, 'x')->count();
    }

    private function contarPropietariosConLiquidacionesSinClienteNiInmueble(int $importacionId): int
    {
        return (int) DB::table('web_importaciones_registros as r')
            ->where('r.web_importacion_id', $importacionId)
            ->where('r.web_tipo', 'propietario')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('liquidaciones_de_clientes as l')
                    ->whereRaw('l.nro_cuenta = CAST(r.web_clave AS numeric)');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('clientes as c')
                    ->whereRaw('c.id_prop = CAST(r.web_clave AS numeric)')
                    ->where('c.id_inq', 0);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('inmuebles_propietarios as ip')
                    ->whereRaw('ip.id_prop = CAST(r.web_clave AS numeric)');
            })
            ->count();
    }

    private function contarInquilinosConContratoUnicoSinIdInq(int $importacionId): int
    {
        $query = DB::table('web_importaciones_registros as r')
            ->join('contratos_inquilinos as ci', DB::raw("CAST(r.web_clave AS numeric)"), '=', 'ci.id_inq')
            ->join('clientes as c', 'c.codigo_cliente', '=', 'ci.codigo_cliente')
            ->where('r.web_importacion_id', $importacionId)
            ->where('r.web_tipo', 'inquilino')
            ->where('c.id_inq', 0)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('clientes as c2')
                    ->whereRaw('c2.id_inq = CAST(r.web_clave AS numeric)');
            })
            ->selectRaw('r.web_id, COUNT(DISTINCT ci.codigo_cliente) as clientes')
            ->groupBy('r.web_id')
            ->havingRaw('COUNT(DISTINCT ci.codigo_cliente) = 1');

        return (int) DB::query()->fromSub($query, 'x')->count();
    }

    private function contarInquilinosConContratoConflictivoSinIdInq(int $importacionId): int
    {
        $query = DB::table('web_importaciones_registros as r')
            ->join('contratos_inquilinos as ci', DB::raw("CAST(r.web_clave AS numeric)"), '=', 'ci.id_inq')
            ->join('clientes as c', 'c.codigo_cliente', '=', 'ci.codigo_cliente')
            ->where('r.web_importacion_id', $importacionId)
            ->where('r.web_tipo', 'inquilino')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('clientes as c2')
                    ->whereRaw('c2.id_inq = CAST(r.web_clave AS numeric)');
            })
            ->selectRaw('r.web_id, COUNT(DISTINCT ci.codigo_cliente) as clientes, SUM(CASE WHEN c.id_inq <> 0 THEN 1 ELSE 0 END) as con_otro_id')
            ->groupBy('r.web_id')
            ->havingRaw('COUNT(DISTINCT ci.codigo_cliente) > 1 OR SUM(CASE WHEN c.id_inq <> 0 THEN 1 ELSE 0 END) > 0');

        return (int) DB::query()->fromSub($query, 'x')->count();
    }

    private function propietarioTieneLiquidaciones(int $cuenta): bool
    {
        return DB::table('liquidaciones_de_clientes')
            ->where('nro_cuenta', $cuenta)
            ->exists();
    }

    private function clientePropietarioPorInmuebleUnico(int $cuenta): object|string|null
    {
        $codigos = DB::table('inmuebles_propietarios')
            ->where('id_prop', $cuenta)
            ->distinct()
            ->pluck('codigo_cliente')
            ->map(fn ($codigoCliente) => (int) $codigoCliente)
            ->values()
            ->all();

        if ($codigos === []) {
            return null;
        }

        if (count($codigos) !== 1) {
            return 'conflicto';
        }

        $cliente = DB::table('clientes')
            ->where('codigo_cliente', $codigos[0])
            ->first();

        if (! $cliente) {
            return null;
        }

        $idPropActual = (int) ($cliente->id_prop ?? 0);

        if ($idPropActual !== 0 && $idPropActual !== $cuenta) {
            return 'conflicto';
        }

        return $cliente;
    }

    private function clienteInquilinoPorContratoUnico(int $cuenta): object|string|null
    {
        $codigos = DB::table('contratos_inquilinos')
            ->where('id_inq', $cuenta)
            ->distinct()
            ->pluck('codigo_cliente')
            ->map(fn ($codigoCliente) => (int) $codigoCliente)
            ->values()
            ->all();

        if ($codigos === []) {
            return null;
        }

        if (count($codigos) !== 1) {
            return 'conflicto';
        }

        $cliente = DB::table('clientes')
            ->where('codigo_cliente', $codigos[0])
            ->first();

        if (! $cliente) {
            return null;
        }

        $idInqActual = (int) ($cliente->id_inq ?? 0);

        if ($idInqActual !== 0 && $idInqActual !== $cuenta) {
            return 'conflicto';
        }

        return $cliente;
    }

    private function aplicarPropietario(object $registro, array &$resumen): void
    {
        $payload = $this->payload($registro);
        $cuenta = (int) $payload['cuenta'];
        $hash = $this->hashOrigen($registro, 'clientes:propietario');

        if ($this->yaAplicado($hash)) {
            $resumen['propietarios']['omitidos']++;
            return;
        }

        $datos = [
            'apellidos' => Str::limit((string) $payload['nombre'], 40, ''),
            'nombres' => '',
            'razon_social' => Str::limit((string) $payload['nombre'], 100, ''),
            'domicilio' => Str::limit((string) $payload['domicilio'], 100, ''),
            'provincia' => Str::limit((string) $payload['provincia'], 30, ''),
            'localidad' => Str::limit((string) $payload['localidad'], 50, ''),
            'cp' => Str::limit((string) $payload['codigo_postal'], 8, ''),
            'telefonos' => Str::limit((string) $payload['telefono'], 50, ''),
            'cuit' => $this->cuit((string) $payload['identificacion_fiscal']),
            'condicion_iva' => $this->condicionIva((int) $payload['personeria_fiscal']),
            'personeria' => $this->personeria((int) $payload['personeria_fiscal']),
            'id_prop' => $cuenta,
            'web_operativo' => true,
        ];

        $existente = DB::table('clientes')
            ->where('id_prop', $cuenta)
            ->where('id_inq', 0)
            ->first();

        if ($existente) {
            $datos = $this->datosConservadores($datos, $existente, [
                'domicilio',
                'provincia',
                'localidad',
                'cp',
                'telefonos',
                'cuit',
                'condicion_iva',
                'personeria',
            ]);
            DB::table('clientes')
                ->where('codigo_cliente', $existente->codigo_cliente)
                ->update($this->filtrarColumnas('clientes', $datos));
            $resumen['propietarios']['actualizados']++;
            $codigoCliente = (int) $existente->codigo_cliente;
            $accion = 'actualizado';
        } else {
            $existentePorInmueble = $this->clientePropietarioPorInmuebleUnico($cuenta);

            if ($existentePorInmueble === 'conflicto') {
                $resumen['propietarios']['omitidos']++;
                $resumen['advertencias'][] = [
                    'codigo' => 'propietario_omitido_por_inmueble_conflictivo',
                    'mensaje' => 'La cuenta propietaria aparece en inmuebles_propietarios, pero no identifica un unico cliente seguro. Se omite para evitar duplicados.',
                    'cuenta' => $cuenta,
                    'archivo' => $registro->web_archivo,
                    'linea' => $registro->web_linea,
                ];
                $this->registrarAplicacion(
                    $registro,
                    $hash,
                    'propietario',
                    'clientes',
                    (string) $cuenta,
                    'omitido_conflicto',
                    $payload
                );

                return;
            }

            if ($existentePorInmueble) {
                $datos = $this->datosConservadores($datos, $existentePorInmueble, [
                    'domicilio',
                    'provincia',
                    'localidad',
                    'cp',
                    'telefonos',
                    'cuit',
                    'condicion_iva',
                    'personeria',
                ]);
                DB::table('clientes')
                    ->where('codigo_cliente', $existentePorInmueble->codigo_cliente)
                    ->update($this->filtrarColumnas('clientes', $datos));
                $resumen['propietarios']['actualizados']++;
                $codigoCliente = (int) $existentePorInmueble->codigo_cliente;
                $accion = 'actualizado_por_inmueble';
            } elseif ($this->propietarioTieneLiquidaciones($cuenta)) {
                $resumen['propietarios']['omitidos']++;
                $resumen['advertencias'][] = [
                    'codigo' => 'propietario_omitido_por_liquidaciones_existentes',
                    'mensaje' => 'La cuenta propietaria ya existe en liquidaciones historicas, pero no como clientes.id_prop exacto. Se omite para evitar duplicar copropietarios o clientes historicos.',
                    'cuenta' => $cuenta,
                    'archivo' => $registro->web_archivo,
                    'linea' => $registro->web_linea,
                ];
                $this->registrarAplicacion(
                    $registro,
                    $hash,
                    'propietario',
                    'clientes',
                    (string) $cuenta,
                    'omitido_conflicto',
                    $payload
                );

                return;
            } else {
                $codigoCliente = (int) DB::table('clientes')->insertGetId(
                    $this->filtrarColumnas('clientes', $datos + $this->defaultsCliente()),
                    'codigo_cliente'
                );
                $resumen['propietarios']['insertados']++;
                $accion = 'insertado';
            }
        }

        $this->registrarAplicacion($registro, $hash, 'propietario', 'clientes', (string) $codigoCliente, $accion, $payload);
    }

    private function aplicarInquilino(object $registro, array &$resumen): void
    {
        $payload = $this->payload($registro);
        $cuenta = (int) $payload['cuenta'];
        $hash = $this->hashOrigen($registro, 'clientes:inquilino');

        if ($this->yaAplicado($hash)) {
            $resumen['inquilinos']['omitidos']++;
            return;
        }

        $datos = [
            'apellidos' => Str::limit((string) $payload['nombre'], 40, ''),
            'nombres' => '',
            'razon_social' => Str::limit((string) $payload['nombre'], 100, ''),
            'domicilio' => Str::limit((string) $payload['domicilio_legal'], 100, ''),
            'provincia' => Str::limit((string) $payload['provincia'], 30, ''),
            'localidad' => Str::limit((string) $payload['localidad'], 50, ''),
            'cp' => Str::limit((string) $payload['codigo_postal'], 8, ''),
            'telefonos' => trim(($payload['telefono_particular'] ?? '').' '.($payload['telefono_laboral'] ?? '')),
            'doctipo' => $this->tipoDocumento((int) $payload['tipo_documento']),
            'docnro' => Str::limit((string) $payload['documento'], 12, ''),
            'cuit' => $this->cuit((string) $payload['identificacion_fiscal']),
            'condicion_iva' => $this->condicionIva((int) $payload['personeria_fiscal']),
            'personeria' => $this->personeria((int) $payload['personeria_fiscal']),
            'id_inq' => $cuenta,
            'fecha_inicio_inquilino' => $payload['fecha_inicio'] ?: self::FECHA_NULA,
            'web_operativo' => ! (bool) ($payload['omitido_por_baja_antigua'] ?? false),
        ];

        $existente = DB::table('clientes')
            ->where('id_inq', $cuenta)
            ->first();

        if ($existente) {
            $datos = $this->datosConservadores($datos, $existente, [
                'domicilio',
                'provincia',
                'localidad',
                'cp',
                'telefonos',
                'cuit',
                'condicion_iva',
                'personeria',
                'doctipo',
                'docnro',
            ]);
            DB::table('clientes')
                ->where('codigo_cliente', $existente->codigo_cliente)
                ->update($this->filtrarColumnas('clientes', $datos));
            $resumen['inquilinos']['actualizados']++;
            $codigoCliente = (int) $existente->codigo_cliente;
            $accion = 'actualizado';
        } else {
            $existentePorContrato = $this->clienteInquilinoPorContratoUnico($cuenta);

            if ($existentePorContrato === 'conflicto') {
                $resumen['inquilinos']['omitidos']++;
                $resumen['advertencias'][] = [
                    'codigo' => 'inquilino_omitido_por_contrato_conflictivo',
                    'mensaje' => 'La cuenta de inquilino aparece en contratos, pero no identifica un unico cliente seguro. Se omite para evitar duplicados.',
                    'cuenta' => $cuenta,
                    'archivo' => $registro->web_archivo,
                    'linea' => $registro->web_linea,
                ];
                $this->registrarAplicacion(
                    $registro,
                    $hash,
                    'inquilino',
                    'clientes',
                    (string) $cuenta,
                    'omitido_conflicto',
                    $payload
                );

                return;
            }

            if ($existentePorContrato) {
                $datos = $this->datosConservadores($datos, $existentePorContrato, [
                    'domicilio',
                    'provincia',
                    'localidad',
                    'cp',
                    'telefonos',
                    'cuit',
                    'condicion_iva',
                    'personeria',
                    'doctipo',
                    'docnro',
                ]);
                DB::table('clientes')
                    ->where('codigo_cliente', $existentePorContrato->codigo_cliente)
                    ->update($this->filtrarColumnas('clientes', $datos));
                $resumen['inquilinos']['actualizados']++;
                $codigoCliente = (int) $existentePorContrato->codigo_cliente;
                $accion = 'actualizado_por_contrato';
            } else {
                $codigoCliente = (int) DB::table('clientes')->insertGetId(
                    $this->filtrarColumnas('clientes', $datos + $this->defaultsCliente()),
                    'codigo_cliente'
                );
                $resumen['inquilinos']['insertados']++;
                $accion = 'insertado';
            }
        }

        $this->registrarAplicacion($registro, $hash, 'inquilino', 'clientes', (string) $codigoCliente, $accion, $payload);
    }

    private function aplicarMovimiento(object $registro, string $rol, array &$resumen): void
    {
        $payload = $this->payload($registro);
        $hash = $this->hashOrigen($registro, "movimientos:{$rol}");
        $claveResumen = $rol === 'propietario' ? 'movimientos_propietarios' : 'movimientos_inquilinos';

        if ($this->yaAplicado($hash)) {
            $resumen[$claveResumen]['omitidos']++;
            return;
        }

        [$codigoConcepto, $numeroDetalle] = $this->codigoYNumero((string) ($payload['numero_movimiento'] ?? ''));
        $debe = (float) ($payload['debe'] ?? 0);
        $haber = (float) ($payload['haber'] ?? 0);
        $total = $debe - $haber;
        $cuenta = (int) $payload['cuenta'];

        $datos = [
            'id_prop' => $rol === 'propietario' ? $cuenta : 0,
            'id_inq' => $rol === 'inquilino' ? $cuenta : 0,
            'codigo_concepto' => $codigoConcepto,
            'detalle' => (string) ($payload['concepto'] ?? ''),
            'fecha' => $payload['fecha'] ?: self::FECHA_NULA,
            'numero_detalle' => $numeroDetalle,
            'total' => $total,
            'tipo' => $rol === 'propietario' ? 'PROP' : 'INQ',
            'tipo_de_movimiento' => $haber > 0 ? 'HABER' : 'DEBE',
            'observacion' => sprintf(
                'KNG %s linea %s periodo %s',
                $registro->web_archivo,
                $registro->web_linea,
                $payload['periodo'] ?? ''
            ),
        ];

        $idMov = (int) DB::table('movimientos_de_cuentas')->insertGetId(
            $this->filtrarColumnas('movimientos_de_cuentas', $datos),
            'id_mov'
        );
        $resumen[$claveResumen]['insertados']++;
        $this->registrarAplicacion($registro, $hash, "movimiento_{$rol}", 'movimientos_de_cuentas', (string) $idMov, 'insertado', $payload);
    }

    private function aplicarLiquidacion(object $registro, array &$resumen, bool $conItems = false): void
    {
        $payload = $this->payload($registro);
        $hash = $this->hashOrigen($registro, 'liquidacion');

        if ($this->yaAplicado($hash)) {
            $resumen['liquidaciones']['omitidas']++;
            return;
        }

        $cuenta = (int) ($payload['cuenta'] ?? 0);
        $numeroComprobante = (int) ($payload['numero_de_comprobante'] ?? 0);
        $cliente = DB::table('clientes')
            ->where('id_prop', $cuenta)
            ->orderBy('codigo_cliente')
            ->first();

        $datos = [
            'punto_venta' => 0,
            'numero' => $numeroComprobante,
            'fecha' => $payload['fecha'] ?: self::FECHA_NULA,
            'codigo_cliente' => $cliente?->codigo_cliente ?? 0,
            'nro_cuenta' => $cuenta,
            'periodo' => Str::limit((string) ($payload['periodo'] ?? ''), 25, ''),
            'nombre' => Str::limit((string) ($cliente->apellidos ?? ''), 100, ''),
            'razon_social' => Str::limit((string) ($cliente->razon_social ?? ''), 100, ''),
            'numero_de_comprobante' => $numeroComprobante,
            'tipo_de_liquidacion' => Str::limit((string) ($payload['tipo'] ?? ''), 15, ''),
        ];

        $existente = DB::table('liquidaciones_de_clientes')
            ->where('punto_venta', 0)
            ->where('numero', $numeroComprobante)
            ->where('nro_cuenta', $cuenta)
            ->first();

        if ($existente) {
            DB::table('liquidaciones_de_clientes')
                ->where('numero_de_liquidacion', $existente->numero_de_liquidacion)
                ->update($this->filtrarColumnas('liquidaciones_de_clientes', $datos));
            $numeroLiquidacion = (int) $existente->numero_de_liquidacion;
            $accion = 'actualizado';
            $resumen['liquidaciones']['actualizadas']++;
        } else {
            $numeroLiquidacion = (int) DB::table('liquidaciones_de_clientes')->insertGetId(
                $this->filtrarColumnas('liquidaciones_de_clientes', $datos),
                'numero_de_liquidacion'
            );
            $accion = 'insertado';
            $resumen['liquidaciones']['insertadas']++;
        }

        $this->registrarAplicacion($registro, $hash, 'liquidacion', 'liquidaciones_de_clientes', (string) $numeroLiquidacion, $accion, $payload);

        if ($conItems && is_array($payload['items'] ?? null)) {
            $this->aplicarItemsLiquidacion($registro, $payload, $numeroLiquidacion, $numeroComprobante, $resumen);
        }
    }

    private function aplicarItemsLiquidacion(
        object $registro,
        array $payload,
        int $numeroLiquidacion,
        int $numeroComprobante,
        array &$resumen
    ): void {
        foreach (array_values($payload['items']) as $orden => $item) {
            if (! is_array($item)) {
                continue;
            }

            $hash = hash('sha256', implode('|', [
                self::MAPPING_VERSION,
                'liquidaciones_de_clientes_items',
                $registro->web_importacion_id,
                $registro->web_archivo,
                $registro->web_linea,
                $numeroLiquidacion,
                $orden + 1,
                json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]));

            if ($this->yaAplicado($hash)) {
                $resumen['items_liquidaciones']['omitidos']++;
                continue;
            }

            $debe = (float) ($item['debe'] ?? 0);
            $haber = (float) ($item['haber'] ?? 0);
            $detalle = trim((string) ($item['detalle'] ?? ''));
            $referencia = trim((string) ($item['referencia'] ?? ''));

            $datos = [
                'numero_de_liquidacion' => $numeroLiquidacion,
                'punto_venta' => 0,
                'numero' => $numeroComprobante,
                'fecha' => $payload['fecha'] ?: self::FECHA_NULA,
                'codigo_concepto' => 0,
                'id_concepto' => 0,
                'numero_detalle' => $this->numeroDesdeReferencia($referencia),
                'detalle' => Str::limit($referencia ? "{$detalle} ({$referencia})" : $detalle, 100, ''),
                'neto_no_gravado' => 0,
                'total' => $debe - $haber,
                'tipo' => Str::limit((string) ($payload['tipo'] ?? ''), 10, ''),
                'codigo_inmueble' => 0,
                'codigo_contrato' => 0,
            ];

            $numeroItem = (int) DB::table('liquidaciones_de_clientes_items')->insertGetId(
                $this->filtrarColumnas('liquidaciones_de_clientes_items', $datos),
                'numero_de_item'
            );

            $this->registrarAplicacion($registro, $hash, 'item_liquidacion', 'liquidaciones_de_clientes_items', (string) $numeroItem, 'insertado', [
                'orden' => $orden + 1,
                'item' => $item,
            ]);
            $resumen['items_liquidaciones']['insertados']++;
        }
    }

    private function procesarRegistros(int $importacionId, string $tipo, callable $callback, bool $like = false): void
    {
        DB::table('web_importaciones_registros')
            ->where('web_importacion_id', $importacionId)
            ->when(
                $like,
                fn ($query) => $query->where('web_tipo', 'like', $tipo),
                fn ($query) => $query->where('web_tipo', $tipo)
            )
            ->orderBy('web_id')
            ->chunk(1000, function ($registros) use ($callback): void {
                foreach ($registros as $registro) {
                    $callback($registro);
                }
            });
    }

    private function conteosStaging(int $importacionId): array
    {
        return DB::table('web_importaciones_registros')
            ->where('web_importacion_id', $importacionId)
            ->selectRaw('web_tipo, COUNT(*) as cantidad')
            ->groupBy('web_tipo')
            ->orderBy('web_tipo')
            ->pluck('cantidad', 'web_tipo')
            ->map(fn ($cantidad) => (int) $cantidad)
            ->all();
    }

    /**
     * @throws JsonException
     */
    private function payload(object $registro): array
    {
        if (is_array($registro->web_payload)) {
            return $registro->web_payload;
        }

        return json_decode((string) $registro->web_payload, true, 512, JSON_THROW_ON_ERROR);
    }

    private function hashOrigen(object $registro, string $destino): string
    {
        return hash('sha256', implode('|', [
            $destino,
            $registro->web_importacion_id,
            $registro->web_archivo,
            $registro->web_linea,
            $registro->web_tipo,
            json_encode($this->payload($registro), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]));
    }

    private function yaAplicado(string $hash): bool
    {
        return DB::table('web_migraciones_aplicaciones')
            ->where('web_hash_origen', $hash)
            ->exists();
    }

    private function registrarAplicacion(
        object $registro,
        string $hash,
        string $tipo,
        string $tabla,
        string $clave,
        string $accion,
        array $payload
    ): void {
        DB::table('web_migraciones_aplicaciones')->insert([
            'web_importacion_id' => $registro->web_importacion_id,
            'web_registro_id' => $registro->web_id,
            'web_tipo' => $tipo,
            'web_componente' => $this->componentePorTipo($tipo),
            'web_estado' => 'confirmado',
            'web_confirmado' => true,
            'web_simulado' => false,
            'web_mapping_version' => self::MAPPING_VERSION,
            'web_tabla_destino' => $tabla,
            'web_clave_destino' => $clave,
            'web_hash_origen' => $hash,
            'web_accion' => $accion,
            'web_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizarComponentes(array $componentes): array
    {
        $componentes = array_values(array_unique(array_map('strval', $componentes)));
        $invalidos = array_values(array_diff($componentes, self::COMPONENTES));

        if ($invalidos !== []) {
            throw new \InvalidArgumentException('Componentes invalidos: '.implode(', ', $invalidos));
        }

        return $componentes === [] ? self::COMPONENTES : $componentes;
    }

    private function datosConservadores(array $datos, object $existente, array $soloSiDestinoVacio): array
    {
        foreach ($soloSiDestinoVacio as $campo) {
            $actual = trim((string) ($existente->{$campo} ?? ''));
            $nuevo = trim((string) ($datos[$campo] ?? ''));

            if ($actual !== '' && $nuevo === '') {
                unset($datos[$campo]);
                continue;
            }

            if ($actual !== '' && in_array($campo, ['domicilio', 'telefonos', 'cuit', 'docnro'], true)) {
                unset($datos[$campo]);
            }
        }

        return $datos;
    }

    private function contarLiquidacionesConItems(int $importacionId): int
    {
        return (int) DB::table('web_importaciones_registros')
            ->where('web_importacion_id', $importacionId)
            ->where('web_tipo', 'like', 'liquidacion_%')
            ->where('web_payload', 'like', '%"items"%')
            ->count();
    }

    private function contarLiquidacionesIncompletas(int $importacionId): int
    {
        $total = 0;
        $this->procesarRegistros(
            $importacionId,
            'liquidacion_%',
            function ($registro) use (&$total): void {
                $payload = $this->payload($registro);
                $fecha = $payload['fecha'] ?? null;
                $comprobante = preg_replace('/\D+/', '', (string) ($payload['numero_de_comprobante'] ?? '')) ?: '';

                if (! $fecha || $comprobante === '') {
                    $total++;
                }
            },
            like: true
        );

        return $total;
    }

    private function contarItemsEnPayload(int $importacionId): int
    {
        $total = 0;
        $this->procesarRegistros(
            $importacionId,
            'liquidacion_%',
            function ($registro) use (&$total): void {
                $payload = $this->payload($registro);
                $items = $payload['items'] ?? [];
                $total += is_array($items) ? count($items) : 0;
            },
            like: true
        );

        return $total;
    }

    private function numeroDesdeReferencia(string $referencia): int
    {
        preg_match('/\d+/', $referencia, $match);

        return isset($match[0]) ? (int) $match[0] : 0;
    }

    private function componentePorTipo(string $tipo): string
    {
        if (str_contains($tipo, 'propietario') || str_contains($tipo, 'inquilino')) {
            return in_array($tipo, ['propietario', 'inquilino'], true) ? 'clientes' : 'movimientos';
        }

        if ($tipo === 'item_liquidacion') {
            return 'items';
        }

        if ($tipo === 'dailoc') {
            return 'dailoc';
        }

        return 'liquidaciones';
    }

    private function filtrarColumnas(string $tabla, array $datos): array
    {
        if (! isset($this->columnasPorTabla[$tabla])) {
            $this->columnasPorTabla[$tabla] = array_fill_keys(Schema::getColumnListing($tabla), true);
        }

        return array_intersect_key($datos, $this->columnasPorTabla[$tabla]);
    }

    private function defaultsCliente(): array
    {
        return [
            'doctipo' => '',
            'docnro' => '',
            'departamento' => '',
            'caractel' => '',
            'celular' => '',
            'fax' => '',
            'email' => '',
            'nacionalidad' => '',
            'id_prop' => 0,
            'id_inq' => 0,
            'profesion' => '',
            'lugar_de_trabajo' => '',
            'saldo_inicial_inquilino' => 0,
            'saldo_inicial_propietario' => 0,
            'saldo_inicial_imputado_inquilino' => 0,
            'saldo_inicial_imputado_propietario' => 0,
            'saldo_a_favor_inquilino' => 0,
            'saldo_a_favor_propietario' => 0,
            'web_validada' => true,
        ];
    }

    private function cuit(string $valor): string
    {
        $digitos = preg_replace('/\D+/', '', $valor) ?: '';
        return Str::limit($digitos, 13, '');
    }

    private function condicionIva(int $codigo): string
    {
        return match ($codigo) {
            1 => 'RESPONSABLE INSCRIPTO',
            2 => 'EXENTO',
            3 => 'CONSUMIDOR FINAL',
            4 => 'MONOTRIBUTO',
            default => '',
        };
    }

    private function personeria(int $codigo): string
    {
        return $codigo > 0 ? 'Física' : '';
    }

    private function tipoDocumento(int $codigo): string
    {
        return match ($codigo) {
            1 => 'DNI',
            2 => 'LC',
            3 => 'LE',
            default => '',
        };
    }

    private function codigoYNumero(string $numeroMovimiento): array
    {
        $numeroMovimiento = preg_replace('/\D+/', '', $numeroMovimiento) ?: '';
        return [
            (int) substr(str_pad($numeroMovimiento, 8, '0', STR_PAD_LEFT), 0, 2),
            (int) substr(str_pad($numeroMovimiento, 8, '0', STR_PAD_LEFT), 2, 6),
        ];
    }
}
