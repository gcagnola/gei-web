@php
    $perfilActual = auth()->user()?->perfil;
    $puedeModulo = static fn (string $codigo): bool => $perfilActual?->puedeVerModulo($codigo) ?? false;

    $puedeImportar = $puedeModulo('ARCHIVO_IMPORTAR');
    $puedeInmuebles = $puedeModulo('ARCHIVO_INMUEBLES');
    $puedeClientes = $puedeModulo('ARCHIVO_CLIENTES');
    $puedeImpresionesCobol = $puedeModulo('ARCHIVO_IMPRESIONES_COBOL');
    $puedeLiquidacionesPropietarios = $puedeModulo('PROPIETARIOS_LIQUIDACIONES');

    $puedeUsuarios = $puedeModulo('OPCIONES_USUARIOS');
    $puedePerfiles = $puedeModulo('OPCIONES_PERFILES');
    $puedePermisos = $puedeModulo('OPCIONES_PERMISOS');
    $puedeSedes = $puedeModulo('PARAMETROS_SEDES');
    $puedeSeteos = $puedeModulo('OPCIONES_SETEOS');

    $mostrarArchivo = $puedeImportar || $puedeInmuebles || $puedeClientes || $puedeImpresionesCobol;
    $mostrarOpciones = $puedeUsuarios || $puedePerfiles || $puedePermisos || $puedeSedes || $puedeSeteos;

    $archivoActivo =
        request()->routeIs('archivo.*')
        || request()->routeIs('personas.*')
        || request()->routeIs('inmuebles.*')
        || request()->routeIs('cuentas-corrientes.*')
        || request()->routeIs('core-clientes.*');

    $propietariosActivo = request()->routeIs('propietarios.*');
    $opcionesActivo = request()->routeIs('usuarios.*')
        || request()->routeIs('perfiles.*')
        || request()->routeIs('permisos.*')
        || request()->routeIs('parametros.sedes.*')
        || request()->routeIs('seteos.*');
@endphp

<ul class="gei-menu">
    <li>
        <a href="{{ route('inicio') }}" class="gei-menu__link {{ request()->routeIs('inicio') ? 'is-active' : '' }}">
            <span class="gei-menu__icon" aria-hidden="true">⌂</span>
            <span class="gei-menu__label">Inicio</span>
        </a>
    </li>

    @if ($mostrarArchivo)
        <li>
            <details class="gei-menu__group {{ $archivoActivo ? 'is-active' : '' }}">
                <summary class="gei-menu__summary">
                    <span class="gei-menu__icon" aria-hidden="true">▣</span>
                    <span class="gei-menu__label">Archivo</span>
                    <span class="gei-menu__chevron" aria-hidden="true"></span>
                </summary>

                <ul class="gei-submenu">
                    @if ($puedeImportar)
                        <li>
                            <a href="{{ route('archivo.importar') }}" class="gei-menu__link {{ request()->routeIs('archivo.importar*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Importar</span>
                            </a>
                        </li>
                    @endif
                    @if ($puedeInmuebles)
                        <li>
                            <a href="{{ route('inmuebles.index') }}" class="gei-menu__link {{ request()->routeIs('inmuebles.*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Inmuebles</span>
                            </a>
                        </li>
                    @endif
                    @if ($puedeClientes)
                        <li>
                            <a href="{{ route('core-clientes.index') }}" class="gei-menu__link {{ request()->routeIs('core-clientes.*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Clientes</span>
                            </a>
                        </li>
                    @endif
                    @if ($puedeImpresionesCobol)
                        <li>
                            <a href="{{ route('archivo.impresiones-cobol.index') }}" class="gei-menu__link {{ request()->routeIs('archivo.impresiones-cobol.*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Impresiones COBOL</span>
                            </a>
                        </li>
                    @endif
                </ul>
            </details>
        </li>
    @endif

    @if ($puedeLiquidacionesPropietarios)
        <li>
            <details class="gei-menu__group {{ $propietariosActivo ? 'is-active' : '' }}">
                <summary class="gei-menu__summary">
                    <span class="gei-menu__icon" aria-hidden="true">⌂</span>
                    <span class="gei-menu__label">Propietarios</span>
                    <span class="gei-menu__chevron" aria-hidden="true"></span>
                </summary>
                <ul class="gei-submenu">
                    <li>
                        <a href="{{ route('propietarios.liquidaciones.index') }}" class="gei-menu__link {{ request()->routeIs('propietarios.liquidaciones.*') ? 'is-active' : '' }}">
                            <span class="gei-menu__label">Liquidaciones</span>
                        </a>
                    </li>
                </ul>
            </details>
        </li>
    @endif

    @if ($mostrarOpciones)
        <li>
            <details class="gei-menu__group {{ $opcionesActivo ? 'is-active' : '' }}">
                <summary class="gei-menu__summary">
                    <span class="gei-menu__icon" aria-hidden="true">⚙</span>
                    <span class="gei-menu__label">Opciones</span>
                    <span class="gei-menu__chevron" aria-hidden="true"></span>
                </summary>
                <ul class="gei-submenu">
                    @if ($puedeUsuarios)
                        <li>
                            <a href="{{ route('usuarios.index') }}" class="gei-menu__link {{ request()->routeIs('usuarios.*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Usuarios</span>
                            </a>
                        </li>
                    @endif
                    @if ($puedePerfiles)
                        <li>
                            <a href="{{ route('perfiles.index') }}" class="gei-menu__link {{ request()->routeIs('perfiles.*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Perfiles</span>
                            </a>
                        </li>
                    @endif
                    @if ($puedePermisos)
                        <li>
                            <a href="{{ route('permisos.index') }}" class="gei-menu__link {{ request()->routeIs('permisos.*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Permisos</span>
                            </a>
                        </li>
                    @endif
                    @if ($puedeSedes)
                        <li>
                            <a href="{{ route('parametros.sedes.index') }}" class="gei-menu__link {{ request()->routeIs('parametros.sedes.*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Sedes</span>
                            </a>
                        </li>
                    @endif
                    @if ($puedeSeteos)
                        <li>
                            <a href="{{ route('seteos.index') }}" class="gei-menu__link {{ request()->routeIs('seteos.*') ? 'is-active' : '' }}">
                                <span class="gei-menu__label">Seteos</span>
                            </a>
                        </li>
                    @endif
                </ul>
            </details>
        </li>
    @endif


    <li>
        <details class="gei-menu__group {{ request()->routeIs('parametros.*') ? 'is-active' : '' }}">
            <summary class="gei-menu__summary">
                <span class="gei-menu__icon" aria-hidden="true">⚙</span>
                <span class="gei-menu__label">Parámetros</span>
                <span class="gei-menu__chevron" aria-hidden="true"></span>
            </summary>
            <ul class="gei-submenu">
                <li>
                    <a href="{{ route('parametros.conceptos.index') }}" class="gei-menu__link {{ request()->routeIs('parametros.conceptos.*') ? 'is-active' : '' }}">
                        <span class="gei-menu__label">Conceptos</span>
                    </a>
                </li>
            </ul>
        </details>
    </li>
</ul>
