@php
    $archivoActivo = request()->routeIs('archivo.*') || request()->routeIs('clientes.*');
    $propietariosActivo = request()->routeIs('propietarios.*');
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
        <details
            class="gei-menu__group {{ $archivoActivo ? 'is-active' : '' }}"
            @if ($archivoActivo) open @endif
        >
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
                    <a href="{{ route('clientes.index') }}" class="gei-menu__link {{ request()->routeIs('clientes.*') ? 'is-active' : '' }}">
                        <span class="gei-menu__label">Clientes</span>
                    </a>
                </li>
            </ul>
        </details>
    </li>

    <li>
        <details
            class="gei-menu__group {{ $propietariosActivo ? 'is-active' : '' }}"
            @if ($propietariosActivo) open @endif
        >
            <summary class="gei-menu__summary">
                <span class="gei-menu__icon" aria-hidden="true">⌂</span>
                <span class="gei-menu__label">Propietarios</span>
                <span class="gei-menu__chevron" aria-hidden="true"></span>
            </summary>

            <ul class="gei-submenu">
                <li>
                    <a
                        href="{{ route('propietarios.liquidaciones.index') }}"
                        class="gei-menu__link {{ request()->routeIs('propietarios.liquidaciones.*') ? 'is-active' : '' }}"
                    >
                        <span class="gei-menu__label">Liquidaciones</span>
                    </a>
                </li>
            </ul>
        </details>
    </li>
</ul>
