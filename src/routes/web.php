<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RecuperarClaveController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClienteComprobanteArcaController;
use App\Http\Controllers\ComprobanteArcaController;
use App\Http\Controllers\ImportacionArchivosController;
use App\Http\Controllers\MigracionGeiWebController;
use App\Http\Controllers\LiquidacionPropietarioController;
use App\Http\Controllers\UnificacionInmuebleController;
use App\Http\Controllers\UnificacionClienteController;
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

    Route::get('/archivo/importar', [ImportacionArchivosController::class, 'index'])
        ->name('archivo.importar');

    Route::post('/archivo/importar', [ImportacionArchivosController::class, 'store'])
        ->name('archivo.importar.store');

    Route::post(
        '/archivo/importar/{periodo}/migrar',
        [ImportacionArchivosController::class, 'migrar']
    )
        ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
        ->name('archivo.importar.migrar');

    Route::get(
        '/archivo/importar/{periodo}/actualizar-gei',
        [MigracionGeiWebController::class, 'show']
    )
        ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
        ->name('archivo.importar.actualizar-gei');

    Route::post(
        '/archivo/importar/{periodo}/actualizar-gei/analizar',
        [MigracionGeiWebController::class, 'analizar']
    )
        ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
        ->name('archivo.importar.actualizar-gei.analizar');

    Route::post(
        '/archivo/importar/{periodo}/actualizar-gei/aplicar',
        [MigracionGeiWebController::class, 'aplicar']
    )
        ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
        ->name('archivo.importar.actualizar-gei.aplicar');


    Route::post(
        '/archivo/importar/{periodo}/actualizar-gei/liquidaciones-propietarios/analizar',
        [MigracionGeiWebController::class, 'analizarLiquidaciones']
    )
        ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
        ->name('archivo.importar.actualizar-gei.liquidaciones.analizar');

    Route::post(
        '/archivo/importar/{periodo}/actualizar-gei/liquidaciones-propietarios/aplicar',
        [MigracionGeiWebController::class, 'aplicarLiquidaciones']
    )
        ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
        ->name('archivo.importar.actualizar-gei.liquidaciones.aplicar');




    Route::get('/archivo/clientes/comprobantes-arca', [ClienteComprobanteArcaController::class, 'index'])
        ->name('clientes.comprobantes-arca.index');
    Route::post('/archivo/clientes/comprobantes-arca/enviar-emails', [ClienteComprobanteArcaController::class, 'enviarEmails'])
        ->name('clientes.comprobantes-arca.enviar-emails');
    Route::post('/archivo/clientes/{cliente}/comprobantes-arca/enviar-email', [ClienteComprobanteArcaController::class, 'enviarEmail'])
        ->whereNumber('cliente')
        ->name('clientes.comprobantes-arca.enviar-email');

    Route::resource('/archivo/clientes', ClienteController::class)
        ->parameters(['clientes' => 'cliente'])
        ->only(['index', 'show', 'create', 'store', 'edit', 'update']);
    Route::get('/archivo/inmuebles', $modulo('Inmuebles', 'Archivo'))
        ->name('inmuebles.index');

    Route::middleware('can:administrar-unificaciones')->group(function (): void {
        Route::get('/archivo/unificacion', [UnificacionInmuebleController::class, 'index'])
            ->name('archivo.unificacion.index');
        Route::get('/archivo/unificacion/inmuebles/comparar', [UnificacionInmuebleController::class, 'comparar'])
            ->name('archivo.unificacion.inmuebles.comparar');
        Route::post('/archivo/unificacion/inmuebles', [UnificacionInmuebleController::class, 'unificar'])
            ->name('archivo.unificacion.inmuebles.unificar');
        Route::post('/archivo/unificacion/inmuebles/candidato', [UnificacionInmuebleController::class, 'resolverCandidato'])
            ->name('archivo.unificacion.inmuebles.candidato');
        Route::post('/archivo/unificacion/inmuebles/conflictos/{conflicto}/resolver', [UnificacionInmuebleController::class, 'resolverConflicto'])
            ->whereNumber('conflicto')
            ->name('archivo.unificacion.inmuebles.conflicto.resolver');

        Route::get('/archivo/unificacion/clientes', [UnificacionClienteController::class, 'index'])
            ->name('archivo.unificacion.clientes.index');
        Route::get('/archivo/unificacion/clientes/comparar', [UnificacionClienteController::class, 'comparar'])
            ->name('archivo.unificacion.clientes.comparar');
        Route::post('/archivo/unificacion/clientes', [UnificacionClienteController::class, 'unificar'])
            ->name('archivo.unificacion.clientes.unificar');
        Route::post('/archivo/unificacion/clientes/candidato', [UnificacionClienteController::class, 'resolverCandidato'])
            ->name('archivo.unificacion.clientes.candidato');
        Route::get('/archivo/unificacion/clientes/conflictos/{conflicto}', [UnificacionClienteController::class, 'revisarConflicto'])
            ->whereNumber('conflicto')
            ->name('archivo.unificacion.clientes.conflicto.revisar');
        Route::post('/archivo/unificacion/clientes/conflictos/{conflicto}/resolver', [UnificacionClienteController::class, 'resolverConflicto'])
            ->whereNumber('conflicto')
            ->name('archivo.unificacion.clientes.conflicto.resolver');
    });
    Route::get('/archivo/conceptos', $modulo('Conceptos', 'Archivo'))
        ->name('conceptos.index');
    Route::get('/archivo/proveedores', $modulo('Proveedores', 'Archivo'))
        ->name('proveedores.index');
    Route::get('/archivo/contratos', $modulo('Contratos', 'Archivo'))
        ->name('contratos.index');

    Route::get('/propietarios/liquidaciones', [LiquidacionPropietarioController::class, 'index'])
        ->name('propietarios.liquidaciones.index');
    Route::get('/comprobantes-arca/{periodo}/{archivo}/ver', [ComprobanteArcaController::class, 'ver'])
        ->where('periodo', '(19|20)\d{2}(0[1-9]|1[0-2])')
        ->where('archivo', '[A-Za-z0-9._-]+')
        ->name('comprobantes-arca.ver');
    Route::get('/propietarios/liquidaciones/generar', [LiquidacionPropietarioController::class, 'index'])
        ->name('propietarios.liquidaciones.generar');
    Route::post('/propietarios/liquidaciones/generar', [LiquidacionPropietarioController::class, 'procesar'])
        ->name('propietarios.liquidaciones.procesar');
    Route::post('/propietarios/liquidaciones/enviar-emails', [LiquidacionPropietarioController::class, 'enviarEmails'])
        ->name('propietarios.liquidaciones.enviar-emails');
    Route::post('/propietarios/liquidaciones/{liquidacion}/enviar-email', [LiquidacionPropietarioController::class, 'enviarEmail'])
        ->whereNumber('liquidacion')
        ->name('propietarios.liquidaciones.enviar-email');
    Route::get('/propietarios/liquidaciones/{liquidacion}/ver', [LiquidacionPropietarioController::class, 'ver'])
        ->whereNumber('liquidacion')
        ->name('propietarios.liquidaciones.ver');
    Route::get('/propietarios/liquidaciones/{liquidacion}/descargar', [LiquidacionPropietarioController::class, 'descargar'])
        ->whereNumber('liquidacion')
        ->name('propietarios.liquidaciones.descargar');
    Route::get('/propietarios/liquidaciones/{liquidacion}/impuestos/ver', [LiquidacionPropietarioController::class, 'verImpuestos'])
        ->whereNumber('liquidacion')
        ->name('propietarios.liquidaciones.impuestos.ver');
    Route::get('/propietarios/liquidaciones/{liquidacion}/impuestos/descargar', [LiquidacionPropietarioController::class, 'descargarImpuestos'])
        ->whereNumber('liquidacion')
        ->name('propietarios.liquidaciones.impuestos.descargar');
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
