{{-- Vista unificada LCCINQ para inquilinos; propietarios conservan el saldo contable original. --}}
@if(!empty($cuadrosCuentaCobol))
    @include('cuentas-corrientes.partials.lccinq', ['cuadrosCuentaCobol' => $cuadrosCuentaCobol])
@endif
@if(($resumenSaldosCuentas ?? collect())->contains('rol', 'PROPIETARIO'))
    @include('clientes-maestro.partials.saldos-e-intereses-antes-lccinq', [
        'resumenSaldosCuentas' => $resumenSaldosCuentas->where('rol', 'PROPIETARIO')
    ])
@endif
