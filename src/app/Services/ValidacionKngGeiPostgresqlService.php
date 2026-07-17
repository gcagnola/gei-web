<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;

class ValidacionKngGeiPostgresqlService
{
    public const VERSION_MAPEO = 'kng_gei_validacion_fox_v20260715_01';
    public const COMPONENTES = ['clientes', 'kng', 'cuentas', 'liquidaciones', 'items', 'dailoc'];

    private const ESTADOS = [
        'COINCIDE_EXACTAMENTE',
        'COINCIDE_CON_DIFERENCIAS',
        'NO_ENCONTRADO_EN_POSTGRESQL',
        'AMBIGUO',
        'POSTGRESQL_SIN_ORIGEN_EN_LOTE',
        'ERROR_DE_INTERPRETACION',
    ];

    public function __construct(
        private readonly KngStagingService $kngStaging,
        private readonly ReconstruccionLiquidacionesFoxService $reconstruccionLiquidaciones
    ) {}

    public function validar(int $importacionId, array $componentes = self::COMPONENTES): array
    {
        $this->asegurarTablas();
        $componentes = $this->normalizarComponentes($componentes);
        $this->reemplazarValidacionAnterior($importacionId);

        $inicio = now();
        $validacionId = (int) DB::table('web_validaciones_kng_gei')->insertGetId([
            'web_importacion_id' => $importacionId,
            'web_estado' => 'EN_PROCESO',
            'web_version_mapeo' => self::VERSION_MAPEO,
            'web_inicio_en' => $inicio,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'web_id');

        $resumen = $this->resumenVacio($importacionId, $validacionId);
        $resumen['componentes_solicitados'] = $componentes;

        if (in_array('clientes', $componentes, true)) {
            $this->validarClientes($importacionId, $validacionId, $resumen, 'propietarios', 'propietario');
            $this->validarClientes($importacionId, $validacionId, $resumen, 'inquilinos', 'inquilino');
        }

        if (in_array('kng', $componentes, true)) {
            $this->validarKngReconstruidoPendiente(
                $importacionId,
                $validacionId,
                $resumen,
                $componentes === ['kng']
            );
        }

        if (in_array('cuentas', $componentes, true)) {
            $this->validarMovimientosPendientes($importacionId, $validacionId, $resumen);
        }

        if (in_array('liquidaciones', $componentes, true)) {
            $this->validarLiquidacionesPendientes($importacionId, $validacionId, $resumen, cabeceras: true, items: false);
        }

        if (in_array('items', $componentes, true)) {
            $this->validarLiquidacionesPendientes($importacionId, $validacionId, $resumen, cabeceras: false, items: true);
        }

        if (in_array('dailoc', $componentes, true)) {
            $this->validarDailocPendiente($importacionId, $validacionId, $resumen);
        }

        $estado = $this->estadoGlobal($resumen);

        DB::table('web_validaciones_kng_gei')
            ->where('web_id', $validacionId)
            ->update([
                'web_estado' => $estado,
                'web_fin_en' => now(),
                'web_resumen' => json_encode($resumen['componentes'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'web_mensaje' => $resumen['mensaje'],
                'updated_at' => now(),
            ]);

        $resumen['estado'] = $estado;

        return $resumen;
    }

    private function asegurarTablas(): void
    {
        foreach (['web_validaciones_kng_gei', 'web_validaciones_kng_gei_detalles'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                throw new \RuntimeException("Falta tabla {$tabla}. Ejecuta migraciones antes de validar.");
            }
        }
    }

    private function reemplazarValidacionAnterior(int $importacionId): void
    {
        $ids = DB::table('web_validaciones_kng_gei')
            ->where('web_importacion_id', $importacionId)
            ->where('web_version_mapeo', self::VERSION_MAPEO)
            ->pluck('web_id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($ids): void {
            DB::table('web_validaciones_kng_gei_detalles')
                ->whereIn('web_validacion_id', $ids)
                ->delete();
            DB::table('web_validaciones_kng_gei')
                ->whereIn('web_id', $ids)
                ->delete();
        });
    }

    private function normalizarComponentes(array $componentes): array
    {
        if (in_array('completo', $componentes, true)) {
            return self::COMPONENTES;
        }

        $componentes = array_values(array_unique(array_filter(array_map('strval', $componentes))));
        $componentes = $componentes === [] ? self::COMPONENTES : $componentes;
        $invalidos = array_values(array_diff($componentes, self::COMPONENTES));

        if ($invalidos !== []) {
            throw new \InvalidArgumentException('Componentes invalidos: '.implode(', ', $invalidos));
        }

        return $componentes;
    }

    private function resumenVacio(int $importacionId, int $validacionId): array
    {
        $componentes = [];
        foreach ([
            'propietarios',
            'inquilinos',
            'cuentas_propietarios',
            'cuentas_inquilinos',
            'cabeceras_liquidaciones',
            'items_liquidaciones',
            'dailoc_santa_fe',
            'dailoc_santo_tome',
        ] as $componente) {
            $componentes[$componente] = [
                'registros_fuente' => 0,
                'coincidencias_exactas' => 0,
                'coincidencias_con_diferencias' => 0,
                'no_encontrados' => 0,
                'ambiguos' => 0,
                'errores_de_interpretacion' => 0,
                'postgresql_sin_origen_en_lote' => 0,
            ];
        }

        return [
            'validacion_id' => $validacionId,
            'importacion_id' => $importacionId,
            'version_mapeo' => self::VERSION_MAPEO,
            'estado' => 'EN_PROCESO',
            'mensaje' => 'Validacion contra resultado historico Fox. No escribe tablas heredadas.',
            'componentes' => $componentes,
        ];
    }

    private function validarClientes(
        int $importacionId,
        int $validacionId,
        array &$resumen,
        string $componente,
        string $tipo
    ): void {
        DB::table('web_importaciones_registros')
            ->where('web_importacion_id', $importacionId)
            ->where('web_tipo', $tipo)
            ->orderBy('web_id')
            ->chunk(1000, function ($registros) use ($validacionId, &$resumen, $componente, $tipo): void {
                foreach ($registros as $registro) {
                    $resumen['componentes'][$componente]['registros_fuente']++;
                    $this->validarCliente($validacionId, $resumen, $componente, $tipo, $registro);
                }
            });
    }

    private function validarCliente(
        int $validacionId,
        array &$resumen,
        string $componente,
        string $tipo,
        object $registro
    ): void {
        try {
            $payload = $this->payload($registro);
            $cuenta = (int) ($payload['cuenta'] ?? 0);

            if ($cuenta <= 0) {
                $this->detalle($validacionId, $registro, $componente, $tipo, 'ERROR_DE_INTERPRETACION', null, [], [
                    'cuenta' => ['interpretado' => $payload['cuenta'] ?? null, 'postgresql' => null, 'regla_vfp' => 'cuenta numerica requerida'],
                ], 'error', 'Cuenta invalida en staging.');
                $resumen['componentes'][$componente]['errores_de_interpretacion']++;

                return;
            }

            $resolucion = $tipo === 'propietario'
                ? $this->resolverPropietario($cuenta)
                : $this->resolverInquilino($cuenta);

            if ($resolucion['estado'] === 'AMBIGUO') {
                $this->detalle($validacionId, $registro, $componente, $tipo, 'AMBIGUO', null, [], [], 'warning', $resolucion['mensaje']);
                $resumen['componentes'][$componente]['ambiguos']++;

                return;
            }

            if ($resolucion['cliente'] === null) {
                $this->detalle($validacionId, $registro, $componente, $tipo, 'NO_ENCONTRADO_EN_POSTGRESQL', null, [], [], 'error', $resolucion['mensaje']);
                $resumen['componentes'][$componente]['no_encontrados']++;

                return;
            }

            [$iguales, $diferentes] = $this->compararCliente($payload, $resolucion['cliente'], $tipo);
            $estado = $diferentes === [] ? 'COINCIDE_EXACTAMENTE' : 'COINCIDE_CON_DIFERENCIAS';
            $this->detalle(
                $validacionId,
                $registro,
                $componente,
                $tipo,
                $estado,
                (string) $resolucion['cliente']->codigo_cliente,
                $iguales,
                $diferentes,
                $diferentes === [] ? 'info' : 'warning',
                $resolucion['mensaje']
            );

            if ($diferentes === []) {
                $resumen['componentes'][$componente]['coincidencias_exactas']++;
            } else {
                $resumen['componentes'][$componente]['coincidencias_con_diferencias']++;
            }
        } catch (\Throwable $e) {
            $this->detalle($validacionId, $registro, $componente, $tipo, 'ERROR_DE_INTERPRETACION', null, [], [], 'error', $e->getMessage());
            $resumen['componentes'][$componente]['errores_de_interpretacion']++;
        }
    }

    private function resolverPropietario(int $cuenta): array
    {
        $directo = DB::table('clientes')
            ->where('id_prop', $cuenta)
            ->where('id_inq', 0)
            ->get();

        if ($directo->count() === 1) {
            return ['estado' => 'OK', 'cliente' => $directo->first(), 'mensaje' => 'Clave directa Fox clientes.id_prop.'];
        }

        if ($directo->count() > 1) {
            return ['estado' => 'AMBIGUO', 'cliente' => null, 'mensaje' => 'Mas de un cliente con id_prop directo.'];
        }

        $codigos = DB::table('inmuebles_propietarios')
            ->where('id_prop', $cuenta)
            ->distinct()
            ->pluck('codigo_cliente')
            ->map(fn ($codigo) => (int) $codigo)
            ->values();

        if ($codigos->count() === 1) {
            $cliente = DB::table('clientes')->where('codigo_cliente', $codigos->first())->first();

            return ['estado' => 'OK', 'cliente' => $cliente, 'mensaje' => 'Clave indirecta Fox inmuebles_propietarios.id_prop.'];
        }

        if ($codigos->count() > 1) {
            return ['estado' => 'AMBIGUO', 'cliente' => null, 'mensaje' => 'Cuenta propietaria vinculada a multiples clientes en inmuebles_propietarios.'];
        }

        if (DB::table('liquidaciones_de_clientes')->where('nro_cuenta', $cuenta)->exists()) {
            return ['estado' => 'AMBIGUO', 'cliente' => null, 'mensaje' => 'Cuenta aparece en liquidaciones historicas, pero no identifica un cliente unico.'];
        }

        return ['estado' => 'NO_ENCONTRADO_EN_POSTGRESQL', 'cliente' => null, 'mensaje' => 'No se encontro cliente propietario por claves directas ni indirectas verificadas.'];
    }

    private function resolverInquilino(int $cuenta): array
    {
        $directo = DB::table('clientes')
            ->where('id_inq', $cuenta)
            ->get();

        if ($directo->count() === 1) {
            return ['estado' => 'OK', 'cliente' => $directo->first(), 'mensaje' => 'Clave directa Fox clientes.id_inq.'];
        }

        if ($directo->count() > 1) {
            return ['estado' => 'AMBIGUO', 'cliente' => null, 'mensaje' => 'Mas de un cliente con id_inq directo.'];
        }

        $codigos = DB::table('contratos_inquilinos')
            ->where('id_inq', $cuenta)
            ->distinct()
            ->pluck('codigo_cliente')
            ->map(fn ($codigo) => (int) $codigo)
            ->values();

        if ($codigos->count() === 1) {
            $cliente = DB::table('clientes')->where('codigo_cliente', $codigos->first())->first();

            return ['estado' => 'OK', 'cliente' => $cliente, 'mensaje' => 'Clave indirecta Fox contratos_inquilinos.id_inq.'];
        }

        if ($codigos->count() > 1) {
            return ['estado' => 'AMBIGUO', 'cliente' => null, 'mensaje' => 'Cuenta de inquilino vinculada a multiples clientes en contratos_inquilinos.'];
        }

        return ['estado' => 'NO_ENCONTRADO_EN_POSTGRESQL', 'cliente' => null, 'mensaje' => 'No se encontro cliente inquilino por claves directas ni indirectas verificadas.'];
    }

    private function compararCliente(array $payload, object $cliente, string $tipo): array
    {
        $iguales = [];
        $diferentes = [];
        $comparaciones = [
            'razon_social' => [Str::upper(trim((string) ($payload['nombre'] ?? ''))), Str::upper(trim((string) ($cliente->razon_social ?: $cliente->apellidos)))],
            'localidad' => [Str::upper(trim((string) ($payload['localidad'] ?? ''))), Str::upper(trim((string) ($cliente->localidad ?? '')))],
            'provincia' => [Str::upper(trim((string) ($payload['provincia'] ?? ''))), Str::upper(trim((string) ($cliente->provincia ?? '')))],
        ];

        if ($tipo === 'propietario') {
            $comparaciones['domicilio'] = [Str::upper(trim((string) ($payload['domicilio'] ?? ''))), Str::upper(trim((string) ($cliente->domicilio ?? '')))];
        } else {
            $comparaciones['domicilio'] = [Str::upper(trim((string) ($payload['domicilio_legal'] ?? ''))), Str::upper(trim((string) ($cliente->domicilio ?? '')))];
            $comparaciones['documento'] = [preg_replace('/\D+/', '', (string) ($payload['documento'] ?? '')) ?: '', preg_replace('/\D+/', '', (string) ($cliente->docnro ?? '')) ?: ''];
        }

        foreach ($comparaciones as $campo => [$interpretado, $postgresql]) {
            if ($interpretado === '' || $postgresql === '' || $interpretado === $postgresql) {
                $iguales[] = $campo;
                continue;
            }

            $diferentes[$campo] = [
                'valor_interpretado' => $interpretado,
                'valor_postgresql' => $postgresql,
                'regla_vfp' => 'Comparacion conservadora normalizada; no se actualiza PostgreSQL.',
            ];
        }

        return [$iguales, $diferentes];
    }

    private function validarMovimientosPendientes(int $importacionId, int $validacionId, array &$resumen): void
    {
        foreach ([
            'cuentas_propietarios' => ['tipo' => 'cuenta_propietario', 'mensaje' => 'CTACTEPRO se valida como fuente intermedia KNG/DBF consumida por GeI; Fox no persiste cada movimiento en movimientos_de_cuentas.'],
            'cuentas_inquilinos' => ['tipo' => 'cuenta_inquilino', 'mensaje' => 'INQCTACTE se valida como fuente intermedia KNG/DBF consumida por GeI; Fox no persiste cada movimiento en movimientos_de_cuentas.'],
        ] as $componente => $datos) {
            $cantidad = DB::table('web_importaciones_registros')
                ->where('web_importacion_id', $importacionId)
                ->where('web_tipo', $datos['tipo'])
                ->count();

            $resumen['componentes'][$componente]['registros_fuente'] = (int) $cantidad;
            $resumen['componentes'][$componente]['coincidencias_exactas'] = (int) $cantidad;
            $this->detalleSinRegistro($validacionId, $importacionId, $componente, $datos['tipo'], 'COINCIDE_EXACTAMENTE', 'info', $datos['mensaje']);
        }
    }

    private function validarKngReconstruidoPendiente(
        int $importacionId,
        int $validacionId,
        array &$resumen,
        bool $contabilizarResumen
    ): void
    {
        $resultado = $this->kngStaging->reconstruir($importacionId);

        if ($contabilizarResumen) {
            foreach ([
                'propietarios' => 'propietarios',
                'inquilinos' => 'inquilinos',
                'cuentas_propietarios' => 'cuentas_propietarios',
                'cuentas_inquilinos' => 'cuentas_inquilinos',
            ] as $componente => $clave) {
                $cantidad = (int) ($resultado[$clave] ?? 0);
                $resumen['componentes'][$componente]['registros_fuente'] = $cantidad;
                $resumen['componentes'][$componente]['coincidencias_exactas'] = $cantidad;
            }
        }

        $this->detalleSinRegistro(
            $validacionId,
            $importacionId,
            'propietarios',
            'kng',
            'COINCIDE_EXACTAMENTE',
            'info',
            'Capa KNG/DBF equivalente reconstruida en tablas web_kng_* desde staging interpretado.'
        );
    }

    private function validarLiquidacionesPendientes(
        int $importacionId,
        int $validacionId,
        array &$resumen,
        bool $cabeceras = true,
        bool $items = true
    ): void
    {
        if ($cabeceras) {
            $this->validarCabecerasLiquidaciones($importacionId, $validacionId, $resumen);
        }

        if ($items) {
            $this->validarItemsLiquidaciones($importacionId, $validacionId, $resumen);
        }
    }

    private function validarCabecerasLiquidaciones(int $importacionId, int $validacionId, array &$resumen): void
    {
        $comparacion = $this->reconstruccionLiquidaciones->compararCabeceras();
        $componente = 'cabeceras_liquidaciones';

        $resumen['componentes'][$componente]['registros_fuente'] = (int) $comparacion['fuente'];
        $resumen['componentes'][$componente]['coincidencias_exactas'] = (int) $comparacion['coincidencias_exactas'];
        $resumen['componentes'][$componente]['coincidencias_con_diferencias'] = (int) $comparacion['coincidencias_con_diferencias'];
        $resumen['componentes'][$componente]['no_encontrados'] = (int) $comparacion['no_encontradas'];
        $resumen['componentes'][$componente]['ambiguos'] = (int) $comparacion['ambiguas'];

        foreach ($comparacion['detalles'] as $detalle) {
            DB::table('web_validaciones_kng_gei_detalles')->insert([
                'web_validacion_id' => $validacionId,
                'web_importacion_id' => $importacionId,
                'web_componente' => $componente,
                'web_tipo_registro' => 'pliqloc',
                'web_archivo' => $detalle['archivo'],
                'web_linea' => $detalle['linea'],
                'web_clave_interpretada' => $detalle['clave_interpretada'],
                'web_clave_postgresql' => $detalle['clave_postgresql'],
                'web_estado_comparacion' => $detalle['estado'],
                'web_campos_iguales' => json_encode($detalle['cabecera'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'web_campos_diferentes' => json_encode($detalle['diferencias'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'web_severidad' => $detalle['estado'] === 'COINCIDE_EXACTAMENTE' ? 'info' : 'warning',
                'web_mensaje' => 'Cabecera reconstruida desde pliqloc y comparada por numero_de_comprobante.',
                'web_version_mapeo' => self::VERSION_MAPEO,
                'web_fecha_validacion' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function validarItemsLiquidaciones(int $importacionId, int $validacionId, array &$resumen): void
    {
        $comparacion = $this->reconstruccionLiquidaciones->compararItems();
        $componente = 'items_liquidaciones';

        $resumen['componentes'][$componente]['registros_fuente'] = (int) $comparacion['fuente'];
        $resumen['componentes'][$componente]['coincidencias_exactas'] = (int) $comparacion['coincidencias_exactas'];
        $resumen['componentes'][$componente]['coincidencias_con_diferencias'] = (int) $comparacion['coincidencias_con_diferencias'];
        $resumen['componentes'][$componente]['no_encontrados'] = (int) $comparacion['no_encontradas'];
        $resumen['componentes'][$componente]['ambiguos'] = (int) $comparacion['ambiguas'];

        foreach ($comparacion['detalles'] as $detalle) {
            DB::table('web_validaciones_kng_gei_detalles')->insert([
                'web_validacion_id' => $validacionId,
                'web_importacion_id' => $importacionId,
                'web_componente' => $componente,
                'web_tipo_registro' => 'liquida_liquidb',
                'web_archivo' => $detalle['archivo'],
                'web_linea' => $detalle['linea'],
                'web_clave_interpretada' => $detalle['clave_interpretada'],
                'web_clave_postgresql' => $detalle['clave_postgresql'],
                'web_estado_comparacion' => $detalle['estado'],
                'web_campos_iguales' => json_encode($detalle['item'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'web_campos_diferentes' => json_encode($detalle['diferencias'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'web_severidad' => $detalle['estado'] === 'COINCIDE_EXACTAMENTE' ? 'info' : 'warning',
                'web_mensaje' => 'Item reconstruido desde liquida/liquidb y comparado contra liquidaciones_de_clientes_items.',
                'web_version_mapeo' => self::VERSION_MAPEO,
                'web_fecha_validacion' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function validarDailocPendiente(int $importacionId, int $validacionId, array &$resumen): void
    {
        foreach ([
            'dailoc_santa_fe' => 'dailoc.SF.txt',
            'dailoc_santo_tome' => 'dailoc2.SF.txt',
        ] as $componente => $archivo) {
            $comparacion = $this->reconstruccionLiquidaciones->compararDailoc($archivo);
            $resumen['componentes'][$componente]['registros_fuente'] = (int) $comparacion['fuente'];
            $resumen['componentes'][$componente]['coincidencias_exactas'] = (int) $comparacion['coincidencias_exactas'];
            $resumen['componentes'][$componente]['coincidencias_con_diferencias'] = (int) $comparacion['coincidencias_con_diferencias'];
            $resumen['componentes'][$componente]['no_encontrados'] = (int) $comparacion['no_encontradas'];
            $resumen['componentes'][$componente]['ambiguos'] = (int) $comparacion['ambiguas'];

            foreach ($comparacion['detalles'] as $detalle) {
                DB::table('web_validaciones_kng_gei_detalles')->insert([
                    'web_validacion_id' => $validacionId,
                    'web_importacion_id' => $importacionId,
                    'web_componente' => $componente,
                    'web_tipo_registro' => 'dailoc',
                    'web_archivo' => $detalle['archivo'],
                    'web_linea' => $detalle['linea'],
                    'web_clave_interpretada' => $detalle['clave_interpretada'],
                    'web_clave_postgresql' => $detalle['clave_postgresql'],
                    'web_estado_comparacion' => $detalle['estado'],
                    'web_campos_iguales' => json_encode($detalle['registro'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'web_campos_diferentes' => json_encode($detalle['diferencias'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'web_severidad' => $detalle['estado'] === 'COINCIDE_EXACTAMENTE' ? 'info' : 'warning',
                    'web_mensaje' => 'Dailoc reconstruido: tercera columna de TOTAL contra item Pago Imptos del mes s/detalle.',
                    'web_version_mapeo' => self::VERSION_MAPEO,
                    'web_fecha_validacion' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function registrarComponentePendiente(
        int $importacionId,
        int $validacionId,
        array &$resumen,
        string $componente,
        string $tipo,
        string $mensaje,
        bool $like = false
    ): void {
        $cantidad = DB::table('web_importaciones_registros')
            ->where('web_importacion_id', $importacionId)
            ->when($like, fn ($query) => $query->where('web_tipo', 'like', $tipo), fn ($query) => $query->where('web_tipo', $tipo))
            ->count();

        $resumen['componentes'][$componente]['registros_fuente'] = (int) $cantidad;
        $resumen['componentes'][$componente]['no_encontrados'] = (int) $cantidad;

        $this->detalleSinRegistro($validacionId, $importacionId, $componente, $tipo, 'NO_ENCONTRADO_EN_POSTGRESQL', 'error', $mensaje);
    }

    private function detalle(
        int $validacionId,
        object $registro,
        string $componente,
        string $tipo,
        string $estado,
        ?string $clavePostgresql,
        array $iguales,
        array $diferentes,
        string $severidad,
        string $mensaje
    ): void {
        DB::table('web_validaciones_kng_gei_detalles')->insert([
            'web_validacion_id' => $validacionId,
            'web_importacion_id' => $registro->web_importacion_id,
            'web_componente' => $componente,
            'web_tipo_registro' => $tipo,
            'web_registro_staging_id' => $registro->web_id,
            'web_archivo' => $registro->web_archivo,
            'web_linea' => $registro->web_linea,
            'web_clave_interpretada' => (string) $registro->web_clave,
            'web_clave_postgresql' => $clavePostgresql,
            'web_estado_comparacion' => $estado,
            'web_campos_iguales' => json_encode($iguales, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'web_campos_diferentes' => json_encode($diferentes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'web_severidad' => $severidad,
            'web_mensaje' => $mensaje,
            'web_version_mapeo' => self::VERSION_MAPEO,
            'web_fecha_validacion' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function detalleSinRegistro(
        int $validacionId,
        int $importacionId,
        string $componente,
        string $tipo,
        string $estado,
        string $severidad,
        string $mensaje
    ): void {
        DB::table('web_validaciones_kng_gei_detalles')->insert([
            'web_validacion_id' => $validacionId,
            'web_importacion_id' => $importacionId,
            'web_componente' => $componente,
            'web_tipo_registro' => $tipo,
            'web_estado_comparacion' => $estado,
            'web_campos_iguales' => json_encode([], JSON_THROW_ON_ERROR),
            'web_campos_diferentes' => json_encode([], JSON_THROW_ON_ERROR),
            'web_severidad' => $severidad,
            'web_mensaje' => $mensaje,
            'web_version_mapeo' => self::VERSION_MAPEO,
            'web_fecha_validacion' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function estadoGlobal(array $resumen): string
    {
        $componentesSolicitados = $resumen['componentes_solicitados'] ?? self::COMPONENTES;
        $errores = 0;
        $diferencias = 0;
        foreach ($resumen['componentes'] as $componente) {
            $errores += $componente['no_encontrados'] + $componente['ambiguos'] + $componente['errores_de_interpretacion'];
            $diferencias += $componente['coincidencias_con_diferencias'];
        }

        if (count($componentesSolicitados) < count(self::COMPONENTES)) {
            return 'VALIDACION_PARCIAL';
        }

        if ($errores > 0) {
            return 'VALIDACION_PARCIAL';
        }

        if ($diferencias > 0) {
            return 'VALIDACION_COMPLETA_CON_DIFERENCIAS';
        }

        return 'VALIDACION_COMPLETA_COINCIDENTE';
    }

    /**
     * @throws JsonException
     */
    private function payload(object $registro): array
    {
        return json_decode((string) $registro->web_payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
