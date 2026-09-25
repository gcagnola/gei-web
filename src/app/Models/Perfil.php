<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Perfil extends Model
{
    protected $table = 'perfiles';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(Modulo::class, 'perfiles_modulos', 'perfil_id', 'modulo_id')
            ->withTimestamps();
    }

    public function puedeVerModulo(string $codigo): bool
    {
        if (! $this->activo) {
            return false;
        }

        if ($this->codigo === 'ADMINISTRADOR') {
            return true;
        }

        $this->loadMissing('modulos');

        return $this->modulos->contains(
            static fn (Modulo $modulo): bool => $modulo->activo && $modulo->codigo === $codigo
        );
    }
}
