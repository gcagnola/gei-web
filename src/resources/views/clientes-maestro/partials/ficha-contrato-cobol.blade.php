@php
    // Se incluye exclusivamente desde contratos ya autorizados por cuenta/rol/sede.
    $ficha = $fichasContratos[(int) $idContratoFicha] ?? null;
    $dato = static fn (string $campo) => $ficha[$campo] ?? null;
    $texto = static fn ($valor): string => ($valor === null || trim((string)$valor) === '') ? '—' : (string) $valor;
    $moneda = static fn ($n): string => $n === null ? '—' : '$ '.number_format((float)$n, 2, ',', '.');
    $fechaCobolFicha = static function ($valor) use ($texto): string {
        $s = trim((string) ($valor ?? ''));
        if ($s === '' || preg_match('/^0+$/D', $s) === 1) return '—';
        $clave = \App\Services\ClienteMaestroFichaContratoCobolService::fechaClave($s);
        if ($clave === null) return $texto($s); // Mostrar evidencia original si no es una fecha válida.
        return substr($clave, 6, 2).'/'.substr($clave, 4, 2).'/'.substr($clave, 0, 4);
    };
    $ajustesFicha = [];
    if ($ficha !== null) {
        for ($n = 1; $n <= 8; $n++) {
            $f = $dato('ajuste_'.$n.'_fecha');
            $p = $dato('ajuste_'.$n.'_porcentaje');
            if ($f !== null || $p !== null) {
                $ajustesFicha[] = ['n' => $n, 'fecha' => $f, 'porcentaje' => $p];
            }
        }
    }
