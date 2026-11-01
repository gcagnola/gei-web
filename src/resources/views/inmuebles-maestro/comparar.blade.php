@extends('layouts.app')

@section('title', 'Comparación de inmuebles operativos')
@section('page-title', 'Inmuebles · Consolidación')

@section('content')
@php
    $principalInmueble = $principal['inmueble'];
    $otroInmueble = $secundario['inmueble'];
    $formatoCuenta = static function ($cuenta): string {
        $digitos = preg_replace('/\D/', '', (string) $cuenta) ?: '';
        return strlen($digitos) === 11
            ? substr($digitos, 0, 4).'/'.substr($digitos, 4, 5).'/'.substr($digitos, 9, 2)
            : ((string) $cuenta ?: '—');
    };
    $formatoFecha = static fn ($fecha): string => $fecha
        ? \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') : '—';
    $secciones = [
        'Cuentas de origen registradas' => ['origenes', ['cuenta_propietario', 'clave_origen', 'direccion_finca', 'estado_origen']],
        'Propietarios y vigencias' => ['propietarios', ['cliente_nombre', 'cuenta_propietario', 'porcentaje', 'vigencia_desde', 'vigencia_hasta', 'activo']],
        'Partidas inmobiliarias' => ['partidas', ['partida', 'vigencia_desde', 'vigencia_hasta', 'activo']],
        'Contratos' => ['contratos', ['contrato_id', 'cuenta_propietario', 'cuenta_inquilino', 'fecha_inicio', 'fecha_fin', 'contrato_estado']],
        'Avisos pendientes o históricos' => ['conflictos', ['motivo', 'estado', 'cuenta_propietario', 'cuenta_inquilino']],
        'Resoluciones de origen' => ['resoluciones_origen', ['entidad_origen', 'clave_origen', 'decision']],
    ];
    $mostrar = static function ($fila, string $campo) use ($formatoCuenta, $formatoFecha): string {
        $valor = $fila->{$campo} ?? null;
        if ($valor === null || $valor === '') return '—';
        if (in_array($campo, ['cuenta_propietario', 'cuenta_inquilino', 'clave_origen'], true)
            && preg_match('/^(?:1103|1202|2103|2202)\d{7}$/', (string) $valor)) {
            return $formatoCuenta($valor);
        }
        if (in_array($campo, ['fecha_inicio', 'fecha_fin', 'vigencia_desde', 'vigencia_hasta'], true)) {
            return $formatoFecha($valor);
        }
        if (is_bool($valor)) return $valor ? 'Sí' : 'No';
        return (string) $valor;
    };
