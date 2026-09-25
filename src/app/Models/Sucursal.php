<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Sucursal extends Model
{
    protected $table = 'sucursales';

    protected $fillable = [
        'codigo',
        'nombre',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(
            Usuario::class,
            'usuarios_sucursales',
            'sucursal_id',
            'usuario_id'
        )->withTimestamps();
    }
}
