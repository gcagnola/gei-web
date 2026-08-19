@extends('layouts.app')

@section('title', 'Comparar inmuebles')
@section('page-title', 'Unificación de inmuebles')

@section('content')
@php
    $p = $principal['inmueble'];
    $s = $secundario['inmueble'];
@endphp

<div class="container-fluid py-3" style="padding-bottom: 7rem !important;">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Comparar inmuebles</h1>
            <p class="text-muted mb-0">La columna izquierda permanece. La derecha será absorbida.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('archivo.unificacion.index') }}">Volver</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                <div style="white-space: pre-line;">{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @if ($plan['bloqueos'] !== [])
        <div class="alert alert-danger">
            <div class="fw-semibold mb-2">No se puede ejecutar todavía.</div>
            <ul class="mb-0">
                @foreach ($plan['bloqueos'] as $bloqueo)
                    <li>{{ $bloqueo }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <div class="alert alert-warning">
            Esta operación modifica relaciones dentro de una transacción. El inmueble absorbido se conserva,
            queda inactivo y apunta al inmueble canónico. La operación queda auditada.
        </div>
    @endif

    <div class="alert alert-info">
        <strong>Identidades históricas:</strong> las cuentas COBOL de inquilino/propietario, orígenes y partidas
        del inmueble absorbido no se borran. Se reasignan al inmueble que queda para que futuras importaciones,
        impuestos/servicios y liquidaciones puedan resolver hacia la entidad canónica.
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card h-100 border-success">
                <div class="card-header bg-success-subtle">
                    <strong>INMUEBLE QUE QUEDA</strong>
                </div>
                <div class="card-body">
                    <div class="fs-4 fw-semibold">#{{ $p->id }} — {{ $p->domicilio }}</div>
                    <dl class="row mt-3 mb-0">
                        <dt class="col-sm-4">Normalizado</dt><dd class="col-sm-8">{{ $p->domicilio_normalizado }}</dd>
                        <dt class="col-sm-4">Clave migración</dt><dd class="col-sm-8"><code>{{ $p->clave_migracion }}</code></dd>
                        <dt class="col-sm-4">Código origen</dt><dd class="col-sm-8">{{ $p->codigo_origen ?: '—' }}</dd>
                        <dt class="col-sm-4">Estado</dt><dd class="col-sm-8">{{ $p->estado }}</dd>
                        <dt class="col-sm-4">Observaciones</dt><dd class="col-sm-8">{{ $p->observaciones ?: '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card h-100 border-danger">
                <div class="card-header bg-danger-subtle">
                    <strong>INMUEBLE A ABSORBER</strong>
                </div>
                <div class="card-body">
                    <div class="fs-4 fw-semibold">#{{ $s->id }} — {{ $s->domicilio }}</div>
                    <dl class="row mt-3 mb-0">
                        <dt class="col-sm-4">Normalizado</dt><dd class="col-sm-8">{{ $s->domicilio_normalizado }}</dd>
                        <dt class="col-sm-4">Clave migración</dt><dd class="col-sm-8"><code>{{ $s->clave_migracion }}</code></dd>
                        <dt class="col-sm-4">Código origen</dt><dd class="col-sm-8">{{ $s->codigo_origen ?: '—' }}</dd>
                        <dt class="col-sm-4">Estado</dt><dd class="col-sm-8">{{ $s->estado }}</dd>
                        <dt class="col-sm-4">Observaciones</dt><dd class="col-sm-8">{{ $s->observaciones ?: '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Resumen de la operación</div>
        <div class="card-body">
            <div class="row g-3 text-center">
                @foreach ($plan['resumen']['traslados'] as $etiqueta => $cantidad)
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="fs-4 fw-semibold">{{ $cantidad }}</div>
                            <div class="small text-muted">{{ str_replace('_', ' ', ucfirst($etiqueta)) }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($plan['resumen']['duplicados_exactos_eliminados'] > 0)
                <div class="alert alert-info mt-3 mb-0">
                    Se detectaron {{ $plan['resumen']['duplicados_exactos_eliminados'] }} relaciones duplicadas exactas.
                    Se conservará la relación del inmueble principal y el registro redundante quedará documentado
                    íntegramente en la auditoría antes de eliminarse.
                </div>
            @endif
        </div>
    </div>

    @php
        $secciones = [
            'Orígenes COBOL' => ['clave' => 'origenes', 'columnas' => ['clave_origen', 'cuenta_propietario', 'direccion_finca', 'estado_origen']],
            'Propietarios' => ['clave' => 'propietarios', 'columnas' => ['cliente_nombre', 'cliente_cuit', 'cuenta_propietario', 'porcentaje', 'vigencia_desde', 'vigencia_hasta', 'activo']],
            'Partidas' => ['clave' => 'partidas', 'columnas' => ['partida', 'vigencia_desde', 'vigencia_hasta', 'activo', 'origen']],
            'Contratos' => ['clave' => 'contratos', 'columnas' => ['contrato_clave_migracion', 'cuenta_inquilino', 'fecha_inicio', 'fecha_fin', 'contrato_estado', 'activo']],
            'Conflictos de importación' => ['clave' => 'conflictos', 'columnas' => ['motivo', 'estado', 'cuenta_inquilino', 'cuenta_propietario', 'ultima_deteccion_at']],
            'Resoluciones manuales de origen' => ['clave' => 'resoluciones_origen', 'columnas' => ['sistema_origen', 'entidad_origen', 'clave_origen', 'decision', 'created_at']],
        ];

        $etiquetasColumnas = [
            'contrato_clave_migracion' => 'Contrato clave migración',
        ];

        $columnasFecha = [
            'vigencia_desde',
            'vigencia_hasta',
            'fecha_inicio',
            'fecha_fin',
            'ultima_deteccion_at',
            'created_at',
            'updated_at',
            'detectado_at',
            'resuelto_at',
        ];

        $formatearFecha = static function ($valor): string {
            if ($valor === null || $valor === '') {
                return '—';
            }

            try {
                return \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y');
            } catch (\Throwable) {
                return (string) $valor;
            }
        };
    @endphp

    @foreach ($secciones as $titulo => $config)
        <div class="card mb-4">
            <div class="card-header fw-semibold">{{ $titulo }}</div>
            <div class="card-body p-0">
                <div class="row g-0">
                    @foreach ([['titulo' => 'Principal', 'datos' => $principal[$config['clave']]], ['titulo' => 'A absorber', 'datos' => $secundario[$config['clave']]]] as $lado)
                        <div class="col-xl-6 border-end">
                            <div class="px-3 py-2 bg-body-tertiary fw-semibold">{{ $lado['titulo'] }} ({{ $lado['datos']->count() }})</div>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            @foreach ($config['columnas'] as $columna)
                                                @php
                                                    $etiqueta = $etiquetasColumnas[$columna]
                                                        ?? str_replace('_', ' ', ucfirst($columna));
                                                    $partesEtiqueta = explode('|', $etiqueta);
                                                @endphp
                                                <th class="text-nowrap">
                                                    @foreach ($partesEtiqueta as $indice => $parte)
                                                        @if ($indice > 0)<br>@endif{{ $parte }}
                                                    @endforeach
                                                </th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($lado['datos'] as $fila)
                                            <tr>
                                                @foreach ($config['columnas'] as $columna)
                                                    @php $valor = $fila->{$columna} ?? null; @endphp
                                                    <td>
                                                        @if (in_array($columna, $columnasFecha, true))
                                                            {{ $formatearFecha($valor) }}
                                                        @elseif (is_bool($valor))
                                                            {{ $valor ? 'Sí' : 'No' }}
                                                        @elseif ($valor === null || $valor === '')
                                                            —
                                                        @elseif ($columna === 'contrato_clave_migracion')
                                                            <span class="font-monospace">{{ substr((string) $valor, 0, 32) }}<br>{{ substr((string) $valor, 32) }}</span>
                                                        @else
                                                            {{ $valor }}
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @empty
                                            <tr><td colspan="{{ count($config['columnas']) }}" class="text-center text-muted py-3">Sin registros</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    @if ($secundario['absorbidos']->isNotEmpty())
        <div class="alert alert-info">
            El inmueble secundario ya tiene {{ $secundario['absorbidos']->count() }} inmueble(s) histórico(s) absorbido(s).
            Esas referencias se reencadenarán al inmueble principal para evitar cadenas de unificación.
        </div>
    @endif

    <div class="card border-danger mb-4">
        <div class="card-header bg-danger-subtle fw-semibold">Confirmación</div>
        <div class="card-body">
            <p class="mb-2">
                Permanecerá <strong>#{{ $p->id }} — {{ $p->domicilio }}</strong>.
            </p>
            <p>
                Se absorberá <strong>#{{ $s->id }} — {{ $s->domicilio }}</strong>.
            </p>

            @if ($plan['bloqueos'] === [])
                <form method="POST" action="{{ route('archivo.unificacion.inmuebles.unificar') }}" onsubmit="return confirm('¿Confirma la unificación? Esta operación será auditada.');">
                    @csrf
                    <input type="hidden" name="principal" value="{{ $p->id }}">
                    <input type="hidden" name="secundario" value="{{ $s->id }}">
                    <input type="hidden" name="confirmacion" value="UNIFICAR">
                    <button class="btn btn-danger" type="submit">
                        Confirmar: absorber #{{ $s->id }} en #{{ $p->id }}
                    </button>
                </form>
            @else
                <button class="btn btn-danger" type="button" disabled>Resolver conflictos antes de unificar</button>
            @endif
        </div>
    </div>
</div>
@endsection
