<?php

namespace App\Services;

use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * C4: titularidad confirmada de un inmueble físico de gei_db.public.
 *
 * La cuenta COBOL es una relación operativa; NO prueba por sí sola una
 * transferencia de dominio. Los beneficiarios de liquidaciones tampoco.
 * Este servicio nunca crea, fusiona ni cambia el ID de un inmueble.
 *
 * Un cambio se registra sólo a partir de una nómina completa y corroborada
 * de titulares, con referencia documental/administrativa explícita.
 */
final class TitularidadesInmuebleService
{
    /**
     * Compara una nómina confirmada con las relaciones abiertas y prepara un
     * cambio histórico sin consultar ni modificar la base de datos.
     *
     * @param list<array<string,mixed>> $vigentes Filas de inmuebles_propietarios abiertas.
     * @param list<array<string,mixed>> $nuevaNomina Cada fila: cliente_cuenta_id,
     *        cliente_id y porcentaje (null si no está acreditado).
     * @return array{desde:string,hasta_anterior:string,cerrar:list<int>,crear:list<array<string,mixed>>,sin_cambios:int}
     */
    public function planificar(string $periodo, array $vigentes, array $nuevaNomina): array
    {
        if (! preg_match('/^20\d{2}(0[1-9]|1[0-2])$/D', $periodo)) {
            throw new InvalidArgumentException('Período inválido: se requiere AAAAMM.');
        }
        if ($nuevaNomina === []) {
            throw new DomainException('No se admite una nómina vacía: no acredita ausencia de propietarios.');
        }

        $desde = substr($periodo, 0, 4).'-'.substr($periodo, 4, 2).'-01';
        $hastaAnterior = (new DateTimeImmutable($desde))->modify('-1 day')->format('Y-m-d');

        $solicitados = [];
        $totalMicros = 0;
        $porcentajesInformados = 0;
        foreach ($nuevaNomina as $fila) {
            $cuentaId = $this->enteroPositivo($fila['cliente_cuenta_id'] ?? null, 'cliente_cuenta_id');
            $clienteId = $this->enteroPositivo($fila['cliente_id'] ?? null, 'cliente_id');
            if (isset($solicitados[$cuentaId])) {
                throw new DomainException("La cuenta {$cuentaId} está repetida en la nómina.");
            }
            $porcentaje = $this->porcentaje($fila['porcentaje'] ?? null);
            if ($porcentaje !== null) {
                $porcentajesInformados++;
                $totalMicros += $this->micros($porcentaje);
            }
            $solicitados[$cuentaId] = [
                'cliente_cuenta_id' => $cuentaId,
                'cliente_id' => $clienteId,
                'porcentaje' => $porcentaje,
            ];
        }
        // Sólo una nómina con TODOS los porcentajes documentados permite
        // comprobar el 100%. Los valores no acreditados permanecen NULL.
        if ($porcentajesInformados === count($solicitados) && $totalMicros !== 100_000_000) {
            throw new DomainException('Los porcentajes documentados deben sumar exactamente 100%.');
        }

        $actuales = [];
        foreach ($vigentes as $fila) {
            if (($fila['vigencia_hasta'] ?? null) !== null) {
                throw new InvalidArgumentException('El planificador sólo admite relaciones abiertas.');
            }
            $cuentaId = $this->enteroPositivo($fila['cliente_cuenta_id'] ?? null, 'cliente_cuenta_id');
            if (isset($actuales[$cuentaId])) {
                throw new DomainException("Hay más de una titularidad abierta para la cuenta {$cuentaId}.");
            }
            $vigencia = $fila['vigencia_desde'] ?? null;
            if ($vigencia !== null && substr((string) $vigencia, 0, 10) > $desde) {
                throw new DomainException('La nómina solicitada es anterior a una titularidad ya registrada.');
            }
            $actuales[$cuentaId] = $fila;
        }

        $cerrar = [];
        $crear = [];
        $sinCambios = 0;
        foreach ($actuales as $cuentaId => $existente) {
            $nuevo = $solicitados[$cuentaId] ?? null;
            // Desconocido NO significa 0 ni autoriza borrar un porcentaje ya acreditado.
            if ($nuevo !== null && $nuevo['porcentaje'] === null) {
                $nuevo['porcentaje'] = $this->porcentaje($existente['porcentaje'] ?? null);
                $solicitados[$cuentaId] = $nuevo;
            }
            $igual = $nuevo !== null
                && (int) $existente['cliente_id'] === $nuevo['cliente_id']
                && $this->porcentaje($existente['porcentaje'] ?? null) === $nuevo['porcentaje']
                && (bool) ($existente['activo'] ?? true);

            if ($igual) {
                $sinCambios++;
                unset($solicitados[$cuentaId]);
                continue;
            }
            // Nunca truncar retroactivamente un vínculo comenzado en el
            // mismo período o después de la fecha efectiva del cambio.
            $vigencia = $existente['vigencia_desde'] ?? null;
            if ($vigencia !== null && substr((string) $vigencia, 0, 10) > $hastaAnterior) {
                throw new DomainException('La modificación solapa una titularidad ya registrada: requiere revisión histórica.');
            }
            $cerrar[] = $this->enteroPositivo($existente['id'] ?? null, 'id de relación');
        }

        foreach ($solicitados as $nuevo) {
            $crear[] = $nuevo;
        }
        sort($cerrar, SORT_NUMERIC);
        usort($crear, static fn (array $a, array $b): int => $a['cliente_cuenta_id'] <=> $b['cliente_cuenta_id']);

        return [
            'desde' => $desde,
            'hasta_anterior' => $hastaAnterior,
            'cerrar' => $cerrar,
            'crear' => $crear,
            'sin_cambios' => $sinCambios,
        ];
    }