@endphp
<details class="mt-1 mb-1">
    <summary class="text-primary fw-semibold" style="cursor:pointer">Ver ficha completa del contrato</summary>
    @if($ficha === null)
        <div class="alert alert-light border mt-2 mb-0 py-2 small">
            No hay ficha contractual COBOL para este par de cuentas en los cierres importados. Se mantiene visible el contrato operativo.
        </div>
    @else
        <div class="border rounded p-3 mt-2 bg-white">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <strong>Contrato de locación · {{ $contextoFicha === 'propietario' ? 'Vista del propietario' : 'Vista del inquilino' }}</strong>
                    <div class="small text-muted">
                        Inquilino {{ $texto($dato('cuenta_inquilino')) }} · Propietario {{ $texto($dato('cuenta_propietario')) }}
                    </div>
                </div>
                <span class="badge text-bg-light border text-dark">
                    Ficha COBOL {{ preg_match('/^\d{6}$/', (string)$dato('periodo')) ? substr((string)$dato('periodo'), 4, 2).'/'.substr((string)$dato('periodo'), 0, 4) : $texto($dato('periodo')) }}
                </span>
            </div>
            <p class="small text-muted mb-3">Datos originales del último cierre COBOL importado para este par de cuentas; pueden diferir del estado actual. No son un cálculo de intereses ni una nueva relación contractual.</p>
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card border shadow-none h-100">
                        <div class="card-header bg-light fw-semibold">Datos del contrato</div>
                        <div class="card-body"><div class="row g-3 small">
                            <div class="col-sm-6"><div class="text-muted">Fecha contrato</div><strong>{{ $fechaCobolFicha($dato('fecha_contrato')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Vencimiento</div><strong>{{ $fechaCobolFicha($dato('fecha_vencimiento')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Celebración</div><strong>{{ $fechaCobolFicha($dato('fecha_celebracion')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Inicio de locación</div><strong>{{ $fechaCobolFicha($dato('fecha_inicio')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Plazo</div><strong>{{ $texto($dato('plazo_meses')) }} mes(es){{ $dato('plazo_dias') ? ' + '.$dato('plazo_dias').' día(s)' : '' }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Destino (código COBOL)</div><strong>{{ $texto($dato('destino')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">N.º liquidación</div><strong>{{ $texto($dato('nro_liquidacion')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Intimación</div><strong>{{ $texto($dato('marca_intimacion')) }}</strong></div>
                            <div class="col-12"><div class="text-muted">Dirección finca (COBOL)</div><strong>{{ $texto($dato('direccion_finca')) }}</strong></div>
                        </div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border shadow-none h-100">
                        <div class="card-header bg-light fw-semibold">Alquiler y ajustes</div>
                        <div class="card-body"><div class="row g-3 small">
                            <div class="col-sm-6"><div class="text-muted">Primer ajuste</div><strong>{{ $fechaCobolFicha($dato('fecha_primer_ajuste')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Índice / tipo</div><strong>{{ $texto($dato('indice')) }} / {{ $texto($dato('tipo_ajuste')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Cuota 1</div><strong>{{ $moneda($dato('cuota_1')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Cuota 2</div><strong>{{ $moneda($dato('cuota_2')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Alquiler inicial</div><strong>{{ $moneda($dato('alquiler_inicial')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Cuota 2 dólar</div><strong>{{ $moneda($dato('cuota_2_dolar')) }}</strong></div>
                        </div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border shadow-none h-100">
                        <div class="card-header bg-light fw-semibold">Penalidades y administración</div>
                        <div class="card-body"><div class="row g-3 small">
                            <div class="col-sm-6"><div class="text-muted">Cláusula penal (%)</div><strong>{{ $texto($dato('penal_porcentaje')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Importe penal</div><strong>{{ $moneda($dato('penal_importe')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Comisión impuestos</div><strong>{{ $moneda($dato('comision_importe')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Comisión anterior (COBOL)</div><strong>{{ $texto($dato('comision_anterior')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Acumulado penalidad</div><strong>{{ $moneda($dato('acumulado_penalidad')) }}</strong></div>
                            <div class="col-12"><div class="text-muted">Administración responsable</div><strong>{{ $texto($dato('administracion_responsable')) }}</strong></div>
                        </div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border shadow-none h-100">
                        <div class="card-header bg-light fw-semibold">Reparaciones y legales</div>
                        <div class="card-body"><div class="row g-3 small">
                            <div class="col-sm-6"><div class="text-muted">Reparación</div><strong>{{ $texto($dato('reparacion')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Días reparación</div><strong>{{ $texto($dato('dias_reparacion')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Fecha juicio</div><strong>{{ $fechaCobolFicha($dato('fecha_juicio')) }}</strong></div>
                            <div class="col-sm-6"><div class="text-muted">Abogado</div><strong>{{ $texto($dato('abogado')) }}</strong></div>
                        </div></div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border shadow-none">
                        <div class="card-header bg-light fw-semibold">Estado contractual registrado en COBOL</div>
                        <div class="card-body"><div class="row g-3 small">
                            <div class="col-sm-4"><div class="text-muted">Marca de baja</div><strong>{{ $texto($dato('marca_baja')) }}</strong></div>
                            <div class="col-sm-4"><div class="text-muted">Fecha baja</div><strong>{{ $fechaCobolFicha($dato('fecha_baja')) }}</strong></div>
                            <div class="col-sm-4"><div class="text-muted">Intimación</div><strong>{{ $texto($dato('marca_intimacion')) }}</strong></div>
                        </div></div>
                    </div>
                </div>
                @if(count($ajustesFicha) > 0)
                    <div class="col-12">
                        <div class="card border shadow-none">
                            <div class="card-header bg-light fw-semibold">Ajustes adicionales</div>
                            <div class="table-responsive"><table class="table table-sm mb-0">
                                <thead><tr><th>#</th><th>Fecha</th><th>Porcentaje</th></tr></thead>
                                <tbody>@foreach($ajustesFicha as $ajuste)
                                    <tr><td>{{ $ajuste['n'] }}</td><td>{{ $fechaCobolFicha($ajuste['fecha']) }}</td>
                                        <td>{{ $ajuste['porcentaje'] === null ? '—' : number_format((float)$ajuste['porcentaje'], 1, ',', '.').' %' }}</td></tr>
                                @endforeach</tbody>
                            </table></div>
                        </div>
                    </div>
                @endif
            </div>
            @if(count($dato('historial') ?? []) > 1)
                <details class="mt-3">
                    <summary class="text-primary fw-semibold" style="cursor:pointer">
                        Historial del contrato en cierres COBOL ({{ count($dato('historial')) }} períodos)
                    </summary>
                    <div class="table-responsive mt-2"><table class="table table-sm table-striped align-middle small">
                        <thead><tr><th>Cierre</th><th>Alquiler inicial</th><th>Cuota 1</th><th>Cuota 2</th><th>Cláusula penal</th><th>Importe penal</th><th>Marca baja</th></tr></thead>
                        <tbody>
                            @foreach($dato('historial') as $version)
                                <tr>
                                    <td>{{ preg_match('/^\d{6}$/', (string)($version['periodo'] ?? '')) ? substr($version['periodo'], 4, 2).'/'.substr($version['periodo'], 0, 4) : $texto($version['periodo'] ?? null) }}</td>
                                    <td>{{ $moneda($version['alquiler_inicial'] ?? null) }}</td>
                                    <td>{{ $moneda($version['cuota_1'] ?? null) }}</td>
                                    <td>{{ $moneda($version['cuota_2'] ?? null) }}</td>
                                    <td>{{ $texto($version['penal_porcentaje'] ?? null) }}</td>
                                    <td>{{ $moneda($version['penal_importe'] ?? null) }}</td>
                                    <td>{{ $texto($version['marca_baja'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                </details>
            @endif
        </div>
    @endif
</details>
