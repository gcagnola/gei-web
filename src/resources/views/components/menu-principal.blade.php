@php
    $archivoActivo =
        request()->routeIs('archivo.*')
        || request()->routeIs('personas.*')
        || request()->routeIs('inmuebles.*')
        || request()->routeIs('cuentas-corrientes.*')
        || request()->routeIs('core-clientes.*');

    $propietariosActivo = request()->routeIs('propietarios.*');
@endphp

<ul class="gei-menu">
    <li>
        <a href="{{ route('inicio') }}" class="gei-menu__link {{ request()->routeIs('inicio') ? 'is-active' : '' }}">
            <span class="gei-menu__icon" aria-hidden="true">⌂</span>
            <span class="gei-menu__label">Inicio</span>
        </a>
    </li>

    <li>
        <details class="gei-menu__group {{ $archivoActivo ? 'is-active' : '' }}">
            <summary class="gei-menu__summary">
                <span class="gei-menu__icon" aria-hidden="true">▣</span>
                <span class="gei-menu__label">Archivo</span>
                <span class="gei-menu__chevron" aria-hidden="true"></span>
            </summary>

            <ul class="gei-submenu">
                <li>
                    <a href="{{ route('archivo.importar') }}" class="gei-menu__link {{ request()->routeIs('archivo.importar*') ? 'is-active' : '' }}">
                        <span class="gei-menu__label">Importar</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('inmuebles.index') }}" class="gei-menu__link {{ request()->routeIs('inmuebles.*') ? 'is-active' : '' }}">
                        <span class="gei-menu__label">Inmuebles</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('core-clientes.index') }}" class="gei-menu__link {{ request()->routeIs('core-clientes.*') ? 'is-active' : '' }}">
                        <span class="gei-menu__label">Clientes</span>
                    </a>
                </li>
            </ul>
        </details>
    </li>

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
</ul>
