<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepartoPropietario extends Model
{
    protected $table = 'repartos_propietarios';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:6',
            'activo' => 'boolean',
            'datos_origen' => 'array',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function ultimaLiquidacion(): BelongsTo
    {
        return $this->belongsTo(
            LiquidacionPropietario::class,
            'ultima_liquidacion_id'
        );
    }
}
