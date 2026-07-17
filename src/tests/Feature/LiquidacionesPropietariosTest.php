<?php

namespace Tests\Feature;

use App\Mail\LiquidacionPropietarioMail;
use App\Models\Cliente;
use App\Models\LiquidacionCliente;
use App\Models\Usuario;
use App\Services\LiquidacionPdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Mail\Mailables\Attachment;
use Tests\CreatesClienteSchema;
use Tests\TestCase;

class LiquidacionesPropietariosTest extends TestCase
{
    use CreatesClienteSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClienteSchema();
        Storage::fake('liquidaciones');
    }

    public function test_construye_nombre_y_ruta_del_pdf(): void
    {
        $liquidacion = $this->crearLiquidacion([
            'punto_venta' => 0,
            'numero' => 25193,
            'fecha' => '2026-06-19',
        ]);

        $service = app(LiquidacionPdfService::class);

        $this->assertSame('l0000-00025193.pdf', $service->nombreArchivo($liquidacion));
        $this->assertSame('2026/06/l0000-00025193.pdf', $service->rutaRelativa($liquidacion));
    }

    public function test_muestra_liquidaciones_en_pantalla_de_cliente_con_filtros(): void
    {
        [$cliente] = $this->crearClienteConLiquidacion();
        $this->crearLiquidacion([
            'numero' => 30000,
            'fecha' => '2025-05-10',
            'nro_cuenta' => 12020999999,
            'periodo' => 'Mayo/2025',
        ]);
        Storage::disk('liquidaciones')->put(
            '2026/06/l0000-00025193.pdf',
            '%PDF-1.4 contenido'
        );

        $this->actingAs($this->usuario())
            ->get(route('clientes.show', [
                'cliente' => $cliente,
                'actividad' => 'todos',
                'liquidacion_anio' => '2026',
                'liquidacion_mes' => '6',
                'liquidacion_periodo' => 'Junio',
            ]))
            ->assertOk()
            ->assertSee('Liquidaciones')
            ->assertSee('Junio/2026')
            ->assertSee('0-25193')
            ->assertSee('Disponible')
            ->assertDontSee('Mayo/2025');
    }

    public function test_visualiza_pdf_existente(): void
    {
        [$cliente, $liquidacion] = $this->crearClienteConLiquidacion();
        Storage::disk('liquidaciones')->put(
            '2026/06/l0000-00025193.pdf',
            '%PDF-1.4 contenido'
        );

        $this->actingAs($this->usuario())
            ->get(route('clientes.liquidaciones.ver', [$cliente, $liquidacion]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="l0000-00025193.pdf"');
    }

    public function test_visualiza_pdf_existente_en_carpeta_historica_compacta(): void
    {
        [$cliente, $liquidacion] = $this->crearClienteConLiquidacion();
        Storage::disk('liquidaciones')->put(
            '202606/l0000-00025193.pdf',
            '%PDF-1.4 contenido'
        );

        $this->actingAs($this->usuario())
            ->get(route('clientes.liquidaciones.ver', [$cliente, $liquidacion]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_inexistente_responde_404_controlado(): void
    {
        [$cliente, $liquidacion] = $this->crearClienteConLiquidacion();

        $this->actingAs($this->usuario())
            ->get(route('clientes.liquidaciones.ver', [$cliente, $liquidacion]))
            ->assertNotFound();
    }

    public function test_impide_consultar_liquidacion_de_otro_cliente(): void
    {
        [, $liquidacion] = $this->crearClienteConLiquidacion();
        $otroCliente = $this->crearClientePropietario(2, 12020000002);
        Storage::disk('liquidaciones')->put(
            '2026/06/l0000-00025193.pdf',
            '%PDF-1.4 contenido'
        );

        $this->actingAs($this->usuario())
            ->get(route('clientes.liquidaciones.ver', [$otroCliente, $liquidacion]))
            ->assertNotFound();
    }

    public function test_envia_correo_con_pdf_adjunto_y_registra_envio(): void
    {
        Mail::fake();
        [$cliente, $liquidacion] = $this->crearClienteConLiquidacion();
        Storage::disk('liquidaciones')->put(
            '2026/06/l0000-00025193.pdf',
            '%PDF-1.4 contenido'
        );

        $this->actingAs($this->usuario())
            ->post(route('clientes.liquidaciones.enviar', [$cliente, $liquidacion]), [
                'destinatario' => 'propietario@example.com',
            ])
            ->assertRedirect();

        Mail::assertSent(
            LiquidacionPropietarioMail::class,
            function (LiquidacionPropietarioMail $mail): bool {
                $mail->assertHasAttachment(
                    Attachment::fromStorageDisk(
                        'liquidaciones',
                        '2026/06/l0000-00025193.pdf'
                    )
                        ->as('l0000-00025193.pdf')
                        ->withMime('application/pdf')
                );

                return true;
            }
        );

        $this->assertDatabaseHas('web_envios_liquidaciones', [
            'web_codigo_cliente' => $cliente->codigo_cliente,
            'web_numero_de_liquidacion' => $liquidacion->numero_de_liquidacion,
            'web_destinatario' => 'propietario@example.com',
            'web_estado' => 'enviado',
            'web_ruta_relativa_pdf' => '2026/06/l0000-00025193.pdf',
        ]);
    }

    public function test_valida_destinatario_invalido(): void
    {
        [$cliente, $liquidacion] = $this->crearClienteConLiquidacion();

        $this->actingAs($this->usuario())
            ->post(route('clientes.liquidaciones.enviar', [$cliente, $liquidacion]), [
                'destinatario' => 'correo-invalido',
            ])
            ->assertSessionHasErrors('destinatario');
    }

    public function test_registra_error_si_falta_pdf_al_enviar(): void
    {
        Mail::fake();
        [$cliente, $liquidacion] = $this->crearClienteConLiquidacion();

        $this->actingAs($this->usuario())
            ->post(route('clientes.liquidaciones.enviar', [$cliente, $liquidacion]), [
                'destinatario' => 'propietario@example.com',
            ])
            ->assertSessionHasErrors('liquidacion');

        Mail::assertNothingSent();

        $this->assertDatabaseHas('web_envios_liquidaciones', [
            'web_codigo_cliente' => $cliente->codigo_cliente,
            'web_numero_de_liquidacion' => $liquidacion->numero_de_liquidacion,
            'web_estado' => 'error',
            'web_mensaje_error' => 'PDF no encontrado',
            'web_ruta_relativa_pdf' => '2026/06/l0000-00025193.pdf',
        ]);
    }

    private function crearClienteConLiquidacion(): array
    {
        $cliente = $this->crearClientePropietario(1, 12020999999);
        $liquidacion = $this->crearLiquidacion([
            'nro_cuenta' => 12020999999,
        ]);

        return [$cliente, $liquidacion];
    }

    private function crearClientePropietario(
        int $codigoCliente,
        int $idProp
    ): Cliente {
        DB::table('clientes')->insert([
            'codigo_cliente' => $codigoCliente,
            'personeria' => 'Física',
            'apellidos' => 'Propietario',
            'nombres' => "Cliente {$codigoCliente}",
            'doctipo' => 'DNI',
            'docnro' => (string) (30000000 + $codigoCliente),
            'cuit' => '',
            'domicilio' => '',
            'provincia' => '',
            'departamento' => '',
            'localidad' => '',
            'cp' => '',
            'caractel' => '',
            'telefonos' => '',
            'celular' => '',
            'fax' => '',
            'email' => 'propietario@example.com',
            'nacionalidad' => '',
            'condicion_iva' => '',
            'id_prop' => $idProp,
            'id_inq' => 0,
            'profesion' => '',
            'lugar_de_trabajo' => '',
            'razon_social' => '',
            'saldo_inicial_inquilino' => 0,
            'web_validada' => false,
            'web_operativo' => true,
        ]);

        return Cliente::query()->findOrFail($codigoCliente);
    }

    private function crearLiquidacion(array $datos = []): LiquidacionCliente
    {
        $id = DB::table('liquidaciones_de_clientes')->insertGetId(array_merge([
            'punto_venta' => 0,
            'numero' => 25193,
            'fecha' => '2026-06-19',
            'codigo_cliente' => 0,
            'nro_cuenta' => 12020999999,
            'periodo' => 'Junio/2026',
            'nombre' => 'Propietario',
            'razon_social' => 'Propietario',
            'fecha_desde' => '2026-06-01',
            'fecha_hasta' => '2026-06-30',
            'numero_de_comprobante' => 363150,
            'total_liquidado' => 1500,
        ], $datos), 'numero_de_liquidacion');

        return LiquidacionCliente::query()->findOrFail($id);
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
