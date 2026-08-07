<?php

namespace App\Http\Requests;

use App\Models\Cliente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'tipo_persona', 'nombre', 'tipo_documento', 'numero_documento',
            'cuit', 'condicion_iva', 'domicilio', 'codigo_postal', 'localidad',
            'provincia', 'telefono', 'telefono_alternativo', 'email',
        ] as $campo) {
            if ($this->has($campo)) {
                $this->merge([$campo => trim((string) $this->input($campo))]);
            }
        }

        $this->merge([
            'tipo_persona' => strtoupper((string) $this->input('tipo_persona')),
            'tipo_documento' => strtoupper((string) $this->input('tipo_documento')),
            'numero_documento' => preg_replace('/\D+/', '', (string) $this->input('numero_documento')),
            'cuit' => preg_replace('/\D+/', '', (string) $this->input('cuit')),
            'activo' => $this->boolean('activo'),
        ]);
    }

    public function rules(): array
    {
        $cliente = $this->route('cliente');
        $clienteId = $cliente instanceof Cliente ? $cliente->id : null;
        $tipoDocumento = (string) $this->input('tipo_documento');

        return [
            'tipo_persona' => ['required', Rule::in(['FISICA', 'JURIDICA', 'DESCONOCIDA'])],
            'nombre' => ['required', 'string', 'max:180'],
            'tipo_documento' => ['nullable', Rule::in(['DNI', 'LC', 'LE', 'CUIT', 'CEDULA', 'PASAPORTE', 'OTRO'])],
            'numero_documento' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('clientes', 'numero_documento')
                    ->ignore($clienteId)
                    ->where(fn ($query) => $query->where('tipo_documento', $tipoDocumento)),
            ],
            'cuit' => ['nullable', 'digits:11', Rule::unique('clientes', 'cuit')->ignore($clienteId)],
            'condicion_iva' => [
                'nullable',
                Rule::in([
                    'RESPONSABLE_INSCRIPTO',
                    'RESPONSABLE_NO_INSCRIPTO',
                    'CONSUMIDOR_FINAL',
                    'EXENTO',
                    'MONOTRIBUTISTA',
                    'NO_CATEGORIZADO',
                ]),
            ],
            'domicilio' => ['nullable', 'string', 'max:180'],
            'codigo_postal' => ['nullable', 'string', 'max:12'],
            'localidad' => ['nullable', 'string', 'max:120'],
            'provincia' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:100'],
            'telefono_alternativo' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:180'],
            'activo' => ['required', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'unique' => 'Ya existe un cliente con ese :attribute.',
            'email' => 'Ingresá un correo electrónico válido.',
            'digits' => 'El campo :attribute debe contener :digits dígitos.',
        ];
    }

    public function attributes(): array
    {
        return [
            'tipo_persona' => 'tipo de persona',
            'nombre' => 'nombre o razón social',
            'tipo_documento' => 'tipo de documento',
            'numero_documento' => 'número de documento',
            'cuit' => 'CUIT',
            'condicion_iva' => 'condición de IVA',
        ];
    }

    public function datosCliente(): array
    {
        $datos = $this->safe()->only([
            'tipo_persona', 'nombre', 'tipo_documento', 'numero_documento',
            'cuit', 'condicion_iva', 'domicilio', 'codigo_postal', 'localidad',
            'provincia', 'telefono', 'telefono_alternativo', 'email', 'activo',
        ]);

        foreach ($datos as $campo => $valor) {
            if ($valor === '') {
                $datos[$campo] = null;
            }
        }

        return $datos;
    }

    /** @return list<int> */
    public function rolesIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('roles', []))));
    }
}
