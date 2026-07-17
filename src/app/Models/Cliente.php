<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'codigo_cliente';

    public $timestamps = false;

    protected $fillable = [
        'personeria',
        'doctipo',
        'docnro',
        'apellidos',
        'nombres',
        'razon_social',
        'domicilio',
        'provincia',
        'departamento',
        'localidad',
        'cp',
        'caractel',
        'telefonos',
        'celular',
        'fax',
        'email',
        'nacionalidad',
        'cuit',
        'condicion_iva',
        'profesion',
        'lugar_de_trabajo',
        'web_validada',
        'web_operativo',
    ];

    protected function casts(): array
    {
        return [
            'codigo_cliente' => 'integer',
            'web_validada' => 'boolean',
            'web_operativo' => 'boolean',
        ];
    }

    public function contratos(): BelongsToMany
    {
        return $this->belongsToMany(
            Contrato::class,
            'contratos_inquilinos',
            'codigo_cliente',
            'codigo_contrato'
        )->withPivot(['porcentaje_participacion', 'id_inq']);
    }

    public function inmueblesPropios(): BelongsToMany
    {
        return $this->belongsToMany(
            Inmueble::class,
            'inmuebles_propietarios',
            'codigo_cliente',
            'codigo_inmueble'
        )->withPivot(['porcentaje_titularidad', 'id_prop']);
    }

    public function getNombreVisibleAttribute(): string
    {
        $razonSocial = trim((string) $this->razon_social);
        $nombrePersonal = trim(implode(', ', array_filter([
            trim((string) $this->apellidos),
            trim((string) $this->nombres),
        ])));

        if (trim((string) $this->personeria) === 'Física' && $nombrePersonal !== '') {
            return $nombrePersonal;
        }

        foreach ([
            $razonSocial,
            $nombrePersonal,
            trim((string) $this->cuit),
            trim((string) $this->docnro),
        ] as $valor) {
            if ($valor !== '') {
                return $valor;
            }
        }

        return "Cliente #{$this->codigo_cliente}";
    }
}
