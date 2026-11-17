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
            <input type="hidden" name="orden" value="{{ $ordenActual }}">
            <input type="hidden" name="direccion" value="{{ $direccionActual }}">

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
            grupo(s) o revisiones individuales encontrados.
            Se muestran todos los integrantes de un grupo si alguno coincide con los filtros.
            Agrupar no implica unificar.
        </p>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        @foreach ([
                            'conflicto' => 'Conflicto',
                            'cuenta' => 'Cuenta COBOL',
                            'nombre' => 'Nombre COBOL',
                            'candidatos' => 'Candidatos a unificar',
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
                            $miembros = $cf->grupo_revision ?? [$cf];
                            $cantidad = count($miembros);
                            $destinos = json_decode((string) ($cf->candidatos_listado ?? '[]'), true) ?: [];
                            $formatoCuenta = static function ($v) {
                                $d = preg_replace('/\D+/', '', (string) $v);
                                return strlen($d) === 11 ? substr($d,0,4).'/'.substr($d,4,5).'/'.substr($d,9,2) : $v;
                            };
                        @endphp
                        @foreach ($miembros as $miembro)
                            @php $origen = json_decode((string) ($miembro->datos_origen ?? '{}'), true) ?: []; @endphp
                            <tr @class(['border-top border-2' => $loop->first, 'table-light' => $cantidad > 1])>
                                <td class="text-nowrap"><strong>#{{ $miembro->id }}</strong></td>
                                <td class="text-nowrap">{{ $formatoCuenta($miembro->clave_origen) }}</td>
                                <td>{{ $origen['nombre'] ?? '—' }}</td>
                                @if ($loop->first)
                                    <td rowspan="{{ $cantidad }}" class="text-nowrap small align-middle">
                                        @forelse ($destinos as $destino)
                                            <div class="mb-1"><strong>#{{ $destino['id'] }}</strong>
                                                @forelse ($destino['cuentas'] as $cuentaDestino)
                                                    <div>{{ $formatoCuenta($cuentaDestino) }}</div>
                                                @empty
                                                    <div class="text-muted">Sin cuentas</div>
                                                @endforelse
                                            </div>
                                        @empty
                                            <span class="text-muted">Sin candidatos</span>
                                        @endforelse
                                    </td>
                                @endif
                                <td>{{ $miembro->entidad_origen }}</td>
                                <td><span class="badge {{ $miembro->estado_origen === 'ACTIVO' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $miembro->estado_origen ?? '—' }}</span></td>
                                <td><small style="overflow-wrap:anywhere">{{ $miembro->motivo }}</small></td>
                                @if ($loop->first)
                                    <td rowspan="{{ $cantidad }}" class="align-middle text-nowrap">
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $cf->id) }}">{{ $cantidad > 1 ? 'Revisar grupo ('.$cantidad.')' : 'Revisar' }}</a>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="8"
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
