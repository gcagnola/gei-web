<p>{{ $cliente->nombre_visible }}:</p>

@if ($documentosEnvio === 'TODOS')
    <p>
        Adjuntamos la liquidación de propietario, el detalle de impuestos garantizados y los comprobantes ARCA
        correspondientes al período <strong>{{ $liquidacion->periodo_formateado }}</strong>.
    </p>
@elseif ($documentosEnvio === 'ARCA')
    <p>
        Adjuntamos los comprobantes ARCA correspondientes al período
        <strong>{{ $liquidacion->periodo_formateado }}</strong>.
    </p>
@elseif ($documentosEnvio === 'AMBOS')
    <p>
        Adjuntamos la liquidación de propietario y el detalle de impuestos garantizados correspondientes al período
        <strong>{{ $liquidacion->periodo_formateado }}</strong>.
    </p>
@elseif ($documentosEnvio === 'IMPUESTOS')
    <p>
        Adjuntamos el detalle de impuestos garantizados correspondiente al período
        <strong>{{ $liquidacion->periodo_formateado }}</strong>.
    </p>
@else
    <p>
        Adjuntamos la liquidación de propietario correspondiente al período
        <strong>{{ $liquidacion->periodo_formateado }}</strong>.
    </p>
@endif

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
