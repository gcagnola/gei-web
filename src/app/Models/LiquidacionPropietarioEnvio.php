<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidacionPropietarioEnvio extends Model
{
    protected $table = 'liquidaciones_propietarios_envios';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'intentado_at' => 'datetime',
            'enviado_at' => 'datetime',
            'intentos' => 'integer',
        ];
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(
            LiquidacionPropietario::class,
            'liquidacion_propietario_id'
        );
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
