<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contrato extends Model
{
    protected $table = 'contratos';

    protected $primaryKey = 'codigo_contrato';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'codigo_contrato' => 'integer',
            'fecha_contrato' => 'date',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'importe_inicial' => 'decimal:2',
            'cotizacion_dolar' => 'decimal:2',
        ];
    }

    public function inmuebles(): BelongsToMany
    {
        return $this->belongsToMany(
            Inmueble::class,
            'contratos_inmuebles',
            'codigo_contrato',
            'codigo_inmueble'
        );
    }

    public function getNombreVisibleAttribute(): string
    {
        $numero = trim((string) $this->numero_de_contrato);

        return $numero !== '' && $numero !== '0'
            ? "Contrato {$numero}"
            : "Contrato interno {$this->codigo_contrato}";
    }

    public function getEstadoAttribute(): string
    {
        $hoy = now()->startOfDay();

        if ($this->fecha_inicio && $this->fecha_inicio->startOfDay()->isAfter($hoy)) {
            return 'Futuro';
        }

        if ($this->fecha_fin && $this->fecha_fin->startOfDay()->isBefore($hoy)) {
            return 'Vencido';
        }

        return 'Vigente';
    }
}
