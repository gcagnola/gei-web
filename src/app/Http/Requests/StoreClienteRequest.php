<?php

namespace App\Http\Requests;

class StoreClienteRequest extends ClienteRequest
{
    public function datosCliente(): array
    {
        $datos = parent::datosCliente();

        if ($datos['personeria'] === 'Física') {
            $datos['razon_social'] = '';
        } else {
            $datos['doctipo'] = 'CUIT';
            $datos['docnro'] = preg_replace('/\D+/', '', $datos['cuit']);
            $datos['apellidos'] = '';
            $datos['nombres'] = '';
        }

        return $datos;
    }
}
