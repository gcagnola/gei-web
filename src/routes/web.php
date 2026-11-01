<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RecuperarClaveController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClienteMaestroController;
use App\Http\Controllers\ClientePortalAccesoController;
use App\Http\Controllers\ClienteComprobanteArcaController;
use App\Http\Controllers\ComprobanteArcaController;
use App\Http\Controllers\ConfiguracionFacturacionController;
use App\Http\Controllers\ImportacionArchivosController;
use App\Http\Controllers\CuentaCorrienteExploracionController;
use App\Http\Controllers\InmuebleMaestroController;
use App\Http\Controllers\InmuebleConsolidacionConsultaController;
use App\Http\Controllers\MigracionGeiWebController;
use App\Http\Controllers\LiquidacionPropietarioController;
use App\Http\Controllers\UnificacionInmuebleController;
use App\Http\Controllers\UnificacionClienteController;
use App\Http\Controllers\UnificacionClientesDuplicadosController;
use App\Http\Controllers\UnificacionClientesAuditoriaController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\PermisoPerfilController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\Api\CobolImpresionController;
use App\Http\Controllers\CobolImpresionListadoController;
use App\Http\Controllers\KngImportacionController;
use App\Http\Controllers\ConceptoController;
use App\Http\Controllers\PlanCuentaController;
use App\Http\Controllers\CamaraController;
use App\Http\Controllers\RelojController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Controllers\TransferenciaInformadaController;
use App\Http\Controllers\PortalTransferenciaController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalCuentaCorrienteController;
use App\Http\Controllers\PortalDocumentosController;
use App\Http\Controllers\PortalReciboController;
use App\Http\Middleware\AutenticarPortal;
use App\Http\Middleware\SoloAdministradorGlobal;
use App\Http\Middleware\VerificarFuncionalidadPortal;
use Illuminate\Support\Facades\Route;

