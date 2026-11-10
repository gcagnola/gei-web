{{-- GEI_PDF_COMPLEMENTARIOS_20261107 --}}
{{-- GEI_CONTRATOS_DOCUMENTALES_V2_20261107: contratos verificados, vigentes primero. --}}
<section class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Contratos como inquilino</div>
    <div class="card-body pb-2 small text-muted">
        @if($hayContratosDocumentalesVigentes)
            Contrato actual con documentación verificada. Fechas del expediente documental.
        @else
            Último contrato documental registrado; no se identificó uno vigente a la fecha.
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>N.º contrato</th><th>Cuenta</th><th>Inmueble</th><th>Desde</th><th>Hasta</th><th>Estado</th><th>PDF</th></tr>
            </thead>
            <tbody>
                @foreach($contratosDocumentalesVigentes as $documental)
                    <tr>
                        <td><strong>{{ $documental->numero_documental ?: '—' }}</strong></td>
                        <td>{{ $formatearCuentaCobol($documental->cuenta_inquilino) }}</td>
                        <td>{{ $v($documental->inmueble_documental) }}</td>
                        <td>{{ $fecha($documental->fecha_inicio) }}</td>
                        <td>{{ $fecha($documental->fecha_fin) }}</td>
                        <td>
                            @if($hayContratosDocumentalesVigentes)
                                <span class="badge text-bg-success">Vigente</span>
                            @else
                                <span class="badge text-bg-secondary">Sin vigencia actual</span>
                            @endif
                        </td>
                        <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary px-1" target="_blank" rel="noopener noreferrer"
                            href="{{ route('clientes-maestro.contrato-documental.pdf', ['cliente' => $operativo->id, 'documento' => $documental->documento_id, 'sede' => $sedeElegida]) }}">Contrato</a>
                                    @if($documentosComplementariosPorExpediente->has($documental->id))
                                        <div class="d-inline-flex flex-nowrap align-items-center gap-1 ms-1">
                                            @foreach($documentosComplementariosPorExpediente->get($documental->id) as $complementario)
                                                <a class="btn btn-sm btn-outline-secondary px-1" target="_blank" rel="noopener noreferrer"
                                                   href="{{ route('clientes-maestro.contrato-documental.pdf', ['cliente' => $operativo->id, 'documento' => $complementario->id, 'sede' => $sedeElegida]) }}">{{ $complementario->etiqueta }}</a>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                    </tr>
                    @if($documental->contrato_id)
                        <tr>
                            <td colspan="7" class="bg-light">
                                @include('clientes-maestro.partials.ficha-contrato-cobol', ['idContratoFicha' => $documental->contrato_id, 'contextoFicha' => 'inquilino'])
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
    @if($contratosDocumentalesAnteriores->isNotEmpty())
        <div class="card-body pt-2">
            <details>
                <summary class="fw-semibold" style="cursor:pointer">Mostrar contratos anteriores ({{ $contratosDocumentalesAnteriores->count() }})</summary>
                <div class="table-responsive mt-2">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>N.º</th><th>Cuenta</th><th>Inmueble</th><th>Desde</th><th>Hasta</th><th>PDF</th></tr></thead>
                        <tbody>
                            @foreach($contratosDocumentalesAnteriores as $anterior)
                                <tr>
                                    <td>{{ $anterior->numero_documental ?: '—' }}</td>
                                    <td>{{ $formatearCuentaCobol($anterior->cuenta_inquilino) }}</td>
                                    <td>{{ $v($anterior->inmueble_documental) }}</td>
                                    <td>{{ $fecha($anterior->fecha_inicio) }}</td>
                                    <td>{{ $fecha($anterior->fecha_fin) }}</td>
                                    <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary px-1" target="_blank" rel="noopener noreferrer"
                                        href="{{ route('clientes-maestro.contrato-documental.pdf', ['cliente' => $operativo->id, 'documento' => $anterior->documento_id, 'sede' => $sedeElegida]) }}">Contrato</a>
                                    @if($documentosComplementariosPorExpediente->has($anterior->id))
                                        <div class="d-inline-flex flex-nowrap align-items-center gap-1 ms-1">
                                            @foreach($documentosComplementariosPorExpediente->get($anterior->id) as $complementario)
                                                <a class="btn btn-sm btn-outline-secondary px-1" target="_blank" rel="noopener noreferrer"
                                                   href="{{ route('clientes-maestro.contrato-documental.pdf', ['cliente' => $operativo->id, 'documento' => $complementario->id, 'sede' => $sedeElegida]) }}">{{ $complementario->etiqueta }}</a>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    @endif
</section>
