<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Inmueble extends Model
{
    protected $table = 'inmuebles';

    protected $primaryKey = 'codigo_inmueble';

    public $timestamps = false;

    protected $guarded = [];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(
            TipoDeInmueble::class,
            'cod_tipo_inmueble',
            'cod_tipo_inmueble'
        );
    }

    public function propietarios(): BelongsToMany
    {
        return $this->belongsToMany(
            Cliente::class,
            'inmuebles_propietarios',
            'codigo_inmueble',
            'codigo_cliente'
        )->withPivot(['porcentaje_titularidad', 'id_prop']);
    }

    public function getDomicilioVisibleAttribute(): string
    {
        $partes = [];

        foreach ([
            'domicilio_calle',
            'domicilio_nro',
            'domicilio_edificio',
            'domicilio_piso',
            'domicilio_dpto',
            'localidad',
        ] as $campo) {
            $valor = trim((string) $this->{$campo});

            if ($valor !== '' && ! in_array(mb_strtolower($valor), array_map('mb_strtolower', $partes), true)) {
                $partes[] = $valor;
            }
        }

        return $partes !== []
            ? implode(', ', $partes)
            : "Inmueble #{$this->codigo_inmueble}";
    }
}
