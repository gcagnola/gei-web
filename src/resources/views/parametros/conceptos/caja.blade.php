@extends('layouts.app')

@section('title', 'Imputación a Caja')
@section('page-title', 'Conceptos')

@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Imputación a Caja</h1>
            <p class="text-muted mb-0">
                {{ $concepto->dominio === 'INQ' ? 'Inquilino' : 'Propietario' }}
                · Código {{ $concepto->codigo }}
                · {{ $concepto->descripcion }}
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('parametros.conceptos.index', ['dominio' => $concepto->dominio]) }}">Volver</a>
    </div>

    @if (session('ok'))
        <div class="alert alert-success">{{ session('ok') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Revisá las cuentas ingresadas.</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="gei-card p-3 p-lg-4">
        <div class="mb-4">
            <h2 class="h5 mb-1">Cuentas de Caja</h2>
            <p class="text-muted mb-0">
                Equivalencias según sede, moneda y circuito. Las cuentas provienen del plan de cuentas SCCUENT.
            </p>
        </div>

        <form method="POST" action="{{ route('parametros.conceptos.caja.update', $concepto) }}">
            @csrf
            @method('PUT')

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Sede</th>
                            <th style="width: 150px;">Circuito</th>
                            <th style="width: 120px;">Moneda</th>
                            <th style="width: 150px;">Cuenta</th>
                            <th>Plan de cuentas</th>
                            <th style="width: 120px;">Origen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($filas as $fila)
                            @php
                                $clave = strtolower($fila['sede'].'_'.$fila['moneda'].'_'.($fila['judicial'] ? 'J' : 'N'));
                                $valorBase = trim((string) ($fila['cuenta_caja_codigo'] ?? ''));
                                $valorBase = $valorBase === '0000' ? '' : $valorBase;
                                $valor = old('cuentas.'.$clave, $valorBase);
                                $valor = trim((string) $valor) === '0000' ? '' : $valor;
                            @endphp
                            <tr>
                                <td>{{ $fila['sede'] === 'SF' ? 'Santa Fe' : 'Santo Tomé' }}</td>
                                <td>{{ $fila['judicial'] ? 'Judicial' : 'Normal' }}</td>
                                <td>{{ $fila['moneda'] === 'ARS' ? 'Pesos' : 'Dólares' }}</td>
                                <td>
                                    <input
                                        class="form-control"
                                        name="cuentas[{{ $clave }}]"
                                        value="{{ $valor }}"
                                        maxlength="4"
                                        inputmode="numeric"
                                        pattern="[0-9]{4}"
                                        placeholder=""
                                    >
                                </td>
                                <td>
                                    @if ($fila['cuenta_caja_nombre'] || $fila['cuenta_caja_subcuenta'])
                                        <div>{{ $fila['cuenta_caja_nombre'] ?: '—' }}</div>
                                        @if ($fila['cuenta_caja_subcuenta'])
                                            <div class="small text-muted">{{ $fila['cuenta_caja_subcuenta'] }}</div>
                                        @endif
                                        @if ($fila['numero_contable'])
                                            <div class="small text-muted">Contable: {{ $fila['numero_contable'] }}</div>
                                        @endif
                                    @elseif ($valor)
                                        <span class="text-danger">Cuenta no vinculada al plan SCCUENT</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $fila['origen_cobol'] ?: ($valor ? 'Manual' : '—') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-flex gap-2 justify-content-end mt-4">
                <a class="btn btn-outline-secondary" href="{{ route('parametros.conceptos.index', ['dominio' => $concepto->dominio]) }}">Cancelar</a>
                <button class="btn btn-primary" type="submit">Guardar</button>
            </div>
        </form>
    </div>
</div>
@endsection
