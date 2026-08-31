<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarComprobantesArcaClienteJob;
use App\Models\Cliente;
use App\Services\ComprobantesArcaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

final class ClienteComprobanteArcaController extends Controller
{
    private const ACTIVIDADES = ['activos', 'pasivos', 'todos'];

    public function __construct(
        private readonly ComprobantesArcaService $comprobantesArca,
    ) {
    }

    public function index(Request $request): View
    {
        $filtros = $this->filtros($request);
        $periodosDisponibles = $this->comprobantesArca->periodosDisponibles();

        $periodoSolicitado = trim((string) $request->query('periodo', ''));
        $periodo = preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodoSolicitado) === 1
            ? $periodoSolicitado
            : (string) ($periodosDisponibles->first() ?? '');

        $todos = $periodo !== ''
            ? $this->clientesConComprobantes($periodo, $filtros)
            : collect();

        $porPagina = 30;
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        $clientes = new LengthAwarePaginator(
            $todos->forPage($pagina, $porPagina)->values(),
            $todos->count(),
            $porPagina,
            $pagina,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $cantidadEnviables = $todos
            ->filter(fn (object $cliente): bool => $this->emailValido((string) $cliente->email))
            ->count();

        return view('clientes.comprobantes-arca', [
            'clientes' => $clientes,
            'periodosDisponibles' => $periodosDisponibles,
            'periodo' => $periodo,
            'filtros' => $filtros,
            'cantidadEnviables' => $cantidadEnviables,
            'hayFiltros' => $filtros['buscar'] !== ''
                || $filtros['cuenta'] !== ''
                || $filtros['comprobante'] !== ''
                || $filtros['actividad'] !== 'activos',
        ]);
    }

