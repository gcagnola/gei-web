<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferenciaInformada extends Model
{
    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_VERIFICADA = 'VERIFICADA';
    public const ESTADO_RECHAZADA = 'RECHAZADA';
    public const ESTADO_ANULADA = 'ANULADA';

    protected $table = 'transferencias_informadas';

    protected $fillable = [
        'cliente_id',
        'cliente_cuenta_id',
        'fecha_transferencia',
        'importe',
        'referencia',
        'observaciones',
        'estado',
        'verificada_por_usuario_id',
        'verificada_at',
        'rechazo_motivo',
    ];

    protected function casts(): array
    {
        return [
            'fecha_transferencia' => 'date',
            'importe' => 'decimal:2',
            'verificada_at' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function clienteCuenta(): BelongsTo
    {
        return $this->belongsTo(ClienteCuenta::class, 'cliente_cuenta_id');
    }

    public function verificadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'verificada_por_usuario_id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(TransferenciaInformadaArchivo::class);
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }
}
