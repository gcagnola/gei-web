<?php

namespace App\Http\Requests;

use App\Models\Cliente;
use App\Rules\DocumentoClienteUnico;
use App\Services\NormalizadorDocumentoCliente;
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
        $personeria = trim((string) $this->input('personeria'));
        $tipoDocumento = trim((string) $this->input('doctipo'));
        $cuit = NormalizadorDocumentoCliente::cuit($this->input('cuit'));
        $cliente = $this->route('cliente');

        if (
            $personeria === 'Jurídica'
            && (
                ! $cliente instanceof Cliente
                || trim((string) $cliente->personeria) !== 'Jurídica'
            )
        ) {
            $tipoDocumento = 'CUIT';
        }

        $numeroDocumento = $personeria === 'Jurídica'
            && $tipoDocumento === 'CUIT'
                ? $cuit
                : NormalizadorDocumentoCliente::documento(
                    $tipoDocumento,
                    $this->input('docnro')
                );

        $this->merge([
            'personeria' => $personeria,
            'doctipo' => $tipoDocumento,
            'docnro' => $numeroDocumento,
            'cuit' => $cuit,
        ]);
    }

    public function rules(): array
    {
        $cliente = $this->route('cliente');
        $codigoCliente = $cliente instanceof Cliente
            ? (int) $cliente->codigo_cliente
            : null;
        $personaFisica = $this->input('personeria') === 'Física';

        return [
            'personeria' => ['required', Rule::in(['Física', 'Jurídica'])],
            'apellidos' => [
                Rule::requiredIf($personaFisica),
                'nullable',
                'string',
                'max:40',
            ],
            'nombres' => [
                Rule::requiredIf($personaFisica),
                'nullable',
                'string',
                'max:80',
            ],
            'razon_social' => [
                Rule::requiredIf(! $personaFisica),
                'nullable',
                'string',
                'max:100',
            ],
            'doctipo' => [
                'required',
                Rule::in($personaFisica
                    ? ['DNI', 'LC', 'LE']
                    : ['CUIT', 'DNI', 'LC', 'LE']),
            ],
            'docnro' => [
                'required',
                'string',
                'max:12',
                new DocumentoClienteUnico(
                    (string) $this->input('doctipo'),
                    $codigoCliente
                ),
            ],
            'cuit' => [
                Rule::requiredIf(! $personaFisica),
                'nullable',
                'digits:11',
            ],
            'domicilio' => ['nullable', 'string', 'max:100'],
            'provincia' => ['required', 'string', 'max:30'],
            'departamento' => ['nullable', 'string', 'max:30'],
            'localidad' => ['required', 'string', 'max:50'],
            'cp' => ['nullable', 'string', 'max:8'],
            'caractel' => ['nullable', 'string', 'max:6'],
            'telefonos' => ['nullable', 'string', 'max:50'],
            'celular' => ['nullable', 'string', 'max:25'],
            'fax' => ['nullable', 'string', 'max:25'],
            'email' => ['nullable', 'email', 'max:255'],
            'nacionalidad' => ['required', 'string', 'max:40'],
            'condicion_iva' => [
                'required',
                Rule::in([
                    'Categorizado',
                    'Consumidor Final',
                    'Exento',
                    'Responsable Inscripto',
                    'Responsable Monotributo',
                    'Sujeto no Categorizado',
                ]),
            ],
            'profesion' => ['nullable', 'string', 'max:100'],
            'lugar_de_trabajo' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'email' => 'Ingresá un correo electrónico válido.',
            'digits' => 'El campo :attribute debe contener :digits dígitos.',
            'max.string' => 'El campo :attribute no puede superar :max caracteres.',
            'in' => 'El valor seleccionado para :attribute no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'personeria' => 'personería',
            'apellidos' => 'apellidos',
            'nombres' => 'nombres',
            'razon_social' => 'razón social',
            'doctipo' => 'tipo de documento',
            'docnro' => 'número de documento',
            'cuit' => 'CUIT',
            'domicilio' => 'domicilio',
            'provincia' => 'provincia',
            'localidad' => 'localidad',
            'condicion_iva' => 'condición de IVA',
        ];
    }

    public function datosCliente(): array
    {
        $datos = $this->validated();

        foreach ($datos as $campo => $valor) {
            if (is_string($valor)) {
                $datos[$campo] = trim($valor);
            }

            if ($valor === null) {
                $datos[$campo] = '';
            }
        }

        $datos['cuit'] = NormalizadorDocumentoCliente::cuitFormateado(
            $datos['cuit'] ?? ''
        );

        return $datos;
    }
}