    /**
     * Aplica EXCLUSIVAMENTE una nómina completa, confirmada por el operador.
     * Por defecto simula; no hay ningún disparador desde la importación COBOL.
     *
     * @param list<array{cliente_cuenta_id:int,porcentaje?:int|float|string|null}> $titulares
     * @return array<string,mixed>
     */
    public function registrarCambioConfirmado(
        int $inmuebleId,
        string $periodo,
        array $titulares,
        string $referencia,
        bool $confirmar = false
    ): array {
        if ($inmuebleId < 1 || trim($referencia) === '') {
            throw new InvalidArgumentException('Se requieren un inmueble válido y una referencia verificable.');
        }

        return DB::transaction(function () use ($inmuebleId, $periodo, $titulares, $referencia, $confirmar): array {
            $inmueble = DB::table('inmuebles')->where('id', $inmuebleId)->lockForUpdate()->first();
            if ($inmueble === null) {
                throw new DomainException('Inmueble inexistente en el maestro operativo.');
            }
            if (($inmueble->id_inmueble_canonico ?? null) !== null) {
                throw new DomainException('Debe utilizarse el inmueble canónico, no uno absorbido.');
            }

            $cuentaIds = array_map(
                fn (array $fila): int => $this->enteroPositivo($fila['cliente_cuenta_id'] ?? null, 'cliente_cuenta_id'),
                $titulares
            );
            $cuentas = DB::table('clientes_cuentas')->whereIn('id', $cuentaIds)
                ->get(['id', 'cliente_id', 'cuenta', 'rol'])->keyBy('id');
            $sedeTitulares = null;
            $nuevaNomina = [];
            foreach ($titulares as $titular) {
                $cuenta = $cuentas->get($titular['cliente_cuenta_id']);
                if ($cuenta === null || $cuenta->rol !== 'PROPIETARIO') {
                    throw new DomainException('Cuenta de propietario inexistente o de rol incorrecto.');
                }
                $sede = match (substr((string) $cuenta->cuenta, 0, 4)) {
                    '1202' => 'SF', '2202' => 'ST', default => null,
                };
                if ($sede === null || ($sedeTitulares !== null && $sede !== $sedeTitulares)) {
                    throw new DomainException('Las cuentas propietarias presentan sedes contradictorias.');
                }
                $sedeTitulares = $sede;
                $nuevaNomina[] = [
                    'cliente_cuenta_id' => (int) $cuenta->id,
                    'cliente_id' => (int) $cuenta->cliente_id,
                    'porcentaje' => $titular['porcentaje'] ?? null,
                ];
            }

            if (($inmueble->sucursal_id ?? null) === null) {
                throw new DomainException('El inmueble no tiene una sede confirmada: resolver antes de registrar titularidades.');
            }
            $sedeInmueble = DB::table('sucursales')->where('id', $inmueble->sucursal_id)->value('codigo');
            if ($sedeInmueble === null || $sedeInmueble !== $sedeTitulares) {
                throw new DomainException('La sede del inmueble contradice las cuentas propietarias.');
            }

            $vigentes = DB::table('inmuebles_propietarios')
                ->where('inmueble_id', $inmuebleId)->whereNull('vigencia_hasta')
                ->lockForUpdate()->get()->map(static fn (object $fila): array => (array) $fila)->all();
            $plan = $this->planificar($periodo, $vigentes, $nuevaNomina);
            $hayCambios = $plan['cerrar'] !== [] || $plan['crear'] !== [];

            if ($hayCambios) {
                // Una carga posterior ya cerrada no puede reescribirse desde
                // un período anterior: la corrección requiere revisión manual.
                $cruce = DB::table('inmuebles_propietarios')
                    ->where('inmueble_id', $inmuebleId)
                    ->whereNotNull('vigencia_hasta')
                    ->where('vigencia_hasta', '>=', $plan['desde'])
                    ->exists();
                if ($cruce) {
                    throw new DomainException('Existen titularidades históricas posteriores: no se permite reescribir períodos.');
                }
            }

            // Una nómina verificada sin cambios también debe quedar marcada
            // como confirmada, para que COBOL no la reescriba en una futura carga.
            $confirmacionesNuevas = 0;
            if ($confirmar) {
                $cuentasSinCambio = array_diff(
                    array_map(static fn (array $fila): int => (int) $fila['cliente_cuenta_id'], $nuevaNomina),
                    array_map(static fn (array $fila): int => (int) $fila['cliente_cuenta_id'], $plan['crear'])
                );
                foreach ($vigentes as $relacion) {
                    if (! in_array((int) $relacion['cliente_cuenta_id'], $cuentasSinCambio, true)
                        || in_array((int) $relacion['id'], $plan['cerrar'], true)
                        || ($relacion['origen'] ?? null) === 'GEI_CONFIRMADA') {
                        continue;
                    }
                    $datos = json_decode((string) ($relacion['datos_origen'] ?? ''), true);
                    $datos = is_array($datos) ? $datos : [];
                    $datos['confirmacion_titularidad'] = [
                        'periodo' => $periodo,
                        'referencia' => trim($referencia),
                    ];
                    DB::table('inmuebles_propietarios')
                        ->where('id', $relacion['id'])
                        ->whereNull('vigencia_hasta')
                        ->update([
                            'origen' => 'GEI_CONFIRMADA',
                            'datos_origen' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);
                    $confirmacionesNuevas++;
                }
            }

            if ($confirmar && $hayCambios) {
                if ($plan['cerrar'] !== []) {
                    DB::table('inmuebles_propietarios')
                        ->where('inmueble_id', $inmuebleId)
                        ->whereIn('id', $plan['cerrar'])
                        ->whereNull('vigencia_hasta')
                        ->update([
                            'vigencia_hasta' => $plan['hasta_anterior'],
                            'activo' => false,
                            'updated_at' => now(),
                        ]);
                }
                foreach ($plan['crear'] as $fila) {
                    DB::table('inmuebles_propietarios')->insert([
                        'inmueble_id' => $inmuebleId,
                        'cliente_id' => $fila['cliente_id'],
                        'cliente_cuenta_id' => $fila['cliente_cuenta_id'],
                        'porcentaje' => $fila['porcentaje'],
                        'vigencia_desde' => $plan['desde'],
                        'vigencia_hasta' => null,
                        'activo' => true,
                        'origen' => 'GEI_CONFIRMADA',
                        'datos_origen' => json_encode([
                            'periodo' => $periodo,
                            'referencia' => trim($referencia),
                            'tipo' => 'TITULARIDAD_CONFIRMADA',
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return [
                'inmueble_id' => $inmuebleId,
                'periodo' => $periodo,
                'confirmado' => $confirmar,
                'relaciones_cerradas' => count($plan['cerrar']),
                'relaciones_creadas' => count($plan['crear']),
                'relaciones_sin_cambios' => $plan['sin_cambios'],
                'relaciones_confirmadas_sin_cambio' => $confirmacionesNuevas,
                'detalle' => $plan,
            ];
        }, 3);
    }

    private function enteroPositivo(mixed $valor, string $campo): int
    {
        if (! is_int($valor) && (! is_string($valor) || ! preg_match('/^[1-9][0-9]*$/D', $valor))) {
            throw new InvalidArgumentException("{$campo} debe ser un entero positivo.");
        }
        $numero = filter_var($valor, FILTER_VALIDATE_INT);
        if ($numero === false || $numero < 1) {
            throw new InvalidArgumentException("{$campo} debe ser un entero positivo.");
        }

        return $numero;
    }

    private function porcentaje(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (is_float($valor)) {
            if (! is_finite($valor)) {
                throw new InvalidArgumentException('Porcentaje inválido.');
            }
            $valor = number_format($valor, 6, '.', '');
        }
        if (! is_string($valor) && ! is_int($valor)) {
            throw new InvalidArgumentException('Porcentaje inválido.');
        }
        $cadena = (string) $valor;
        if (! preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,6})?$/D', $cadena)) {
            throw new InvalidArgumentException('Porcentaje inválido: máximo 6 decimales y entre 0 y 100.');
        }
        [$entero, $decimal] = array_pad(explode('.', $cadena, 2), 2, '');
        $decimal = str_pad($decimal, 6, '0');
        $micros = (int) $entero * 1_000_000 + (int) $decimal;
        if ($micros > 100_000_000) {
            throw new InvalidArgumentException('El porcentaje supera el 100%.');
        }

        return sprintf('%d.%06d', intdiv($micros, 1_000_000), $micros % 1_000_000);
    }

    private function micros(string $porcentaje): int
    {
        [$entero, $decimal] = explode('.', $porcentaje, 2);

        return (int) $entero * 1_000_000 + (int) $decimal;
    }
}
