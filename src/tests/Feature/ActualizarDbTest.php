<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\ImportadorPythonService;
use App\Services\ValidacionKngGeiPostgresqlService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActualizarDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_la_pantalla_requiere_autenticacion(): void
    {
        $this->get(route('archivo.actualizar-db'))
            ->assertRedirect(route('login'));
    }

    public function test_muestra_archivos_cobol_almacenados(): void
    {
        Storage::put('liquidaciones/cobol/PROPIETAR.TXT', 'propietarios');
        Storage::put('liquidaciones/cobol/INQUILINO.TXT', 'inquilinos');

        $this->actingAs($this->usuario())
            ->get(route('archivo.actualizar-db'))
            ->assertOk()
            ->assertSee('Actualizar DB')
            ->assertSee('PROPIETAR.TXT')
            ->assertSee('INQUILINO.TXT')
            ->assertSee('Esta operación no modifica clientes, movimientos ni liquidaciones')
            ->assertSee('Validar archivos contra importación Fox');
    }

    public function test_ejecuta_validacion_cobol_y_muestra_resultado(): void
    {
        Storage::put('liquidaciones/cobol/PROPIETAR.TXT', 'propietarios');
        Storage::put('liquidaciones/cobol/INQUILINO.TXT', 'inquilinos');

        $this->app->instance(
            ImportadorPythonService::class,
            new class extends ImportadorPythonService
            {
                public function validarCobol(int $repositorioId): array
                {
                    return [
                        'exit_code' => 0,
                        'stdout' => '{}',
                        'stderr' => '',
                        'json' => [
                            'repositorio_id' => $repositorioId,
                            'modo' => 'solo-validar',
                            'archivos' => [
                                'PROPIETAR.TXT' => [
                                    'registros' => 2,
                                    'validos' => 2,
                                    'errores' => 0,
                                    'encoding' => 'cp1252',
                                ],
                                'INQUILINO.TXT' => [
                                    'registros' => 3,
                                    'validos' => 3,
                                    'errores' => 0,
                                    'encoding' => 'cp1252',
                                ],
                            ],
                            'escritura_postgresql' => false,
                        ],
                    ];
                }

                public function compararCobol(int $repositorioId): array
                {
                    return [
                        'exit_code' => 0,
                        'stdout' => '{}',
                        'stderr' => '',
                        'json' => [
                            'repositorio_id' => $repositorioId,
                            'modo' => 'comparar',
                            'archivos' => [],
                            'comparacion_postgresql' => [
                                'existentes_sin_cambios' => 1,
                                'existentes_con_diferencias' => 0,
                                'nuevos' => 0,
                                'ambiguos' => 0,
                                'errores' => 0,
                                'omitidos_por_baja_antigua' => 0,
                                'muestras' => [],
                            ],
                            'escritura_postgresql' => false,
                        ],
                    ];
                }
            }
        );

        $this->actingAs($this->usuario())
            ->post(route('archivo.actualizar-db.validar-cobol'))
            ->assertRedirect(route('archivo.actualizar-db'))
            ->assertSessionHas('resultado_actualizar_db');
    }

    public function test_ejecuta_comparacion_cobol_y_muestra_resultado(): void
    {
        Storage::put('liquidaciones/cobol/PROPIETAR.TXT', 'propietarios');
        Storage::put('liquidaciones/cobol/INQUILINO.TXT', 'inquilinos');

        $this->app->instance(
            ImportadorPythonService::class,
            new class extends ImportadorPythonService
            {
                public function validarCobol(int $repositorioId): array
                {
                    return [];
                }

                public function compararCobol(int $repositorioId): array
                {
                    return [
                        'exit_code' => 0,
                        'stdout' => '{}',
                        'stderr' => '',
                        'json' => [
                            'repositorio_id' => $repositorioId,
                            'modo' => 'comparar',
                            'archivos' => [],
                            'comparacion_postgresql' => [
                                'existentes_sin_cambios' => 10,
                                'existentes_con_diferencias' => 1,
                                'nuevos' => 0,
                                'ambiguos' => 0,
                                'errores' => 0,
                                'omitidos_por_baja_antigua' => 2,
                                'ambiguos_resueltos_por_id_inq' => 1,
                                'motivos_resumen' => [
                                    'Existe inmuebles_propietarios para inmueble + id_prop con otro codigo_cliente' => 1,
                                ],
                                'muestras' => [],
                            ],
                            'escritura_postgresql' => false,
                        ],
                    ];
                }
            }
        );

        $this->actingAs($this->usuario())
            ->post(route('archivo.actualizar-db.comparar-cobol'))
            ->assertRedirect(route('archivo.actualizar-db'))
            ->assertSessionHas('resultado_actualizar_db');
    }

    public function test_muestra_resumen_de_comparacion_postgresql(): void
    {
        $this->actingAs($this->usuario())
            ->withSession([
                'resultado_actualizar_db' => [
                    'exit_code' => 0,
                    'stdout' => '{}',
                    'stderr' => '',
                    'json' => [
                        'repositorio_id' => 123,
                        'modo' => 'comparar',
                        'archivos' => [],
                        'comparacion_postgresql' => [
                            'existentes_sin_cambios' => 10,
                            'existentes_con_diferencias' => 2,
                            'nuevos' => 0,
                            'ambiguos' => 1,
                            'errores' => 0,
                            'omitidos_por_baja_antigua' => 3,
                            'ambiguos_resueltos_por_id_inq' => 9,
                            'motivos_resumen' => [
                                'Mas de un cliente coincide por CUIT/docnro' => 1,
                            ],
                            'cruces_resumen' => [
                                'Mas de un cliente coincide por CUIT/docnro' => [
                                    'id_inq_en_inqctacte=si' => 1,
                                    'id_prop_en_liquidaciones=si' => 1,
                                ],
                            ],
                            'muestras' => [],
                        ],
                        'escritura_postgresql' => false,
                    ],
                ],
            ])
            ->get(route('archivo.actualizar-db'))
            ->assertOk()
            ->assertSee('Resumen de diferencias y ambiguos')
            ->assertSee('Cruce con otros archivos COBOL y liquidaciones')
            ->assertSee('Mas de un cliente coincide por CUIT/docnro')
            ->assertSee('id_inq_en_inqctacte=si')
            ->assertSee('id_inq');
    }

    public function test_no_ejecuta_validacion_si_faltan_archivos_requeridos(): void
    {
        Storage::put('liquidaciones/cobol/PROPIETAR.TXT', 'propietarios');

        $this->app->instance(
            ImportadorPythonService::class,
            new class extends ImportadorPythonService
            {
                public function validarCobol(int $repositorioId): array
                {
                    throw new \RuntimeException('No deberia invocarse el importador.');
                }

                public function compararCobol(int $repositorioId): array
                {
                    throw new \RuntimeException('No deberia invocarse el importador.');
                }
            }
        );

        $this->actingAs($this->usuario())
            ->post(route('archivo.actualizar-db.validar-cobol'))
            ->assertRedirect(route('archivo.actualizar-db'))
            ->assertSessionHas('error_actualizar_db');
    }

    public function test_muestra_advertencias_y_errores_de_formato_del_resultado(): void
    {
        $this->actingAs($this->usuario())
            ->withSession([
                'resultado_actualizar_db' => [
                    'exit_code' => 0,
                    'stdout' => '{}',
                    'stderr' => '',
                    'json' => [
                        'repositorio_id' => 123,
                        'modo' => 'solo-validar',
                        'archivos' => [
                            'PROPIETAR.TXT' => [
                                'registros' => 1,
                                'validos' => 1,
                                'errores' => 0,
                                'encoding' => 'cp1252',
                                'errores_detalle' => [],
                            ],
                            'INQUILINO.TXT' => [
                                'registros' => 1,
                                'validos' => 0,
                                'errores' => 1,
                                'encoding' => 'cp1252',
                                'errores_detalle' => [
                                    [
                                        'archivo' => 'INQUILINO.TXT',
                                        'linea' => 1951,
                                        'mensaje' => 'day is out of range for month',
                                        'valor' => 'registro truncado',
                                    ],
                                ],
                            ],
                        ],
                        'advertencias' => [
                            'Se uso liquidaciones/cobol como fallback legacy.',
                        ],
                        'escritura_postgresql' => false,
                    ],
                ],
            ])
            ->get(route('archivo.actualizar-db'))
            ->assertOk()
            ->assertSee('Advertencias')
            ->assertSee('Errores de formato detectados')
            ->assertSee('day is out of range for month')
            ->assertSee('No se realizaron cambios en PostgreSQL.');
    }

    public function test_ejecuta_pipeline_completo_de_migracion(): void
    {
        $this->app->instance(
            ImportadorPythonService::class,
            new class extends ImportadorPythonService
            {
                public array $llamados = [];

                public function validarLoteMigracion(): array
                {
                    $this->llamados[] = 'validar';

                    return $this->respuesta('validado', false);
                }

                public function importarLoteMigracion(): array
                {
                    $this->llamados[] = 'importar';

                    return $this->respuesta('importado', true);
                }

                public function reconciliarLoteMigracion(): array
                {
                    $this->llamados[] = 'reconciliar';

                    return $this->respuesta('reconciliado', false);
                }

                private function respuesta(string $estado, bool $escritura): array
                {
                    return [
                        'exit_code' => 0,
                        'stdout' => '{}',
                        'stderr' => '',
                        'json' => [
                            'estado' => $estado,
                            'importacion_id' => $escritura ? 4 : null,
                            'periodo' => 'Junio/2026',
                            'archivos' => 10,
                            'registros_leidos' => 641667,
                            'registros_validos' => 622313,
                            'registros_interpretados' => 622313,
                            'advertencias' => 1,
                            'errores' => 0,
                            'resumen_archivos' => [
                                'PROPIETAR.TXT' => [
                                    'registros' => 4084,
                                    'validos' => 4084,
                                    'errores' => 0,
                                    'encoding' => 'cp1252',
                                ],
                            ],
                            'advertencias_detalle' => [
                                ['mensaje' => 'Los periodos detectados no coinciden.'],
                            ],
                            'escritura_postgresql' => $escritura,
                        ],
                    ];
                }
            }
        );

        foreach ([
            'archivo.actualizar-db.validar-lote',
            'archivo.actualizar-db.importar-lote',
            'archivo.actualizar-db.reconciliar-lote',
        ] as $route) {
            $this->actingAs($this->usuario())
                ->post(route($route))
                ->assertRedirect(route('archivo.actualizar-db'))
                ->assertSessionHas('resultado_actualizar_db');
        }
    }

    public function test_validacion_contra_fox_desde_pantalla_no_modifica_tablas_heredadas(): void
    {
        $this->crearEsquemaMinimoValidacion();
        DB::table('clientes')->insert(['codigo_cliente' => 1, 'id_prop' => 0, 'id_inq' => 0]);
        DB::table('movimientos_de_cuentas')->insert(['id_mov' => 1]);
        DB::table('liquidaciones_de_clientes')->insert(['numero_de_liquidacion' => 1]);
        DB::table('liquidaciones_de_clientes_items')->insert(['numero_de_item' => 1]);
        DB::table('liquidaciones_impuestos_servicios')->insert(['id_mov_impserv' => 1]);
        DB::table('web_importaciones')->insert([
            'web_id' => 4,
            'web_tipo' => 'kng_gei',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->instance(
            ValidacionKngGeiPostgresqlService::class,
            new class extends ValidacionKngGeiPostgresqlService
            {
                public function __construct() {}

                public function validar(int $importacionId, array $componentes = self::COMPONENTES): array
                {
                    return [
                        'validacion_id' => 1,
                        'importacion_id' => $importacionId,
                        'estado' => 'VALIDACION_PARCIAL',
                        'componentes_solicitados' => $componentes,
                        'componentes' => [
                            'propietarios' => [
                                'registros_fuente' => 1,
                                'coincidencias_exactas' => 1,
                                'coincidencias_con_diferencias' => 0,
                                'no_encontrados' => 0,
                                'ambiguos' => 0,
                                'errores_de_interpretacion' => 0,
                            ],
                        ],
                    ];
                }
            }
        );

        $antes = $this->conteosHeredados();

        $this->actingAs($this->usuario())
            ->post(route('archivo.actualizar-db.simular-persistencia-postgresql'), ['componente' => 'clientes'])
            ->assertRedirect(route('archivo.actualizar-db'))
            ->assertSessionHas('resultado_actualizar_db');

        $this->assertSame($antes, $this->conteosHeredados());
    }

    private function crearEsquemaMinimoValidacion(): void
    {
        foreach ([
            'web_importaciones',
            'clientes',
            'movimientos_de_cuentas',
            'liquidaciones_de_clientes',
            'liquidaciones_de_clientes_items',
            'liquidaciones_impuestos_servicios',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('web_importaciones', function (Blueprint $table) {
            $table->id('web_id');
            $table->string('web_tipo', 60);
            $table->timestamps();
        });
        Schema::create('clientes', function (Blueprint $table) {
            $table->increments('codigo_cliente');
            $table->decimal('id_prop')->default(0);
            $table->decimal('id_inq')->default(0);
        });
        Schema::create('movimientos_de_cuentas', function (Blueprint $table) {
            $table->increments('id_mov');
        });
        Schema::create('liquidaciones_de_clientes', function (Blueprint $table) {
            $table->increments('numero_de_liquidacion');
        });
        Schema::create('liquidaciones_de_clientes_items', function (Blueprint $table) {
            $table->increments('numero_de_item');
        });
        Schema::create('liquidaciones_impuestos_servicios', function (Blueprint $table) {
            $table->increments('id_mov_impserv');
        });
    }

    private function conteosHeredados(): array
    {
        return [
            'clientes' => DB::table('clientes')->count(),
            'movimientos_de_cuentas' => DB::table('movimientos_de_cuentas')->count(),
            'liquidaciones_de_clientes' => DB::table('liquidaciones_de_clientes')->count(),
            'liquidaciones_de_clientes_items' => DB::table('liquidaciones_de_clientes_items')->count(),
            'liquidaciones_impuestos_servicios' => DB::table('liquidaciones_impuestos_servicios')->count(),
        ];
    }

    private function usuario(): Usuario
    {
        $usuario = new Usuario;
        $usuario->forceFill([
            'cod_usuario' => 1,
            'nombre' => 'ADMIN',
            'tipo_de_usuario' => 'Administrador',
        ]);
        $usuario->exists = true;

        return $usuario;
    }
}
