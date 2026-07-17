<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoDeInmueble extends Model
{
    protected $table = 'tipos_de_inmuebles';

    protected $primaryKey = 'cod_tipo_inmueble';

    public $timestamps = false;

    protected $guarded = [];

    public function getNombreAttribute(): string
    {
        return trim((string) $this->tipo_inmueble);
    }
}
