<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConceptoImputacionCaja extends Model
{
    protected $table = 'conceptos_imputaciones_caja';

    protected $fillable = [
        'concepto_id',
        'sede',
        'moneda',
        'judicial',
        'cuenta_caja_codigo',
        'cuenta_caja_id',
        'origen_cobol',
    ];

    protected $casts = [
        'judicial' => 'boolean',
    ];

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(Concepto::class);
    }

    public function cuentaCaja(): BelongsTo
    {
        return $this->belongsTo(CuentaCaja::class, 'cuenta_caja_id');
    }
}
