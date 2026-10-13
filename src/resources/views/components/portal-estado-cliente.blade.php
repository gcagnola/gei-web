@if($cliente)
    <div class="mt-3 pt-3 border-top">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <strong>Autogestión</strong>

            @switch($estado)
                @case('ACTIVA')
                    <span class="badge text-bg-success">Activa</span>
                    @break
                @case('PENDIENTE')
                    <span class="badge text-bg-warning">Pendiente de activación</span>
                    @break
                @case('VENCIDA')
                    <span class="badge text-bg-secondary">Invitación vencida</span>
                    @break
                @case('DESHABILITADA')
                    <span class="badge text-bg-danger">Deshabilitada</span>
                    @break
                @default
                    <span class="badge text-bg-light border text-dark">No habilitada</span>
            @endswitch
        </div>

        <div class="small text-muted mb-2">
            @if($identificador)
                <div><strong>Usuario:</strong> {{ $identificador }}</div>
            @endif

            @if($estado === 'ACTIVA' && $portalUsuario)
                <div><strong>Activada:</strong> {{ optional($portalUsuario->activado_at)->format('d/m/Y H:i') ?: '—' }}</div>
                <div><strong>Último acceso:</strong> {{ optional($portalUsuario->ultimo_acceso_at)->format('d/m/Y H:i') ?: '—' }}</div>
                <div><strong>Datos confirmados:</strong> {{ optional($portalUsuario->datos_confirmados_at)->format('d/m/Y H:i') ?: '—' }}</div>
            @elseif($estado === 'PENDIENTE' && $tokenActivacion)
                <div><strong>Enviado:</strong> {{ optional($tokenActivacion->enviado_at)->format('d/m/Y H:i') ?: '—' }}</div>
                <div><strong>Vence:</strong> {{ optional($tokenActivacion->vence_at)->format('d/m/Y H:i') ?: '—' }}</div>
                <div><strong>Enviado a:</strong> {{ $tokenActivacion->enviado_a }}</div>
            @elseif($estado === 'VENCIDA' && $tokenActivacion)
                <div><strong>Último envío:</strong> {{ optional($tokenActivacion->enviado_at)->format('d/m/Y H:i') ?: '—' }}</div>
                <div><strong>Venció:</strong> {{ optional($tokenActivacion->vence_at)->format('d/m/Y H:i') ?: '—' }}</div>
            @endif
        </div>

        @if($estado === 'PENDIENTE')
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                Invitación vigente
            </button>
        @elseif($estado === 'NO_HABILITADA' || $estado === 'VENCIDA')
            <form method="POST" action="{{ route('clientes.autogestion.enviar-acceso', ['cliente' => $cliente->id]) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-success">
                    {{ $estado === 'VENCIDA' ? 'Enviar nuevo acceso' : 'Enviar acceso' }}
                </button>
            </form>
        @endif

        @error('autogestion')
            <div class="text-danger small mt-2">{{ $message }}</div>
        @enderror
    </div>
@endif
