<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteCuenta extends Model
{
    protected $table = 'clientes_cuentas';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'datos_origen' => 'array',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
