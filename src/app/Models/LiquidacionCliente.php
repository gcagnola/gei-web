<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiquidacionCliente extends Model
{
    protected $table = 'liquidaciones_de_clientes';

    protected $primaryKey = 'numero_de_liquidacion';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'numero_de_liquidacion' => 'integer',
            'punto_venta' => 'integer',
            'numero' => 'integer',
            'fecha' => 'date',
            'nro_cuenta' => 'integer',
            'fecha_desde' => 'date',
            'fecha_hasta' => 'date',
            'numero_de_comprobante' => 'integer',
            'total' => 'decimal:2',
            'total_liquidado' => 'decimal:2',
        ];
    }

    public function getPeriodoLimpioAttribute(): string
    {
        return trim((string) $this->periodo);
    }
}
