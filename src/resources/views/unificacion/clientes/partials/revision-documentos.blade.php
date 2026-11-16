@if (($filas ?? collect())->isEmpty())
    <span class="text-muted small">Sin documentación reciente</span>
@else
    <div class="small">
    @foreach ($filas as $documentoFila)
        <div class="border rounded p-2 mb-2">
            <div class="fw-semibold mb-1">
                {{ substr($documentoFila->periodo, 4, 2) }}/{{ substr($documentoFila->periodo, 0, 4) }}
                · {{ $formatearCuentaCobol($documentoFila->cuenta) }}
            </div>
            @foreach ($documentoFila->inmuebles as $inmuebleDoc)
                <div class="text-muted">{{ $inmuebleDoc->domicilio }} ({{ $inmuebleDoc->relacion }})</div>
            @endforeach
            <div class="d-flex flex-wrap gap-1 mt-1">
                @foreach ($documentoFila->liquidaciones as $liquidacion)
                    @if ($liquidacion->pdf_disponible ?? false)
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('propietarios.liquidaciones.ver', $liquidacion->id) }}"
                           target="_blank" rel="noopener">Liquidación</a>
                    @endif
                    @if ($liquidacion->impuestos_pdf_disponible ?? false)
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('propietarios.liquidaciones.impuestos.ver', $liquidacion->id) }}"
                           target="_blank" rel="noopener">Imp. garantizados</a>
                    @endif
                @endforeach
                @foreach ($documentoFila->comprobantes_arca as $arca)
                    <a class="btn btn-sm btn-outline-primary"
                       href="{{ route('comprobantes-arca.ver', ['periodo' => $documentoFila->periodo, 'archivo' => $arca->nombre_archivo]) }}"
                       target="_blank" rel="noopener">ARCA: {{ pathinfo($arca->nombre_archivo, PATHINFO_FILENAME) }}</a>
                @endforeach
            </div>
        </div>
    @endforeach
    </div>
@endif
