<?php

namespace App\Services;

final class ClienteCobolNormalizer
{
    /**
     * @return array{cuenta:string,nombre:string,tipo_documento:?string,numero_documento:?string,
     * cuit:?string,condicion_iva:?string,tipo_iva_origen:?string,nro_iva_origen:?string,
     * cuenta_propietario:?string,domicilio:?string,codigo_postal:?string,
     * localidad:?string,provincia:?string,telefono:?string,telefono_alternativo:?string,
     * activo:bool,archivo_origen_id:?int,numero_linea:?int,hash_origen:?string}
     */
    public function propietario(object $fila): array
    {
        return [
            'cuenta' => $this->cuenta($fila->nro_cta_prop ?? null),
            'nombre' => $this->texto($fila->nombre_prop ?? null) ?? 'SIN NOMBRE',
            'tipo_documento' => null,
            'numero_documento' => null,
            'cuit' => $this->cuit($fila->nro_iva ?? null),
            'condicion_iva' => $this->condicionIvaPropietario($fila->tipo_iva ?? null),
            'tipo_iva_origen' => $this->texto($fila->tipo_iva ?? null),
            'nro_iva_origen' => $this->texto($fila->nro_iva ?? null),
            'cuenta_propietario' => null,
            'domicilio' => $this->texto($fila->domicilio_prop ?? null),
            'codigo_postal' => $this->texto($fila->encot_prop ?? null),
            'localidad' => $this->texto($fila->localidad_prop ?? null),
            'provincia' => $this->texto($fila->provincia_prop ?? null),
            'telefono' => $this->telefono($fila->telefono_1 ?? null),
            'telefono_alternativo' => $this->telefono($fila->telefono_2 ?? null),
            'activo' => true,
            'archivo_origen_id' => isset($fila->archivo_id) ? (int) $fila->archivo_id : null,
            'numero_linea' => isset($fila->numero_linea) ? (int) $fila->numero_linea : null,
            'hash_origen' => $this->texto($fila->sha256_registro ?? null),
        ];
    }

    /**
     * @return array{cuenta:string,nombre:string,tipo_documento:?string,numero_documento:?string,
     * cuit:?string,condicion_iva:?string,tipo_iva_origen:?string,nro_iva_origen:?string,
     * cuenta_propietario:?string,domicilio:?string,codigo_postal:?string,
     * localidad:?string,provincia:?string,telefono:?string,telefono_alternativo:?string,
     * activo:bool,archivo_origen_id:?int,numero_linea:?int,hash_origen:?string}
     */
    public function inquilino(object $fila): array
    {
        $tipoDocumento = $this->tipoDocumento($fila->tipo_documento ?? null);
        $numeroDocumento = $this->documento($fila->nro_documento ?? null);

        if ($tipoDocumento === null || $numeroDocumento === null) {
            $tipoDocumento = null;
            $numeroDocumento = null;
        }

        return [
            'cuenta' => $this->cuenta($fila->cta_inquilino ?? null),
            'nombre' => $this->texto($fila->nombre_inquilino ?? null) ?? 'SIN NOMBRE',
            'tipo_documento' => $tipoDocumento,
            'numero_documento' => $numeroDocumento,
            'cuit' => $this->cuit($fila->nro_iva ?? null),
            'condicion_iva' => $this->condicionIvaInquilino($fila->tipo_iva ?? null),
            'tipo_iva_origen' => $this->texto($fila->tipo_iva ?? null),
            'nro_iva_origen' => $this->texto($fila->nro_iva ?? null),
            'cuenta_propietario' => $this->cuentaOpcional($fila->cta_propietario ?? null),
            'domicilio' => $this->texto($fila->domicilio_legal ?? null),
            'codigo_postal' => $this->texto($fila->encot_legal ?? null),
            'localidad' => $this->texto($fila->localidad_legal ?? null),
            'provincia' => $this->texto($fila->provincia_legal ?? null),
            'telefono' => $this->telefono($fila->telefono_particular ?? null),
            'telefono_alternativo' => $this->telefono($fila->telefono_laboral ?? null),
            'activo' => strtoupper((string) ($fila->marca_baja ?? '')) !== 'B',
            'archivo_origen_id' => isset($fila->archivo_id) ? (int) $fila->archivo_id : null,
            'numero_linea' => isset($fila->numero_linea) ? (int) $fila->numero_linea : null,
            'hash_origen' => $this->texto($fila->sha256_registro ?? null),
        ];
    }

    public function cuenta(mixed $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor) ?? '';
    }

    public function cuit(mixed $valor): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $valor) ?? '';

        if (strlen($digitos) === 12 && str_starts_with($digitos, '0')) {
            $digitos = substr($digitos, 1);
        }

        if (strlen($digitos) !== 11 || preg_match('/^0+$/', $digitos)) {
            return null;
        }

        return $digitos;
    }

    public function documento(mixed $valor): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $valor) ?? '';
        $digitos = ltrim($digitos, '0');

        if ($digitos === '' || strlen($digitos) > 9) {
            return null;
        }

        return $digitos;
    }

    public function tipoDocumento(mixed $codigo): ?string
    {
        return match (trim((string) $codigo)) {
            '1' => 'LE',
            '2' => 'LC',
            '3' => 'DNI',
            '4' => 'CEDULA',
            '5' => 'PASAPORTE',
            default => null,
        };
    }

    public function tipoPersona(?string $cuit, ?string $numeroDocumento): string
    {
        if ($cuit !== null) {
            return match (substr($cuit, 0, 2)) {
                '20', '23', '24', '27' => 'FISICA',
                '30', '33', '34' => 'JURIDICA',
                default => 'DESCONOCIDA',
            };
        }

        return $numeroDocumento !== null ? 'FISICA' : 'DESCONOCIDA';
    }

    private function condicionIvaPropietario(mixed $codigo): ?string
    {
        return match (trim((string) $codigo)) {
            '1' => 'RESPONSABLE_INSCRIPTO',
            '2' => 'RESPONSABLE_NO_INSCRIPTO',
            '3' => 'EXENTO',
            '4' => 'NO_RESPONSABLE',
            '5' => 'MONOTRIBUTISTA',
            '6' => 'NO_CATEGORIZADO',
            default => null,
        };
    }

    private function condicionIvaInquilino(mixed $codigo): ?string
    {
        return match (trim((string) $codigo)) {
            '1' => 'RESPONSABLE_INSCRIPTO',
            '2' => 'RESPONSABLE_NO_INSCRIPTO',
            '3' => 'CONSUMIDOR_FINAL',
            '4' => 'EXENTO',
            '5' => 'MONOTRIBUTISTA',
            '6' => 'NO_CATEGORIZADO',
            default => null,
        };
    }

    private function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function telefono(mixed $valor): ?string
    {
        $telefono = $this->texto($valor);

        return $telefono === null || preg_match('/^0+$/', $telefono) ? null : $telefono;
    }

    private function cuentaOpcional(mixed $valor): ?string
    {
        $cuenta = $this->cuenta($valor);

        return $cuenta === '' || preg_match('/^0+$/', $cuenta) ? null : $cuenta;
    }
}
