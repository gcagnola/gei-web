<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\CreatesClienteSchema;
use Tests\TestCase;

class ClientesTest extends TestCase
{
    use CreatesClienteSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClienteSchema();

        DB::table('provincias')->insert([
            'nombre' => 'Santa Fe',
            'pais' => 'Argentina',
            'codprov' => 12,
        ]);
        DB::table('localidades')->insert([
            'provincia' => 'Santa Fe',
            'nombre' => 'Santa Fe',
            'caractel' => '342',
            'cp' => '3000',
        ]);
    }

    public function test_el_listado_requiere_autenticacion(): void
    {
        $this->get(route('clientes.index'))
            ->assertRedirect(route('login'));
    }

    public function test_un_usuario_autenticado_puede_ver_el_listado(): void
    {
        $cliente = $this->crearCliente([
            'apellidos' => 'Pérez',
            'web_operativo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Clientes')
            ->assertSee('Pérez')
            ->assertSee((string) $cliente->codigo_cliente)
            ->assertSee('Mostrar auditoría')
            ->assertDontSee('Pendientes CSV')
            ->assertDontSee('Revisado')
            ->assertDontSee('A revisar');
    }

    public function test_busca_por_apellido_razon_social_y_documento(): void
    {
        $this->crearCliente([
            'apellidos' => 'Guastavino',
            'docnro' => '12345678',
            'web_operativo' => true,
        ]);
        $this->crearCliente([
            'personeria' => 'Jurídica',
            'apellidos' => '',
            'nombres' => '',
            'razon_social' => 'Inmobiliaria Central',
            'doctipo' => 'CUIT',
            'docnro' => '30718706307',
            'cuit' => '30-71870630-7',
            'web_operativo' => true,
        ]);

        $usuario = $this->usuario();

        $this->actingAs($usuario)->get(route('clientes.index', ['buscar' => 'Guastavino']))
            ->assertOk()->assertSee('Guastavino');
        $this->actingAs($usuario)->get(route('clientes.index', ['buscar' => 'Inmobiliaria Central']))
            ->assertOk()->assertSee('Inmobiliaria Central');
        $this->actingAs($usuario)->get(route('clientes.index', ['buscar' => '12345678']))
            ->assertOk()->assertSee('Guastavino');
    }

    public function test_filtra_propietarios_e_inquilinos_por_relaciones(): void
    {
        $propietario = $this->crearCliente(['apellidos' => 'Propietario']);
        $inquilino = $this->crearCliente(['apellidos' => 'Inquilino']);
        $otro = $this->crearCliente(['apellidos' => 'Sin relación']);

        DB::table('inmuebles_propietarios')->insert([
            'codigo_inmueble' => 10,
            'codigo_cliente' => $propietario->codigo_cliente,
        ]);
        DB::table('contratos_inquilinos')->insert([
            'codigo_contrato' => 20,
            'codigo_cliente' => $inquilino->codigo_cliente,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', [
                'filtro' => 'propietarios',
                'actividad' => 'todos',
            ]))
            ->assertSee('Propietario')
            ->assertDontSee('Sin relación');

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', [
                'filtro' => 'inquilinos',
                'actividad' => 'todos',
            ]))
            ->assertSee('Inquilino')
            ->assertDontSee('Sin relación');
    }

    public function test_inquilinos_activos_requiere_contrato_vigente(): void
    {
        $vigente = $this->crearCliente(['apellidos' => 'Inquilino Vigente']);
        $vencido = $this->crearCliente(['apellidos' => 'Inquilino Vencido']);

        $contratoVigente = DB::table('contratos')->insertGetId([
            'fecha_inicio' => now()->subMonth()->toDateString(),
            'fecha_fin' => now()->addMonth()->toDateString(),
            'importe_inicial' => 0,
        ]);
        $contratoVencido = DB::table('contratos')->insertGetId([
            'fecha_inicio' => now()->subMonths(3)->toDateString(),
            'fecha_fin' => now()->subMonth()->toDateString(),
            'importe_inicial' => 0,
        ]);

        DB::table('contratos_inquilinos')->insert([
            [
                'codigo_contrato' => $contratoVigente,
                'codigo_cliente' => $vigente->codigo_cliente,
            ],
            [
                'codigo_contrato' => $contratoVencido,
                'codigo_cliente' => $vencido->codigo_cliente,
            ],
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', ['filtro' => 'inquilinos']))
            ->assertOk()
            ->assertSee('Inquilino Vigente')
            ->assertDontSee('Inquilino Vencido');

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', [
                'filtro' => 'inquilinos',
                'actividad' => 'inactivos',
            ]))
            ->assertOk()
            ->assertSee('Inquilino Vencido')
            ->assertDontSee('Inquilino Vigente');
    }

    public function test_por_defecto_muestra_clientes_operativos(): void
    {
        $operativo = $this->crearCliente([
            'apellidos' => 'Cliente Operativo',
            'web_operativo' => true,
        ]);
        $inactivo = $this->crearCliente([
            'apellidos' => 'Cliente Inactivo',
            'web_operativo' => false,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Cliente Operativo')
            ->assertDontSee('Cliente Inactivo');

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', ['actividad' => 'inactivos']))
            ->assertOk()
            ->assertSee('Cliente Inactivo')
            ->assertDontSee('Cliente Operativo');

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', ['actividad' => 'todos']))
            ->assertOk()
            ->assertSee('Cliente Operativo')
            ->assertSee('Cliente Inactivo');
    }

    public function test_filtra_clientes_por_validacion_web(): void
    {
        $validado = $this->crearCliente([
            'apellidos' => 'ClienteValidadoUnico',
            'web_validada' => true,
        ]);
        $pendiente = $this->crearCliente([
            'apellidos' => 'ClientePendienteUnico',
            'web_validada' => false,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', [
                'mostrar_validacion' => 1,
                'validacion' => 'validados',
                'actividad' => 'todos',
            ]))
            ->assertOk()
            ->assertSee('Pendientes CSV')
            ->assertSee('Auditoría: todos')
            ->assertSee('ClienteValidadoUnico')
            ->assertDontSee('ClientePendienteUnico');

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', [
                'mostrar_validacion' => 1,
                'validacion' => 'pendientes',
                'actividad' => 'todos',
            ]))
            ->assertOk()
            ->assertSee('ClientePendienteUnico')
            ->assertDontSee('ClienteValidadoUnico');
    }

    public function test_ignora_filtro_de_validacion_si_la_auditoria_no_esta_visible(): void
    {
        $this->crearCliente([
            'apellidos' => 'ClienteValidadoOculto',
            'web_validada' => true,
            'web_operativo' => true,
        ]);
        $this->crearCliente([
            'apellidos' => 'ClientePendienteOculto',
            'web_validada' => false,
            'web_operativo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.index', ['validacion' => 'validados']))
            ->assertOk()
            ->assertSee('ClienteValidadoOculto')
            ->assertSee('ClientePendienteOculto')
            ->assertDontSee('Pendientes CSV')
            ->assertDontSee('Auditoría: todos');
    }

    public function test_exporta_clientes_pendientes_de_validacion(): void
    {
        $this->crearCliente([
            'apellidos' => 'Validado',
            'web_validada' => true,
        ]);
        $this->crearCliente([
            'apellidos' => 'Pendiente',
            'docnro' => '87654321',
            'web_validada' => false,
        ]);

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('clientes.validacion-pendientes.csv'));

        $respuesta
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $contenido = $respuesta->streamedContent();
        $this->assertStringContainsString('Pendiente', $contenido);
        $this->assertStringContainsString('87654321', $contenido);
        $this->assertStringNotContainsString('Validado', $contenido);
    }

    public function test_crea_persona_fisica_valida(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('clientes.store'), $this->datosValidos())
            ->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'apellidos' => 'Pérez',
            'nombres' => 'Ana',
            'doctipo' => 'DNI',
            'docnro' => '12345678',
            'razon_social' => '',
        ]);
    }

    public function test_crea_persona_juridica_valida_y_normaliza_cuit(): void
    {
        $datos = $this->datosValidos([
            'personeria' => 'Jurídica',
            'apellidos' => '',
            'nombres' => '',
            'razon_social' => 'Empresa SA',
            'doctipo' => 'DNI',
            'docnro' => '',
            'cuit' => '30-71870630-7',
        ]);

        $this->actingAs($this->usuario())
            ->post(route('clientes.store'), $datos)
            ->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'razon_social' => 'Empresa SA',
            'doctipo' => 'CUIT',
            'docnro' => '30718706307',
            'cuit' => '30-71870630-7',
        ]);
    }

    public function test_rechaza_campos_obligatorios_segun_personeria(): void
    {
        $this->actingAs($this->usuario())
            ->from(route('clientes.create'))
            ->post(route('clientes.store'), $this->datosValidos(['apellidos' => '']))
            ->assertSessionHasErrors('apellidos');

        $this->actingAs($this->usuario())
            ->from(route('clientes.create'))
            ->post(route('clientes.store'), $this->datosValidos([
                'personeria' => 'Jurídica',
                'razon_social' => '',
                'cuit' => '30-71870630-7',
            ]))
            ->assertSessionHasErrors('razon_social');
    }

    public function test_permite_mismo_numero_con_distinto_tipo(): void
    {
        $this->crearCliente(['doctipo' => 'DNI', 'docnro' => '12345678']);

        $this->actingAs($this->usuario())
            ->post(route('clientes.store'), $this->datosValidos([
                'doctipo' => 'LC',
                'docnro' => '12345678',
            ]))
            ->assertSessionDoesntHaveErrors('docnro');

        $this->assertDatabaseHas('clientes', [
            'doctipo' => 'LC',
            'docnro' => '12345678',
        ]);
    }

    public function test_rechaza_misma_combinacion_documental(): void
    {
        $this->crearCliente(['doctipo' => 'DNI', 'docnro' => '12345678']);

        $this->actingAs($this->usuario())
            ->post(route('clientes.store'), $this->datosValidos())
            ->assertSessionHasErrors('docnro');
    }

    public function test_modificacion_excluye_al_propio_cliente_y_preserva_columnas_ajenas(): void
    {
        $cliente = $this->crearCliente();
        DB::table('clientes')
            ->where('codigo_cliente', $cliente->codigo_cliente)
            ->update(['saldo_inicial_inquilino' => 987.65]);

        $this->actingAs($this->usuario())
            ->put(
                route('clientes.update', $cliente),
                $this->datosValidos(['nombres' => 'Andrea'])
            )
            ->assertRedirect(route('clientes.show', $cliente));

        $this->assertDatabaseHas('clientes', [
            'codigo_cliente' => $cliente->codigo_cliente,
            'nombres' => 'Andrea',
            'saldo_inicial_inquilino' => 987.65,
        ]);
    }

    public function test_muestra_contratos_inmuebles_y_fallback_de_numero(): void
    {
        $cliente = $this->crearCliente();
        $propietario = $this->crearCliente([
            'apellidos' => 'Dueño',
            'nombres' => 'Carlos',
            'docnro' => '33444555',
        ]);
        $tipo = DB::table('tipos_de_inmuebles')->insertGetId([
            'tipo_inmueble' => 'Departamento',
        ]);
        $inmueble = DB::table('inmuebles')->insertGetId([
            'domicilio_calle' => 'San Martín',
            'domicilio_nro' => '1234',
            'localidad' => 'Santa Fe',
            'cod_tipo_inmueble' => $tipo,
        ]);
        $contrato = DB::table('contratos')->insertGetId([
            'numero_de_contrato' => '0',
            'fecha_inicio' => now()->subMonth()->toDateString(),
            'fecha_fin' => now()->addMonth()->toDateString(),
            'importe_inicial' => 0,
        ]);
        DB::table('contratos_inquilinos')->insert([
            'codigo_contrato' => $contrato,
            'codigo_cliente' => $cliente->codigo_cliente,
            'porcentaje_participacion' => 100,
        ]);
        DB::table('contratos_inmuebles')->insert([
            'codigo_contrato' => $contrato,
            'codigo_inmueble' => $inmueble,
        ]);
        DB::table('inmuebles_propietarios')->insert([
            'codigo_inmueble' => $inmueble,
            'codigo_cliente' => $propietario->codigo_cliente,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertSee("Contrato interno {$contrato}")
            ->assertSee('Departamento')
            ->assertSee('San Martín')
            ->assertSee('Propietario')
            ->assertSee('Dueño, Carlos')
            ->assertSee('0,00');
    }

    public function test_endpoint_de_localidades_devuelve_cp_y_caracteristica(): void
    {
        $this->actingAs($this->usuario())
            ->getJson(route('clientes.localidades', ['provincia' => 'Santa Fe']))
            ->assertOk()
            ->assertJsonFragment([
                'nombre' => 'Santa Fe',
                'caractel' => '342',
                'cp' => '3000',
            ]);
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

    private function crearCliente(array $datos = []): Cliente
    {
        return Cliente::query()->create(array_merge(
            $this->datosValidos(),
            $datos
        ));
    }

    private function datosValidos(array $datos = []): array
    {
        return array_merge([
            'personeria' => 'Física',
            'apellidos' => 'Pérez',
            'nombres' => 'Ana',
            'razon_social' => '',
            'doctipo' => 'DNI',
            'docnro' => '12345678',
            'cuit' => '',
            'domicilio' => 'San Jerónimo 1000',
            'provincia' => 'Santa Fe',
            'departamento' => '',
            'localidad' => 'Santa Fe',
            'cp' => '3000',
            'caractel' => '342',
            'telefonos' => '',
            'celular' => '3425000000',
            'fax' => '',
            'email' => 'ana@example.com',
            'nacionalidad' => 'Argentina',
            'condicion_iva' => 'Consumidor Final',
            'profesion' => '',
            'lugar_de_trabajo' => '',
            'web_operativo' => true,
        ], $datos);
    }
}
