<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RecuperarClaveController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClienteComprobanteArcaController;
use App\Http\Controllers\ComprobanteArcaController;
use App\Http\Controllers\ConfiguracionFacturacionController;
use App\Http\Controllers\ImportacionArchivosController;
use App\Http\Controllers\CuentaCorrienteExploracionController;
use App\Http\Controllers\InmuebleExploracionController;
use App\Http\Controllers\PersonaExploracionController;
use App\Http\Controllers\MigracionGeiWebController;
use App\Http\Controllers\LiquidacionPropietarioController;
use App\Http\Controllers\UnificacionInmuebleController;
use App\Http\Controllers\UnificacionClienteController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\PermisoPerfilController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\Api\CobolImpresionController;
use App\Http\Controllers\CobolImpresionListadoController;
use App\Http\Controllers\KngImportacionController;
use App\Http\Controllers\ConceptoController;
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

use App\Http\Controllers\GeiCoreClienteController;

// Progreso de importación: ruta firmada y sin middleware auth.
// Lee únicamente el JSON local de progreso y no consulta PostgreSQL.

Route::get(
    '/archivo/importar/{periodo}/progreso',
    [ImportacionArchivosController::class, 'progreso']
)
    ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
    ->middleware('signed')
    ->name('archivo.importar.progreso');

Route::post(
    '/cobol/impresion',
    [CobolImpresionController::class, 'store']
)->name('cobol.impresion.store');

