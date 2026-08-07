<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $fillable = [
        'tipo_persona',
        'nombre',
        'tipo_documento',
        'numero_documento',
        'cuit',
        'condicion_iva',
        'domicilio',
        'codigo_postal',
        'localidad',
        'provincia',
        'telefono',
        'telefono_alternativo',
        'email',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'clientes_roles',
            'cliente_id',
            'rol_id'
        )
            ->withTimestamps();
    }

    public function cuentas(): HasMany
    {
        return $this->hasMany(ClienteCuenta::class);
    }

    public function getNombreVisibleAttribute(): string
    {
        return trim((string) $this->nombre) !== ''
            ? trim((string) $this->nombre)
            : "Cliente #{$this->id}";
    }
}
