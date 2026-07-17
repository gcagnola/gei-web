@php
    $esEdicion = isset($cliente);
    $personeria = old('personeria', $cliente->personeria ?? 'Física');
    $provincia = old('provincia', $cliente->provincia ?? 'Santa Fe');
    $localidad = old('localidad', $cliente->localidad ?? 'Santa Fe');
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

<form
    method="POST"
    action="{{ $esEdicion ? route('clientes.update', $cliente) : route('clientes.store') }}"
    class="gei-client-form"
    data-client-form
>
    @csrf
    @if ($esEdicion)
        @method('PUT')
    @endif

    <section class="gei-card p-4 mb-4">
        <div class="gei-section-title">
            <div>
                <h2>Identificación</h2>
                <p>Datos principales y documentación del cliente.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label for="personeria" class="form-label">Personería *</label>
                <select id="personeria" name="personeria" class="form-select" required>
                    <option value="Física" @selected($personeria === 'Física')>Física</option>
                    <option value="Jurídica" @selected($personeria === 'Jurídica')>Jurídica</option>
                </select>
            </div>

            <div class="col-md-4" data-persona-fisica>
                <label for="apellidos" class="form-label">Apellidos *</label>
                <input
                    type="text"
                    id="apellidos"
                    name="apellidos"
                    value="{{ old('apellidos', $cliente->apellidos ?? '') }}"
                    maxlength="40"
                    class="form-control"
                >
            </div>

            <div class="col-md-4" data-persona-fisica>
                <label for="nombres" class="form-label">Nombres *</label>
                <input
                    type="text"
                    id="nombres"
                    name="nombres"
                    value="{{ old('nombres', $cliente->nombres ?? '') }}"
                    maxlength="80"
                    class="form-control"
                >
            </div>

            <div class="col-md-8" data-persona-juridica>
                <label for="razon_social" class="form-label">Razón social *</label>
                <input
                    type="text"
                    id="razon_social"
                    name="razon_social"
                    value="{{ old('razon_social', $cliente->razon_social ?? '') }}"
                    maxlength="100"
                    class="form-control"
                >
            </div>

            <div class="col-md-4" data-documento-personal>
                <label for="doctipo" class="form-label">Tipo de documento *</label>
                <select id="doctipo" name="doctipo" class="form-select">
                    @foreach (['DNI', 'LC', 'LE'] as $tipo)
                        <option value="{{ $tipo }}" @selected(old('doctipo', trim((string) ($cliente->doctipo ?? 'DNI'))) === $tipo)>
                            {{ $tipo }}
                        </option>
                    @endforeach
                    @if ($esEdicion && trim((string) $cliente->doctipo) === 'CUIT')
                        <option value="CUIT" selected>CUIT</option>
                    @endif
                </select>
            </div>

            <div class="col-md-4" data-documento-personal>
                <label for="docnro" class="form-label">Número de documento *</label>
                <input
                    type="text"
                    id="docnro"
                    name="docnro"
                    value="{{ old('docnro', $cliente->docnro ?? '') }}"
                    maxlength="12"
                    inputmode="numeric"
                    class="form-control"
                >
            </div>

            <div class="col-md-4">
                <label for="cuit" class="form-label">CUIT <span data-cuit-required>*</span></label>
                <input
                    type="text"
                    id="cuit"
                    name="cuit"
                    value="{{ old('cuit', $cliente->cuit ?? '') }}"
                    maxlength="13"
                    inputmode="numeric"
                    placeholder="XX-XXXXXXXX-X"
                    class="form-control"
                >
            </div>
        </div>
    </section>

    <section class="gei-card p-4 mb-4">
        <div class="gei-section-title">
            <div>
                <h2>Domicilio y contacto</h2>
                <p>Ubicación y medios de comunicación.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-8">
                <label for="domicilio" class="form-label">Domicilio</label>
                <input type="text" id="domicilio" name="domicilio" value="{{ old('domicilio', $cliente->domicilio ?? '') }}" maxlength="100" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="provincia" class="form-label">Provincia *</label>
                <select
                    id="provincia"
                    name="provincia"
                    class="form-select"
                    data-localidades-url="{{ route('clientes.localidades') }}"
                    required
                >
                    @foreach ($provincias as $nombreProvincia)
                        <option value="{{ $nombreProvincia }}" @selected($provincia === $nombreProvincia)>
                            {{ $nombreProvincia }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="localidad" class="form-label">Localidad *</label>
                <select id="localidad" name="localidad" class="form-select" data-selected="{{ $localidad }}" required>
                    <option value="{{ $localidad }}">{{ $localidad }}</option>
                </select>
            </div>
            <div class="col-md-4">
                <label for="departamento" class="form-label">Departamento</label>
                <input type="text" id="departamento" name="departamento" value="{{ old('departamento', $cliente->departamento ?? '') }}" maxlength="30" class="form-control">
            </div>
            <div class="col-md-2">
                <label for="caractel" class="form-label">Característica</label>
                <input type="text" id="caractel" name="caractel" value="{{ old('caractel', $cliente->caractel ?? '') }}" maxlength="6" class="form-control">
            </div>
            <div class="col-md-2">
                <label for="cp" class="form-label">Código postal</label>
                <input type="text" id="cp" name="cp" value="{{ old('cp', $cliente->cp ?? '') }}" maxlength="8" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="telefonos" class="form-label">Teléfonos</label>
                <input type="text" id="telefonos" name="telefonos" value="{{ old('telefonos', $cliente->telefonos ?? '') }}" maxlength="50" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="celular" class="form-label">Celular</label>
                <input type="text" id="celular" name="celular" value="{{ old('celular', $cliente->celular ?? '') }}" maxlength="25" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="fax" class="form-label">Fax</label>
                <input type="text" id="fax" name="fax" value="{{ old('fax', $cliente->fax ?? '') }}" maxlength="25" class="form-control">
            </div>
            <div class="col-md-8">
                <label for="email" class="form-label">Correo electrónico</label>
                <input type="email" id="email" name="email" value="{{ old('email', $cliente->email ?? '') }}" class="form-control">
            </div>
        </div>
    </section>

    <section class="gei-card p-4 mb-4">
        <div class="gei-section-title">
            <div>
                <h2>Información fiscal y laboral</h2>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label for="condicion_iva" class="form-label">Condición de IVA *</label>
                <select id="condicion_iva" name="condicion_iva" class="form-select" required>
                    @foreach ($condicionesIva as $condicion)
                        <option value="{{ $condicion }}" @selected(old('condicion_iva', $cliente->condicion_iva ?? 'Consumidor Final') === $condicion)>
                            {{ $condicion }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="nacionalidad" class="form-label">Nacionalidad *</label>
                <input type="text" id="nacionalidad" name="nacionalidad" value="{{ old('nacionalidad', $cliente->nacionalidad ?? 'Argentina') }}" maxlength="40" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label for="profesion" class="form-label">Profesión</label>
                <input type="text" id="profesion" name="profesion" value="{{ old('profesion', $cliente->profesion ?? '') }}" maxlength="100" class="form-control">
            </div>
            <div class="col-md-6">
                <label for="lugar_de_trabajo" class="form-label">Lugar de trabajo</label>
                <input type="text" id="lugar_de_trabajo" name="lugar_de_trabajo" value="{{ old('lugar_de_trabajo', $cliente->lugar_de_trabajo ?? '') }}" maxlength="100" class="form-control">
            </div>
        </div>
    </section>

    <div class="d-flex flex-wrap justify-content-end gap-2">
        <a href="{{ $esEdicion ? route('clientes.show', $cliente) : route('clientes.index') }}" class="btn btn-outline-secondary">
            Cancelar
        </a>
        <button type="submit" class="btn gei-button gei-button--primary px-4">
            {{ $esEdicion ? 'Guardar cambios' : 'Crear cliente' }}
        </button>
    </div>
</form>
