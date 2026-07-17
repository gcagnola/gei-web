<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Usuario extends Authenticatable
{
    use Notifiable;

    protected $table = 'usuarios';

    protected $primaryKey = 'cod_usuario';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'clave',
        'web_clave_hash',
        'web_recordar_token',
    ];

    protected $casts = [
        'cod_usuario' => 'integer',
        'habilitado' => 'integer',
        'web_intentos_fallidos' => 'integer',
        'web_ultimo_acceso' => 'datetime',
        'web_bloqueado_hasta' => 'datetime',
        'web_clave_actualizada' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return trim((string) $this->web_clave_hash);
    }

    public function getRememberTokenName(): string
    {
        return 'web_recordar_token';
    }

    public function getNombreLimpioAttribute(): string
    {
        return trim((string) $this->nombre);
    }

    public function getTipoUsuarioLimpioAttribute(): string
    {
        return trim((string) $this->tipo_de_usuario);
    }

    public function estaHabilitado(): bool
    {
        return (int) $this->habilitado === 1;
    }
}   