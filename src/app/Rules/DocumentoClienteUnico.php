<?php

namespace App\Rules;

use App\Models\Cliente;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DocumentoClienteUnico implements ValidationRule
{
    public function __construct(
        private readonly string $tipoDocumento,
        private readonly ?int $clienteActual = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $numero = trim((string) $value);

        if ($numero === '') {
            return;
        }

        $mismoNumero = Cliente::query()
            ->whereRaw('TRIM(docnro) = ?', [$numero])
            ->when(
                $this->clienteActual,
                fn ($query) => $query->where(
                    'codigo_cliente',
                    '<>',
                    $this->clienteActual
                )
            );

        if (! $mismoNumero->exists()) {
            return;
        }

        if (
            (clone $mismoNumero)
                ->whereRaw('TRIM(doctipo) = ?', [$this->tipoDocumento])
                ->exists()
        ) {
            $fail('Ya existe un cliente con el mismo tipo y número de documento.');
        }
    }
}
