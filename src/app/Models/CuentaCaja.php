<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CuentaCaja extends Model
{
    protected $table = 'cuentas_caja';
    protected $fillable = ['codigo_cobol','numero_contable','cuenta_contable_id','nombre','subcuenta','afecta_disponibilidad','tipo_disponibilidad','activo','origen_cobol'];
    protected function casts(): array { return ['afecta_disponibilidad'=>'boolean','activo'=>'boolean']; }
    public function cuentaContable(): BelongsTo { return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id'); }
    public function imputacionesConceptos(): HasMany { return $this->hasMany(ConceptoImputacionCaja::class, 'cuenta_caja_id'); }
}
