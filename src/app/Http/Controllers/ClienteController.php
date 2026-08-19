<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Models\Cliente;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\View\View;

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
        $cliente = DB::transaction(function () use ($request): Cliente {
            $cliente = Cliente::query()->create($request->datosCliente());
            $cliente->roles()->sync($request->rolesIds());

            return $cliente;
        });

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('estado', 'El cliente fue creado correctamente.');
    }

    public function edit(Cliente $cliente): View
    {
        $cliente->load('roles:id');

        return view('clientes.edit', [
            ...$this->datosFormulario(),
            'cliente' => $cliente,
        ]);
    }

    public function update(UpdateClienteRequest $request, Cliente $cliente): RedirectResponse
    {
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

        return DB::table('liquidaciones_propietarios')
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
