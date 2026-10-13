<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PortalUsuario extends Authenticatable
{
    use Notifiable;

    protected $table = 'portal_usuarios';

    protected $fillable = [
        'cliente_id',
        'password',
        'activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'intentos_fallidos' => 'integer',
            'bloqueado_hasta' => 'datetime',
            'activado_at' => 'datetime',
            'datos_confirmados_at' => 'datetime',
            'ultimo_acceso_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PortalToken::class);
    }

    public function estaActivado(): bool
    {
        return $this->activo
            && $this->activado_at !== null
            && filled($this->password);
    }
}