    public function enviarEmail(Request $request, Cliente $cliente): RedirectResponse
    {
        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
        ]);

        if ($cliente->id_cliente_canonico !== null) {
            return back()->withErrors([
                'envios' => 'El cliente fue unificado. Envíe los comprobantes desde el cliente canónico.',
            ]);
        }

        $email = mb_strtolower(trim((string) $cliente->email));
        if (! $this->emailValido($email)) {
            return back()->withErrors([
                'envios' => 'El cliente no tiene un email válido asociado.',
            ]);
        }

        $archivos = $this->archivosDelCliente($cliente, (string) $datos['periodo']);
        if ($archivos->isEmpty()) {
            return back()->withErrors([
                'envios' => 'El cliente no tiene comprobantes ARCA disponibles para ese período.',
            ]);
        }

        EnviarComprobantesArcaClienteJob::dispatch(
            (int) $cliente->id,
            (string) $datos['periodo'],
            $email,
            $archivos->pluck('nombre_archivo')->values()->all(),
        );

        return back()->with(
            'estado',
            sprintf(
                'El envío de %d comprobante(s) ARCA a %s fue agregado a la cola de emails.',
                $archivos->count(),
                $email
            )
        );
    }

    public function enviarEmails(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'actividad' => ['nullable', 'in:activos,pasivos,todos'],
            'buscar' => ['nullable', 'string', 'max:180'],
            'cuenta' => ['nullable', 'string', 'max:30'],
            'comprobante' => ['nullable', 'string', 'max:40'],
        ]);

        $filtros = [
            'actividad' => (string) ($datos['actividad'] ?? 'activos'),
            'buscar' => trim((string) ($datos['buscar'] ?? '')),
            'cuenta' => trim((string) ($datos['cuenta'] ?? '')),
            'comprobante' => trim((string) ($datos['comprobante'] ?? '')),
        ];

        $clientes = $this->clientesConComprobantes((string) $datos['periodo'], $filtros);

        $programados = 0;
        $sinEmail = 0;

        foreach ($clientes as $cliente) {
            $email = mb_strtolower(trim((string) $cliente->email));
            if (! $this->emailValido($email)) {
                $sinEmail++;
                continue;
            }

            EnviarComprobantesArcaClienteJob::dispatch(
                (int) $cliente->id,
                (string) $datos['periodo'],
                $email,
                $cliente->comprobantes_arca
                    ->pluck('nombre_archivo')
                    ->unique()
                    ->values()
                    ->all(),
            );

            $programados++;
        }

        return redirect()
            ->route('clientes.comprobantes-arca.index', [
                'periodo' => (string) $datos['periodo'],
                'actividad' => $filtros['actividad'],
                'buscar' => $filtros['buscar'],
                'cuenta' => $filtros['cuenta'],
                'comprobante' => $filtros['comprobante'],
            ])
            ->with(
                'estado',
                sprintf(
                    'Envío masivo programado: %d email(s) en cola y %d cliente(s) sin email válido.',
                    $programados,
                    $sinEmail
                )
            );
    }

    /**
     * @param array{actividad:string,buscar:string,cuenta:string,comprobante:string} $filtros
     * @return Collection<int, object>
     */
    private function clientesConComprobantes(string $periodo, array $filtros): Collection
    {
        $porCuenta = $this->comprobantesArca->porPeriodo($periodo);

        if ($filtros['comprobante'] !== '') {
            $aguja = $this->normalizarTextoComprobante($filtros['comprobante']);

            $porCuenta = $porCuenta
                ->map(function (Collection $items) use ($aguja): Collection {
                    return $items
                        ->filter(function (object $item) use ($aguja): bool {
                            $texto = $this->normalizarTextoComprobante(
                                $item->tipo_codigo
                                .$item->punto_venta
                                .$item->numero_comprobante
                                .$item->nombre_archivo
                            );

                            return $aguja === '' || str_contains($texto, $aguja);
                        })
                        ->values();
                })
                ->filter(fn (Collection $items): bool => $items->isNotEmpty());
        }

        if ($porCuenta->isEmpty()) {
            return collect();
        }

        $cuentaBuscada = $this->comprobantesArca->normalizarCuenta($filtros['cuenta']);

        $consulta = DB::table('clientes_cuentas as cc')
            ->join('clientes as c', 'c.id', '=', 'cc.cliente_id')
            ->select([
                'c.id',
                'c.nombre',
                'c.tipo_documento',
                'c.numero_documento',
                'c.cuit',
                'c.email',
                'c.activo',
                'cc.cuenta',
                'cc.rol',
            ])
            ->when(
                Schema::hasColumn('clientes', 'id_cliente_canonico'),
                fn ($query) => $query->whereNull('c.id_cliente_canonico')
            )
            ->when(
                $filtros['actividad'] === 'activos',
                fn ($query) => $query->where('c.activo', true)
            )
            ->when(
                $filtros['actividad'] === 'pasivos',
                fn ($query) => $query->where('c.activo', false)
            )
            ->when(
                $filtros['buscar'] !== '',
                function ($query) use ($filtros): void {
                    $patron = '%'.$this->escaparLike($filtros['buscar']).'%';
                    $digitos = $this->comprobantesArca->normalizarCuenta($filtros['buscar']);

                    $query->where(function ($busqueda) use ($patron, $digitos): void {
                        $busqueda
                            ->whereRaw('c.nombre ILIKE ?', [$patron])
                            ->orWhereRaw("COALESCE(c.cuit, '') ILIKE ?", [$patron])
                            ->orWhereRaw("COALESCE(c.numero_documento, '') ILIKE ?", [$patron]);

                        if ($digitos !== '') {
                            $busqueda->orWhereRaw(
                                "regexp_replace(COALESCE(cc.cuenta, ''), '[^0-9]', '', 'g') LIKE ?",
                                ['%'.$this->escaparLike($digitos).'%']
                            );
                        }
                    });
                }
            );

        $grupos = [];

        foreach ($consulta->orderBy('c.id')->orderBy('cc.cuenta')->get() as $fila) {
            $cuenta = $this->comprobantesArca->normalizarCuenta((string) $fila->cuenta);

            if ($cuenta === '' || ! $porCuenta->has($cuenta)) {
                continue;
            }

            if ($cuentaBuscada !== '' && ! str_contains($cuenta, $cuentaBuscada)) {
                continue;
            }

            $id = (int) $fila->id;

            if (! isset($grupos[$id])) {
                $grupos[$id] = (object) [
                    'id' => $id,
                    'nombre' => trim((string) $fila->nombre),
                    'tipo_documento' => $fila->tipo_documento,
                    'numero_documento' => $fila->numero_documento,
                    'cuit' => $fila->cuit,
                    'email' => $fila->email,
                    'activo' => (bool) $fila->activo,
                    'cuentas' => collect(),
                    'comprobantes_arca' => collect(),
                ];
            }

            $grupos[$id]->cuentas->put($cuenta, (string) $fila->cuenta);

            foreach ($porCuenta->get($cuenta, collect()) as $comprobante) {
                $grupos[$id]->comprobantes_arca->put(
                    (string) $comprobante->nombre_archivo,
                    $comprobante
                );
            }
        }

        return collect($grupos)
            ->map(function (object $cliente): object {
                $cliente->cuentas = $cliente->cuentas->values();

                $cliente->comprobantes_arca = $cliente->comprobantes_arca
                    ->values()
                    ->sortByDesc(
                        fn (object $item): string => $item->tipo_codigo
                            .'-'.$item->punto_venta
                            .'-'.$item->numero_comprobante
                    )
                    ->values();

                $cliente->comprobantes_arca_cantidad = $cliente->comprobantes_arca->count();

                return $cliente;
            })
            ->sortBy(fn (object $cliente): string => mb_strtolower($cliente->nombre))
            ->values();
    }

    /** @return Collection<int, object> */
    private function archivosDelCliente(Cliente $cliente, string $periodo): Collection
    {
        $cliente->loadMissing('cuentas');

        $cuentas = $cliente->cuentas
            ->pluck('cuenta')
            ->map(fn (mixed $cuenta): string => $this->comprobantesArca->normalizarCuenta((string) $cuenta))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->comprobantesArca
            ->porCuentasYPeriodo($cuentas, $periodo)
            ->flatten(1)
            ->unique('nombre_archivo')
            ->values();
    }

    /** @return array{actividad:string,buscar:string,cuenta:string,comprobante:string} */
    private function filtros(Request $request): array
    {
        $actividad = strtolower(trim((string) $request->query('actividad', 'activos')));

        if (! in_array($actividad, self::ACTIVIDADES, true)) {
            $actividad = 'activos';
        }

        return [
            'actividad' => $actividad,
            'buscar' => mb_substr(trim((string) $request->query('buscar', '')), 0, 180),
            'cuenta' => mb_substr(trim((string) $request->query('cuenta', '')), 0, 30),
            'comprobante' => mb_substr(trim((string) $request->query('comprobante', '')), 0, 40),
        ];
    }

    private function emailValido(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function normalizarTextoComprobante(string $texto): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper($texto)) ?? '';
    }

    private function escaparLike(string $valor): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valor);
    }
}
