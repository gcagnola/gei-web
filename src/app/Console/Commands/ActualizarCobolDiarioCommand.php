<?php

namespace App\Console\Commands;

use App\Services\MigracionClientesCobolService;
use App\Services\MigracionInmueblesCobolService;
use App\Services\MigracionContratosCobolService;
use App\Services\MigracionCuentasCorrientesCobolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;
use RuntimeException;

final class ActualizarCobolDiarioCommand extends Command
{
    protected $signature = 'gei:actualizar-cobol-diario
        {directorio : Directorio AAAAMM dentro del contenedor con los cuatro TXT}
        {--fecha= : Fecha YYYY-MM-DD de EXPORTACION_OK}
        {--sha256= : SHA-256 del marcador EXPORTACION_OK verificado externamente}
        {--confirmar : Autoriza la actualización operativa en gei_db}';

    protected $description = 'Publica la fotografía diaria COBOL acumulativa en gei_db (ambas sedes).';

    public function handle(
        MigracionClientesCobolService $clientes,
        MigracionInmueblesCobolService $inmuebles,
        MigracionContratosCobolService $contratos,
        MigracionCuentasCorrientesCobolService $cuentas
    ): int {
        $fecha = (string) $this->option('fecha');
        $sha = strtolower((string) $this->option('sha256'));
        $directorio = (string) $this->argument('directorio');
        if (! $this->option('confirmar')) {
            $this->error('Sin --confirmar no se modifica gei_db.');
            return self::FAILURE;
        }
        if (! preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $fecha)
            || ! checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))
            || ! preg_match('/^[0-9a-f]{64}$/D', $sha)) {
            $this->error('Fecha o SHA-256 inválidos.');
            return self::FAILURE;
        }
        $periodo = substr($fecha, 0, 4).substr($fecha, 5, 2);
        if (basename(rtrim($directorio, '/')) !== $periodo || ! is_dir($directorio.'/cobol')) {
            $this->error('Directorio de fotografía no corresponde al período del cierre.');
            return self::FAILURE;
        }
        if (! Schema::hasTable('cobol_publicaciones')) {
            $this->error('Falta migración cobol_publicaciones. No se publica ningún cierre.');
            return self::FAILURE;
        }
        $lock = Cache::store((string) config('gei.exploracion.lock_store', 'file'))
            ->lock('gei:transformacion-cobol', 7200);
        if (! $lock->get()) {
            $this->error('Ya se ejecuta una actualización COBOL mensual o diaria.');
            return self::FAILURE;
        }
        try {
            $ultimo = DB::table('cobol_publicaciones')->orderByDesc('fecha_cierre')
                ->orderByDesc('id')->first();
            if ($ultimo !== null && $fecha < $ultimo->fecha_cierre) {
                throw new RuntimeException('Cierre anterior al último publicado; no se retrocede.');
            }
            if ($ultimo !== null && $sha === $ultimo->sha256_exportacion) {
                $this->info('SIN_CAMBIOS: lote ya publicado '.$ultimo->fecha_cierre);
                return self::SUCCESS;
            }
            foreach (['PROPIETAR.TXT', 'INQUILINO.TXT', 'CTACTEPRO.TXT', 'INQCTACTE.TXT'] as $nombre) {
                $ruta = $directorio.'/cobol/'.$nombre;
                if (! is_file($ruta) || filesize($ruta) <= 0 || is_link($ruta)) {
                    throw new RuntimeException('Falta TXT íntegro: '.$nombre);
                }
            }
            $script = base_path('scripts/crear_db_staging_gei_diario.sh');
            if (! is_file($script)) {
                throw new RuntimeException('Falta el importador nocturno de staging.');
            }
            $env = [
                'PGHOST' => (string) config('gei.exploracion.host'),
                'PGPORT' => (string) config('gei.exploracion.port'),
                'PGUSER' => (string) config('gei.exploracion.username'),
                'PGPASSWORD' => (string) config('gei.exploracion.password'),
                'GEI_DB' => (string) config('gei.exploracion.database', 'gei_exploracion'),
                'GEI_SOLO_COBOL' => '1',
            ];
            $process = new Process(['/bin/bash', $script, $directorio], base_path(), $env);
            $process->setTimeout(3600);
            $this->line('Cargando fotografía COBOL aislada de los períodos mensuales...');
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Falló la preparación: '.substr($process->getErrorOutput(), -2000));
            }
            // Las cuatro tablas contienen exactamente el último lote, no una
            // acumulación de snapshots crudos; el acumulado está en gei_db.
            config(['gei.exploracion.schema' => 'cobol_diario']);
            $this->descartarFotografiasCrudasPrevias($periodo);

            $resumen = DB::transaction(function () use ($clientes, $inmuebles, $contratos, $cuentas, $periodo, $fecha, $sha): array {
                $r = [];
                $this->line('Clientes y cuentas COBOL...');
                $r['clientes'] = $clientes->ejecutar(true);
                $this->line('Identidad física de inmuebles y relaciones...');
                $r['inmuebles'] = $inmuebles->ejecutar(true, null, null, null, null, $periodo);
                $this->line('Contratos e inquilinos...');
                $r['contratos'] = $contratos->ejecutar(true);
                $this->line('Movimientos acumulados (upsert por clave_origen)...');
                $r['cuentas_corrientes'] = $cuentas->ejecutar(true);
                DB::table('cobol_publicaciones')->insert([
                    'fecha_cierre' => $fecha,
                    'sha256_exportacion' => $sha,
                    'estado' => 'PUBLICADO',
                    'resumen' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'publicado_en' => now(),
                ]);
                return $r;
            }, 1);
            $this->info('CIERRE_PUBLICADO_OK fecha='.$fecha.' movimientos_nuevos='.
                ($resumen['cuentas_corrientes']['movimientos_creados'] ?? 0).' movimientos_actualizados='.
                ($resumen['cuentas_corrientes']['movimientos_actualizados'] ?? 0));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('NO_PUBLICADO: '.$e->getMessage());
            report($e);
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function descartarFotografiasCrudasPrevias(string $periodo): void
    {
        // Laravel mensual crea esta conexión dinámicamente; el comando diario
        // necesita la misma conexión aun antes de invocar los servicios.
        $cfg = config('database.connections.pgsql');
        foreach (['host', 'port', 'database', 'username', 'password'] as $campo) {
            $cfg[$campo] = config('gei.exploracion.'.$campo);
        }
        $cfg['search_path'] = 'public';
        config(['database.connections.gei_exploracion' => $cfg]);
        DB::purge('gei_exploracion');
        $db = DB::connection('gei_exploracion');
        $schema = 'cobol_diario';
        $ultima = $db->table($schema.'.migraciones_periodos')
            ->where('periodo', $periodo)->where('estado', 'OK')
            ->orderByDesc('id')->first(['id']);
        if ($ultima === null) {
            throw new RuntimeException('No existe cierre diario validado en staging.');
        }
        $relaciones = $db->table($schema.'.migraciones_periodos_archivos')
            ->where('migracion_id', $ultima->id)->pluck('archivo_id', 'procesado_en_tabla');
        $db->transaction(function () use ($db, $schema, $relaciones): void {
            foreach (['propietar', 'inquilino', 'ctactepro', 'inqctacte'] as $tabla) {
                $id = $relaciones->get($tabla);
                if (! $id) {
                    throw new RuntimeException('Falta archivo diario validado '.$tabla);
                }
                $db->table($schema.'.'.$tabla)->where('archivo_id', '<>', (int) $id)->delete();
                $esperados = $db->table($schema.'.archivos_importados')
                    ->where('id', $id)->value('cantidad_registros');
                $actuales = $db->table($schema.'.'.$tabla)->where('archivo_id', $id)->count();
                if ($actuales !== (int) $esperados || $actuales === 0) {
                    throw new RuntimeException('Fotografía incompleta '.$tabla);
                }
            }
        }, 1);
    }
}
