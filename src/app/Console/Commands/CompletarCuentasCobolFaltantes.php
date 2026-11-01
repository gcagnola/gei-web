<?php

namespace App\Console\Commands;

use App\Services\ClienteCobolNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Incorpora exclusivamente cuentas COBOL que carecen de cliente operativo.
 *
 * No intenta fusionar por CUIT, DNI o nombre con el maestro preexistente.
 * Los registros faltantes entre sí se agrupan por nombre, tipo/documento y CUIT;
 * ante ausencia total de documento y CUIT se distinguen además por rol.
 * No escribe en gei_exploracion ni modifica clientes preexistentes.
 */
final class CompletarCuentasCobolFaltantes extends Command
{
    protected $signature = 'gei:completar-cuentas-cobol
        {--confirmar : Ejecuta la carga (por defecto solo simula)}
        {--esperados= : Cantidad exacta de cuentas faltantes exigida al confirmar}';

    protected $description = 'Completa las cuentas COBOL sin asociación, conservando clientes, emails y unificaciones existentes.';

    public function handle(ClienteCobolNormalizer $normalizador): int
    {
        try {
            $this->verificarBaseDestino();
            $origen = $this->conexionExploracion();
            $plan = $this->armarPlan($origen, $normalizador);
            $this->mostrarPlan($plan);

            if (! (bool) $this->option('confirmar')) {
                $this->info('SIMULACIÓN: no se modificó ninguna base.');
                return self::SUCCESS;
            }

            $esperados = $this->option('esperados');
            if ($esperados === null || ! preg_match('/^\d+$/', (string) $esperados)) {
                throw new RuntimeException('Para confirmar indicá --esperados=N según la simulación revisada.');
            }
            if ((int) $esperados !== count($plan['cuentas'])) {
                throw new RuntimeException('La cantidad actual no coincide con --esperados. Volvé a simular antes de aplicar.');
            }

            $bloqueo = Cache::store((string) config('gei.exploracion.lock_store', 'file'))
                ->lock('gei:transformacion-cobol', 300);
            if (! $bloqueo->get()) {
                throw new RuntimeException('Hay otra transformación COBOL en curso. No se realizaron cambios.');
            }

            try {
                $resultado = DB::transaction(function () use ($plan, $origen, $normalizador): array {
                    $actual = $this->armarPlan($origen, $normalizador);
                    if ($this->firmas($actual) !== $this->firmas($plan)) {
                        throw new RuntimeException('Las cuentas o los datos cambiaron durante la revisión. Repetí la simulación.');
                    }
                    return $this->aplicarPlan($plan);
                }, 1);
            } finally {
                $bloqueo->release();
            }

            $this->info('CONFIRMADO: '.$resultado['clientes'].' clientes, '.$resultado['cuentas'].' cuentas y '.$resultado['roles'].' roles incorporados.');
            $this->line('No se alteraron clientes, emails ni unificaciones preexistentes.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function verificarBaseDestino(): void
    {
        if (DB::selectOne('select current_database() as base')->base !== 'gei_db') {
            throw new RuntimeException('Se esperaba gei_db como base de destino. No se ejecuta.');
        }
    }

    private function conexionExploracion(): ConnectionInterface
    {
        $cfg = config('database.connections.pgsql');
        if (! is_array($cfg)) {
            throw new RuntimeException('Falta la configuración de conexión pgsql.');
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $campo) {
            $cfg[$campo] = config('gei.exploracion.'.$campo);
        }
        if ($cfg['database'] !== 'gei_exploracion') {
            throw new RuntimeException('El origen debe ser gei_exploracion.');
        }
        $cfg['search_path'] = 'public';
        config(['database.connections.gei_exploracion' => $cfg]);
        DB::purge('gei_exploracion');
        return DB::connection('gei_exploracion');
    }

    /** @return array{cuentas:list<array<string,mixed>>,grupos:array<string,list<int>>,asociados_pendientes:int} */
    private function armarPlan(ConnectionInterface $origen, ClienteCobolNormalizer $normalizador): array
    {
        $asociadas = [];
        foreach (DB::table('clientes_cuentas')->select('cuenta', 'rol')->cursor() as $asociacion) {
            $cuenta = $normalizador->cuenta($asociacion->cuenta);
            $asociadas[$asociacion->rol.'|'.$cuenta] = true;
        }

        $resoluciones = [];
        foreach (DB::table('clientes_resoluciones_origen')
            ->where('sistema_origen', 'COBOL')->get(['entidad_origen', 'clave_origen']) as $r) {
            $resoluciones[$r->entidad_origen.'|'.$normalizador->cuenta($r->clave_origen)] = true;
        }
        $origenes = [];
        foreach (DB::table('clientes_origenes')
            ->where('sistema_origen', 'COBOL')->get(['entidad_origen', 'clave_origen']) as $o) {
            $origenes[$o->entidad_origen.'|'.$normalizador->cuenta($o->clave_origen)] = true;
        }

        $cuentas = [];
        $grupos = [];
        $asociadosPendientes = 0;
        $schema = (string) config('gei.exploracion.schema', 'cobol_staging');
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $schema)) {
            throw new RuntimeException('El esquema de staging no tiene un nombre válido.');
        }

        foreach (DB::table('clientes_conflictos')
            ->where('sistema_origen', 'COBOL')
            ->where('estado', 'PENDIENTE')
            ->whereIn('entidad_origen', ['INQUILINO', 'PROPIETAR'])
            ->orderBy('entidad_origen')->orderBy('clave_origen')->cursor() as $conflicto) {
            $entidad = (string) $conflicto->entidad_origen;
            $rol = $entidad === 'PROPIETAR' ? 'PROPIETARIO' : 'INQUILINO';
            $cuenta = $normalizador->cuenta($conflicto->clave_origen);
            if (! preg_match('/^[0-9]{11}$/', $cuenta)) {
                throw new RuntimeException("Cuenta COBOL inválida en conflicto: {$entidad}/{$cuenta}.");
            }
            if (isset($asociadas[$rol.'|'.$cuenta])) {
                $asociadosPendientes++;
                continue;
            }
            if ($conflicto->cliente_resuelto_id !== null) {
                throw new RuntimeException("La cuenta {$cuenta} tiene cliente_resuelto_id, pero ninguna asociación. Revisar manualmente.");
            }
            if (isset($origenes[$entidad.'|'.$cuenta]) || isset($resoluciones[$entidad.'|'.$cuenta])) {
                throw new RuntimeException("La cuenta {$cuenta} ya tiene origen/resolución registrada. No se puede crear otro cliente automáticamente.");
            }

            [$tabla, $columna] = $entidad === 'PROPIETAR'
                ? ['propietar', 'nro_cta_prop']
                : ['inquilino', 'cta_inquilino'];
            $fila = $origen->table($schema.'.'.$tabla)
                ->whereRaw("regexp_replace(coalesce({$columna}, ''), '[^0-9]', '', 'g') = ?", [$cuenta])
                ->orderByDesc('archivo_id')
                ->orderByDesc('numero_linea')
                ->first();
            if ($fila === null) {
                throw new RuntimeException("No se encontró {$entidad} {$cuenta} en staging.");
            }

            $datos = $entidad === 'PROPIETAR'
                ? $normalizador->propietario($fila)
                : $normalizador->inquilino($fila);
            if ($datos['cuenta'] !== $cuenta) {
                throw new RuntimeException("La normalización de la cuenta {$cuenta} cambió.");
            }

            $nombre = mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($datos['nombre'])), 'UTF-8');
            $sinIdentidad = $datos['cuit'] === null && $datos['numero_documento'] === null;
            $firma = json_encode([
                $nombre,
                $datos['tipo_documento'],
                $datos['numero_documento'],
                $datos['cuit'],
                $sinIdentidad ? $rol : null,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $cuentas[] = [
                'entidad' => $entidad,
                'rol' => $rol,
                'cuenta' => $cuenta,
                'datos' => $datos,
                'firma' => $firma,
                'conflicto_id' => (int) $conflicto->id,
            ];
            $grupos[$firma][] = count($cuentas) - 1;
        }

        return [
            'cuentas' => $cuentas,
            'grupos' => $grupos,
            'asociados_pendientes' => $asociadosPendientes,
        ];
    }

    private function mostrarPlan(array $plan): void
    {
        $this->line('Destino: gei_db.public | Origen: gei_exploracion.cobol_staging');
        $this->table(['Concepto', 'Cantidad'], [
            ['Cuentas sin asociación', count($plan['cuentas'])],
            ['Clientes nuevos (grupos exactos)', count($plan['grupos'])],
            ['Conflictos pendientes con cuenta existente (NO se tocan)', $plan['asociados_pendientes']],
        ]);
        foreach (array_slice($plan['cuentas'], 0, 20) as $registro) {
            $this->line(sprintf('  %-11s %-12s %s', $registro['rol'], $registro['cuenta'], $registro['datos']['nombre']));
        }
        if (count($plan['cuentas']) > 20) {
            $this->line('  ... (se muestran las primeras 20 cuentas)');
        }
    }

    /** @return list<string> */
    private function firmas(array $plan): array
    {
        return array_map(
            static fn (array $r): string => $r['entidad'].'|'.$r['cuenta'].'|'.$r['firma'].'|'.($r['datos']['hash_origen'] ?? ''),
            $plan['cuentas']
        );
    }

    /** @return array{clientes:int,cuentas:int,roles:int} */
    private function aplicarPlan(array $plan): array
    {
        $roles = DB::table('roles')->whereIn('codigo', ['INQUILINO', 'PROPIETARIO'])
            ->pluck('id', 'codigo')->all();
        foreach (['INQUILINO', 'PROPIETARIO'] as $rol) {
            if (! isset($roles[$rol])) {
                throw new RuntimeException("Falta el rol {$rol} en gei_db.public.roles.");
            }
        }

        $emailsAntes = DB::table('clientes')
            ->whereNotNull('email')->whereRaw("btrim(email) <> ''")->count();
        $clientePorFirma = [];
        $activoPorFirma = [];
        foreach ($plan['cuentas'] as $registro) {
            $firmaGrupo = $registro['firma'];
            $activoPorFirma[$firmaGrupo] = ($activoPorFirma[$firmaGrupo] ?? false)
                || (bool) $registro['datos']['activo'];
        }
        $rolesAsignados = [];
        $clientesCreados = 0;
        $cuentasCreadas = 0;
        $nuevosRoles = 0;

        foreach ($plan['cuentas'] as $registro) {
            $datos = $registro['datos'];
            $rol = $registro['rol'];
            $cuenta = $registro['cuenta'];
            $firma = $registro['firma'];
            if (! isset($clientePorFirma[$firma])) {
                $clientePorFirma[$firma] = (int) DB::table('clientes')->insertGetId([
                    'tipo_persona' => app(ClienteCobolNormalizer::class)->tipoPersona($datos['cuit'], $datos['numero_documento']),
                    'nombre' => $datos['nombre'],
                    'tipo_documento' => $datos['tipo_documento'],
                    'numero_documento' => $datos['numero_documento'],
                    'cuit' => $datos['cuit'],
                    'condicion_iva' => $datos['condicion_iva'],
                    'domicilio' => $datos['domicilio'],
                    'codigo_postal' => $datos['codigo_postal'],
                    'localidad' => $datos['localidad'],
                    'provincia' => $datos['provincia'],
                    'telefono' => $datos['telefono'],
                    'telefono_alternativo' => $datos['telefono_alternativo'],
                    'email' => null,
                    'activo' => $activoPorFirma[$firma],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $clientesCreados++;
            }
            $id = $clientePorFirma[$firma];
            // Revalidación dentro de la transacción: jamás sumar una segunda asignación.
            if (DB::table('clientes_cuentas')->where('rol', $rol)
                ->whereRaw("regexp_replace(coalesce(cuenta, ''), '[^0-9]', '', 'g') = ?", [$cuenta])
                ->exists()) {
                throw new RuntimeException("La cuenta {$cuenta} fue asociada antes de confirmar; se revierte la transacción.");
            }
            $payload = $datos;
            unset($payload['archivo_origen_id'], $payload['numero_linea'], $payload['hash_origen']);
            $estadoOrigen = $datos['activo'] ? 'ACTIVO' : 'BAJA';
            DB::table('clientes_cuentas')->insert([
                'cliente_id' => $id,
                'cuenta' => $cuenta,
                'rol' => $rol,
                'activo' => $datos['activo'],
                'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $cuentasCreadas++;
            if (! isset($rolesAsignados[$id.'|'.$rol])) {
                DB::table('clientes_roles')->insert([
                    'cliente_id' => $id,
                    'rol_id' => $roles[$rol],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $rolesAsignados[$id.'|'.$rol] = true;
                $nuevosRoles++;
            }
            DB::table('clientes_origenes')->insert([
                'cliente_id' => $id,
                'sistema_origen' => 'COBOL',
                'entidad_origen' => $registro['entidad'],
                'clave_origen' => $cuenta,
                'archivo_origen_id' => $datos['archivo_origen_id'],
                'numero_linea' => $datos['numero_linea'],
                'hash_origen' => $datos['hash_origen'],
                'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'estado_origen' => $estadoOrigen,
                'ultimo_importado_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('clientes_resoluciones_origen')->insert([
                'sistema_origen' => 'COBOL',
                'entidad_origen' => $registro['entidad'],
                'clave_origen' => $cuenta,
                'decision' => 'CREAR_SEPARADO',
                'cliente_id' => $id,
                'usuario_id' => null,
                'detalle_json' => json_encode([
                    'metodo' => 'COMPLETAR_CUENTAS_FALTANTES',
                    'criterio' => 'NOMBRE_DOCUMENTO_CUIT',
                    'sin_asociacion_automatica_a_cliente_existente' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('clientes_conflictos')
                ->where('id', $registro['conflicto_id'])
                ->where('estado', 'PENDIENTE')
                ->update([
                    'estado' => 'RESUELTO',
                    'cliente_resuelto_id' => $id,
                    'resuelto_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $emailsDespues = DB::table('clientes')
            ->whereNotNull('email')->whereRaw("btrim(email) <> ''")->count();
        if ($emailsAntes !== $emailsDespues) {
            throw new RuntimeException('Cambió el número de clientes con email. Se revierte la operación.');
        }

        return ['clientes' => $clientesCreados, 'cuentas' => $cuentasCreadas, 'roles' => $nuevosRoles];
    }
}
