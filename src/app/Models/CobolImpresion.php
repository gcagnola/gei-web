<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CobolImpresion extends Model
{
    protected $table = 'cobol_impresiones';

    protected $fillable = [
        'origen',
        'archivo_origen',
        'archivo_raw',
        'sha256',
        'bytes',
        'tipo_documento',
        'cuenta',
        'nombre_cliente',
        'fecha_documento',
        'estado',
        'pdf_path',
        'recibido_en',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'fecha_documento' => 'date',
            'recibido_en' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
