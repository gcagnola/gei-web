<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuentaCaja extends Model
{
    protected $table = 'cuentas_caja';

    protected $fillable = [
        'codigo_cobol',
        'numero_contable',
        'nombre',
        'subcuenta',
        'activo',
        'origen_cobol',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function imputacionesConceptos(): HasMany
    {
        return $this->hasMany(ConceptoImputacionCaja::class, 'cuenta_caja_id');
    }
}
