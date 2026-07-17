<?php

use App\Services\ImportadorPythonService;
use App\Services\MigracionKngGeiPostgresqlService;
use App\Services\ValidacionKngGeiPostgresqlService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gei:marcar-clientes-validados {--repositorio-id=} {--dry-run}', function (
    ImportadorPythonService $importadorPython
) {
    if (! Schema::hasColumn('clientes', 'web_validada')) {
        $this->error(
            'Falta clientes.web_validada. Ejecutá primero sql/2026_07_07_add_web_validada_to_clientes.sql.'
        );

        return 1;
    }

    $repositorioId = (int) ($this->option('repositorio-id') ?: config('gei.importador.repositorio_id'));
    $resultado = $importadorPython->compararCobol($repositorioId);
    $clientesValidados = $resultado['json']['comparacion_postgresql']['clientes_validados'] ?? [];
    $clientesValidados = array_values(array_unique(array_map('intval', $clientesValidados)));

    if ($clientesValidados === []) {
        $this->warn('El comparador no devolvió clientes validados para marcar.');

        return 0;
    }

    if ($this->option('dry-run')) {
        $this->info('Clientes que serían marcados: '.count($clientesValidados));

        return 0;
    }

    $actualizados = 0;
    foreach (array_chunk($clientesValidados, 500) as $lote) {
        $actualizados += DB::table('clientes')
            ->whereIn('codigo_cliente', $lote)
            ->update(['web_validada' => true]);
    }

    $this->info("Clientes marcados como web_validada=true: {$actualizados}");

    return 0;
})->purpose('Marca clientes reconciliados por Actualizar DB sin modificar datos heredados.');

Artisan::command('gei:marcar-clientes-operativos {--repositorio-id=} {--dry-run}', function (
    ImportadorPythonService $importadorPython
) {
    if (! Schema::hasColumn('clientes', 'web_operativo')) {
        $this->error(
            'Falta clientes.web_operativo. Ejecutá primero sql/2026_07_07_add_web_operativo_to_clientes.sql.'
        );

        return 1;
    }

    $repositorioId = (int) ($this->option('repositorio-id') ?: config('gei.importador.repositorio_id'));
    $resultado = $importadorPython->compararCobol($repositorioId);
    $clientesOperativos = $resultado['json']['comparacion_postgresql']['clientes_operativos'] ?? [];
    $clientesOperativos = array_values(array_unique(array_map('intval', $clientesOperativos)));

    $hoy = now()->toDateString();
    $clientesConContratoVigente = DB::table('contratos')
        ->join(
            'contratos_inmuebles',
            'contratos_inmuebles.codigo_contrato',
            '=',
            'contratos.codigo_contrato'
        )
        ->leftJoin(
            'contratos_inquilinos',
            'contratos_inquilinos.codigo_contrato',
            '=',
            'contratos.codigo_contrato'
        )
        ->leftJoin(
            'inmuebles_propietarios',
            'inmuebles_propietarios.codigo_inmueble',
            '=',
            'contratos_inmuebles.codigo_inmueble'
        )
        ->whereDate('contratos.fecha_inicio', '<=', $hoy)
        ->whereDate('contratos.fecha_fin', '>=', $hoy)
        ->selectRaw('contratos_inquilinos.codigo_cliente AS codigo_cliente')
        ->whereNotNull('contratos_inquilinos.codigo_cliente')
        ->union(
            DB::table('contratos')
                ->join(
                    'contratos_inmuebles',
                    'contratos_inmuebles.codigo_contrato',
                    '=',
                    'contratos.codigo_contrato'
                )
                ->join(
                    'inmuebles_propietarios',
                    'inmuebles_propietarios.codigo_inmueble',
                    '=',
                    'contratos_inmuebles.codigo_inmueble'
                )
                ->whereDate('contratos.fecha_inicio', '<=', $hoy)
                ->whereDate('contratos.fecha_fin', '>=', $hoy)
                ->selectRaw('inmuebles_propietarios.codigo_cliente AS codigo_cliente')
        )
        ->pluck('codigo_cliente')
        ->map(fn ($codigoCliente) => (int) $codigoCliente)
        ->all();

    $clientesOperativos = array_values(array_unique([
        ...$clientesOperativos,
        ...$clientesConContratoVigente,
    ]));

    if ($this->option('dry-run')) {
        $this->info('Clientes que serían marcados como operativos: '.count($clientesOperativos));

        return 0;
    }

    DB::table('clientes')->update(['web_operativo' => false]);

    $actualizados = 0;
    foreach (array_chunk($clientesOperativos, 500) as $lote) {
        $actualizados += DB::table('clientes')
            ->whereIn('codigo_cliente', $lote)
            ->update(['web_operativo' => true]);
    }

    $this->info("Clientes marcados como web_operativo=true: {$actualizados}");

    return 0;
})->purpose('Marca clientes operativos por contratos vigentes y evidencia de archivos sin modificar datos heredados.');

