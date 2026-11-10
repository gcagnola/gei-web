{{-- GEI_INMUEBLES_CONTRATOS_V12_20261109: un inmueble físico, varios contratos. --}}
@php
    $hoyInmueble = now('America/Argentina/Cordoba')->toDateString();
    $imprimirContratoDocumento = true;
@endphp
<section class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Inmuebles alquilados</div>
    <div class="card-body py-2 small text-muted">
        Cada inmueble se muestra una sola vez. Los documentos se asocian por inmueble/contrato operativo
        o domicilio documental exacto y único; los demás quedan separados.
    </div>

    @forelse($inmueblesAlquiladosFicha['vigentes'] as $grupo)
        <div class="border-top">
            <div class="px-3 py-2 d-flex flex-wrap justify-content-between gap-2 align-items-center">
                <div><strong>{{ $grupo->domicilio }}</strong>
                    <div class="small text-muted">Inmueble #{{ $grupo->inmueble_id }} · Cuenta(s): {{ $grupo->cuentas->map($formatearCuentaCobol)->implode(' · ') }}
                        @if($grupo->propietarios->isNotEmpty()) · Propietario: {{ $grupo->propietarios->implode(' · ') }} @endif
                    </div>
                </div>
                <span class="badge text-bg-success">Vigente</span>
            </div>
            @if($grupo->vigentesDocumentales->isNotEmpty())
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>N.º contrato</th><th>Cuenta</th><th>Desde</th><th>Hasta</th><th>Estado</th><th>PDF</th></tr></thead>
                    <tbody>
                    @foreach($grupo->vigentesDocumentales as $exp)
                        @include('clientes-maestro.partials.inmuebles-contrato-documental-fila')
                    @endforeach
                    </tbody>
                </table></div>
            @elseif($grupo->operativos->isNotEmpty())
                <div class="px-3 pb-2 small text-muted">Relación operativa vigente. Sin PDF documental vinculado de forma inequívoca a este inmueble.</div>
            @endif
            @if($grupo->anterioresDocumentales->isNotEmpty())
                <details class="px-3 py-2 border-top">
                    <summary class="fw-semibold" style="cursor:pointer">Contratos anteriores del inmueble ({{ $grupo->anterioresDocumentales->count() }})</summary>
                    <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>N.º contrato</th><th>Cuenta</th><th>Desde</th><th>Hasta</th><th>Estado</th><th>PDF</th></tr></thead>
                        <tbody>@foreach($grupo->anterioresDocumentales as $exp)
                            @include('clientes-maestro.partials.inmuebles-contrato-documental-fila')
                        @endforeach</tbody>
                    </table></div>
                </details>
            @endif
            @foreach($grupo->operativos as $contratoOperativo)
                <div class="px-3 py-1 border-top small">
                    <span class="text-muted">Contrato operativo COBOL · desde {{ $fecha($contratoOperativo->fecha_inicio) }} · hasta {{ $fecha($contratoOperativo->fecha_fin) }}:</span>
                    @include('clientes-maestro.partials.ficha-contrato-cobol', ['idContratoFicha' => $contratoOperativo->id, 'contextoFicha' => 'inquilino'])
                </div>
            @endforeach
        </div>
    @empty
        <div class="px-3 py-2 text-muted small">No hay inmuebles con locación vigente vinculada de forma operativa.</div>
    @endforelse

    @if($inmueblesAlquiladosFicha['anteriores']->isNotEmpty())
        <details class="px-3 py-3 border-top">
            <summary class="fw-semibold" style="cursor:pointer">Inmuebles anteriores ({{ $inmueblesAlquiladosFicha['anteriores']->count() }})</summary>
            @foreach($inmueblesAlquiladosFicha['anteriores'] as $grupo)
                <div class="border rounded mt-2 p-2">
                    <strong>{{ $grupo->domicilio }}</strong>
                    <span class="badge text-bg-secondary ms-2">No vigente</span>
                    <div class="small text-muted">Inmueble #{{ $grupo->inmueble_id }} · Cuenta(s): {{ $grupo->cuentas->map($formatearCuentaCobol)->implode(' · ') }}</div>
                    @if($grupo->documentales->isNotEmpty())
                        <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
                            <thead><tr><th>N.º contrato</th><th>Cuenta</th><th>Desde</th><th>Hasta</th><th>Estado</th><th>PDF</th></tr></thead>
                            <tbody>@foreach($grupo->documentales as $exp)
                                @include('clientes-maestro.partials.inmuebles-contrato-documental-fila')
                            @endforeach</tbody>
                        </table></div>
                    @endif
                    @foreach($grupo->operativos as $contratoOperativo)
                        @include('clientes-maestro.partials.ficha-contrato-cobol', ['idContratoFicha' => $contratoOperativo->id, 'contextoFicha' => 'inquilino'])
                    @endforeach
                </div>
            @endforeach
        </details>
    @endif

    @if($inmueblesAlquiladosFicha['sinInmuebleVigentes']->isNotEmpty())
        <div class="px-3 py-3 border-top">
            <strong>Contratos vigentes sin inmueble confirmado ({{ $inmueblesAlquiladosFicha['sinInmuebleVigentes']->count() }})</strong>
            <div class="small text-muted my-2">Cuenta COBOL verificada, pero asociación al inmueble físico pendiente.</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>N.º contrato</th><th>Cuenta</th><th>Desde</th><th>Hasta</th><th>Estado</th><th>PDF</th></tr></thead>
                <tbody>@foreach($inmueblesAlquiladosFicha['sinInmuebleVigentes'] as $exp)
                    @include('clientes-maestro.partials.inmuebles-contrato-documental-fila')
                @endforeach</tbody>
            </table></div>
        </div>
    @endif
    @if($inmueblesAlquiladosFicha['sinInmuebleAnteriores']->isNotEmpty())
        <details class="px-3 py-3 border-top">
            <summary class="fw-semibold" style="cursor:pointer">Contratos anteriores sin inmueble confirmado ({{ $inmueblesAlquiladosFicha['sinInmuebleAnteriores']->count() }})</summary>
            <div class="small text-muted my-2">No se asociaron por nombre, por cuenta sola ni mediante los domicilios de GARANTE.TXT.</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>N.º contrato</th><th>Cuenta</th><th>Desde</th><th>Hasta</th><th>Estado</th><th>PDF</th></tr></thead>
                <tbody>@foreach($inmueblesAlquiladosFicha['sinInmuebleAnteriores'] as $exp)
                    @include('clientes-maestro.partials.inmuebles-contrato-documental-fila')
                @endforeach</tbody>
            </table></div>
        </details>
    @endif
</section>
