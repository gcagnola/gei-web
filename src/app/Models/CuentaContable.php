<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CuentaContable extends Model
{
    protected $table = 'cuentas_contables';
    protected $fillable = ['codigo','descripcion','imputable','naturaleza','naturaleza_confirmada','activo','origen'];
    protected function casts(): array { return ['imputable'=>'boolean','naturaleza_confirmada'=>'boolean','activo'=>'boolean']; }
    public function cuentasCaja(): HasMany { return $this->hasMany(CuentaCaja::class, 'cuenta_contable_id'); }
}
