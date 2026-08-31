@extends('layouts.app')

@section('title', 'Comparar clientes')
@section('page-title', 'Unificación de clientes')

@section('content')
@php
    $p = $principal['cliente'];
    $s = $secundario['cliente'];
    $fecha = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '—';
@endphp
<div class="container-fluid py-3 pb-5">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div><h1 class="h3 mb-1">Comparar clientes</h1><p class="text-muted mb-0">La columna izquierda permanece. La derecha será absorbida.</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('archivo.unificacion.clientes.index') }}">Volver</a>
    </div>

    @if ($errors->any())<div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    <div class="alert alert-warning">La operación modifica relaciones dentro de una transacción. El cliente absorbido se conserva, queda inactivo y apunta al cliente canónico. Las cuentas y orígenes COBOL no se borran.</div>
    <div class="alert alert-info"><strong>Datos maestros:</strong> el registro principal conserva sus valores. Sólo se completan campos que estén vacíos en el principal y tengan valor en el absorbido. Si ambos tienen valores distintos, se muestran abajo y no se sobrescriben silenciosamente.</div>

    <div class="row g-3 mb-3">
        @foreach ([['x'=>$p,'titulo'=>'CLIENTE QUE QUEDA','clase'=>'success'], ['x'=>$s,'titulo'=>'CLIENTE A ABSORBER','clase'=>'danger']] as $lado)
            <div class="col-lg-6"><div class="card border-{{ $lado['clase'] }} h-100">
                <div class="card-header text-bg-{{ $lado['clase'] }} bg-opacity-10 fw-semibold">{{ $lado['titulo'] }}</div>
                <div class="card-body"><h2 class="h4">#{{ $lado['x']->id }} — {{ $lado['x']->nombre }}</h2>
                    <dl class="row small mb-0">
                        <dt class="col-4">Tipo persona</dt><dd class="col-8">{{ $lado['x']->tipo_persona ?: '—' }}</dd>
                        <dt class="col-4">Documento</dt><dd class="col-8">{{ trim(($lado['x']->tipo_documento ?: '').' '.($lado['x']->numero_documento ?: '')) ?: '—' }}</dd>
                        <dt class="col-4">CUIT</dt><dd class="col-8">{{ $lado['x']->cuit ?: '—' }}</dd>
                        <dt class="col-4">IVA</dt><dd class="col-8">{{ $lado['x']->condicion_iva ?: '—' }}</dd>
                        <dt class="col-4">Domicilio</dt><dd class="col-8">{{ $lado['x']->domicilio ?: '—' }}</dd>
                        <dt class="col-4">Localidad</dt><dd class="col-8">{{ $lado['x']->localidad ?: '—' }}</dd>
                        <dt class="col-4">Teléfono</dt><dd class="col-8">{{ $lado['x']->telefono ?: '—' }} @if($lado['x']->telefono_alternativo) · {{ $lado['x']->telefono_alternativo }} @endif</dd>
                        <dt class="col-4">Email</dt><dd class="col-8">{{ $lado['x']->email ?: '—' }}</dd>
                        <dt class="col-4">Activo BD</dt><dd class="col-8">{{ $lado['x']->activo === null ? 'Sin informar' : ($lado['x']->activo ? 'Sí' : 'No') }}</dd>
                    </dl>
                </div>
            </div></div>
        @endforeach
    </div>

    <div class="card mb-3"><div class="card-header fw-semibold">Resumen de la operación</div><div class="card-body">
        <div class="row g-2 text-center">
            @foreach ([
                'Orígenes'=>$plan['resumen']['origenes'], 'Cuentas'=>$plan['resumen']['cuentas'], 'Roles'=>$plan['resumen']['roles'],
                'Inmuebles'=>$plan['resumen']['inmuebles'], 'Contratos'=>$plan['resumen']['contratos'], 'Ctas. corrientes'=>$plan['resumen']['cuentas_corrientes'],
                'Liquidaciones'=>$plan['resumen']['liquidaciones'], 'Envíos'=>$plan['resumen']['envios'], 'Repartos'=>$plan['resumen']['repartos']
            ] as $et=>$n)<div class="col-6 col-md-4 col-xl"><div class="border rounded p-2"><strong class="d-block h5 mb-0">{{ $n }}</strong><small class="text-muted">{{ $et }}</small></div></div>@endforeach
        </div>
        @if ($plan['campos_completar'] !== [])
            <div class="alert alert-success mt-3 mb-0"><strong>Se completarán campos vacíos del principal:</strong> {{ implode(', ', array_keys($plan['campos_completar'])) }}.</div>
        @endif
        @if ($plan['diferencias_maestras'] !== [])
            <div class="alert alert-warning mt-3 mb-0"><strong>Diferencias de datos maestros:</strong> se conservará el valor del cliente principal. Revise estas diferencias antes de confirmar.</div>
            <div class="table-responsive mt-2"><table class="table table-sm"><thead><tr><th>Campo</th><th>Principal</th><th>Absorbido</th></tr></thead><tbody>
            @foreach ($plan['diferencias_maestras'] as $campo=>$dif)<tr><td>{{ $campo }}</td><td>{{ $dif['principal'] }}</td><td>{{ $dif['secundario'] }}</td></tr>@endforeach
            </tbody></table></div>
        @endif
        @if ($plan['bloqueos'] !== [])
            <div class="alert alert-danger mt-3 mb-0"><strong>No se puede confirmar todavía:</strong><ul class="mb-0">@foreach ($plan['bloqueos'] as $b)<li>{{ $b }}</li>@endforeach</ul></div>
        @endif
    </div></div>

    @php
        $secciones = [
            ['titulo'=>'Roles','a'=>$principal['roles'],'b'=>$secundario['roles'],'cols'=>['codigo'=>'Código','nombre'=>'Rol']],
            ['titulo'=>'Cuentas COBOL','a'=>$principal['cuentas'],'b'=>$secundario['cuentas'],'cols'=>['cuenta'=>'Cuenta','rol'=>'Rol','activo'=>'Activo']],
            ['titulo'=>'Orígenes COBOL','a'=>$principal['origenes'],'b'=>$secundario['origenes'],'cols'=>['entidad_origen'=>'Entidad','clave_origen'=>'Clave','estado_origen'=>'Estado']],
        ];
    @endphp
    @foreach ($secciones as $sec)
        <div class="card mb-3"><div class="card-header fw-semibold">{{ $sec['titulo'] }}</div><div class="row g-0">
            @foreach ([['t'=>'Principal','r'=>$sec['a']], ['t'=>'A absorber','r'=>$sec['b']]] as $lado)
                <div class="col-lg-6 border-end"><div class="px-2 py-1 bg-light small fw-semibold">{{ $lado['t'] }} ({{ $lado['r']->count() }})</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>@foreach($sec['cols'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead><tbody>
                @forelse($lado['r'] as $r)<tr>@foreach($sec['cols'] as $campo=>$h)<td>@if($campo==='activo'){{ $r->{$campo} === null ? '—' : ($r->{$campo} ? 'Sí' : 'No') }}@else{{ $r->{$campo} ?? '—' }}@endif</td>@endforeach</tr>@empty<tr><td colspan="{{ count($sec['cols']) }}" class="text-muted text-center">Sin registros</td></tr>@endforelse
                </tbody></table></div></div>
            @endforeach
        </div></div>
    @endforeach

    <div class="card mb-3"><div class="card-header fw-semibold">Inmuebles como propietario</div><div class="row g-0">
        @foreach ([['t'=>'Principal','r'=>$principal['inmuebles']],['t'=>'A absorber','r'=>$secundario['inmuebles']]] as $lado)
            <div class="col-lg-6 border-end"><div class="px-2 py-1 bg-light small fw-semibold">{{ $lado['t'] }} ({{ $lado['r']->count() }})</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>ID inmueble</th><th>Domicilio</th><th>Cuenta</th><th>%</th><th>Vigencia</th><th>Activo</th></tr></thead><tbody>
            @forelse($lado['r'] as $r)<tr><td>{{ $r->inmueble_id }}</td><td>{{ $r->domicilio }}</td><td>{{ $r->cuenta }}</td><td>{{ $r->porcentaje ?? '—' }}</td><td>{{ $fecha($r->vigencia_desde) }} → {{ $fecha($r->vigencia_hasta) }}</td><td>{{ $r->activo ? 'Sí' : 'No' }}</td></tr>@empty<tr><td colspan="6" class="text-muted text-center">Sin registros</td></tr>@endforelse
            </tbody></table></div></div>
        @endforeach
    </div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">Contratos como inquilino</div><div class="row g-0">
        @foreach ([['t'=>'Principal','r'=>$principal['contratos']],['t'=>'A absorber','r'=>$secundario['contratos']]] as $lado)
            <div class="col-lg-6 border-end"><div class="px-2 py-1 bg-light small fw-semibold">{{ $lado['t'] }} ({{ $lado['r']->count() }})</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Contrato</th><th>Cuenta</th><th>Inicio</th><th>Fin</th><th>Estado</th><th>Rol</th></tr></thead><tbody>
            @forelse($lado['r'] as $r)<tr><td>{{ $r->contrato_id }}</td><td>{{ $r->cuenta_inquilino ?: $r->cuenta }}</td><td>{{ $fecha($r->fecha_inicio) }}</td><td>{{ $fecha($r->fecha_fin) }}</td><td>{{ $r->contrato_estado }}</td><td>{{ $r->rol }}</td></tr>@empty<tr><td colspan="6" class="text-muted text-center">Sin registros</td></tr>@endforelse
            </tbody></table></div></div>
        @endforeach
    </div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">Liquidaciones / envíos / repartos</div><div class="card-body row g-3">
        <div class="col-lg-6"><strong>Principal</strong><div>Liquidaciones: {{ $principal['liquidaciones_count'] }}</div><div>Envíos: {{ $principal['envios_count'] }}</div><div>Repartos vinculados: {{ $principal['repartos']->count() }}</div></div>
        <div class="col-lg-6"><strong>A absorber</strong><div>Liquidaciones: {{ $secundario['liquidaciones_count'] }}</div><div>Envíos: {{ $secundario['envios_count'] }}</div><div>Repartos vinculados: {{ $secundario['repartos']->count() }}</div></div>
        <div class="col-12"><small class="text-muted">Las liquidaciones conservan nombre, CUIT, cuenta, domicilio e importes históricos. Sólo cambia su referencia cliente_id al canónico. Los email_destino de envíos pasados tampoco se modifican.</small></div>
    </div></div>

    <div class="card border-danger mb-5"><div class="card-header text-bg-danger bg-opacity-10 fw-semibold">Confirmación</div><div class="card-body">
        <p>Permanecerá <strong>#{{ $p->id }} — {{ $p->nombre }}</strong>.</p><p>Se absorberá <strong>#{{ $s->id }} — {{ $s->nombre }}</strong>.</p>
        @if ($plan['bloqueos'] === [])
            <form method="POST" action="{{ route('archivo.unificacion.clientes.unificar') }}" onsubmit="return confirm('¿Confirma absorber el cliente #{{ $s->id }} en #{{ $p->id }}?');">@csrf
                <input type="hidden" name="principal" value="{{ $p->id }}"><input type="hidden" name="secundario" value="{{ $s->id }}"><input type="hidden" name="confirmacion" value="UNIFICAR">
                <button class="btn btn-danger">Confirmar: absorber #{{ $s->id }} en #{{ $p->id }}</button>
            </form>
        @else
            <button class="btn btn-danger" disabled>No se puede confirmar mientras existan conflictos</button>
        @endif
    </div></div>
</div>
@endsection
