<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Models\Cliente;
use App\Models\Role;
use App\Services\ComprobantesArcaService;
use App\Services\ImpuestosGarantizadosPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ClienteController extends Controller
{
    public function __construct(
        private readonly ImpuestosGarantizadosPdfService $impuestosGarantizados,
        private readonly ComprobantesArcaService $comprobantesArca,
    ) {
    }

    public function index(Request $request): View
    {
        return $this->vistaListado($request);
    }

    public function show(Request $request, Cliente $cliente): View|RedirectResponse
    {
        if ($cliente->id_cliente_canonico !== null) {
            return redirect()
                ->route('clientes.show', ['cliente' => $cliente->id_cliente_canonico])
                ->with('estado', "El cliente #{$cliente->id} fue unificado. Se muestra el cliente canónico #{$cliente->id_cliente_canonico}.");
        }

        return $this->vistaListado($request, $cliente);
    }

    public function create(): View
    {
        return view('clientes.create', $this->datosFormulario());
    }

    public function store(StoreClienteRequest $request): RedirectResponse
    {
        $cliente = DB::transaction(function () use ($request): Cliente {
            $cliente = Cliente::query()->create($request->datosCliente());
            $cliente->roles()->sync($request->rolesIds());

            return $cliente;
        });

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('estado', 'El cliente fue creado correctamente.');
    }

    public function edit(Cliente $cliente): View|RedirectResponse
    {
        if ($cliente->id_cliente_canonico !== null) {
            return redirect()
                ->route('clientes.edit', $cliente->id_cliente_canonico)
                ->with('estado', "El cliente #{$cliente->id} fue unificado. Se edita el cliente canónico #{$cliente->id_cliente_canonico}.");
        }

        $cliente->load('roles:id');

        return view('clientes.edit', [
            ...$this->datosFormulario(),
            'cliente' => $cliente,
        ]);
    }

    public function update(UpdateClienteRequest $request, Cliente $cliente): RedirectResponse
    {
        if ($cliente->id_cliente_canonico !== null) {
            return redirect()
                ->route('clientes.edit', $cliente->id_cliente_canonico)
                ->withErrors(['cliente' => 'No se puede modificar un cliente absorbido. Modifique el cliente canónico.']);
        }

        DB::transaction(function () use ($request, $cliente): void {
            $cliente->update($request->datosCliente());
            $cliente->roles()->sync($request->rolesIds());
        });

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('estado', 'Los datos del cliente fueron actualizados.');
    }

    private function vistaListado(Request $request, ?Cliente $clienteSeleccionado = null): View
    {
        $busqueda = trim((string) $request->query('buscar'));
        $rol = strtoupper(trim((string) $request->query('rol', 'TODOS')));
        $actividad = strtolower(trim((string) $request->query('actividad', 'todos')));

        if (! in_array($rol, ['TODOS', 'PROPIETARIO', 'INQUILINO', 'GARANTE', 'PROVEEDOR', 'OTRO'], true)) {
            $rol = 'TODOS';
        }
        if (! in_array($actividad, ['todos', 'activos', 'inactivos'], true)) {
            $actividad = 'todos';
        }

        $clientes = Cliente::query()
            ->whereNull('id_cliente_canonico')
            ->with([
                'roles:id,codigo,nombre',
                'cuentas' => fn ($query) => $query
                    ->select(['id', 'cliente_id', 'cuenta', 'rol', 'activo'])
                    ->orderBy('rol')
                    ->orderBy('cuenta'),
            ])
            ->when($busqueda !== '', fn (Builder $query) => $this->aplicarBusqueda($query, $busqueda))
            ->when(
                $rol !== 'TODOS',
                fn (Builder $query) => $query->whereHas(
                    'roles',
                    fn (Builder $roles) => $roles->where('codigo', $rol)
                )
            )
            ->when($actividad === 'activos', fn (Builder $query) => $query->where('activo', true))
            ->when($actividad === 'inactivos', fn (Builder $query) => $query->where('activo', false))
            ->orderByRaw("TRANSLATE(LOWER(TRIM(nombre)), 'áéíóúüñ', 'aeiouun')")
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        if (! $clienteSeleccionado && $request->filled('cliente')) {
            $clienteSeleccionado = Cliente::query()->find((int) $request->query('cliente'));
        }

        $clienteSeleccionado ??= $clientes->first();
        $inmuebles = collect();
        $contratos = collect();
        $inquilinosDePropietario = collect();
        $liquidaciones = collect();
        $documentos = collect();
        $repartos = collect();

        if ($clienteSeleccionado) {
            $clienteSeleccionado->load([
                'roles:id,codigo,nombre',
                'cuentas' => fn ($query) => $query->orderBy('rol')->orderBy('cuenta'),
            ]);

            $inmuebles = $this->inmueblesDelPropietario($clienteSeleccionado);

            $contratos = $this->contratosDelInquilino($clienteSeleccionado->id);

            $inquilinosDePropietario = $this->inquilinosDelPropietario(
                $clienteSeleccionado->id
            );

            $liquidaciones = $this->liquidacionesDelPropietario($clienteSeleccionado);
            $documentos = $this->documentosDelCliente($clienteSeleccionado, $liquidaciones);
            $repartos = $this->repartosDelPropietario($clienteSeleccionado);
        }

        return view('clientes.index', compact(
            'clientes',
            'clienteSeleccionado',
            'busqueda',
            'rol',
            'actividad',
            'inmuebles',
            'contratos',
            'inquilinosDePropietario',
            'liquidaciones',
            'documentos',
            'repartos'
        ));
    }

    private function inmueblesDelPropietario(Cliente $cliente): Collection
    {
        return DB::table('inmuebles as i')
            ->join('inmuebles_propietarios as ip', 'ip.inmueble_id', '=', 'i.id')
            ->leftJoin('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')
            ->where('ip.cliente_id', $cliente->id)
            ->select([
                'i.id',
                'i.domicilio',
                'i.estado',
                'ip.activo',
                'cc.cuenta',
            ])
            ->orderBy('i.domicilio')
            ->orderBy('cc.cuenta')
            ->get()
            ->groupBy(fn (object $fila): string => (string) $fila->id)
            ->map(function (Collection $filas): object {
                $primera = $filas->first();

                return (object) [
                    'id' => $primera->id,
                    'domicilio' => $primera->domicilio,
                    'estado' => $filas->contains(fn (object $fila): bool => $fila->estado === 'ACTIVO')
                        ? 'ACTIVO'
                        : $primera->estado,
                    'cuentas' => $filas->pluck('cuenta')->filter()->unique()->sort()->values()->implode(' · '),
                    'relaciones' => $filas->count(),
                ];
            })
            ->sortBy(fn (object $inmueble): string => mb_strtolower($inmueble->domicilio))
            ->values();
    }

    private function repartosDelPropietario(Cliente $cliente): Collection
    {
        if (! Schema::hasTable('repartos_propietarios')) {
            return collect();
        }

        $cuentas = $cliente->cuentas
            ->where('rol', 'PROPIETARIO')
            ->pluck('cuenta')
            ->map(fn (mixed $cuenta): string => preg_replace('/\D+/', '', (string) $cuenta) ?: '')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($cuentas === []) {
            return collect();
        }

        return DB::table('repartos_propietarios as rp')
            ->leftJoin('clientes as beneficiario_cliente', 'beneficiario_cliente.id', '=', 'rp.cliente_id')
            ->whereIn('rp.cuenta', $cuentas)
            ->where('rp.activo', true)
            ->select([
                'rp.id',
                'rp.cuenta',
                'rp.cuenta_impresa',
                'rp.propietario',
                'rp.beneficiario',
                'rp.porcentaje',
                'rp.ultimo_periodo',
                'rp.cliente_id',
                'beneficiario_cliente.nombre as cliente_nombre',
            ])
            ->orderBy('rp.cuenta')
            ->orderByRaw("TRANSLATE(LOWER(TRIM(rp.beneficiario)), 'áéíóúüñ', 'aeiouun')")
            ->orderBy('rp.id')
            ->get();
    }

    private function contratosDelInquilino(int $clienteId): Collection
    {
        return DB::table('contratos as c')
                ->join('contratos_inquilinos as ci', 'ci.contrato_id', '=', 'c.id')
                ->leftJoin('contratos_inmuebles as cim', 'cim.contrato_id', '=', 'c.id')
                ->leftJoin('inmuebles as i', 'i.id', '=', 'cim.inmueble_id')
                ->leftJoin('inmuebles_propietarios as ip', function ($join): void {
                    $join->on('ip.inmueble_id', '=', 'i.id')
                        ->where('ip.activo', true);
                })
                ->leftJoin('clientes as propietario', 'propietario.id', '=', 'ip.cliente_id')
                ->where('ci.cliente_id', $clienteId)
                ->select([
                    'c.id',
                    'c.codigo_origen',
                    'c.fecha_inicio',
                    'c.fecha_fin',
                    'c.estado',
                    'c.cuenta_inquilino',
                    'c.cuenta_propietario',
                    'i.id as inmueble_id',
                    'i.domicilio as inmueble_domicilio',
                    'propietario.id as propietario_id',
                    'propietario.nombre as propietario_nombre',
                ])
                ->orderByDesc('c.fecha_inicio')
                ->orderBy('c.id')
                ->get()
                ->groupBy('id')
                ->map(function (Collection $filas): object {
                    $contrato = clone $filas->first();
                    $contrato->inmuebles = $filas->pluck('inmueble_domicilio')
                        ->filter()->unique()->sort()->values()->implode(' · ');
                    $contrato->propietarios = $filas->pluck('propietario_nombre')
                        ->filter()->map(fn (string $nombre): string => trim($nombre))
                        ->unique()->sort()->values()->implode(' · ');

                    return $contrato;
                })
                ->values();
    }

    private function inquilinosDelPropietario(int $clienteId): Collection
    {
        return DB::table('inmuebles_propietarios as ip')
                ->join('inmuebles as i', 'i.id', '=', 'ip.inmueble_id')
                ->join('contratos_inmuebles as cim', 'cim.inmueble_id', '=', 'i.id')
                ->join('contratos as c', 'c.id', '=', 'cim.contrato_id')
                ->join('contratos_inquilinos as ci', 'ci.contrato_id', '=', 'c.id')
                ->join('clientes as inquilino', 'inquilino.id', '=', 'ci.cliente_id')
                ->where('ip.cliente_id', $clienteId)
                ->select([
                    'c.id as contrato_id',
                    'c.codigo_origen',
                    'c.cuenta_inquilino',
                    'c.fecha_inicio',
                    'c.fecha_fin',
                    'c.estado',
                    'i.id as inmueble_id',
                    'i.domicilio as inmueble_domicilio',
                    'inquilino.id as inquilino_id',
                    'inquilino.nombre as inquilino_nombre',
                    'inquilino.cuit as inquilino_cuit',
                ])
                ->orderByDesc('c.fecha_inicio')
                ->get()
                ->unique(fn (object $fila): string => implode('|', [
                    $fila->contrato_id,
                    $fila->inmueble_id,
                    $fila->inquilino_id,
                ]))
                ->sortBy(fn (object $fila): string => mb_strtolower(trim($fila->inquilino_nombre)))
                ->values();
    }

    private function liquidacionesDelPropietario(Cliente $cliente): Collection
    {
        $cuit = preg_replace('/\D+/', '', (string) $cliente->cuit) ?: '';
        $cuentas = $cliente->cuentas
            ->where('rol', 'PROPIETARIO')
            ->pluck('cuenta')
            ->map(fn (mixed $cuenta): string => preg_replace('/\D+/', '', (string) $cuenta) ?: '')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $liquidaciones = DB::table('liquidaciones_propietarios')
            ->where(function ($query) use ($cliente, $cuit, $cuentas): void {
                $query->where('cliente_id', $cliente->id);

                if ($cuit !== '') {
                    $query->orWhereRaw(
                        "REGEXP_REPLACE(COALESCE(cuit, ''), '[^0-9]', '', 'g') = ?",
                        [$cuit]
                    );
                }

                if ($cuentas !== []) {
                    $query->orWhereIn('cuenta', $cuentas);
                }
            })
            ->select([
                'id',
                'periodo',
                'fecha',
                'cuenta',
                'cuenta_impresa',
                'comprobante',
                'numero_interno',
                'total_final',
                'estado',
                'pdf_ruta',
            ])
            ->orderByDesc('periodo')
            ->orderByDesc('numero_interno')
            ->limit(24)
            ->get();

        foreach ($liquidaciones as $liquidacion) {
            $liquidacion->pdf_disponible = $liquidacion->estado === 'PDF_GENERADO'
                && ! empty($liquidacion->pdf_ruta)
                && Storage::disk('liquidaciones')->exists((string) $liquidacion->pdf_ruta);

            $cuentaVisible = (string) ($liquidacion->cuenta_impresa ?: $liquidacion->cuenta);
            $rutaImpuestos = $this->impuestosGarantizados->rutaPdfParaLiquidacion(
                (string) $liquidacion->periodo,
                $liquidacion->pdf_ruta ? (string) $liquidacion->pdf_ruta : null,
                $cuentaVisible,
            );
            $liquidacion->impuestos_pdf_disponible = $rutaImpuestos !== null
                && Storage::disk('liquidaciones')->exists($rutaImpuestos);
        }

        return $liquidaciones;
    }

    /**
     * Reúne, por período, todos los documentos operativos del cliente:
     * liquidaciones, impuestos garantizados y comprobantes ARCA.
     *
     * Los comprobantes ARCA se buscan por TODAS las cuentas vinculadas al cliente,
     * independientemente de si son cuentas de propietario o inquilino.
     *
     * @param Collection<int, object> $liquidaciones
     * @return Collection<int, object>
     */
    private function documentosDelCliente(Cliente $cliente, Collection $liquidaciones): Collection
    {
        $porPeriodo = [];

        $obtenerPeriodo = static function (array &$porPeriodo, string $periodo): object {
            if (! isset($porPeriodo[$periodo])) {
                $porPeriodo[$periodo] = (object) [
                    'periodo' => $periodo,
                    'cuentas' => collect(),
                    'liquidaciones' => collect(),
                    'comprobantes_arca' => collect(),
                ];
            }

            return $porPeriodo[$periodo];
        };

        // Primero incorporamos las liquidaciones e impuestos de propietario.
        foreach ($liquidaciones as $liquidacion) {
            $periodo = trim((string) $liquidacion->periodo);
            if ($periodo === '') {
                continue;
            }

            $fila = $obtenerPeriodo($porPeriodo, $periodo);
            $cuenta = $this->comprobantesArca->normalizarCuenta(
                (string) ($liquidacion->cuenta_impresa ?: $liquidacion->cuenta)
            );

            if ($cuenta !== '') {
                $fila->cuentas->put($cuenta, $cuenta);
            }

            $fila->liquidaciones->put((int) $liquidacion->id, $liquidacion);
        }

        // ARCA se busca por todas las cuentas del cliente. Limitamos a los
        // últimos 12 períodos físicos para mantener ágil la ficha de cliente.
        $cuentas = $cliente->cuentas
            ->pluck('cuenta')
            ->map(fn (mixed $cuenta): string => $this->comprobantesArca->normalizarCuenta((string) $cuenta))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($cuentas !== []) {
            foreach ($this->comprobantesArca->periodosDisponibles()->take(12) as $periodo) {
                $grupos = $this->comprobantesArca->porCuentasYPeriodo($cuentas, (string) $periodo);

                if ($grupos->isEmpty()) {
                    continue;
                }

                $fila = $obtenerPeriodo($porPeriodo, (string) $periodo);

                foreach ($grupos as $cuenta => $comprobantes) {
                    $cuentaNormalizada = $this->comprobantesArca->normalizarCuenta((string) $cuenta);

                    if ($cuentaNormalizada !== '') {
                        $fila->cuentas->put($cuentaNormalizada, $cuentaNormalizada);
                    }

                    foreach ($comprobantes as $comprobante) {
                        $fila->comprobantes_arca->put(
                            (string) $comprobante->nombre_archivo,
                            $comprobante
                        );
                    }
                }
            }
        }

        return collect($porPeriodo)
            ->map(function (object $fila): object {
                $fila->cuentas = $fila->cuentas->values();
                $fila->liquidaciones = $fila->liquidaciones->values();
                $fila->comprobantes_arca = $fila->comprobantes_arca
                    ->values()
                    ->sortByDesc(
                        fn (object $item): string =>
                            $item->tipo_codigo.'-'.$item->punto_venta.'-'.$item->numero_comprobante
                    )
                    ->values();

                return $fila;
            })
            ->sortByDesc(fn (object $fila): string => $fila->periodo)
            ->take(24)
            ->values();
    }

    private function aplicarBusqueda(Builder $query, string $busqueda): void
    {
        $termino = '%'.mb_strtolower($busqueda).'%';
        $cuenta = preg_replace('/\D+/', '', $busqueda) ?: '';

        $query->where(function (Builder $subquery) use ($termino, $cuenta): void {
            $subquery
                ->whereRaw('LOWER(CAST(clientes.id AS TEXT)) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(TRIM(clientes.nombre)) LIKE ?', [$termino])
                ->orWhereRaw("LOWER(TRIM(COALESCE(clientes.numero_documento, ''))) LIKE ?", [$termino])
                ->orWhereRaw("LOWER(TRIM(COALESCE(clientes.cuit, ''))) LIKE ?", [$termino])
                ->orWhereRaw("LOWER(TRIM(COALESCE(clientes.email, ''))) LIKE ?", [$termino])
                ->orWhereRaw("LOWER(TRIM(COALESCE(clientes.telefono, ''))) LIKE ?", [$termino]);

            if ($cuenta !== '') {
                $subquery->orWhereHas(
                    'cuentas',
                    fn (Builder $cuentas) => $cuentas->whereRaw(
                        "REGEXP_REPLACE(cuenta, '[^0-9]', '', 'g') LIKE ?",
                        ['%'.$cuenta.'%']
                    )
                );
            }
        });
    }

    private function datosFormulario(): array
    {
        return [
            'rolesDisponibles' => Role::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
            'condicionesIva' => [
                'RESPONSABLE_INSCRIPTO' => 'Responsable inscripto',
                'RESPONSABLE_NO_INSCRIPTO' => 'Responsable no inscripto',
                'CONSUMIDOR_FINAL' => 'Consumidor final',
                'EXENTO' => 'Exento',
                'MONOTRIBUTISTA' => 'Monotributista',
                'NO_CATEGORIZADO' => 'No categorizado',
            ],
        ];
    }
}
