<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\LiquidacionCliente;
use App\Services\LiquidacionPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClienteController extends Controller
{
    public function index(Request $request): View
    {
        return $this->vistaListado($request);
    }

    public function show(Request $request, Cliente $cliente): View
    {
        return $this->vistaListado($request, $cliente);
    }

    public function create(): View
    {
        return view('clientes.create', $this->datosFormulario());
    }

    public function store(StoreClienteRequest $request): RedirectResponse
    {
        $cliente = DB::transaction(
            fn () => Cliente::query()->create($request->datosCliente())
        );

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('estado', 'El cliente fue creado correctamente.');
    }

    public function edit(Cliente $cliente): View
    {
        return view('clientes.edit', [
            ...$this->datosFormulario(),
            'cliente' => $cliente,
        ]);
    }

    public function update(
        UpdateClienteRequest $request,
        Cliente $cliente
    ): RedirectResponse {
        DB::transaction(
            fn () => $cliente->update($request->datosCliente())
        );

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('estado', 'Los datos del cliente fueron actualizados.');
    }

    public function localidades(Request $request): JsonResponse
    {
        $provincia = trim((string) $request->query('provincia'));

        if ($provincia === '') {
            return response()->json([]);
        }

        $localidades = DB::table('localidades')
            ->selectRaw(
                'TRIM(nombre) AS nombre, '
                .'TRIM(COALESCE(caractel, \'\')) AS caractel, '
                .'TRIM(COALESCE(cp, \'\')) AS cp'
            )
            ->whereRaw('TRIM(provincia) = ?', [$provincia])
            ->orderByRaw('TRIM(nombre)')
            ->get();

        return response()->json($localidades);
    }

    public function exportarPendientesValidacion(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $salida = fopen('php://output', 'w');

            fputcsv($salida, [
                'codigo_cliente',
                'nombre',
                'personeria',
                'doctipo',
                'docnro',
                'cuit',
                'id_inq',
                'id_prop',
                'localidad',
                'telefonos',
                'email',
            ]);

            DB::table('clientes')
                ->select([
                    'codigo_cliente',
                    'personeria',
                    'doctipo',
                    'docnro',
                    'apellidos',
                    'nombres',
                    'razon_social',
                    'cuit',
                    'id_inq',
                    'id_prop',
                    'localidad',
                    'telefonos',
                    'email',
                ])
                ->where('web_validada', false)
                ->orderBy('codigo_cliente')
                ->cursor()
                ->each(function ($cliente) use ($salida): void {
                    $nombre = trim((string) $cliente->razon_social);
                    if ($nombre === '') {
                        $nombre = trim(trim((string) $cliente->apellidos).' '.trim((string) $cliente->nombres));
                    }

                    fputcsv($salida, [
                        $cliente->codigo_cliente,
                        $nombre,
                        trim((string) $cliente->personeria),
                        trim((string) $cliente->doctipo),
                        trim((string) $cliente->docnro),
                        trim((string) $cliente->cuit),
                        (string) $cliente->id_inq,
                        (string) $cliente->id_prop,
                        trim((string) $cliente->localidad),
                        trim((string) $cliente->telefonos),
                        trim((string) $cliente->email),
                    ]);
                });

            fclose($salida);
        }, 'clientes-pendientes-validacion.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function vistaListado(
        Request $request,
        ?Cliente $clienteSeleccionado = null
    ): View {
        $busqueda = trim((string) $request->query('buscar'));
        $filtro = (string) $request->query('filtro', 'todos');
        $mostrarValidacion = $request->boolean('mostrar_validacion');
        $validacion = (string) $request->query('validacion', 'todos');
        $actividad = (string) $request->query('actividad', 'activos');
        $liquidacionAnio = trim((string) $request->query('liquidacion_anio'));
        $liquidacionMes = trim((string) $request->query('liquidacion_mes'));
        $liquidacionPeriodo = trim((string) $request->query('liquidacion_periodo'));

        if (! in_array($filtro, ['todos', 'propietarios', 'inquilinos'], true)) {
            $filtro = 'todos';
        }
        if (! in_array($validacion, ['todos', 'validados', 'pendientes'], true)) {
            $validacion = 'todos';
        }
        if (! $mostrarValidacion) {
            $validacion = 'todos';
        }
        if (! in_array($actividad, ['todos', 'activos', 'inactivos'], true)) {
            $actividad = 'todos';
        }

        $consulta = Cliente::query()
            ->select([
                'codigo_cliente',
                'personeria',
                'doctipo',
                'docnro',
                'apellidos',
                'nombres',
                'razon_social',
                'domicilio',
                'provincia',
                'departamento',
                'localidad',
                'cp',
                'caractel',
                'telefonos',
                'celular',
                'fax',
                'email',
                'nacionalidad',
                'cuit',
                'condicion_iva',
                'profesion',
                'lugar_de_trabajo',
                'web_validada',
                'web_operativo',
            ])
            ->when(
                $busqueda !== '',
                fn (Builder $query) => $this->aplicarBusqueda(
                    $query,
                    $busqueda
                )
            )
            ->when(
                $filtro === 'propietarios',
                fn (Builder $query) => $query->whereExists(
                    fn ($subquery) => $subquery
                        ->selectRaw('1')
                        ->from('inmuebles_propietarios')
                        ->whereColumn(
                            'inmuebles_propietarios.codigo_cliente',
                            'clientes.codigo_cliente'
                        )
                )
            )
            ->when(
                $filtro === 'inquilinos',
                fn (Builder $query) => $query->whereExists(
                    fn ($subquery) => $subquery
                        ->selectRaw('1')
                        ->from('contratos_inquilinos')
                        ->whereColumn(
                            'contratos_inquilinos.codigo_cliente',
                            'clientes.codigo_cliente'
                        )
                )
            )
            ->when(
                $validacion === 'validados',
                fn (Builder $query) => $query->where('web_validada', true)
            )
            ->when(
                $validacion === 'pendientes',
                fn (Builder $query) => $query->where('web_validada', false)
            )
            ->when(
                $actividad === 'activos',
                fn (Builder $query) => $this->aplicarActividad(
                    $query,
                    true,
                    $filtro
                )
            )
            ->when(
                $actividad === 'inactivos',
                fn (Builder $query) => $this->aplicarActividad(
                    $query,
                    false,
                    $filtro
                )
            )
            ->orderByRaw("COALESCE(NULLIF(TRIM(razon_social), ''), TRIM(apellidos), '')")
            ->orderByRaw('TRIM(nombres)')
            ->orderBy('codigo_cliente');

        $clientes = $consulta
            ->paginate(25)
            ->withQueryString();

        if (! $clienteSeleccionado && $request->filled('cliente')) {
            $clienteSeleccionado = Cliente::query()->find(
                (int) $request->query('cliente')
            );
        }

        $clienteSeleccionado ??= $clientes->first();
        $contratos = null;
        $liquidaciones = null;

        if ($clienteSeleccionado) {
            $contratos = Contrato::query()
                ->select([
                    'contratos.codigo_contrato',
                    'contratos.numero_de_contrato',
                    'contratos.fecha_inicio',
                    'contratos.fecha_fin',
                    'contratos.importe_inicial',
                    'contratos_inquilinos.porcentaje_participacion',
                ])
                ->join(
                    'contratos_inquilinos',
                    'contratos_inquilinos.codigo_contrato',
                    '=',
                    'contratos.codigo_contrato'
                )
                ->where(
                    'contratos_inquilinos.codigo_cliente',
                    $clienteSeleccionado->codigo_cliente
                )
                ->with([
                    'inmuebles' => fn ($query) => $query
                        ->select([
                            'inmuebles.codigo_inmueble',
                            'domicilio_calle',
                            'domicilio_nro',
                            'domicilio_edificio',
                            'domicilio_piso',
                            'domicilio_dpto',
                            'localidad',
                            'cod_tipo_inmueble',
                        ])
                        ->with([
                            'tipo:cod_tipo_inmueble,tipo_inmueble',
                            'propietarios' => fn ($propietarios) => $propietarios
                                ->select([
                                    'clientes.codigo_cliente',
                                    'personeria',
                                    'apellidos',
                                    'nombres',
                                    'razon_social',
                                    'cuit',
                                    'docnro',
                                ])
                                ->orderByRaw("COALESCE(NULLIF(TRIM(razon_social), ''), TRIM(apellidos), '')")
                                ->orderByRaw('TRIM(nombres)'),
                        ]),
                ])
                ->orderByDesc('contratos.fecha_inicio')
                ->orderByDesc('contratos.codigo_contrato')
                ->paginate(8, ['*'], 'contratos_page')
                ->withQueryString();

            if ((int) $clienteSeleccionado->id_prop !== 0) {
                $pdfService = app(LiquidacionPdfService::class);

                $liquidaciones = LiquidacionCliente::query()
                    ->select([
                        'numero_de_liquidacion',
                        'punto_venta',
                        'numero',
                        'fecha',
                        'nro_cuenta',
                        'periodo',
                        'nombre',
                        'razon_social',
                        'fecha_desde',
                        'fecha_hasta',
                        'numero_de_comprobante',
                        'total_liquidado',
                    ])
                    ->where('nro_cuenta', (int) $clienteSeleccionado->id_prop)
                    ->when(
                        $liquidacionAnio !== '' && ctype_digit($liquidacionAnio),
                        fn (Builder $query) => $query->whereYear(
                            'fecha',
                            (int) $liquidacionAnio
                        )
                    )
                    ->when(
                        $liquidacionMes !== '' && ctype_digit($liquidacionMes),
                        fn (Builder $query) => $query->whereMonth(
                            'fecha',
                            (int) $liquidacionMes
                        )
                    )
                    ->when(
                        $liquidacionPeriodo !== '',
                        fn (Builder $query) => $query->whereRaw(
                            'LOWER(TRIM(periodo)) LIKE ?',
                            ['%'.mb_strtolower($liquidacionPeriodo).'%']
                        )
                    )
                    ->orderByDesc('fecha')
                    ->orderByDesc('numero')
                    ->paginate(8, ['*'], 'liquidaciones_page')
                    ->withQueryString();

                $liquidaciones->getCollection()->transform(
                    function (LiquidacionCliente $liquidacion) use ($pdfService) {
                        $liquidacion->pdf_disponible = $pdfService->existe($liquidacion);
                        $liquidacion->pdf_ruta_relativa = $pdfService->rutaRelativaExistente($liquidacion)
                            ?? $pdfService->rutaRelativa($liquidacion);

                        return $liquidacion;
                    }
                );
            }
        }

        return view('clientes.index', compact(
            'clientes',
            'clienteSeleccionado',
            'contratos',
            'busqueda',
            'filtro',
            'mostrarValidacion',
            'validacion',
            'actividad',
            'liquidaciones',
            'liquidacionAnio',
            'liquidacionMes',
            'liquidacionPeriodo'
        ));
    }

    private function aplicarActividad(
        Builder $query,
        bool $activos,
        string $filtro
    ): void {
        if ($filtro === 'inquilinos') {
            $existeContratoVigenteComoInquilino = function ($subquery): void {
                $hoy = now()->toDateString();

                $subquery
                    ->selectRaw('1')
                    ->from('contratos')
                    ->join(
                        'contratos_inquilinos',
                        'contratos_inquilinos.codigo_contrato',
                        '=',
                        'contratos.codigo_contrato'
                    )
                    ->whereColumn(
                        'contratos_inquilinos.codigo_cliente',
                        'clientes.codigo_cliente'
                    )
                    ->whereDate('contratos.fecha_inicio', '<=', $hoy)
                    ->whereDate('contratos.fecha_fin', '>=', $hoy);
            };

            $activos
                ? $query->whereExists($existeContratoVigenteComoInquilino)
                : $query->whereNotExists($existeContratoVigenteComoInquilino);

            return;
        }

        $this->aplicarActividadOperativa($query, $activos);
    }

    private function aplicarActividadOperativa(Builder $query, bool $activos): void
    {
        $activos
            ? $query->where('web_operativo', true)
            : $query->where('web_operativo', false);
    }

    private function aplicarBusqueda(
        Builder $query,
        string $busqueda
    ): void {
        $termino = '%'.mb_strtolower($busqueda).'%';
        $palabras = preg_split('/\s+/', mb_strtolower($busqueda), -1, PREG_SPLIT_NO_EMPTY);

        $query->where(function (Builder $subquery) use ($termino, $palabras) {
            $subquery
                ->whereRaw('LOWER(CAST(codigo_cliente AS TEXT)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(apellidos)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(nombres)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(razon_social)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(docnro)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(cuit)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(email)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(telefonos)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(celular)) LIKE ?', [$termino]);

            if (count($palabras) > 1) {
                $subquery->orWhere(function (Builder $nombre) use ($palabras) {
                    foreach ($palabras as $palabra) {
                        $nombre->whereRaw(
                            "LOWER(TRIM(apellidos) || ' ' || TRIM(nombres) || ' ' || TRIM(nombres) || ' ' || TRIM(apellidos)) LIKE ?",
                            ['%'.$palabra.'%']
                        );
                    }
                });
            }
        });
    }

    private function datosFormulario(): array
    {
        return [
            'provincias' => DB::table('provincias')
                ->selectRaw('TRIM(nombre) AS nombre')
                ->orderByRaw('TRIM(nombre)')
                ->pluck('nombre'),
            'condicionesIva' => [
                'Categorizado',
                'Consumidor Final',
                'Exento',
                'Responsable Inscripto',
                'Responsable Monotributo',
                'Sujeto no Categorizado',
            ],
        ];
    }
}
