<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $table = 'roles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function clientes(): BelongsToMany
    {
        return $this->belongsToMany(
            Cliente::class,
            'clientes_roles',
            'rol_id',
            'cliente_id'
        )
            ->withTimestamps();
    }
}
