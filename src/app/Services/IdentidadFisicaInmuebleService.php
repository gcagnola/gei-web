<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Planificador cronológico de identidades físicas, INDEPENDIENTE de la cuenta COBOL.
 *
 * Una dirección exacta es un candidato de identidad física, no una garantía:
 * si hay evidencia de unidades distintas (partidas disjuntas simultáneas)
 * se informa para resolver mediante identificadores físicos, nunca mediante
 * una unificación automática de datos.
 */
final class IdentidadFisicaInmuebleService
{
    /** @var array<string, array<string, mixed>> */
    private array $unidades = [];

    private ?string $ultimoPeriodo = null;

    /** @var array<string, true> */
    private array $identidadesBloqueadas = [];

    public function __construct(private readonly InmuebleCobolNormalizer $normalizer)
    {
    }

    public function sede(string $cuentaPropietario, string $cuentaInquilino): ?string
    {
        $prop = match (substr($this->normalizer->cuenta($cuentaPropietario), 0, 4)) {
            '1202' => 'SF', '2202' => 'ST', default => null,
        };
        $inq = match (substr($this->normalizer->cuenta($cuentaInquilino), 0, 4)) {
            '1103' => 'SF', '2103' => 'ST', default => null,
        };

        return $prop !== null && $prop === $inq ? $prop : null;
    }

    public function clave(string $sede, string $domicilio): ?string
    {
        $direccion = $this->normalizer->direccion($domicilio);
        if (! in_array($sede, ['SF', 'ST'], true) || $direccion === '') {
            return null;
        }

        return $sede.'|'.$direccion;
    }

