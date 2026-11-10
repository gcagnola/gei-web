{{-- GEI_INMUEBLES_CONTRATOS_V12_20261109: una fila de expediente y sus PDF. --}}
<tr>
    <td><strong>{{ $exp->numero_documental ?: '—' }}</strong></td>
    <td>{{ $formatearCuentaCobol($exp->cuenta_inquilino) }}</td>
    <td>{{ $fecha($exp->fecha_inicio) }}</td>
    <td>{{ $fecha($exp->fecha_fin) }}</td>
    <td>
        @if($exp->fecha_inicio && $exp->fecha_fin && $exp->fecha_inicio <= $hoyInmueble && $exp->fecha_fin >= $hoyInmueble)
            <span class="badge text-bg-success">Vigente</span>
        @else
            <span class="badge text-bg-secondary">Anterior</span>
        @endif
    </td>
    <td class="text-nowrap">
        <a class="btn btn-sm btn-outline-primary px-1" target="_blank" rel="noopener noreferrer"
           href="{{ route('clientes-maestro.contrato-documental.pdf', ['cliente' => $operativo->id, 'documento' => $exp->documento_id, 'sede' => $sedeElegida]) }}">Contrato</a>
        @foreach($documentosComplementariosPorExpediente->get($exp->id, collect()) as $complementario)
            <a class="btn btn-sm btn-outline-secondary px-1" target="_blank" rel="noopener noreferrer"
               href="{{ route('clientes-maestro.contrato-documental.pdf', ['cliente' => $operativo->id, 'documento' => $complementario->id, 'sede' => $sedeElegida]) }}">{{ $complementario->etiqueta }}</a>
        @endforeach
    </td>
</tr>
