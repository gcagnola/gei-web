<div class="card mb-3">
    <div class="card-body">

        <h5 class="card-title mb-3">
            Conflictos COBOL pendientes
            <span class="badge text-bg-warning">
                {{ $resumenPendientes['TODOS'] }}
            </span>
        </h5>

        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach (['ACTIVO', 'BAJA', 'DESCONOCIDO'] as $estado)
                <span class="badge text-bg-secondary">
                    {{ $estado }}: {{ $resumenPendientes[$estado] }}
                </span>
            @endforeach
        </div>

        <form method="GET"
              action="{{ route('archivo.unificacion.clientes.index') }}"
              class="row g-2 align-items-end">
            <input type="hidden" name="vista" value="activos_revision">

            <div class="col-md-5">
                <label class="form-label">Buscar</label>
                <input type="text"
                       name="q"
                       class="form-control"
                       value="{{ $texto }}"
                       placeholder="Cuenta, nombre o número de conflicto">
            </div>

            <div class="col-md-2">
                <label class="form-label">Estado origen</label>
                <select name="estado_origen" class="form-select">
                    @foreach (['TODOS', 'ACTIVO', 'BAJA', 'DESCONOCIDO'] as $op)
                        <option value="{{ $op }}"
                            @selected($estadoOrigen === $op)>
                            {{ $op }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Entidad COBOL</label>
                <select name="entidad" class="form-select">
                    <option value="TODAS"
                        @selected($entidad === 'TODAS')>
                        Todas
                    </option>
                    <option value="PROPIETAR"
                        @selected($entidad === 'PROPIETAR')>
                        Propietarios
                    </option>
                    <option value="INQUILINO"
                        @selected($entidad === 'INQUILINO')>
                        Inquilinos
                    </option>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button class="btn btn-primary">
                    Buscar
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">

        <p class="text-muted small">
            {{ $conflictosPendientes->total() }}
            conflictos encontrados.
            Los estados de origen no modifican la identidad
            ni la actividad administrativa del cliente.
        </p>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        @foreach ([
                            'conflicto' => 'Conflicto',
                            'cuenta' => 'Cuenta COBOL',
                            'nombre' => 'Nombre COBOL',
                            'entidad' => 'Entidad',
                            'estado' => 'Estado origen',
                            'motivo' => 'Motivo',
                        ] as $columna => $titulo)
                            @php
                                $activo = $ordenActual === $columna;

                                $siguienteDireccion = (
                                    $activo && $direccionActual === 'asc'
                                ) ? 'desc' : 'asc';

                                $urlOrden = request()->fullUrlWithQuery([
                                    'orden' => $columna,
                                    'direccion' => $siguienteDireccion,
                                    'conflictos_page' => 1,
                                ]);
                            @endphp

                            <th>
                                <a href="{{ $urlOrden }}"
                                   class="text-decoration-none text-dark"
                                   title="Ordenar por {{ $titulo }}">
                                    {{ $titulo }}

                                    @if ($activo)
                                        <span class="text-primary">
                                            {{ $direccionActual === 'asc' ? '↑' : '↓' }}
                                        </span>
                                    @else
                                        <span class="text-muted">↕</span>
                                    @endif
                                </a>
                            </th>
                        @endforeach

                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($conflictosPendientes as $cf)
                        @php
                            $origen = json_decode(
                                (string) ($cf->datos_origen ?? '{}'),
                                true
                            );
                            $origen = is_array($origen) ? $origen : [];

                            $cuenta = preg_replace(
                                '/\D+/',
                                '',
                                (string) $cf->clave_origen
                            );

                            $cuentaVisible = strlen($cuenta) === 11
                                ? substr($cuenta, 0, 4).'/'
                                    .substr($cuenta, 4, 5).'/'
                                    .substr($cuenta, 9, 2)
                                : $cf->clave_origen;
                        @endphp

                        <tr>
                            <td>#{{ $cf->id }}</td>

                            <td class="text-nowrap">
                                {{ $cuentaVisible }}
                            </td>

                            <td>
                                {{ $origen['nombre'] ?? '—' }}
                            </td>

                            <td>{{ $cf->entidad_origen }}</td>

                            <td>
                                <span class="badge {{
                                    $cf->estado_origen === 'ACTIVO'
                                        ? 'text-bg-success'
                                        : 'text-bg-secondary'
                                }}">
                                    {{ $cf->estado_origen ?? '—' }}
                                </span>
                            </td>

                            <td>
                                <small>{{ $cf->motivo }}</small>
                            </td>

                            <td>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="{{ route(
                                       'archivo.unificacion.clientes.conflicto.revisar',
                                       $cf->id
                                   ) }}">
                                    Revisar
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7"
                                class="text-center text-muted">
                                No se encontraron conflictos.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $conflictosPendientes->links() }}

    </div>
</div>
