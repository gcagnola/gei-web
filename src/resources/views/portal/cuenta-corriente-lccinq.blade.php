@extends('layouts.portal')
@section('title', 'Cuenta corriente')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h3 mb-1">Cuenta corriente</h1><p class="text-muted mb-0">Movimientos operativos del cierre COBOL publicado.</p></div>
</div>
@if($cuentas->isEmpty())
    <div class="alert alert-info">No tenés cuentas COBOL habilitadas para consultar.</div>
@else
    <form method="GET" class="card card-body mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="seleccion" class="form-label">Cuenta</label>
                <select id="seleccion" name="seleccion" class="form-select">
                    @foreach($cuentas as $fila)
                        <option value="{{ $fila['rol'] }}|{{ $fila['cuenta'] }}" @selected($seleccionada && $seleccionada['cuenta']===$fila['cuenta'] && $seleccionada['rol']===$fila['rol'])>
                            {{ $fila['rol'] }} · {{ substr($fila['cuenta'],0,4) }}/{{ substr($fila['cuenta'],4,5) }}/{{ substr($fila['cuenta'],9,2) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3"><button type="submit" class="btn btn-primary w-100">Ver cuenta</button></div>
        </div>
    </form>
    @if($seleccionada && $seleccionada['rol'] === 'INQUILINO')
        @include('cuentas-corrientes.partials.lccinq', ['cuadrosCuentaCobol' => $cuadrosCuentaCobol])
    @endif
@endif
@endsection
