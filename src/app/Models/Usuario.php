<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Usuario extends Authenticatable
{
    use Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'perfil_id',
        'nombre_usuario',
        'nombre',
        'email',
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
            'ultimo_acceso' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    public function sucursales(): BelongsToMany
    {
        return $this->belongsToMany(
            Sucursal::class,
            'usuarios_sucursales',
            'usuario_id',
            'sucursal_id'
        )->withTimestamps();
    }

    /** @return array<int, string> */
    public function codigosSucursales(): array
    {
        return $this->sucursales()
            ->where('sucursales.activa', true)
            ->orderBy('sucursales.codigo')
            ->pluck('sucursales.codigo')
            ->map(static fn ($codigo): string => strtoupper(trim((string) $codigo)))
            ->filter()
            ->values()
            ->all();
    }

    public function puedeVerSucursal(string $codigo): bool
    {
        return in_array(strtoupper(trim($codigo)), $this->codigosSucursales(), true);
    }

    public function getNombreLimpioAttribute(): string
    {
        return trim($this->nombre);
    }

    public function getTipoUsuarioLimpioAttribute(): string
    {
        return $this->perfil?->nombre ?? '';
    }

    public function estaHabilitado(): bool
    {
        return $this->activo;
    }
}