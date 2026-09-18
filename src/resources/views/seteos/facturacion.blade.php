@extends('layouts.app')

@section('title', 'Seteos de Facturación')
@section('page-title', 'Seteos de Facturación')

@section('content')
<div class="container-fluid py-3">

    <div class="mb-4">
        <h1 class="h3 mb-1">Seteos de Facturación</h1>
        <div class="text-muted">
            Parámetros generales, puntos de venta,
            numeración fiscal y alícuotas.
        </div>
    </div>

    @if(session('ok'))
        <div class="alert alert-success">
            {{ session('ok') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>No se guardaron los cambios.</strong>

            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif


    {{-- CONFIGURACION GENERAL --}}
    <div class="card shadow-sm mb-4">

        <div class="card-header">
            <strong>Configuración general</strong>
        </div>

        <div class="card-body">

            <form
                method="POST"
                action="{{ route('seteos.facturacion.general.update') }}"
            >
                @csrf
                @method('PUT')

                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label">
                            Próximo número de lote
                        </label>

                        <input
                            class="form-control"
                            type="number"
                            min="1"
                            name="proximo_numero_lote"
                            value="{{ old(
                                'proximo_numero_lote',
                                $configuracion?->proximo_numero_lote
                            ) }}"
                            required
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            Tope no gravado
                        </label>

                        <input
                            class="form-control"
                            type="number"
                            step="0.01"
                            min="0"
                            name="tope_no_gravado"
                            value="{{ old(
                                'tope_no_gravado',
                                $configuracion?->tope_no_gravado
                            ) }}"
                            required
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            IVA general (%)
                        </label>

                        <input
                            class="form-control"
                            type="number"
                            step="0.001"
                            min="0"
                            max="100"
                            name="alicuota_iva_general"
                            value="{{ old(
                                'alicuota_iva_general',
                                $configuracion?->alicuota_iva_general
                            ) }}"
                            required
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            Decimales de redondeo
                        </label>

                        <input
                            class="form-control"
                            type="number"
                            min="0"
                            max="6"
                            name="decimales_redondeo"
                            value="{{ old(
                                'decimales_redondeo',
                                $configuracion?->decimales_redondeo
                            ) }}"
                            required
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            Modo de emisión
                        </label>
			<select class="form-select" name="modo_emision" required>
			    <option value="RECE"
			        @selected(old('modo_emision', $configuracion?->modo_emision) === 'RECE')>
			        RECE
			    </option>

			    <option value="WSFE"
			       @selected(old('modo_emision', $configuracion?->modo_emision) === 'WSFE')>
			       WSFE
			    </option>
			</select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            Ambiente ARCA
                        </label>
			<select class="form-select" name="ambiente_arca" required>
			    <option value="HOMOLOGACION"
                                @selected(old('ambiente_arca', $configuracion?->ambiente_arca) === 'HOMOLOGACION')>
			        Homologación
			    </option>

			    <option value="PRODUCCION"
			        @selected(old('ambiente_arca', $configuracion?->ambiente_arca) === 'PRODUCCION')>
			        Producción
			    </option>
			</select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            CUIT emisor
                        </label>

                        <input
                            class="form-control"
			    value="{{ $configuracion?->cuit_emisor ?: 'No configurado' }}"
                            readonly
                            disabled
                        >

			<div class="form-text">
			       Dato fiscal maestro. Se administra directamente en base de datos.
			</div>
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check mb-2">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="emitir_nc_locadores"
                                name="emitir_nc_locadores"
                                value="1"
                                @checked(old(
                                    'emitir_nc_locadores',
                                    $configuracion?->emitir_nc_locadores
                                ))
                            >

                            <label
                                class="form-check-label"
                                for="emitir_nc_locadores"
                            >
                                Emitir NC de locadores
                            </label>

                        </div>
                    </div>

                </div>

                <div class="mt-3">
                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        Guardar configuración
                    </button>
                </div>

            </form>

        </div>
    </div>


    {{-- PUNTOS DE VENTA --}}
    <div class="card shadow-sm mb-4">

        <div class="card-header">
            <strong>Puntos de venta</strong>
        </div>

        <div class="card-body">

            @forelse($puntosVenta as $pv)

                <form
                    method="POST"
                    action="{{ route(
                        'seteos.facturacion.puntos-venta.update',
                        $pv->id_punto_venta
                    ) }}"
                    class="border rounded p-3 mb-3"
                >
                    @csrf
                    @method('PUT')

                    <div class="row g-2 align-items-end">

                        <div class="col-md-1">
                            <label class="form-label">PV</label>

                            <input
                                class="form-control"
                                value="{{ $pv->numero }}"
                                disabled
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">
                                Nombre
                            </label>

                            <input
                                class="form-control"
                                name="nombre"
                                value="{{ $pv->nombre }}"
                                required
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">
                                Localidad
                            </label>

                            <input
                                class="form-control"
                                name="localidad"
                                value="{{ $pv->localidad }}"
                            >
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">
                                Modalidad
                            </label>

                            <input
                                class="form-control"
                                name="modalidad"
                                value="{{ $pv->modalidad }}"
                            >
                        </div>

                        <div class="col-md-1">

                            <div class="form-check mb-2">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="activo"
                                    value="1"
                                    id="pv-{{ $pv->id_punto_venta }}"
                                    @checked($pv->activo)
                                >

                                <label
                                    class="form-check-label"
                                    for="pv-{{ $pv->id_punto_venta }}"
                                >
                                    Activo
                                </label>

                            </div>

                        </div>

                        <div class="col-md-1">

                            @if($pv->historico)

                                <span class="badge text-bg-secondary mb-2">
                                    Histórico
                                </span>

                            @else

                                <span class="badge text-bg-success mb-2">
                                    Operativo
                                </span>

                            @endif

                        </div>

                        <div class="col-md-1 text-end">

                            <button
                                class="btn btn-outline-primary"
                                type="submit"
                            >
                                Guardar
                            </button>

                        </div>

                    </div>

                </form>

            @empty

                <div class="text-muted">
                    No hay puntos de venta configurados.
                </div>

            @endforelse

        </div>
    </div>


    {{-- NUMERACION --}}
    <div class="card shadow-sm mb-4">

        <div
            class="card-header
                   d-flex
                   justify-content-between
                   align-items-center"
        >
            <strong>Numeraciones de comprobantes</strong>

            <span class="badge text-bg-warning">
                Sólo lectura
            </span>
        </div>

        <div class="card-body pb-2">

            <div class="alert alert-warning mb-3">

                La numeración fiscal no se modifica
                manualmente desde esta pantalla.

                El motor de facturación deberá
                sincronizarla con ARCA.

            </div>

        </div>

        <div class="table-responsive">

            <table class="table table-sm align-middle mb-0">

                <thead>
                    <tr>
                        <th>PV</th>
                        <th>Tipo</th>
                        <th class="text-end">Próximo</th>
                        <th class="text-end">Último ARCA</th>
                        <th>Sincronización</th>
                        <th>Estado</th>
                    </tr>
                </thead>

                <tbody>

                    @forelse($numeraciones as $n)

                        <tr>

                            <td>
                                {{ $n->punto_venta_numero }}
                                —
                                {{ $n->punto_venta_nombre }}
                            </td>

                            <td>
                                {{ $n->tipo_comprobante }}
                            </td>

                            <td class="text-end">
                                {{ number_format(
                                    $n->proximo_numero,
                                    0,
                                    ',',
                                    '.'
                                ) }}
                            </td>

                            <td class="text-end">

                                @if($n->ultimo_numero_arca !== null)

                                    {{ number_format(
                                        $n->ultimo_numero_arca,
                                        0,
                                        ',',
                                        '.'
                                    ) }}

                                @else
                                    —
                                @endif

                            </td>

                            <td>
                                {{ $n->ultima_sincronizacion_arca_at
                                    ? \Illuminate\Support\Carbon::parse(
                                        $n->ultima_sincronizacion_arca_at
                                      )->format('d/m/Y H:i')
                                    : '—'
                                }}
                            </td>

                            <td>

                                <span
                                    class="badge {{
                                        $n->activo
                                            ? 'text-bg-success'
                                            : 'text-bg-secondary'
                                    }}"
                                >
                                    {{
                                        $n->activo
                                            ? 'Activa'
                                            : 'Inactiva'
                                    }}
                                </span>

                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td
                                colspan="6"
                                class="text-center text-muted py-4"
                            >
                                No hay numeraciones configuradas.
                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>
    </div>


    {{-- ALICUOTAS --}}
    <div class="card shadow-sm">

        <div class="card-header">
            <strong>Alícuotas IVA</strong>
        </div>

        <div class="card-body">

            @forelse($alicuotas as $alicuota)

                <form
                    method="POST"
                    action="{{ route(
                        'seteos.facturacion.alicuotas.update',
                        $alicuota->id_alicuota_iva
                    ) }}"
                    class="border rounded p-3 mb-3"
                >
                    @csrf
                    @method('PUT')

                    <div class="row g-2 align-items-end">

                        <div class="col-md-2">
                            <label class="form-label">
                                Código
                            </label>

                            <input
                                class="form-control"
                                value="{{ $alicuota->codigo }}"
                                disabled
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                Nombre
                            </label>

                            <input
                                class="form-control"
                                name="nombre"
                                value="{{ $alicuota->nombre }}"
                                required
                            >
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">
                                Porcentaje
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                step="0.001"
                                min="0"
                                max="100"
                                name="porcentaje"
                                value="{{ $alicuota->porcentaje }}"
                                required
                            >
                        </div>

                        <div class="col-md-2">

                            <div class="form-check mb-2">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="activo"
                                    value="1"
                                    id="iva-{{ $alicuota->id_alicuota_iva }}"
                                    @checked($alicuota->activo)
                                >

                                <label
                                    class="form-check-label"
                                    for="iva-{{ $alicuota->id_alicuota_iva }}"
                                >
                                    Activa
                                </label>

                            </div>

                        </div>

                        <div class="col-md-2 text-end">

                            <button
                                class="btn btn-outline-primary"
                                type="submit"
                            >
                                Guardar
                            </button>

                        </div>

                    </div>

                </form>

            @empty

                <div class="text-muted">
                    No hay alícuotas configuradas.
                </div>

            @endforelse

        </div>
    </div>

</div>
@endsection
