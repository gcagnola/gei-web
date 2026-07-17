@php
    $archivoActivo = request()->routeIs('archivo.*');
@endphp

<ul class="gei-menu">
    <li>
        <a
            href="{{ route('inicio') }}"
            class="gei-menu__link {{ request()->routeIs('inicio') ? 'is-active' : '' }}"
        >
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
                    <a href="{{ route('archivo.importar') }}" class="gei-menu__link {{ request()->routeIs('archivo.importar') ? 'is-active' : '' }}">
                        <span class="gei-menu__label">Importar</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('archivo.actualizar-db') }}" class="gei-menu__link {{ request()->routeIs('archivo.actualizar-db*') ? 'is-active' : '' }}">
                        <span class="gei-menu__label">Actualizar DB</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('clientes.index') }}" class="gei-menu__link {{ request()->routeIs('clientes.*') ? 'is-active' : '' }}">
                        <span class="gei-menu__label">Clientes</span>
                    </a>
                </li>
            </ul>
        </details>
    </li>
</ul>
