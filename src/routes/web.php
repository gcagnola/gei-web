<?php

use App\Http\Controllers\ActualizarDbController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RecuperarClaveController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClienteLiquidacionController;
use App\Http\Controllers\ImportacionArchivosController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'mostrar'])
        ->name('login');

    Route::post('/login', [LoginController::class, 'ingresar'])
        ->name('login.ingresar');

    Route::get(
        '/clave-olvidada',
        [RecuperarClaveController::class, 'mostrarSolicitud']
    )->name('password.request');

    Route::post(
        '/clave-olvidada',
        [RecuperarClaveController::class, 'enviarEnlace']
    )->name('password.email');

    Route::get(
        '/restablecer-clave/{token}',
        [RecuperarClaveController::class, 'mostrarRestablecimiento']
    )->name('password.reset');

    Route::post(
        '/restablecer-clave',
        [RecuperarClaveController::class, 'restablecer']
    )->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::view('/', 'inicio')->name('inicio');

    $modulo = static function (string $titulo, string $seccion) {
        return static fn () => view('modulo-en-construccion', [
            'titulo' => $titulo,
            'seccion' => $seccion,
        ]);
    };

    Route::get(
        '/archivo/clientes/localidades',
        [ClienteController::class, 'localidades']
    )->name('clientes.localidades');

    Route::get(
        '/archivo/clientes/pendientes-validacion.csv',
        [ClienteController::class, 'exportarPendientesValidacion']
    )->name('clientes.validacion-pendientes.csv');

    Route::get(
        '/archivo/clientes/{cliente}/liquidaciones/{liquidacion}/ver',
        [ClienteLiquidacionController::class, 'ver']
    )->name('clientes.liquidaciones.ver');

    Route::get(
        '/archivo/clientes/{cliente}/liquidaciones/{liquidacion}/descargar',
        [ClienteLiquidacionController::class, 'descargar']
    )->name('clientes.liquidaciones.descargar');

    Route::post(
        '/archivo/clientes/{cliente}/liquidaciones/{liquidacion}/enviar',
        [ClienteLiquidacionController::class, 'enviar']
    )->name('clientes.liquidaciones.enviar');

    Route::get('/archivo/importar', [ImportacionArchivosController::class, 'index'])
        ->name('archivo.importar');

    Route::post('/archivo/importar', [ImportacionArchivosController::class, 'store'])
        ->name('archivo.importar.store');

    Route::get('/archivo/actualizar-db', [ActualizarDbController::class, 'index'])
        ->name('archivo.actualizar-db');

    Route::post('/archivo/actualizar-db/validar-cobol', [ActualizarDbController::class, 'validarCobol'])
        ->name('archivo.actualizar-db.validar-cobol');

    Route::post('/archivo/actualizar-db/comparar-cobol', [ActualizarDbController::class, 'compararCobol'])
        ->name('archivo.actualizar-db.comparar-cobol');

    Route::post('/archivo/actualizar-db/validar-lote', [ActualizarDbController::class, 'validarLoteMigracion'])
        ->name('archivo.actualizar-db.validar-lote');

    Route::post('/archivo/actualizar-db/importar-lote', [ActualizarDbController::class, 'importarLoteMigracion'])
        ->name('archivo.actualizar-db.importar-lote');

    Route::post('/archivo/actualizar-db/reconciliar-lote', [ActualizarDbController::class, 'reconciliarLoteMigracion'])
        ->name('archivo.actualizar-db.reconciliar-lote');

    Route::post('/archivo/actualizar-db/simular-persistencia-postgresql', [ActualizarDbController::class, 'simularPersistenciaPostgresql'])
        ->name('archivo.actualizar-db.simular-persistencia-postgresql');

    Route::get('/archivo/clientes/cuenta-corriente', $modulo('Cuenta Corriente de Clientes', 'Archivo / Clientes'))
        ->name('clientes.cuenta-corriente');

    Route::resource('/archivo/clientes', ClienteController::class)
        ->parameters(['clientes' => 'cliente'])
        ->only(['index', 'show', 'create', 'store', 'edit', 'update']);
    Route::get('/archivo/inmuebles', $modulo('Inmuebles', 'Archivo'))
        ->name('inmuebles.index');
    Route::get('/archivo/conceptos', $modulo('Conceptos', 'Archivo'))
        ->name('conceptos.index');
    Route::get('/archivo/proveedores', $modulo('Proveedores', 'Archivo'))
        ->name('proveedores.index');
    Route::get('/archivo/contratos', $modulo('Contratos', 'Archivo'))
        ->name('contratos.index');

    Route::get('/propietarios/liquidaciones', $modulo('Administrador de Liquidaciones', 'Propietarios'))
        ->name('propietarios.liquidaciones.index');
    Route::get('/propietarios/liquidaciones/generar', $modulo('Generar Liquidación de Propietarios', 'Propietarios'))
        ->name('propietarios.liquidaciones.generar');
    Route::get('/propietarios/saldos', $modulo('Consulta de saldos de Propietarios', 'Propietarios'))
        ->name('propietarios.saldos');

    Route::get('/inquilinos/liquidaciones', $modulo('Administrador de Liquidaciones', 'Inquilinos'))
        ->name('inquilinos.liquidaciones.index');
    Route::get('/inquilinos/liquidaciones/generar', $modulo('Generar Liquidación de Inquilinos', 'Inquilinos'))
        ->name('inquilinos.liquidaciones.generar');
    Route::get('/inquilinos/saldos', $modulo('Consulta de saldos de Inquilinos', 'Inquilinos'))
        ->name('inquilinos.saldos');

    Route::get('/compras/facturas-proveedores', $modulo('Facturas de Proveedores', 'Compras'))
        ->name('compras.facturas.index');
    Route::get('/compras/cuenta-corriente', $modulo('Cuenta Corriente', 'Compras'))
        ->name('compras.cuenta-corriente');

    Route::get('/contabilidad/plan-de-cuentas', $modulo('Plan de Cuentas', 'Contabilidad'))
        ->name('contabilidad.plan-cuentas.index');
    Route::get('/contabilidad/caja-diaria', $modulo('Caja Diaria', 'Contabilidad'))
        ->name('contabilidad.caja-diaria');
    Route::get('/contabilidad/libro-iva-ventas', $modulo('Libro de IVA Ventas', 'Contabilidad'))
        ->name('contabilidad.iva-ventas');

    Route::get('/opciones/usuarios', $modulo('Usuarios', 'Opciones'))
        ->name('usuarios.index');
    Route::get('/opciones/seteos', $modulo('Seteos', 'Opciones'))
        ->name('seteos.index');

    Route::post('/logout', [LoginController::class, 'salir'])
        ->name('logout');
});
