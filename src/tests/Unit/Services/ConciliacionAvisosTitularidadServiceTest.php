<?php

namespace Tests\Unit\Services;

use App\Services\ConciliacionAvisosTitularidadService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ConciliacionAvisosTitularidadServiceTest extends TestCase
{
    private string $conexionAnterior;
    private const CONEXION = 'gei_avisos_test_aislado';

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Se requiere pdo_sqlite para la prueba aislada.');
        }
        $this->conexionAnterior = DB::getDefaultConnection();
        config(['database.connections.'.self::CONEXION=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        DB::purge(self::CONEXION);
        DB::setDefaultConnection(self::CONEXION);
        foreach ([
            'CREATE TABLE clientes (id INTEGER PRIMARY KEY, id_cliente_canonico INTEGER)',
            'CREATE TABLE clientes_conflictos (id INTEGER PRIMARY KEY, sistema_origen TEXT, entidad_origen TEXT, clave_origen TEXT, estado TEXT, cliente_resuelto_id INTEGER)',
            'CREATE TABLE clientes_resoluciones_origen (id_cliente_resolucion_origen INTEGER PRIMARY KEY, sistema_origen TEXT, entidad_origen TEXT, clave_origen TEXT, decision TEXT, cliente_id INTEGER)',
            'CREATE TABLE clientes_cuentas (id INTEGER PRIMARY KEY, cliente_id INTEGER, cuenta TEXT, rol TEXT)',
            'CREATE TABLE clientes_origenes (id INTEGER PRIMARY KEY, cliente_id INTEGER, sistema_origen TEXT, entidad_origen TEXT, clave_origen TEXT)',
            'CREATE TABLE inmuebles (id INTEGER PRIMARY KEY, id_inmueble_canonico INTEGER, clave_migracion TEXT)',
            'CREATE TABLE inmuebles_origenes (id INTEGER PRIMARY KEY, inmueble_id INTEGER, sistema_origen TEXT, entidad_origen TEXT, clave_origen TEXT, cuenta_propietario TEXT, clave_inmueble TEXT, estado_origen TEXT)',
            'CREATE TABLE inmuebles_propietarios (id INTEGER PRIMARY KEY, inmueble_id INTEGER, cliente_id INTEGER, cliente_cuenta_id INTEGER, activo INTEGER, origen TEXT, porcentaje NUMERIC)',
            'CREATE TABLE inmuebles_conflictos (id INTEGER PRIMARY KEY, inmueble_id INTEGER, cuenta_inquilino TEXT, cuenta_propietario TEXT, clave_inmueble TEXT, motivo TEXT, estado TEXT, detalle TEXT, resuelto_at TEXT, updated_at TEXT)',
        ] as $sql) DB::statement($sql);
        DB::table('clientes')->insert(['id'=>2108]);
        DB::table('clientes_conflictos')->insert(['id'=>14,'sistema_origen'=>'COBOL','entidad_origen'=>'PROPIETAR','clave_origen'=>'12020958205','estado'=>'RESUELTO','cliente_resuelto_id'=>2108]);
        DB::table('clientes_cuentas')->insert(['id'=>21089,'cliente_id'=>2108,'cuenta'=>'12020958205','rol'=>'PROPIETARIO']);
        DB::table('clientes_origenes')->insert(['id'=>12,'cliente_id'=>2108,'sistema_origen'=>'COBOL','entidad_origen'=>'PROPIETAR','clave_origen'=>'12020958205']);
        DB::table('inmuebles')->insert(['id'=>7744,'clave_migracion'=>'clave']);
        DB::table('inmuebles_origenes')->insert(['id'=>20,'inmueble_id'=>7744,'sistema_origen'=>'COBOL','entidad_origen'=>'INQUILINO','clave_origen'=>'11032489205','cuenta_propietario'=>'12020958205','clave_inmueble'=>'clave','estado_origen'=>'ACTIVO']);
        DB::table('inmuebles_propietarios')->insert(['id'=>10331,'inmueble_id'=>7744,'cliente_id'=>2108,'cliente_cuenta_id'=>21089,'activo'=>1,'origen'=>'COBOL','porcentaje'=>50]);
        DB::table('inmuebles_conflictos')->insert(['id'=>63,'inmueble_id'=>7744,'cuenta_inquilino'=>'11032489205','cuenta_propietario'=>'12020958205','clave_inmueble'=>'clave','motivo'=>'PROPIETARIO_EN_CONFLICTO','estado'=>'PENDIENTE','detalle'=>'{"direccion_finca":"PADILLA"}']);
    }

    protected function tearDown(): void
    {
        if (isset($this->conexionAnterior)) {
            DB::setDefaultConnection($this->conexionAnterior);
            DB::purge(self::CONEXION);
        }
        parent::tearDown();
    }

    private function servicio(): ConciliacionAvisosTitularidadService
    {
        return new ConciliacionAvisosTitularidadService();
    }

    public function test_simula_y_resuelve_sin_modificar_titularidad_y_es_idempotente(): void
    {
        $antes = (array)DB::table('inmuebles_propietarios')->first();
        $this->assertSame('CONCILIABLE', $this->servicio()->conciliar()[0]['resultado']);
        $this->assertSame('PENDIENTE', DB::table('inmuebles_conflictos')->value('estado'));
        $r = DB::transaction(fn ()=>$this->servicio()->conciliar('12020958205', true, 77));
        $this->assertSame('RESUELTO', $r[0]['resultado']);
        $aviso = DB::table('inmuebles_conflictos')->first();
        $this->assertSame('RESUELTO', $aviso->estado);
        $this->assertNotNull($aviso->resuelto_at);
        $detalle = json_decode($aviso->detalle, true);
        $this->assertSame('PADILLA', $detalle['direccion_finca']);
        $this->assertSame(77, $detalle['conciliacion_titularidad']['usuario_id']);
        $this->assertSame([14], $detalle['conciliacion_titularidad']['conflictos_identidad_ids']);
        $this->assertSame($antes, (array)DB::table('inmuebles_propietarios')->first());
        $this->assertSame([], DB::transaction(fn ()=>$this->servicio()->conciliar(null, true)));
    }

    public function test_identidad_pendiente_o_incompatible_no_se_cierra(): void
    {
        DB::table('clientes_conflictos')->where('id',14)->update(['estado'=>'PENDIENTE']);
        $this->assertSame('IDENTIDAD_PENDIENTE', DB::transaction(fn ()=>$this->servicio()->conciliar(null,true))[0]['resultado']);
        DB::table('clientes_conflictos')->where('id',14)->update(['estado'=>'RESUELTO','cliente_resuelto_id'=>999]);
        $this->assertSame('IDENTIDAD_INCOMPATIBLE', $this->servicio()->conciliar()[0]['resultado']);
        $this->assertSame('PENDIENTE', DB::table('inmuebles_conflictos')->value('estado'));
    }

    public function test_copropiedad_ambigua_se_conserva(): void
    {
        DB::table('clientes_cuentas')->insert(['id'=>999,'cliente_id'=>999,'cuenta'=>'12020958205','rol'=>'PROPIETARIO']);
        $this->assertSame('CUENTA_NO_UNIVOCA', $this->servicio()->conciliar()[0]['resultado']);
    }

    public function test_titularidad_confirmada_se_conserva(): void
    {
        DB::table('inmuebles_propietarios')->where('id',10331)->update(['origen'=>'GEI_CONFIRMADA']);
        $this->assertSame('TITULARIDAD_GEI_CONFIRMADA', DB::transaction(fn ()=>$this->servicio()->conciliar(null,true))[0]['resultado']);
        $this->assertSame('PENDIENTE', DB::table('inmuebles_conflictos')->value('estado'));
    }

    public function test_origen_que_cambio_y_relacion_inactiva_no_se_cierran(): void
    {
        DB::table('inmuebles_origenes')->where('id',20)->update(['cuenta_propietario'=>'12020963401']);
        $this->assertSame('ORIGEN_INMUEBLE_NO_COINCIDE', $this->servicio()->conciliar()[0]['resultado']);
        DB::table('inmuebles_origenes')->where('id',20)->update(['cuenta_propietario'=>'12020958205']);
        DB::table('inmuebles_propietarios')->where('id',10331)->update(['activo'=>0]);
        $this->assertSame('SIN_RELACION_COBOL_ACTIVA_UNIVOCA', $this->servicio()->conciliar()[0]['resultado']);
    }

    public function test_no_toca_otros_motivos_y_respeta_rollback(): void
    {
        $otro = (array)DB::table('inmuebles_conflictos')->first();
        $otro['id']=64; $otro['motivo']='DOMICILIO_CON_VARIOS_INMUEBLES';
        DB::table('inmuebles_conflictos')->insert($otro);
        DB::beginTransaction();
        try {
            $this->assertCount(1, $this->servicio()->conciliar(null,true));
        } finally { DB::rollBack(); }
        $this->assertSame(2, DB::table('inmuebles_conflictos')->where('estado','PENDIENTE')->count());
    }

    private function prepararHistorico(): void
    {
        DB::table('inmuebles_propietarios')->delete();
        DB::table('inmuebles_origenes')->update(['estado_origen'=>'BAJA']);
    }

    public function test_historico_cierra_sin_crear_titularidad(): void
    {
        $this->prepararHistorico();
        $origen = (array) DB::table('inmuebles_origenes')->first();
        $s = $this->servicio()->conciliar()[0];
        $this->assertSame('CONCILIABLE', $s['resultado']);
        $this->assertSame('IDENTIDAD_RESUELTA_ORIGENES_INQUILINOS_DE_BAJA_SIN_TITULARIDAD', $s['regla']);
        $this->assertSame('PENDIENTE', DB::table('inmuebles_conflictos')->value('estado'));
        $this->assertSame('RESUELTO', DB::transaction(fn ()=>$this->servicio()->conciliar(null,true))[0]['resultado']);
        $detalle = json_decode(DB::table('inmuebles_conflictos')->value('detalle'), true);
        $this->assertSame($s['regla'], $detalle['conciliacion_titularidad']['regla']);
        $this->assertSame([20], $detalle['conciliacion_titularidad']['inmueble_origenes_ids']);
        $this->assertSame(0, DB::table('inmuebles_propietarios')->count());
        $this->assertSame($origen, (array) DB::table('inmuebles_origenes')->first());
    }

    public function test_historico_no_cierra_si_hay_otro_origen_activo_o_propietario_distinto(): void
    {
        $this->prepararHistorico();
        $otro = (array) DB::table('inmuebles_origenes')->first();
        $otro['id']=21; $otro['clave_origen']='11032489206'; $otro['estado_origen']='ACTIVO';
        DB::table('inmuebles_origenes')->insert($otro);
        $this->assertSame('SIN_RELACION_COBOL_ACTIVA_UNIVOCA', $this->servicio()->conciliar()[0]['resultado']);
        DB::table('inmuebles_origenes')->where('id',21)->update(['estado_origen'=>'BAJA','cuenta_propietario'=>'12020963401']);
        $this->assertSame('SIN_RELACION_COBOL_ACTIVA_UNIVOCA', $this->servicio()->conciliar()[0]['resultado']);
    }

    public function test_historico_con_inmueble_absorbido_no_se_cierra(): void
    {
        $this->prepararHistorico();
        DB::table('inmuebles')->insert(['id'=>7745,'id_inmueble_canonico'=>7744]);
        $this->assertSame('SIN_RELACION_COBOL_ACTIVA_UNIVOCA', $this->servicio()->conciliar()[0]['resultado']);
    }

    public function test_crear_separado_antiguo_exige_auditoria_coincidente(): void
    {
        $this->prepararHistorico();
        DB::table('clientes_conflictos')->update(['cliente_resuelto_id'=>null]);
        $this->assertSame('IDENTIDAD_INCOMPATIBLE', $this->servicio()->conciliar()[0]['resultado']);
        DB::table('clientes_resoluciones_origen')->insert(['id_cliente_resolucion_origen'=>7,
            'sistema_origen'=>'COBOL','entidad_origen'=>'PROPIETAR','clave_origen'=>'12020958205',
            'decision'=>'CREAR_SEPARADO','cliente_id'=>999]);
        $this->assertSame('IDENTIDAD_INCOMPATIBLE', $this->servicio()->conciliar()[0]['resultado']);
        DB::table('clientes_resoluciones_origen')->update(['cliente_id'=>2108]);
        $r = $this->servicio()->conciliar()[0];
        $this->assertSame('CONCILIABLE', $r['resultado']);
        $this->assertSame(7, $r['resolucion_origen_id']);
        $this->assertNull(DB::table('clientes_conflictos')->value('cliente_resuelto_id'));
    }

    public function test_confirmacion_exige_transaccion(): void
    {
        $this->expectException(DomainException::class);
        $this->servicio()->conciliar(null,true);
    }
}
