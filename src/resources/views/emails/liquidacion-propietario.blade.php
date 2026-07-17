<p>{{ $cliente->nombre_visible }}:</p>

<p>
    Adjuntamos la liquidación de propietario correspondiente al período
    {{ $liquidacion->periodo_limpio ?: 'indicado' }}.
</p>

<p>
    Número de liquidación:
    {{ (int) $liquidacion->punto_venta }}-{{ (int) $liquidacion->numero }}.
</p>
