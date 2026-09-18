@php
    $detalleConflicto = is_string($conflicto->detalle)
        ? (json_decode($conflicto->detalle, true) ?: [])
        : ((array) ($conflicto->detalle ?? []));
    $esConflictoIdentidad = str_starts_with((string) $conflicto->motivo, 'PARTIDA_')
        || str_starts_with((string) $conflicto->motivo, 'CLAVE_MIGRACION_');
    $partidasDetalle = $detalleConflicto['partidas_coincidentes']
        ?? $detalleConflicto['partidas_ambiguas']
        ?? [];
    $direccionOrigen = trim((string) ($detalleConflicto['direccion_finca'] ?? ''));
    $direccionNormalizada = trim((string) ($detalleConflicto['direccion_normalizada'] ?? ''));
    $candidatos = collect($conflicto->candidatos_detalle ?? []);
    $fechaDeteccion = $conflicto->ultima_deteccion_at
        ? \Illuminate\Support\Carbon::parse($conflicto->ultima_deteccion_at)->format('d/m/Y H:i')
        : '—';
    $motivoLegible = match ((string) $conflicto->motivo) {
        'CLAVE_MIGRACION_COINCIDENTE_REQUIERE_REVISION' => 'Coincide propietario + domicilio con un inmueble existente',
        'CLAVE_MIGRACION_COMPARTIDA_POR_VARIOS_ORIGENES' => 'La misma clave de inmueble aparece en varios registros COBOL',
        'PARTIDA_ASOCIADA_A_OTRO_INMUEBLE' => 'La partida ya está asociada a otro inmueble',
        'PARTIDA_ASOCIADA_A_VARIOS_INMUEBLES' => 'La partida aparece asociada a varios inmuebles',
        'PARTIDA_MULTIPLE_IDENTIDAD_EN_ARCHIVO' => 'La partida aparece con más de una identidad en el archivo COBOL',
        default => str_replace('_', ' ', (string) $conflicto->motivo),
    };
@endphp

<div class="mb-3">
    <div class="fw-semibold">{{ $motivoLegible }}</div>
    <div class="small text-muted">
        Aviso #{{ $conflicto->id }} · Detectado: {{ $fechaDeteccion }}
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-xl-5">
        <div class="border rounded p-3 h-100 bg-light">
            <div class="fw-semibold mb-2">Registro COBOL pendiente</div>
            <dl class="row small mb-0">
                <dt class="col-sm-4">Inquilino</dt>
                <dd class="col-sm-8 mb-1">
                    {{ $conflicto->inquilino_nombre ?: 'Sin nombre relacionado' }}
                    <div class="text-muted">Cuenta: {{ $conflicto->cuenta_inquilino ?: '—' }}</div>
                </dd>

                <dt class="col-sm-4">Propietario</dt>
                <dd class="col-sm-8 mb-1">
                    {{ $conflicto->propietario_nombre ?: 'Sin nombre relacionado' }}
                    <div class="text-muted">Cuenta: {{ $conflicto->cuenta_propietario ?: '—' }}</div>
                </dd>

                <dt class="col-sm-4">Domicilio</dt>
                <dd class="col-sm-8 mb-1">{{ $direccionOrigen !== '' ? $direccionOrigen : '—' }}</dd>

                @if ($direccionNormalizada !== '' && $direccionNormalizada !== $direccionOrigen)
                    <dt class="col-sm-4">Normalizado</dt>
                    <dd class="col-sm-8 mb-1 text-muted">{{ $direccionNormalizada }}</dd>
                @endif

                @if ($partidasDetalle !== [])
                    <dt class="col-sm-4">Partida(s)</dt>
                    <dd class="col-sm-8 mb-1">{{ implode(', ', $partidasDetalle) }}</dd>
                @endif
            </dl>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        @if ($candidatos->isEmpty())
            <div class="alert alert-warning mb-0">
                El aviso no contiene un inmueble candidato disponible para comparar.
            </div>
        @else
            <div class="fw-semibold mb-2">Inmueble(s) existente(s) para comparar</div>
            <div class="vstack gap-2">
                @foreach ($candidatos as $candidato)
                    <div class="border rounded p-3">
                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                            <div>
                                <span class="fw-semibold">Inmueble #{{ $candidato->id }}</span>
                                <span class="badge text-bg-{{ $candidato->estado === 'ACTIVO' ? 'success' : 'secondary' }} ms-1">
                                    {{ $candidato->estado }}
                                </span>
                            </div>
                            @if ($candidato->id_inmueble_canonico)
                                <span class="small text-danger">Absorbido por #{{ $candidato->id_inmueble_canonico }}</span>
                            @endif
                        </div>

                        <dl class="row small mb-3">
                            <dt class="col-sm-4">Domicilio</dt>
                            <dd class="col-sm-8 mb-1">{{ $candidato->domicilio ?: '—' }}</dd>

                            <dt class="col-sm-4">Inquilino(s)</dt>
                            <dd class="col-sm-8 mb-1">
                                {{ $candidato->inquilinos_nombres ?: '—' }}
                                @if ($candidato->cuentas_inquilino)
                                    <div class="text-muted">Cuenta(s): {{ $candidato->cuentas_inquilino }}</div>
                                @endif
                            </dd>

                            <dt class="col-sm-4">Propietario(s)</dt>
                            <dd class="col-sm-8 mb-1">
                                {{ $candidato->propietarios_nombres ?: '—' }}
                                @if ($candidato->cuentas_propietario)
                                    <div class="text-muted">Cuenta(s): {{ $candidato->cuentas_propietario }}</div>
                                @endif
                            </dd>

                            <dt class="col-sm-4">Partida(s)</dt>
                            <dd class="col-sm-8 mb-1">{{ $candidato->partidas ?: '—' }}</dd>
                        </dl>

                        @if ($esConflictoIdentidad && $conflicto->cuenta_inquilino && ! $candidato->id_inmueble_canonico)
                            <form method="POST" action="{{ route('archivo.unificacion.inmuebles.conflicto.resolver', ['conflicto' => $conflicto->id]) }}">
                                @csrf
                                <input type="hidden" name="decision" value="ASOCIAR_EXISTENTE">
                                <input type="hidden" name="inmueble_id" value="{{ $candidato->id }}">
                                <button class="btn btn-sm btn-outline-primary" type="submit"
                                    onclick="return confirm('¿Asociar la identidad COBOL del aviso #{{ $conflicto->id }} al inmueble #{{ $candidato->id }}?');">
                                    Asociar al inmueble #{{ $candidato->id }}
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@if ($esConflictoIdentidad && $conflicto->cuenta_inquilino)
    <div class="border-top pt-3">
        <form method="POST" action="{{ route('archivo.unificacion.inmuebles.conflicto.resolver', ['conflicto' => $conflicto->id]) }}">
            @csrf
            <input type="hidden" name="decision" value="CREAR_SEPARADO">
            <button class="btn btn-sm btn-outline-secondary" type="submit"
                onclick="return confirm('¿Confirmar que este registro COBOL corresponde a un inmueble distinto y debe mantenerse/crearse separado?');">
                Es otro inmueble: mantener / crear separado
            </button>
        </form>
    </div>
@else
    <span class="small text-muted">Revisá los datos de origen COBOL. Este aviso no admite asociación manual de inmueble.</span>
@endif