    /**
     * Se llama UNA VEZ por período en orden cronológico. La cuenta propietaria
     * es una relación de la unidad, NUNCA forma parte de la identidad.
     *
     * @param list<array<string, mixed>> $filas Ya normalizadas por InmuebleCobolNormalizer
     * @return array<string, mixed>
     */
    public function incorporarPeriodo(string $periodo, array $filas): array
    {
        if (! preg_match('/^20\d{2}(0[1-9]|1[0-2])$/', $periodo)) {
            throw new InvalidArgumentException('Período inválido.');
        }
        if ($this->ultimoPeriodo !== null && strcmp($periodo, $this->ultimoPeriodo) <= 0) {
            throw new InvalidArgumentException('Los períodos deben procesarse en orden y una sola vez.');
        }
        $this->ultimoPeriodo = $periodo;

        $grupos = [];
        $sinIdentidad = [];
        foreach ($filas as $fila) {
            $cuentaProp = (string) ($fila['cuenta_propietario'] ?? '');
            $cuentaInq = (string) ($fila['cuenta_inquilino'] ?? '');
            $sede = $this->sede($cuentaProp, $cuentaInq);
            $clave = $this->clave((string) $sede, (string) ($fila['direccion_normalizada'] ?? ''));
            if ($clave === null) {
                $sinIdentidad[] = [
                    'cuenta_propietario' => $cuentaProp,
                    'cuenta_inquilino' => $cuentaInq,
                    'domicilio' => $fila['direccion_normalizada'] ?? '',
                ];
                continue;
            }
            $grupos[$clave][] = $fila;
        }

        $resumen = [
            'periodo' => $periodo,
            'registros_fuente' => count($filas),
            'unidades_en_periodo' => count($grupos),
            'unidades_nuevas' => 0,
            'unidades_existentes' => 0,
            'cuentas_propietario_nuevas' => 0,
            'cuentas_inquilino_nuevas' => 0,
            'registros_sin_identidad_inequivoca' => count($sinIdentidad),
            'unidades_a_revisar' => 0,
            'domicilios_con_contratos_simultaneos' => 0,
            'muestra_revisiones' => array_slice($sinIdentidad, 0, 12),
            'muestra_cambios_cuenta' => [],
        ];

        ksort($grupos, SORT_STRING);
        foreach ($grupos as $clave => $grupo) {
            // La misma dirección puede ocultar varias unidades físicas si la
            // fuente omitió piso/departamento. Partidas disjuntas en el mismo
            // período son una señal de conflicto; no se identifica ni consolida.
            $partidasPorFila = [];
            foreach ($grupo as $fila) {
                $partidas = array_values(array_unique(array_map('strval', array_values($fila['partidas'] ?? []))));
                sort($partidas, SORT_STRING);
                if ($partidas !== []) {
                    $partidasPorFila[implode('|', $partidas)] = $partidas;
                }
            }
            // INQUILINO identifica cuentas/contratos, no una unidad física.
            // Dos contratos activos simultáneos NO prueban dos inmuebles:
            // MISIONES 2109 (202610) es un ejemplo real. Se informa el caso
            // sin bloquear la continuidad de la identidad del inmueble.
            $inquilinosActivos = [];
            foreach ($grupo as $fila) {
                if (($fila['estado_origen'] ?? 'ACTIVO') !== 'BAJA') {
                    $cuenta = (string) ($fila['cuenta_inquilino'] ?? '');
                    if ($cuenta !== '') {
                        $inquilinosActivos[$cuenta] = true;
                    }
                }
            }
            $contratosSimultaneos = count($inquilinosActivos) > 1;
            if ($contratosSimultaneos) {
                $resumen['domicilios_con_contratos_simultaneos']++;
            }

            $conflictoPartidas = false;
            if (count($partidasPorFila) > 1) {
                $conjuntos = array_values($partidasPorFila);
                foreach ($conjuntos as $i => $a) {
                    foreach (array_slice($conjuntos, $i + 1) as $b) {
                        if (array_intersect($a, $b) === []) {
                            $conflictoPartidas = true;
                        }
                    }
                }
            }
            $partidasActuales = [];
            foreach ($partidasPorFila as $partidas) {
                foreach ($partidas as $partida) {
                    $partidasActuales[$partida] = true;
                }
            }
            $partidasPrevias = $this->unidades[$clave]['partidas'] ?? [];
            $contradiccionHistorica = $partidasPrevias !== []
                && $partidasActuales !== []
                && array_intersect(array_keys($partidasPrevias), array_keys($partidasActuales)) === [];
            if ($conflictoPartidas || $contradiccionHistorica || isset($this->identidadesBloqueadas[$clave])) {
                $this->identidadesBloqueadas[$clave] = true;
                $resumen['unidades_a_revisar']++;
                if (count($resumen['muestra_revisiones']) < 12) {
                    $resumen['muestra_revisiones'][] = [
                        'identidad_fisica' => $clave,
                        'motivo' => $contradiccionHistorica
                            ? 'PARTIDA_CAMBIO_SIN_IDENTIDAD_FISICA_CONFIRMADA'
                            : 'MISMO_DOMICILIO_UNIDAD_AMBIGUA',
                        'partidas' => array_values($partidasPorFila),
                        'cuentas_inquilino_activas' => array_keys($inquilinosActivos),
                    ];
                }
                continue;
            }

            $yaExistia = isset($this->unidades[$clave]);
            if (! $yaExistia) {
                $this->unidades[$clave] = [
                    'primera_aparicion' => $periodo,
                    'ultima_aparicion' => $periodo,
                    'cuentas_propietarios' => [],
                    'cuentas_inquilinos' => [],
                    'partidas' => [],
                ];
                $resumen['unidades_nuevas']++;
            } else {
                $resumen['unidades_existentes']++;
                $this->unidades[$clave]['ultima_aparicion'] = $periodo;
            }
            foreach ($partidasActuales as $partida => $_) {
                $this->unidades[$clave]['partidas'][$partida] = true;
            }
            foreach ($grupo as $fila) {
                foreach (['cuenta_propietario' => 'cuentas_propietarios', 'cuenta_inquilino' => 'cuentas_inquilinos'] as $campo => $destino) {
                    $cuenta = (string) $fila[$campo];
                    if (! isset($this->unidades[$clave][$destino][$cuenta])) {
                        $this->unidades[$clave][$destino][$cuenta] = $periodo;
                        $contador = $campo === 'cuenta_propietario' ? 'cuentas_propietario_nuevas' : 'cuentas_inquilino_nuevas';
                        $resumen[$contador]++;
                        if ($campo === 'cuenta_propietario' && $yaExistia && count($resumen['muestra_cambios_cuenta']) < 12) {
                            $resumen['muestra_cambios_cuenta'][] = [
                                'unidad' => $clave,
                                'cuenta_nueva' => $cuenta,
                                'periodo' => $periodo,
                            ];
                        }
                    }
                }
            }
        }
        $resumen['unidades_acumuladas'] = count($this->unidades);

        return $resumen;
    }

    /** @return array<string, array<string, mixed>> */
    public function unidades(): array
    {
        return $this->unidades;
    }
}
