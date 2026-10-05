@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Plan de Cuentas</h1>
        <p class="text-muted mb-0">Relación entre cuentas contables, cuentas de Caja y conceptos operativos.</p>
    </div>

    <div class="row g-3 mb-4">
        @foreach ([
            ['Cuentas contables', $resumen['cuentas']],
            ['Imputables', $resumen['imputables']],
            ['Cuentas Caja vinculadas', $resumen['cajas_vinculadas']],
            ['Referencias pendientes', $resumen['referencias_pendientes']],
        ] as [$titulo, $valor])
            <div class="col-6 col-lg-3">
                <div class="card h-100 {{ $titulo === 'Referencias pendientes' && $valor > 0 ? 'border-warning' : '' }}">
                    <div class="card-body">
                        <div class="text-muted small">{{ $titulo }}</div>
                        <div class="fs-4 fw-semibold">{{ number_format($valor, 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($pendientes->isNotEmpty())
        <details class="card border-warning mb-4">
            <summary class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2" style="cursor: pointer;">
                <div>
                    <strong>Referencias contables pendientes</strong>
                    <div class="small text-muted">
                        La cuenta Caja existe en GeI, pero el Nº contable indicado por el archivo de relaciones no existe en el maestro contable entregado.
                    </div>
                </div>
                <span class="badge text-bg-warning">{{ $pendientes->count() }}</span>
            </summary>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Cuenta Caja</th>
                            <th>Nº contable pendiente</th>
                            <th>Cuenta / subcuenta</th>
                            <th>Conceptos asociados</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendientes as $caja)
                            @php
                                $conceptosPendientes = $caja->imputacionesConceptos
                                    ->map(fn ($i) => $i->concepto)
                                    ->filter()
                                    ->unique(fn ($c) => $c->dominio.'|'.$c->codigo)
                                    ->sortBy(fn ($c) => $c->dominio.'|'.$c->codigo);
                            @endphp
                            <tr>
                                <td class="fw-semibold text-nowrap">{{ $caja->codigo_cobol }}</td>
                                <td class="font-monospace text-nowrap">{{ $caja->numero_contable }}</td>
                                <td>{{ $caja->subcuenta ?: $caja->nombre ?: '—' }}</td>
                                <td>
                                    @forelse ($conceptosPendientes as $concepto)
                                        <div class="d-flex align-items-baseline gap-2 flex-wrap">
                                            <span class="badge text-bg-light border">{{ $concepto->dominio }}</span>
                                            <span class="fw-semibold font-monospace">{{ $concepto->codigo }}</span>
                                            <span>{{ $concepto->descripcion }}</span>
                                        </div>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-muted">
                Estas referencias no se vinculan automáticamente. Deben ser confirmadas, reemplazadas o dadas por obsoletas por Contabilidad.
            </div>
        </details>
    @endif

    <form method="get" class="card card-body mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-6">
                <label class="form-label" for="buscar">Buscar</label>
                <input class="form-control" id="buscar" name="buscar" value="{{ $buscar }}" placeholder="Nº contable, denominación, cuenta Caja...">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="naturaleza">Naturaleza</label>
                <select class="form-select" id="naturaleza" name="naturaleza">
                    <option value="">Todas</option>
                    @foreach (['DEBE', 'HABER', 'VARIABLE'] as $o)
                        <option value="{{ $o }}" @selected($naturaleza === $o)>{{ $o }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="imputable">Imputable</label>
                <select class="form-select" id="imputable" name="imputable">
                    <option value="">Todas</option>
                    <option value="1" @selected($imputable === '1')>Sí</option>
                    <option value="0" @selected($imputable === '0')>No</option>
                </select>
            </div>
            <div class="col-12 col-lg-2 d-grid">
                <button class="btn btn-primary">Filtrar</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Cuenta contable</th>
                        <th>Denominación</th>
                        <th>Imputable</th>
                        <th>Naturaleza</th>
                        <th>Cuenta Caja</th>
                        <th>Conceptos asociados</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cuentas as $cuenta)
                        @php
                            $conceptos = $cuenta->cuentasCaja
                                ->flatMap(fn ($c) => $c->imputacionesConceptos)
                                ->map(fn ($i) => $i->concepto)
                                ->filter()
                                ->unique(fn ($c) => $c->dominio.'|'.$c->codigo)
                                ->sortBy(fn ($c) => $c->dominio.'|'.$c->codigo);
                        @endphp
                        <tr>
                            <td class="fw-semibold text-nowrap">{{ $cuenta->codigo }}</td>
                            <td>{{ $cuenta->descripcion }}</td>
                            <td>{{ $cuenta->imputable ? 'Sí' : 'No' }}</td>
                            <td>
                                @if ($cuenta->naturaleza)
                                    <span class="badge text-bg-light border">{{ $cuenta->naturaleza }}</span>
                                    <div class="small text-muted">{{ $cuenta->naturaleza_confirmada ? 'Confirmada' : 'Inferida' }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @forelse ($cuenta->cuentasCaja as $caja)
                                    <div>
                                        <strong>{{ $caja->codigo_cobol }}</strong>
                                        @if ($caja->subcuenta || $caja->nombre)
                                            — {{ $caja->subcuenta ?: $caja->nombre }}
                                        @endif
                                    </div>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td>
                                @forelse ($conceptos as $concepto)
                                    <div class="d-flex align-items-baseline gap-1 flex-wrap">
                                        <span class="badge text-bg-light border">{{ $concepto->dominio }}</span>
                                        <span class="badge text-bg-light border font-monospace">{{ $concepto->codigo }}</span>
                                        <span>{{ $concepto->descripcion }}</span>
                                    </div>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">No hay cuentas para los filtros seleccionados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($cuentas->hasPages())
            <div class="card-footer">{{ $cuentas->links() }}</div>
        @endif
    </div>

    <div class="alert alert-light border mt-4 mb-0">
        <strong>Naturaleza:</strong> DEBE/HABER se importa como clasificación contable inferida. No reemplaza el Debe/Haber real de cada movimiento.
    </div>
</div>
@endsection