Artisan::command('gei:validar-kng-gei-postgresql {importacion_id?} {--solo=} {--completo}', function (
    ValidacionKngGeiPostgresqlService $validacion
) {
    $importacionId = $this->argument('importacion_id');

    if ($importacionId === null) {
        $importacionId = DB::table('web_importaciones')
            ->where('web_tipo', 'kng_gei')
            ->latest('web_id')
            ->value('web_id');
    }

    if (! $importacionId) {
        $this->error('No existe una importación staging kng_gei para validar.');

        return 1;
    }

    $componentes = $this->option('solo')
        ? array_map('trim', explode(',', (string) $this->option('solo')))
        : ValidacionKngGeiPostgresqlService::COMPONENTES;
    $componentes = $this->option('completo') ? ValidacionKngGeiPostgresqlService::COMPONENTES : $componentes;

    $resultado = $validacion->validar((int) $importacionId, $componentes);
    $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $this->info('Validación completada: no se escribieron tablas heredadas.');

    return $resultado['estado'] === 'VALIDACION_PARCIAL' || $resultado['estado'] === 'VALIDACION_NO_CONFIABLE' ? 2 : 0;
})->purpose('Valida staging KNG/GeI contra el resultado historico Fox sin escribir tablas heredadas.');

Artisan::command('gei:migrar-kng-gei-postgresql {importacion_id?} {--confirmar} {--preflight} {--solo=} {--todo} {--validar-contra-fox} {--completo}', function (
    MigracionKngGeiPostgresqlService $migracion,
    ValidacionKngGeiPostgresqlService $validacion
) {
    $importacionId = $this->argument('importacion_id');

    if ($importacionId === null) {
        $importacionId = DB::table('web_importaciones')
            ->where('web_tipo', 'kng_gei')
            ->latest('web_id')
            ->value('web_id');
    }

    if (! $importacionId) {
        $this->error('No existe una importación staging kng_gei para aplicar.');

        return 1;
    }

    if ($this->option('validar-contra-fox') || ! $this->option('confirmar')) {
        $componentesValidacion = $this->option('solo')
            ? array_map('trim', explode(',', (string) $this->option('solo')))
            : ValidacionKngGeiPostgresqlService::COMPONENTES;
        $componentesValidacion = $this->option('completo') ? ValidacionKngGeiPostgresqlService::COMPONENTES : $componentesValidacion;
        $resultado = $validacion->validar((int) $importacionId, $componentesValidacion);
        $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Validación completada: no se escribieron tablas heredadas.');

        return $resultado['estado'] === 'VALIDACION_PARCIAL' || $resultado['estado'] === 'VALIDACION_NO_CONFIABLE' ? 2 : 0;
    }

    $componentes = $this->option('solo')
        ? array_map('trim', explode(',', (string) $this->option('solo')))
        : MigracionKngGeiPostgresqlService::COMPONENTES;

    if (! $this->option('todo') && $this->option('solo') === null && $this->option('confirmar')) {
        $componentes = ['clientes', 'movimientos', 'liquidaciones'];
    }

    if ($this->option('preflight')) {
        $resultado = $migracion->preflight((int) $importacionId, $componentes);
        $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $resultado['estado'] === 'NO_APTO_PARA_CONFIRMAR' ? 2 : 0;
    }

    if ($this->option('confirmar')) {
        $preflight = $migracion->preflight((int) $importacionId, $componentes);
        if ($preflight['estado'] === 'NO_APTO_PARA_CONFIRMAR') {
            $this->line(json_encode($preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error('Preflight no apto. No se ejecutó confirmación.');

            return 2;
        }
    }

    $resultado = $migracion->aplicar((int) $importacionId, (bool) $this->option('confirmar'), $componentes);

    $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    if (! $this->option('confirmar')) {
        $this->warn('Simulación completada: la transacción fue revertida. Usá --confirmar para escribir tablas heredadas.');
    }

    return 0;
})->purpose('Valida por defecto staging KNG/GeI contra Fox; solo escribe heredadas con --confirmar explicito.');
