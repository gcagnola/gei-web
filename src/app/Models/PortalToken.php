<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalToken extends Model
{
    public const PROPOSITO_ACTIVACION = 'ACTIVACION';
    public const PROPOSITO_RECUPERACION = 'RECUPERACION_PASSWORD';

    protected $table = 'portal_tokens';

    protected $fillable = [
        'portal_usuario_id',
        'proposito',
        'token_hash',
        'enviado_a',
        'enviado_por_usuario_id',
        'enviado_at',
        'vence_at',
        'usado_at',
    ];

    protected function casts(): array
    {
        return [
            'enviado_at' => 'datetime',
            'vence_at' => 'datetime',
            'usado_at' => 'datetime',
        ];
    }

    public function portalUsuario(): BelongsTo
    {
        return $this->belongsTo(PortalUsuario::class);
    }

    public function usuarioEnvio(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'enviado_por_usuario_id');
    }

    public function estaVigente(): bool
    {
        return $this->usado_at === null && $this->vence_at?->isFuture();
    }
}
