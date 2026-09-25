<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Modulo extends Model
{
    protected $table = 'modulos';

    protected $fillable = [
        'codigo',
        'seccion',
        'nombre',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function perfiles(): BelongsToMany
    {
        return $this->belongsToMany(Perfil::class, 'perfiles_modulos', 'modulo_id', 'perfil_id')
            ->withTimestamps();
    }
}