Route::group([], function () {
    Route::get('/login', [LoginController::class, 'mostrar'])
        ->name('login');

    Route::post('/login', [LoginController::class, 'ingresar'])
        ->middleware('throttle:10,1')
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

// Autogestión de clientes. Se mantiene separada del guard administrativo.
Route::prefix('autogestion')->name('portal.')->group(function (): void {
    Route::get('/login', fn () => redirect()->route('login'))->name('login');
    Route::post('/login', [PortalAuthController::class, 'ingresar'])->middleware('throttle:10,1')->name('login.ingresar');

    Route::get('/activar/{token}', [PortalAuthController::class, 'activacion'])->name('activacion.mostrar');
    Route::post('/activar', [PortalAuthController::class, 'activar'])->middleware('throttle:10,1')->name('activacion.guardar');

    Route::get('/clave-olvidada', fn () => redirect()->route('password.request'))->name('password.request');
    Route::post('/clave-olvidada', [PortalAuthController::class, 'enviarRecuperacion'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/restablecer-clave/{token}', [PortalAuthController::class, 'mostrarReset'])->name('password.reset');
    Route::post('/restablecer-clave', [PortalAuthController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');

    Route::middleware(AutenticarPortal::class)->group(function (): void {
        Route::get('/', [PortalController::class, 'inicio'])->name('inicio');
        Route::put('/datos', [PortalController::class, 'actualizarDatos'])->name('datos.update');
        Route::put('/clave', [PortalController::class, 'cambiarClave'])->name('clave.update');

        Route::get('/comprobantes-arca', [PortalDocumentosController::class, 'comprobantesArca'])->name('comprobantes-arca.index');
        Route::get('/comprobantes-arca/{periodo}/{archivo}', [PortalDocumentosController::class, 'verComprobanteArca'])
            ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
            ->name('comprobantes-arca.ver');

        Route::get('/comprobantes-arca/factura/{factura}', [PortalDocumentosController::class, 'verFacturaArca'])
            ->whereNumber('factura')
            ->name('comprobantes-arca.factura');

        Route::get('/cuenta-corriente', [PortalCuentaCorrienteController::class, 'index'])
            ->middleware(VerificarFuncionalidadPortal::class.':cuenta_corriente')
            ->name('cuenta-corriente.index');

        Route::get('/liquidaciones-propietario', [PortalDocumentosController::class, 'liquidacionesPropietario'])->name('liquidaciones-propietario.index');
        Route::get('/liquidaciones-propietario/{liquidacion}/ver', [PortalDocumentosController::class, 'verLiquidacionPropietario'])
            ->whereNumber('liquidacion')
            ->name('liquidaciones-propietario.ver');

        Route::get('/impuestos-garantizados', [PortalDocumentosController::class, 'impuestosGarantizados'])->name('impuestos-garantizados.index');
        Route::get('/impuestos-garantizados/{liquidacion}/ver', [PortalDocumentosController::class, 'verImpuestosGarantizados'])
            ->whereNumber('liquidacion')
            ->name('impuestos-garantizados.ver');


        // Se mantienen los nombres de ruta para poder habilitar los módulos más adelante.
        // Las rutas devuelven 404 mientras la funcionalidad esté desactivada.
        Route::middleware(VerificarFuncionalidadPortal::class.':recibos')->group(function (): void {
            Route::get('/recibos', [PortalReciboController::class, 'index'])->name('recibos.index');
            Route::get('/recibos/{impresion}/pdf', [PortalReciboController::class, 'pdf'])
                ->whereNumber('impresion')
                ->name('recibos.pdf');
        });

        Route::middleware(VerificarFuncionalidadPortal::class.':transferencias')->group(function (): void {
            Route::get('/transferencias', [PortalTransferenciaController::class, 'index'])->name('transferencias.index');
            Route::get('/transferencias/nueva', [PortalTransferenciaController::class, 'create'])->name('transferencias.create');
            Route::post('/transferencias', [PortalTransferenciaController::class, 'store'])->name('transferencias.store');
            Route::get('/transferencias/{transferencia}/archivos/{archivo}', [PortalTransferenciaController::class, 'archivo'])
                ->whereNumber('transferencia')->whereNumber('archivo')->name('transferencias.archivo');
        });

        Route::post('/logout', [PortalAuthController::class, 'salir'])->name('logout');
    });
});


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


// Autorizacion interna para el proxy HTTPS de camaras.
// Devuelve codigos HTTP sin redirigir a la pantalla de login.
Route::get('/_internal/camaras/autorizacion', function (\Illuminate\Http\Request $request) {
    $usuario = $request->user();

    if (! $usuario) {
        abort(401);
    }

    if ($usuario->perfil?->codigo === 'CONTADOR'
        || ! $usuario->perfil?->puedeVerModulo('CAMARAS')) {
        abort(403);
    }

    return response()->noContent();
})->name('camaras.proxy-auth');

Route::middleware('auth')->group(function () {
    Route::view('/', 'inicio')->name('inicio');

    Route::get('/camaras', [CamaraController::class, 'index'])
        ->name('camaras.index');

    Route::get('/reloj', [RelojController::class, 'index'])
        ->name('reloj.index');
    Route::get('/reloj/status', [RelojController::class, 'status'])
        ->name('reloj.status');
    Route::get('/reloj/usuarios', [RelojController::class, 'usuarios'])
        ->name('reloj.usuarios');
    Route::put('/reloj/usuarios/{codigo}/nombre', [RelojController::class, 'guardarNombre'])
        ->whereNumber('codigo')
        ->name('reloj.usuarios.nombre');
    Route::get('/reloj/marcaciones', [RelojController::class, 'marcaciones'])
        ->name('reloj.marcaciones');
    Route::post('/reloj/sincronizar/usuarios', [RelojController::class, 'sincronizarUsuarios'])
        ->name('reloj.sincronizar.usuarios');
    Route::post('/reloj/sincronizar/nuevas', [RelojController::class, 'sincronizarNuevas'])
        ->name('reloj.sincronizar.nuevas');

    Route::get('/parametros/sedes', [SucursalController::class, 'index'])
        ->name('parametros.sedes.index');

    Route::get('/parametros/conceptos/{concepto}/caja', [ConceptoController::class, 'caja'])
        ->whereNumber('concepto')
        ->name('parametros.conceptos.caja');
    Route::put('/parametros/conceptos/{concepto}/caja', [ConceptoController::class, 'updateCaja'])
        ->whereNumber('concepto')
        ->middleware(SoloAdministradorGlobal::class)
        ->name('parametros.conceptos.caja.update');

    Route::get('/parametros/conceptos', [ConceptoController::class, 'index'])
        ->name('parametros.conceptos.index');
    Route::post('/parametros/conceptos', [ConceptoController::class, 'store'])
        ->middleware(SoloAdministradorGlobal::class)
        ->name('parametros.conceptos.store');
    Route::put('/parametros/conceptos/{concepto}', [ConceptoController::class, 'update'])
        ->whereNumber('concepto')
        ->middleware(SoloAdministradorGlobal::class)
        ->name('parametros.conceptos.update');
    Route::delete('/parametros/conceptos/{concepto}', [ConceptoController::class, 'destroy'])
        ->whereNumber('concepto')
        ->middleware(SoloAdministradorGlobal::class)
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
        ->middleware(SoloAdministradorGlobal::class)
        ->name('facturas.pdf.ver');

    // Maestro operativo principal, sólo lectura y filtrado por sucursal.
    Route::get('/archivo/clientes-maestro', [ClienteMaestroController::class, 'index'])
        ->name('clientes-maestro.index');
    Route::get('/archivo/clientes-maestro/{cliente}', [ClienteMaestroController::class, 'show'])
        ->whereNumber('cliente')
        ->name('clientes-maestro.show');
    Route::get('/archivo/clientes-maestro/{cliente}/liquidaciones/{liquidacion}/{tipo}/pdf',
        [ClienteMaestroController::class, 'pdfLiquidacion'])
        ->whereNumber('cliente')->whereNumber('liquidacion')
        ->where('tipo', 'liquidacion|impuestos')
        ->name('clientes-maestro.liquidacion.pdf');
    Route::get('/archivo/clientes-maestro/{cliente}/arca/{periodo}/{archivo}/pdf',
        [ClienteMaestroController::class, 'pdfArca'])
        ->whereNumber('cliente')
        ->where('periodo', '(19|20)[0-9]{2}(0[1-9]|1[0-2])')
        ->where('archivo', '[A-Za-z]{2}-[0-9]{4}-[0-9]{8}-[0-9]{11}\.pdf')
        ->name('clientes-maestro.arca.pdf');
    Route::get('/archivo/clientes-maestro/{cliente}/cuentas/{rol}/{cuenta}/historico',
        [ClienteMaestroController::class, 'historico'])
        ->whereNumber('cliente')
        ->where('rol', 'INQUILINO|PROPIETARIO')
        ->where('cuenta', '[0-9]{11}')
        ->name('clientes-maestro.historico');

    // Cambios de facturación: permisos administrativos + cuenta y sucursal
    // comprobadas nuevamente dentro del controlador.
    Route::put('/archivo/clientes-maestro/{cliente}/facturacion/{cuentaCorriente}',
        [ClienteMaestroController::class, 'actualizarFacturacion'])
        ->whereNumber('cliente')->whereNumber('cuentaCorriente')
        ->name('clientes-maestro.facturacion.update');

    Route::put('/archivo/clientes-maestro/{cliente}/facturacion/beneficiarios/{beneficiario}',
        [ClienteMaestroController::class, 'actualizarBeneficiarioFacturacion'])
        ->whereNumber('cliente')->whereNumber('beneficiario')
        ->name('clientes-maestro.facturacion.beneficiarios.update');

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




    // Envíos ARCA desde ficha heredada: sólo administradores hasta auditar su alcance.
    Route::middleware('can:administrar-unificaciones')->group(function (): void {
        Route::get('/archivo/clientes/comprobantes-arca', [ClienteComprobanteArcaController::class, 'index'])
            ->name('clientes.comprobantes-arca.index');
        Route::post('/archivo/clientes/comprobantes-arca/enviar-emails', [ClienteComprobanteArcaController::class, 'enviarEmails'])
            ->name('clientes.comprobantes-arca.enviar-emails');
        Route::post('/archivo/clientes/{cliente}/comprobantes-arca/enviar-email', [ClienteComprobanteArcaController::class, 'enviarEmail'])
            ->whereNumber('cliente')
            ->name('clientes.comprobantes-arca.enviar-email');

    });

    // CRUD heredado sin filtros de sede completos: restringido a administradores.
    Route::middleware('can:administrar-unificaciones')->group(function (): void {
        Route::resource('/archivo/clientes', ClienteController::class)
            ->parameters(['clientes' => 'cliente'])
            ->only(['index', 'show', 'create', 'store', 'edit', 'update']);
    });


    // Acceso administrativo protegido aunque se ingrese la URL manualmente.
    Route::middleware(VerificarFuncionalidadPortal::class.':transferencias')->group(function (): void {
        Route::get('/archivo/transferencias-informadas', [TransferenciaInformadaController::class, 'index'])
            ->name('transferencias-informadas.index');
        Route::get('/archivo/transferencias-informadas/{transferencia}', [TransferenciaInformadaController::class, 'show'])
            ->whereNumber('transferencia')->name('transferencias-informadas.show');
        Route::get('/archivo/transferencias-informadas/{transferencia}/archivos/{archivo}', [TransferenciaInformadaController::class, 'archivo'])
            ->whereNumber('transferencia')->whereNumber('archivo')->name('transferencias-informadas.archivo');
        Route::post('/archivo/transferencias-informadas/{transferencia}/verificar', [TransferenciaInformadaController::class, 'verificar'])
            ->whereNumber('transferencia')->name('transferencias-informadas.verificar');
        Route::post('/archivo/transferencias-informadas/{transferencia}/rechazar', [TransferenciaInformadaController::class, 'rechazar'])
            ->whereNumber('transferencia')->name('transferencias-informadas.rechazar');
    });

    // Edición/acciones del cliente fuera de la vista operativa: sólo auditores.
    Route::middleware('can:administrar-unificaciones')->group(function (): void {
        Route::post('/archivo/clientes/{cliente}/autogestion/enviar-acceso', [ClientePortalAccesoController::class, 'enviar'])
            ->whereNumber('cliente')
            ->name('clientes.autogestion.enviar-acceso');

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
    });

    // Maestro operativo de inmuebles, único punto de lectura del módulo.
    Route::get('/archivo/inmuebles-maestro', [InmuebleMaestroController::class, 'index'])
        ->name('inmuebles.maestro.index');

    // Comparar inmuebles canonicos: exclusivamente lectura, administrador de ambas sedes.
    Route::get('/archivo/inmuebles-maestro/consolidar/comparar', [InmuebleConsolidacionConsultaController::class, 'comparar'])
        ->middleware('can:administrar-unificaciones')
        ->name('inmuebles.maestro.consolidar.comparar');

    // Compatibilidad con enlaces antiguos: ya no se consulta el maestro paralelo.
    Route::get('/archivo/inmuebles', static function (\Illuminate\Http\Request $request) {
        $parametros = $request->only(['sede', 'buscar', 'seleccionar', 'page', 'revision']);
        $estado = strtoupper((string) $request->query('estado', 'ACTIVO'));
        $parametros['estado'] = match ($estado) {
            'ACTIVOS' => 'ACTIVO', 'TODOS' => 'TODOS',
            'ACTIVO', 'INACTIVO', 'DESCONOCIDO' => $estado,
            default => 'ACTIVO',
        };
        return redirect()->route('inmuebles.maestro.index', $parametros);
    })->name('inmuebles.index');

    Route::get('/archivo/inmuebles/{inmueble}', static function (\Illuminate\Http\Request $request, int $inmueble) {
        return redirect()->route('inmuebles.maestro.index', [
            ...$request->only(['sede', 'buscar', 'revision', 'page']),
            'estado' => 'TODOS', 'seleccionar' => $inmueble,
        ]);
    })->whereNumber('inmueble')->name('inmuebles.show');

    // Las escrituras antiguas dependían de otra base y no deben ejecutarse
    // mientras no exista su reemplazo auditado contra public.inmuebles.
    Route::put('/archivo/inmuebles/{inmueble}/sede', static fn () => abort(403, 'Cambio de sede heredado deshabilitado.'))
        ->whereNumber('inmueble')->name('inmuebles.sede.update');
    Route::post('/archivo/inmuebles/{inmueble}/validar-unico', static fn () => abort(403, 'Validación heredada deshabilitada.'))
        ->whereNumber('inmueble')->name('inmuebles.validar-unico');
    Route::delete('/archivo/inmuebles/{inmueble}/validar-unico', static fn () => abort(403, 'Validación heredada deshabilitada.'))
        ->whereNumber('inmueble')->name('inmuebles.validacion-unico.destroy');

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

        // Auditoría canónica independiente.
        Route::get('/archivo/unificacion/clientes/revisiones', [UnificacionClientesAuditoriaController::class, 'revisiones'])
            ->name('archivo.unificacion.clientes.revisiones');
        Route::get('/archivo/unificacion/clientes/historial', [UnificacionClientesAuditoriaController::class, 'historial'])
            ->name('archivo.unificacion.clientes.historial');
        // Posibles duplicados detectados sobre el maestro canónico.
        Route::get('/archivo/unificacion/clientes/duplicados', [UnificacionClientesDuplicadosController::class, 'index'])
            ->name('archivo.unificacion.clientes.duplicados');
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
        ->middleware(SoloAdministradorGlobal::class)
        ->name('comprobantes-arca.ver');

    Route::get('/comprobantes-arca/lote/{lote}/{archivo}/ver', [\App\Http\Controllers\ComprobanteArcaLoteController::class, 'ver'])
        ->whereNumber('lote')
        ->where('archivo', '[A-Za-z0-9._-]+')
        ->middleware(SoloAdministradorGlobal::class)
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

    Route::get('/contabilidad/plan-de-cuentas', [PlanCuentaController::class, 'index'])
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
    // La ruta antigua se usa sólo desde Clientes-Core (auditoría).
    // Los operadores usan la ruta del maestro, que comprueba cuenta y sede.
    ->middleware(['auth', 'can:administrar-unificaciones'])
    ->whereNumber('lote')
    ->where('archivo', '[A-Za-z0-9._-]+')
    ->name('kng.facturas.pdf');
