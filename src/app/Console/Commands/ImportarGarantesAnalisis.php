<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ImportarGarantesAnalisis extends Command
{
    protected $signature = 'gei:importar-garantes-analisis {--aplicar : Ejecuta la importacion; sin esta opcion solo informa}';
    protected $description = 'Importa GARANTE.TXT desde garantes_analisis al maestro de clientes en Desarrollo';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Bloqueado: APP_ENV=production. Solo ejecutar en GeI-Desarrollo.');
            return self::FAILURE;
        }
        if (! Schema::hasTable('garantes_analisis') || ! Schema::hasTable('garantes_registros')) {
            $this->error('Falta garantes_analisis o garantes_registros.');
            return self::FAILURE;
        }

        $total = DB::table('garantes_analisis')->count();
        $excluir = ['00010240', '00010241', '00010242', '00010243'];
        $this->line("Fuente: {$total} registros; exclusiones iniciales: " . implode(', ', $excluir));
        if (! $this->option('aplicar')) {
            $this->warn('Simulacion: no se escribio en ninguna tabla. Usar --aplicar para importar.');
            return self::SUCCESS;
        }

        $rolId = DB::table('roles')->where('codigo', 'GARANTE')->value('id');
        if (! $rolId) {
            $this->error('No existe el rol GARANTE.');
            return self::FAILURE;
        }

        $stats = ['registros' => 0, 'excluidos' => 0, 'clientes_nuevos' => 0, 'clientes_existentes' => 0];
        DB::table('garantes_analisis')->orderBy('nroreg')->chunk(250, function ($filas) use ($rolId, $excluir, &$stats): void {
            DB::transaction(function () use ($filas, $rolId, $excluir, &$stats): void {
                foreach ($filas as $fila) {
                    $id = trim((string) $fila->nroreg);
                    if (! preg_match('/^\d{8}$/', $id)) {
                        throw new RuntimeException("NROREG invalido: {$id}");
                    }
                    $cuenta = trim((string) $fila->cuenta_inquilino);
                    if (! preg_match('/^\d{11}$/', $cuenta)) {
                        throw new RuntimeException("Cuenta invalida para {$id}: {$cuenta}");
                    }
                    $ahora = now();
                    $base = [
                        'cuenta_inquilino' => $cuenta,
                        'nombre' => trim((string) $fila->nombre),
                        'domicilio_garante' => $this->nulo($fila->domicilio),
                        'codigo_postal' => $this->nulo($fila->codigo_postal),
                        'localidad' => $this->nulo($fila->localidad),
                        'provincia' => $this->nulo($fila->provincia),
                        'telefono_1' => $this->nulo($fila->telefono_1),
                        'telefono_2' => $this->nulo($fila->telefono_2),
                    ];
                    $origen = DB::table('garantes_registros')->where('nroreg', $id)->first();
                    if (! $origen) {
                        DB::table('garantes_registros')->insert(array_merge($base, [
                            'nroreg' => $id,
                            'excluido' => in_array($id, $excluir, true),
                            'motivo_exclusion' => in_array($id, $excluir, true) ? 'Registro basura confirmado' : null,
                            'estado_vinculacion' => 'PENDIENTE',
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ]));
                    } else {
                        // Respeta exclusion, inmueble y resolucion manual previamente confirmados.
                        DB::table('garantes_registros')->where('nroreg', $id)->update(array_merge($base, ['updated_at' => $ahora]));
                    }

                    $actual = DB::table('garantes_registros')->where('nroreg', $id)->first();
                    $stats['registros']++;
                    if ($actual->excluido) {
                        $stats['excluidos']++;
                        continue;
                    }

                    $co = DB::table('clientes_origenes')
                        ->where('sistema_origen', 'COBOL')
                        ->where('entidad_origen', 'GARANTE')
                        ->where('clave_origen', $id)->first();
                    $clienteId = $actual->cliente_id ?: ($co->cliente_id ?? null);
                    if (! $clienteId) {
                        $clienteId = DB::table('clientes')->insertGetId([
                            'nombre' => $base['nombre'],
                            'tipo_persona' => 'DESCONOCIDA',
                            'telefono' => $base['telefono_1'],
                            'telefono_alternativo' => $base['telefono_2'],
                            // DOMICGAR es el domicilio declarado del garante en COBOL.
                            // No sobreescribir datos ya existentes del maestro de clientes.
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                        $stats['clientes_nuevos']++;
                    } else {
                        $stats['clientes_existentes']++;
                    }

                    DB::table('clientes_roles')->insertOrIgnore([
                        'cliente_id' => $clienteId,
                        'rol_id' => $rolId,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                    DB::table('garantes_registros')->where('nroreg', $id)
                        ->update(['cliente_id' => $clienteId, 'updated_at' => $ahora]);

                    $datos = json_encode(array_merge(['nroreg' => $id], $base), JSON_UNESCAPED_UNICODE);
                    $hash = hash('sha256', $datos);
                    if ($co) {
                        DB::table('clientes_origenes')->where('id', $co->id)->update([
                            'cliente_id' => $clienteId,
                            'hash_origen' => $hash,
                            'datos_origen' => $datos,
                            'ultimo_importado_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                    } else {
                        DB::table('clientes_origenes')->insert([
                            'cliente_id' => $clienteId,
                            'sistema_origen' => 'COBOL',
                            'entidad_origen' => 'GARANTE',
                            'clave_origen' => $id,
                            'hash_origen' => $hash,
                            'datos_origen' => $datos,
                            'ultimo_importado_at' => $ahora,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                    }
                }
            });
        });

        foreach ($stats as $nombre => $valor) {
            $this->info("{$nombre}: {$valor}");
        }
        return self::SUCCESS;
    }

    private function nulo($valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));
        return $valor === '' ? null : $valor;
    }
}
