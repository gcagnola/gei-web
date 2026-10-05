<?php

namespace App\Http\Controllers;

use App\Services\RelojAnvizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class RelojController extends Controller
{
    public function __construct(private readonly RelojAnvizService $reloj)
    {
    }

    public function index(): View
    {
        return view('reloj.index');
    }

    public function status(): JsonResponse
    {
        return $this->proxy(fn (): array => $this->reloj->status());
    }

    public function usuarios(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->proxy(
            fn (): array => $this->reloj->usuarios(trim((string) ($datos['q'] ?? '')))
        );
    }

    public function marcaciones(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'user' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->proxy(fn (): array => $this->reloj->marcaciones(
            (int) ($datos['limit'] ?? 200),
            trim((string) ($datos['user'] ?? '')),
            (string) ($datos['from'] ?? ''),
            (string) ($datos['to'] ?? ''),
        ));
    }

    public function guardarNombre(Request $request, int $codigo): JsonResponse
    {
        $datos = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->proxy(
            fn (): array => $this->reloj->guardarNombreLocal($codigo, trim((string) ($datos['name'] ?? '')))
        );
    }

    public function sincronizarUsuarios(): JsonResponse
    {
        return $this->proxy(fn (): array => $this->reloj->sincronizarUsuarios());
    }

    public function sincronizarNuevas(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'max' => ['nullable', 'integer', 'min:25', 'max:5000'],
        ]);

        return $this->proxy(
            fn (): array => $this->reloj->sincronizarNuevas((int) ($datos['max'] ?? 1000))
        );
    }

    private function proxy(callable $operacion): JsonResponse
    {
        try {
            return response()->json($operacion());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 503);
        }
    }
}
