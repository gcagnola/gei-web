<?php

use App\Services\MigracionKngGeiPostgresqlService;
use App\Services\ValidacionKngGeiPostgresqlService;
use App\Services\WebCobolPilotImporter;
use App\Services\WebLiquidacionPropietarioPdfPilotService;
use App\Services\WebLiquidacionPropietarioPilotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gei:web-importar-cobol-piloto
    {--base-dir=storage/app/private/liquidaciones/cobol}
    {--limite-propietarios=5}
    {--limite-inquilinos=5}
    {--limite-movimientos-propietario=20}
    {--limite-movimientos-inquilino=20}
    {--cuenta-propietario=}
    {--cuenta-inquilino=}
    {--modo=piloto}
    {--chunk-size=5000}
    {--sin-limite}
    {--dry-run}', function (WebCobolPilotImporter $importer) {
        $database = DB::connection()->getDatabaseName();
        $this->warn("Base destino: {$database}");

        if ($database === 'db_gei') {
            $this->error('Abortado: DB_DATABASE apunta a db_gei.');

            return 2;
        }

        if ($database !== 'db_gei_web_migraciones_test') {
            $this->error('Abortado: el importador piloto solo puede ejecutarse contra db_gei_web_migraciones_test.');

            return 2;
        }

        $resultado = $importer->importar([
            'modo' => (string) $this->option('modo'),
            'chunk_size' => max(100, (int) $this->option('chunk-size')),
            'base_dir' => (string) $this->option('base-dir'),
            'limite_propietarios' => $this->option('sin-limite') ? null : max(0, (int) $this->option('limite-propietarios')),
            'limite_inquilinos' => $this->option('sin-limite') ? null : max(0, (int) $this->option('limite-inquilinos')),
            'limite_movimientos_propietario' => $this->option('sin-limite') ? null : max(0, (int) $this->option('limite-movimientos-propietario')),
            'limite_movimientos_inquilino' => $this->option('sin-limite') ? null : max(0, (int) $this->option('limite-movimientos-inquilino')),
            'cuenta_propietario' => $this->option('cuenta-propietario') ?: null,
            'cuenta_inquilino' => $this->option('cuenta-inquilino') ?: null,
            'sin_limite' => (bool) $this->option('sin-limite'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
})->purpose('Importador piloto COBOL limitado para tablas web_* en base temporal.');

Artisan::command('gei:web-liquidacion-propietario-piloto
    {cuenta=12020240300}
    {--periodo=}
    {--detalle-limite=200}
    {--clasificar-movimientos}
    {--construir-items}
    {--export-json}
    {--output=}
    {--total-esperado=}', function (WebLiquidacionPropietarioPilotService $service) {
        $exportJson = (bool) $this->option('export-json');
        $construirItems = (bool) $this->option('construir-items') || $exportJson;
        $resultado = $service->reconstruir(
            (string) $this->argument('cuenta'),
            $this->option('periodo') ? (string) $this->option('periodo') : null,
            max(1, (int) $this->option('detalle-limite')),
            (bool) $this->option('clasificar-movimientos') || $exportJson,
            $this->option('total-esperado') ? (string) $this->option('total-esperado') : null,
            $construirItems
        );

        if ($exportJson) {
            $payload = $service->jsonIntermedio($resultado);
            $periodo = $resultado['periodo_usado'] ?? 'sin_periodo';
            $output = $this->option('output') ?: "storage/app/private/liquidaciones/piloto/{$this->argument('cuenta')}_{$periodo}/liquidacion_web_piloto.json";
            $outputPath = base_path((string) $output);

            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $this->line(json_encode([
                'estado' => 'JSON_INTERMEDIO_EXPORTADO',
                'output' => $output,
                'bytes' => File::size($outputPath),
                'total_items' => $payload['encabezado']['total_items'],
                'diferencia' => $payload['encabezado']['diferencia'],
                'items' => $payload['resumen']['items_construidos'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
})->purpose('Reconstruye una liquidacion piloto de propietario desde tablas web_* en PostgreSQL 17 temporal.');

Artisan::command('gei:web-liquidacion-pdf-piloto
    {json}
    {--output=}', function (WebLiquidacionPropietarioPdfPilotService $service) {
        $json = (string) $this->argument('json');
        $output = $this->option('output') ?: preg_replace('/\.json$/', '.pdf', $json);

        if (! is_string($output) || $output === '') {
            $this->error('Debe indicarse --output o usar un JSON con extension .json.');

            return 2;
        }

        $resultado = $service->generar(base_path($json), base_path($output));
        $resultado['json'] = $json;
        $resultado['output'] = $output;

        $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 0;
})->purpose('Genera un PDF piloto aislado desde JSON intermedio web_* sin recalcular liquidacion.');

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

Artisan::command('gei:web-liquidacion-reportlab-piloto {json} {--output=}', function () {
    $jsonPath = base_path($this->argument('json'));

    if (! file_exists($jsonPath)) {
        $this->error("No existe el JSON: {$jsonPath}");
        return 1;
    }

    $output = $this->option('output');

    if ($output === null || $output === '') {
        $dir = dirname($jsonPath);
        $outputPath = $dir . DIRECTORY_SEPARATOR . 'liquidacion_web_piloto_reportlab_artisan.pdf';
    } else {
        $outputPath = base_path($output);
    }

    $python = env('GEI_PDF_PYTHON', '/opt/gei-python/bin/python');
    $script = base_path('python/gei_liquidaciones_piloto/pdf_desde_json_web_piloto.py');

    if (! file_exists($python)) {
        $this->error("No existe el runtime Python: {$python}");
        return 1;
    }

    if (! file_exists($script)) {
        $this->error("No existe el script piloto: {$script}");
        return 1;
    }

    if (! is_dir(dirname($outputPath))) {
        mkdir(dirname($outputPath), 0775, true);
    }

    $cmd = [
        $python,
        $script,
        $jsonPath,
        '--output',
        $outputPath,
    ];

    $process = new Symfony\Component\Process\Process($cmd, base_path());
    $process->setTimeout(120);
    $process->run();

    if (! $process->isSuccessful()) {
        $this->error('Falló la generación PDF piloto.');
        $this->line($process->getErrorOutput());
        $this->line($process->getOutput());
        return 1;
    }

    if (! file_exists($outputPath) || filesize($outputPath) <= 0) {
        $this->error("El PDF no fue generado correctamente: {$outputPath}");
        return 1;
    }

    $header = file_get_contents($outputPath, false, null, 0, 5);

    if ($header !== '%PDF-') {
        $this->error("El archivo generado no parece PDF válido: {$outputPath}");
        return 1;
    }

    $this->info('PDF piloto ReportLab generado correctamente.');
    $this->line("JSON: {$jsonPath}");
    $this->line("PDF: {$outputPath}");
    $this->line('Tamaño: ' . filesize($outputPath) . ' bytes');

    return 0;
});
