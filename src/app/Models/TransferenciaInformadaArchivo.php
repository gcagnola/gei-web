<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferenciaInformadaArchivo extends Model
{
    protected $table = 'transferencias_informadas_archivos';

    protected $fillable = [
        'transferencia_informada_id',
        'nombre_original',
        'archivo',
        'mime_type',
        'tamano',
    ];

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(TransferenciaInformada::class, 'transferencia_informada_id');
    }
}
