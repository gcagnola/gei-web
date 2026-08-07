<p>{{ $cliente->nombre_visible }}:</p>

<p>
    Adjuntamos la liquidación de propietario correspondiente al período
    <strong>{{ $liquidacion->periodo_formateado }}</strong>.
</p>

<p>
    Número de liquidación:
    <strong>{{ sprintf('%04d-%08d', 0, $liquidacion->numero_interno) }}</strong>.
</p>

<p>
    Cuenta:
    <strong>{{ $liquidacion->cuenta_impresa }}</strong>.
</p>

<p>Saludos cordiales.</p>

<p>
    Guastavino e Imbert - Administración
</p>
