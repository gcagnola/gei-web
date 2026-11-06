{{-- Conservar el libro cronologico original para propietarios. Para inquilinos ya aparece LCCINQ. --}}
@if(($movimientosOperativos ?? collect())->contains(static fn ($m): bool => (string) ($m->tipo ?? '') === 'PROPIETARIO'))
    @include('clientes-maestro.partials.movimientos-operativos-antes-lccinq', [
        'movimientosOperativos' => $movimientosOperativos->filter(static fn ($m): bool => (string) ($m->tipo ?? '') === 'PROPIETARIO'),
        'resumenesOperativos' => ($resumenesOperativos ?? collect())->where('rol', 'PROPIETARIO')
    ])
@endif
