<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Concepto extends Model
{
    protected $fillable = [
        'dominio',
        'codigo',
        'descripcion',
        'activo',
        'origen_cobol',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function imputacionesCaja(): HasMany
    {
        return $this->hasMany(ConceptoImputacionCaja::class);
    }
}