Route::middleware('auth')->group(function () {
    Route::view('/', 'inicio')->name('inicio');

    Route::get('/parametros/sedes', [SucursalController::class, 'index'])
        ->name('parametros.sedes.index');

    Route::get('/parametros/conceptos/{concepto}/caja', [ConceptoController::class, 'caja'])
        ->whereNumber('concepto')
        ->name('parametros.conceptos.caja');
    Route::put('/parametros/conceptos/{concepto}/caja', [ConceptoController::class, 'updateCaja'])
        ->whereNumber('concepto')
        ->name('parametros.conceptos.caja.update');

    Route::get('/parametros/conceptos', [ConceptoController::class, 'index'])
        ->name('parametros.conceptos.index');
    Route::post('/parametros/conceptos', [ConceptoController::class, 'store'])
        ->name('parametros.conceptos.store');
    Route::put('/parametros/conceptos/{concepto}', [ConceptoController::class, 'update'])
        ->whereNumber('concepto')
        ->name('parametros.conceptos.update');
    Route::delete('/parametros/conceptos/{concepto}', [ConceptoController::class, 'destroy'])
        ->whereNumber('concepto')
        ->name('parametros.conceptos.destroy');


    Route::get('/archivo/impresiones-cobol', [CobolImpresionListadoController::class, 'index'])
        ->name('archivo.impresiones-cobol.index');
    Route::get('/archivo/impresiones-cobol/datos', [CobolImpresionListadoController::class, 'datos'])
        ->name('archivo.impresiones-cobol.datos');
    Route::get('/archivo/impresiones-cobol/{impresion}/raw', [CobolImpresionListadoController::class, 'raw'])
        ->whereNumber('impresion')
        ->name('archivo.impresiones-cobol.raw');

    Route::post('/archivo/impresiones-cobol/{impresion}/pdf/generar', [CobolImpresionListadoController::class, 'generarPdf'])
        ->whereNumber('impresion')
        ->name('archivo.impresiones-cobol.pdf.generar');
    Route::get('/archivo/impresiones-cobol/{impresion}/pdf', [CobolImpresionListadoController::class, 'pdf'])
        ->whereNumber('impresion')
        ->name('archivo.impresiones-cobol.pdf');

    $modulo = static function (string $titulo, string $seccion) {
        return static fn () => view('modulo-en-construccion', [
            'titulo' => $titulo,
            'seccion' => $seccion,
        ]);
    };

    Route::get('/facturas/{factura}/pdf', [FacturaController::class, 'verPdf'])
        ->whereNumber('factura')
        ->name('facturas.pdf.ver');

    Route::get('/archivo/clientes-core', [GeiCoreClienteController::class, 'index'])
        ->name('core-clientes.index');
    Route::get('/archivo/clientes-core/{persona}', [GeiCoreClienteController::class, 'show'])
        ->whereNumber('persona')
        ->name('core-clientes.show');
    Route::put('/archivo/clientes-core/{persona}/email', [GeiCoreClienteController::class, 'updateEmail'])
        ->whereNumber('persona')
        ->name('core-clientes.email.update');
    Route::get('/archivo/clientes-core/{persona}/actividad', [GeiCoreClienteController::class, 'actividad'])
        ->whereNumber('persona')
        ->name('core-clientes.actividad');

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

    Route::put(
        '/archivo/clientes/{cliente}/facturacion/{cuentaCorriente}',
        [ClienteController::class, 'updateFacturacion']
    )
        ->whereNumber('cliente')
        ->whereNumber('cuentaCorriente')
        ->name('clientes.facturacion.update');

    Route::put(
        '/archivo/clientes/{cliente}/facturacion/beneficiarios/{beneficiario}',
        [ClienteController::class, 'updateBeneficiarioFacturacion']
    )
        ->whereNumber('cliente')
        ->whereNumber('beneficiario')
        ->name('clientes.facturacion.beneficiarios.update');
    Route::get('/archivo/personas', [PersonaExploracionController::class, 'index'])
        ->name('personas.index');
    Route::get('/archivo/personas/{persona}', [PersonaExploracionController::class, 'show'])
        ->whereNumber('persona')
        ->name('personas.show');

    Route::get('/archivo/inmuebles', [InmuebleExploracionController::class, 'index'])
        ->name('inmuebles.index');
    Route::get('/archivo/inmuebles/{inmueble}', [InmuebleExploracionController::class, 'show'])
        ->whereNumber('inmueble')
        ->name('inmuebles.show');
    Route::put('/archivo/inmuebles/{inmueble}/sede', [InmuebleExploracionController::class, 'actualizarSede'])
        ->whereNumber('inmueble')
        ->name('inmuebles.sede.update');
    Route::post('/archivo/inmuebles/{inmueble}/validar-unico', [InmuebleExploracionController::class, 'validarUnico'])
        ->whereNumber('inmueble')
        ->name('inmuebles.validar-unico');
    Route::delete('/archivo/inmuebles/{inmueble}/validar-unico', [InmuebleExploracionController::class, 'deshacerValidacion'])
        ->whereNumber('inmueble')
        ->name('inmuebles.validacion-unico.destroy');

    Route::get('/archivo/cuentas-corrientes', [CuentaCorrienteExploracionController::class, 'index'])
        ->name('cuentas-corrientes.index');


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

    Route::get('/comprobantes-arca/lote/{lote}/{archivo}/ver', [\App\Http\Controllers\ComprobanteArcaLoteController::class, 'ver'])
        ->whereNumber('lote')
        ->where('archivo', '[A-Za-z0-9._-]+')
        ->name('comprobantes-arca.lote.ver');
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

    Route::get('/opciones/usuarios', [UsuarioController::class, 'index'])
        ->name('usuarios.index');
    Route::post('/opciones/usuarios', [UsuarioController::class, 'store'])
        ->name('usuarios.store');
    Route::put('/opciones/usuarios/{usuario}', [UsuarioController::class, 'update'])
        ->name('usuarios.update');
    Route::put('/opciones/usuarios/{usuario}/sucursales', [UsuarioController::class, 'actualizarSucursales'])
        ->whereNumber('usuario')
        ->name('usuarios.sucursales.update');
    Route::delete('/opciones/usuarios/{usuario}', [UsuarioController::class, 'destroy'])
        ->whereNumber('usuario')
        ->name('usuarios.destroy');


    Route::get('/opciones/permisos', [PermisoPerfilController::class, 'index'])
        ->name('permisos.index');
    Route::put('/opciones/permisos', [PermisoPerfilController::class, 'update'])
        ->name('permisos.update');

    Route::get('/opciones/perfiles', [PerfilController::class, 'index'])
        ->name('perfiles.index');
    Route::post('/opciones/perfiles', [PerfilController::class, 'store'])
        ->name('perfiles.store');
    Route::put('/opciones/perfiles/{perfil}', [PerfilController::class, 'update'])
        ->whereNumber('perfil')
        ->name('perfiles.update');
    Route::delete('/opciones/perfiles/{perfil}', [PerfilController::class, 'destroy'])
        ->whereNumber('perfil')
        ->name('perfiles.destroy');
    Route::get(
        '/opciones/seteos',
        [ConfiguracionFacturacionController::class, 'index']
    )->name('seteos.index');


    Route::get(
        '/archivo/importar/kng/progreso',
        [KngImportacionController::class, 'progreso']
    )->name('archivo.importar.kng.progreso');

    Route::post(
        '/archivo/importar/kng',
        [KngImportacionController::class, 'importar']
    )->name('archivo.importar.kng');

    Route::put(
        '/opciones/seteos/facturacion/general',
        [ConfiguracionFacturacionController::class, 'updateGeneral']
    )->name('seteos.facturacion.general.update');

    Route::put(
        '/opciones/seteos/facturacion/puntos-venta/{puntoVenta}',
        [ConfiguracionFacturacionController::class, 'updatePuntoVenta']
    )
        ->whereNumber('puntoVenta')
        ->name('seteos.facturacion.puntos-venta.update');

    Route::put(
        '/opciones/seteos/facturacion/alicuotas/{alicuota}',
        [ConfiguracionFacturacionController::class, 'updateAlicuota']
    )
        ->whereNumber('alicuota')
        ->name('seteos.facturacion.alicuotas.update');

    Route::post('/logout', [LoginController::class, 'salir'])
        ->name('logout');
});


/*
|--------------------------------------------------------------------------
| KNG - Facturas históricas (solo lectura)
|--------------------------------------------------------------------------
*/
\Illuminate\Support\Facades\Route::get(
    '/archivo/kng/facturas/lote/{lote}/{archivo}',
    [\App\Http\Controllers\KngFacturaPdfController::class, 'ver']
)
    ->middleware('auth')
    ->whereNumber('lote')
    ->where('archivo', '[A-Za-z0-9._-]+')
    ->name('kng.facturas.pdf');
