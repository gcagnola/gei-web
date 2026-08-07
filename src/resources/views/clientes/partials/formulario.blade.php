@php
    $esEdicion = isset($cliente);
    $rolesSeleccionados = collect(old('roles', $esEdicion ? $cliente->roles->pluck('id')->all() : []))
        ->map(fn ($id) => (int) $id)
        ->all();
@endphp

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Revisá los datos ingresados.</strong>
        <ul class="mb-0 mt-2">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $esEdicion ? route('clientes.update', $cliente) : route('clientes.store') }}">
    @csrf
    @if ($esEdicion)
        @method('PUT')
    @endif

    <section class="gei-card p-4 mb-4">
        <div class="gei-section-title">
            <div>
                <h2>Identificación</h2>
                <p>Datos del modelo definitivo de clientes.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label for="tipo_persona" class="form-label">Tipo de persona *</label>
                <select id="tipo_persona" name="tipo_persona" class="form-select" required>
                    @foreach (['FISICA' => 'Física', 'JURIDICA' => 'Jurídica', 'DESCONOCIDA' => 'Sin clasificar'] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected(old('tipo_persona', $cliente->tipo_persona ?? 'FISICA') === $valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-8">
                <label for="nombre" class="form-label">Nombre o razón social *</label>
                <input type="text" id="nombre" name="nombre" value="{{ old('nombre', $cliente->nombre ?? '') }}" maxlength="180" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label for="tipo_documento" class="form-label">Tipo de documento</label>
                <select id="tipo_documento" name="tipo_documento" class="form-select">
                    <option value="">Sin informar</option>
                    @foreach (['DNI', 'LC', 'LE', 'CUIT', 'CEDULA', 'PASAPORTE', 'OTRO'] as $tipo)
                        <option value="{{ $tipo }}" @selected(old('tipo_documento', $cliente->tipo_documento ?? '') === $tipo)>{{ $tipo }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="numero_documento" class="form-label">Número de documento</label>
                <input type="text" id="numero_documento" name="numero_documento" value="{{ old('numero_documento', $cliente->numero_documento ?? '') }}" maxlength="30" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="cuit" class="form-label">CUIT</label>
                <input type="text" id="cuit" name="cuit" value="{{ old('cuit', $cliente->cuit ?? '') }}" maxlength="13" class="form-control" placeholder="11 dígitos">
            </div>
        </div>
    </section>

    <section class="gei-card p-4 mb-4">
        <div class="gei-section-title"><div><h2>Roles</h2><p>Funciones que cumple este cliente.</p></div></div>
        <div class="d-flex flex-wrap gap-4">
            @foreach ($rolesDisponibles as $rolDisponible)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $rolDisponible->id }}" id="rol{{ $rolDisponible->id }}" @checked(in_array($rolDisponible->id, $rolesSeleccionados, true))>
                    <label class="form-check-label" for="rol{{ $rolDisponible->id }}">{{ $rolDisponible->nombre }}</label>
                </div>
            @endforeach
        </div>
    </section>

    <section class="gei-card p-4 mb-4">
        <div class="gei-section-title"><div><h2>Domicilio y contacto</h2></div></div>
        <div class="row g-3">
            <div class="col-lg-8"><label for="domicilio" class="form-label">Domicilio</label><input type="text" id="domicilio" name="domicilio" value="{{ old('domicilio', $cliente->domicilio ?? '') }}" maxlength="180" class="form-control"></div>
            <div class="col-md-4"><label for="codigo_postal" class="form-label">Código postal</label><input type="text" id="codigo_postal" name="codigo_postal" value="{{ old('codigo_postal', $cliente->codigo_postal ?? '') }}" maxlength="12" class="form-control"></div>
            <div class="col-md-6"><label for="localidad" class="form-label">Localidad</label><input type="text" id="localidad" name="localidad" value="{{ old('localidad', $cliente->localidad ?? '') }}" maxlength="120" class="form-control"></div>
            <div class="col-md-6"><label for="provincia" class="form-label">Provincia</label><input type="text" id="provincia" name="provincia" value="{{ old('provincia', $cliente->provincia ?? '') }}" maxlength="120" class="form-control"></div>
            <div class="col-md-4"><label for="telefono" class="form-label">Teléfono</label><input type="text" id="telefono" name="telefono" value="{{ old('telefono', $cliente->telefono ?? '') }}" maxlength="100" class="form-control"></div>
            <div class="col-md-4"><label for="telefono_alternativo" class="form-label">Teléfono alternativo</label><input type="text" id="telefono_alternativo" name="telefono_alternativo" value="{{ old('telefono_alternativo', $cliente->telefono_alternativo ?? '') }}" maxlength="100" class="form-control"></div>
            <div class="col-md-4"><label for="email" class="form-label">Correo electrónico</label><input type="email" id="email" name="email" value="{{ old('email', $cliente->email ?? '') }}" maxlength="180" class="form-control"></div>
        </div>
    </section>

    <section class="gei-card p-4 mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label for="condicion_iva" class="form-label">Condición de IVA</label>
                <select id="condicion_iva" name="condicion_iva" class="form-select">
                    <option value="">Sin informar</option>
                    @foreach ($condicionesIva as $codigoCondicion => $nombreCondicion)
                        <option value="{{ $codigoCondicion }}" @selected(old('condicion_iva', $cliente->condicion_iva ?? '') === $codigoCondicion)>{{ $nombreCondicion }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <input type="hidden" name="activo" value="0">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1" @checked((bool) old('activo', $cliente->activo ?? true))>
                    <label class="form-check-label" for="activo">Cliente activo</label>
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex justify-content-end gap-2">
        <a href="{{ $esEdicion ? route('clientes.show', $cliente) : route('clientes.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        <button type="submit" class="btn gei-button gei-button--primary px-4">{{ $esEdicion ? 'Guardar cambios' : 'Crear cliente' }}</button>
    </div>
</form>
