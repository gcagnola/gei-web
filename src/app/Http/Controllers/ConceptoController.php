<?php

namespace App\Http\Controllers;

use App\Models\Concepto;
use App\Models\ConceptoImputacionCaja;
use App\Models\CuentaCaja;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConceptoController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'dominio' => ['nullable', Rule::in(['INQ', 'PROP'])],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos', 'todos'])],
            'q' => ['nullable', 'string', 'max:100'],
            'editar' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Concepto::query()
            ->when($filtros['dominio'] ?? null, fn ($q, $dominio) => $q->where('dominio', $dominio))
            ->when(($filtros['estado'] ?? 'activos') !== 'todos', function ($q) use ($filtros) {
                $q->where('activo', ($filtros['estado'] ?? 'activos') === 'activos');
            })
            ->when(trim($filtros['q'] ?? '') !== '', function ($q) use ($filtros) {
                $texto = trim($filtros['q']);
                $q->where(function ($sub) use ($texto) {
                    $sub->where('codigo', 'ilike', "%{$texto}%")
                        ->orWhere('descripcion', 'ilike', "%{$texto}%");
                });
            })
            ->orderBy('dominio')
            ->orderBy('codigo');

        $conceptos = $query->paginate(50)->withQueryString();

        $conceptoEditar = null;
        if (!empty($filtros['editar'])) {
            $conceptoEditar = Concepto::find($filtros['editar']);
        }

        return view('parametros.conceptos.index', [
            'conceptos' => $conceptos,
            'conceptoEditar' => $conceptoEditar,
            'dominio' => $filtros['dominio'] ?? '',
            'estado' => $filtros['estado'] ?? 'activos',
            'texto' => $filtros['q'] ?? '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        Concepto::create([
            ...$datos,
            'origen_cobol' => null,
        ]);

        return redirect()
            ->route('parametros.conceptos.index', ['dominio' => $datos['dominio']])
            ->with('ok', 'Concepto creado correctamente.');
    }

    public function update(Request $request, Concepto $concepto): RedirectResponse
    {
        $datos = $this->validar($request, $concepto);
        $concepto->update($datos);

        return redirect()
            ->route('parametros.conceptos.index', ['dominio' => $datos['dominio']])
            ->with('ok', 'Concepto modificado correctamente.');
    }

    public function destroy(Concepto $concepto): RedirectResponse
    {
        $concepto->update(['activo' => false]);

        return redirect()
            ->route('parametros.conceptos.index', ['dominio' => $concepto->dominio])
            ->with('ok', 'Concepto dado de baja.');
    }

    public function caja(Concepto $concepto): View
    {
        $concepto->load('imputacionesCaja.cuentaCaja');

        $actuales = $concepto->imputacionesCaja
            ->keyBy(fn (ConceptoImputacionCaja $item) => $this->claveImputacion(
                $item->sede,
                $item->moneda,
                $item->judicial
            ));

        $filas = collect($this->combinaciones($concepto))->map(function (array $fila) use ($actuales) {
            $clave = $this->claveImputacion($fila['sede'], $fila['moneda'], $fila['judicial']);
            $actual = $actuales->get($clave);

            return [
                ...$fila,
                'cuenta_caja_codigo' => $actual?->cuenta_caja_codigo,
                'cuenta_caja_nombre' => $actual?->cuentaCaja?->nombre,
                'cuenta_caja_subcuenta' => $actual?->cuentaCaja?->subcuenta,
                'numero_contable' => $actual?->cuentaCaja?->numero_contable,
                'origen_cobol' => $actual?->origen_cobol,
            ];
        });

        return view('parametros.conceptos.caja', [
            'concepto' => $concepto,
            'filas' => $filas,
        ]);
    }

    public function updateCaja(Request $request, Concepto $concepto): RedirectResponse
    {
        $combinaciones = collect($this->combinaciones($concepto))
            ->keyBy(fn (array $fila) => $this->claveImputacion(
                $fila['sede'],
                $fila['moneda'],
                $fila['judicial']
            ));

        $datos = $request->validate([
            'cuentas' => ['nullable', 'array'],
            'cuentas.*' => [
                'nullable',
                'regex:/^[0-9]{4}$/',
                Rule::exists('cuentas_caja', 'codigo_cobol')->where(fn ($q) => $q->where('activo', true)),
            ],
        ], [
            'cuentas.*.regex' => 'Cada cuenta de Caja debe tener exactamente cuatro dígitos.',
            'cuentas.*.exists' => 'La cuenta indicada no existe en el plan de cuentas de Caja activo.',
        ]);

        DB::transaction(function () use ($concepto, $combinaciones, $datos) {
            foreach ($combinaciones as $clave => $fila) {
                $codigo = trim((string) ($datos['cuentas'][$clave] ?? ''));

                if ($codigo === '') {
                    ConceptoImputacionCaja::query()
                        ->where('concepto_id', $concepto->id)
                        ->where('sede', $fila['sede'])
                        ->where('moneda', $fila['moneda'])
                        ->where('judicial', $fila['judicial'])
                        ->delete();
                    continue;
                }

                $cuenta = CuentaCaja::query()
                    ->where('codigo_cobol', $codigo)
                    ->where('activo', true)
                    ->firstOrFail();

                ConceptoImputacionCaja::updateOrCreate(
                    [
                        'concepto_id' => $concepto->id,
                        'sede' => $fila['sede'],
                        'moneda' => $fila['moneda'],
                        'judicial' => $fila['judicial'],
                    ],
                    [
                        'cuenta_caja_codigo' => $codigo,
                        'cuenta_caja_id' => $cuenta->id,
                        'origen_cobol' => null,
                    ]
                );
            }
        });

        return redirect()
            ->route('parametros.conceptos.caja', $concepto)
            ->with('ok', 'Imputaciones de Caja guardadas correctamente.');
    }

    private function validar(Request $request, ?Concepto $concepto = null): array
    {
        $dominio = $request->input('dominio');

        return $request->validate([
            'dominio' => ['required', Rule::in(['INQ', 'PROP'])],
            'codigo' => [
                'required',
                'regex:/^[0-9]{2}$/',
                Rule::unique('conceptos', 'codigo')
                    ->where(fn ($q) => $q->where('dominio', $dominio))
                    ->ignore($concepto?->id),
            ],
            'descripcion' => ['required', 'string', 'max:120'],
            'activo' => ['required', 'boolean'],
        ], [
            'codigo.regex' => 'El código debe tener exactamente dos dígitos.',
            'codigo.unique' => 'Ya existe ese código para el dominio seleccionado.',
        ]);
    }

    private function combinaciones(Concepto $concepto): array
    {
        $filas = [];

        foreach (['SF', 'ST'] as $sede) {
            foreach (['ARS', 'USD'] as $moneda) {
                $filas[] = [
                    'sede' => $sede,
                    'moneda' => $moneda,
                    'judicial' => false,
                ];
            }
        }

        if ($concepto->dominio === 'INQ') {
            foreach (['SF', 'ST'] as $sede) {
                foreach (['ARS', 'USD'] as $moneda) {
                    $filas[] = [
                        'sede' => $sede,
                        'moneda' => $moneda,
                        'judicial' => true,
                    ];
                }
            }
        }

        return $filas;
    }

    private function claveImputacion(string $sede, string $moneda, bool $judicial): string
    {
        return strtolower($sede . '_' . $moneda . '_' . ($judicial ? 'J' : 'N'));
    }
}
