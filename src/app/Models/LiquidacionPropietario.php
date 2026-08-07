<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiquidacionPropietario extends Model
{
    protected $table = 'liquidaciones_propietarios';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'total' => 'decimal:2',
            'total_bruto' => 'decimal:2',
            'total_copropietario' => 'decimal:2',
            'total_debe' => 'decimal:2',
            'total_haber' => 'decimal:2',
            'total_neto_gravado' => 'decimal:2',
            'total_iva' => 'decimal:2',
            'total_final' => 'decimal:2',
            'control_pliqloc' => 'array',
            'pdf_generado_at' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function envios(): HasMany
    {
        return $this->hasMany(
            LiquidacionPropietarioEnvio::class,
            'liquidacion_propietario_id'
        );
    }

    public function getPeriodoFormateadoAttribute(): string
    {
        $periodo = trim((string) $this->periodo);

        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            return $periodo;
        }

        $meses = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];

        $mes = (int) substr($periodo, 4, 2);
        $anio = substr($periodo, 0, 4);

        return ($meses[$mes] ?? substr($periodo, 4, 2)).'/'.$anio;
    }
}