@endphp

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Consolidación de inmuebles · análisis previo</h1>
            <span class="badge text-bg-warning">SOLO CONSULTA — NO SE FUSIONA NADA</span>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('inmuebles.maestro.index', ['estado' => 'TODOS', 'revision' => 'duplicados']) }}">Volver a revisión</a>
    </div>

    <div class="alert alert-info">
        Un inmueble físico debe conservar un único ID permanente. Una cuenta de propietario distinta puede indicar
        un cambio de titularidad y <strong>no demuestra que sean inmuebles distintos</strong>.
        Esta comparación tampoco demuestra por sí sola que sean la misma unidad: verificá departamento, piso,
        local, cochera, partidas y contratos. La decisión y sus efectos se auditarán antes de habilitar la consolidación.
    </div>

    <div class="row g-3 mb-3">
        @foreach ([['r' => $principal, 'titulo' => 'Inmueble de referencia (sin confirmar)', 'clase' => 'border-primary'],
                   ['r' => $secundario, 'titulo' => 'Segundo registro (sin confirmar)', 'clase' => 'border-secondary']] as $lado)
            @php
                $inmueble = $lado['r']['inmueble'];
                $cuentasTitular = $lado['r']['origenes']->pluck('cuenta_propietario')
                    ->merge($lado['r']['propietarios']->pluck('cuenta_propietario'))
                    ->merge($lado['r']['contratos']->pluck('cuenta_propietario'))
                    ->filter()->unique()->sort()->values();
            @endphp
            <div class="col-xl-6">
                <div class="card h-100 {{ $lado['clase'] }}">
                    <div class="card-header"><strong>{{ $lado['titulo'] }}</strong></div>
                    <div class="card-body">
                        <h2 class="h5">#{{ $inmueble->id }} · {{ $inmueble->domicilio }}</h2>
                        <div><strong>Estado:</strong> {{ $inmueble->estado }}</div>
                        <div><strong>Unidad/cochera:</strong> {{ $inmueble->identificador_cochera ?: '—' }}</div>
                        <div><strong>Cuentas propietarias asociadas:</strong>
                            @forelse($cuentasTitular as $cuenta){{ $formatoCuenta($cuenta) }}@if(!$loop->last), @endif
                            @empty — @endforelse
                        </div>
                        <div><strong>Domicilio normalizado:</strong> {{ $inmueble->domicilio_normalizado }}</div>
                        <div><strong>Código de origen:</strong> {{ $inmueble->codigo_origen ?: '—' }}</div>
                        <div><strong>Observaciones:</strong> {{ $inmueble->observaciones ?: '—' }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if($principalInmueble->domicilio_normalizado !== $otroInmueble->domicilio_normalizado)
        <div class="alert alert-warning">Los domicilios normalizados son diferentes. Verificá especialmente que se trate de la misma unidad física antes de pensar en consolidarlos.</div>
    @endif

    <div class="card mb-3">
        <div class="card-header fw-semibold">Plan técnico existente — exclusivamente informativo</div>
        <div class="card-body">
            <div class="row g-2">
                @foreach ($plan['resumen']['traslados'] as $tabla => $cantidad)
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded p-2 text-center">
                            <strong>{{ $cantidad }}</strong>
                            <div class="small text-muted">{{ str_replace('_', ' ', $tabla) }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="small text-muted mt-3 mb-1">Relaciones detectadas como duplicadas por el plan técnico: {{ $plan['resumen']['duplicados_exactos_eliminados'] }}.</p>
            @if($plan['bloqueos'] !== [])
                <div class="alert alert-danger mt-2 mb-0">
                    <strong>Incompatibilidades del plan:</strong>
                    <ul class="mb-0">@foreach($plan['bloqueos'] as $bloqueo)<li>{{ $bloqueo }}</li>@endforeach</ul>
                </div>
            @else
                <div class="alert alert-warning mt-2 mb-0">El plan no encontró incompatibilidades técnicas conocidas. <strong>Esto no autoriza una fusión:</strong> todavía debe confirmarse la identidad de la unidad física y revisar todas sus relaciones.</div>
            @endif
        </div>
    </div>

    @foreach ($secciones as $titulo => [$clave, $columnas])
        <div class="card mb-3">
            <div class="card-header fw-semibold">{{ $titulo }}</div>
            <div class="row g-0">
                @foreach ([['nombre' => 'Registro #'.$principalInmueble->id, 'datos' => $principal[$clave]],
                           ['nombre' => 'Registro #'.$otroInmueble->id, 'datos' => $secundario[$clave]]] as $lado)
                    <div class="col-xl-6 border-end">
                        <div class="bg-light px-3 py-2 small fw-semibold">{{ $lado['nombre'] }} — {{ $lado['datos']->count() }} registros</div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0 small">
                                <thead><tr>@foreach($columnas as $columna)<th>{{ str_replace('_', ' ', ucfirst($columna)) }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @forelse($lado['datos'] as $fila)
                                        <tr>@foreach($columnas as $columna)<td>{{ $mostrar($fila, $columna) }}</td>@endforeach</tr>
                                    @empty
                                        <tr><td colspan="{{ count($columnas) }}" class="text-muted">Sin registros.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
    <div class="alert alert-secondary">Esta pantalla no ofrece botones para consolidar, reasignar, borrar, modificar sedes ni actualizar cuentas. Solo permite comprobar qué datos contiene cada inmueble.</div>
</div>
@endsection
