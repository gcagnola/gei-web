<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El controlador debe autorizar antes las cuentas; este servicio sólo busca
 * pares (cuenta INQUILINO, cuenta PROPIETARIO) documentados en vínculos operativos.
 * Nunca confiere permisos a partir del nombre o del CUIT.
 */
final class ClienteMaestroFichaContratoCobolService
{
    /**
     * @param Collection<int, object> $cuentasAutorizadas
     * @param array<string, Collection> $vinculosOperativos
     * @return array{fichas:array<int, array<string, mixed>>,error:?string}
     */
    public function consultar(Collection $cuentasAutorizadas, array $vinculosOperativos): array
    {
        $inqPermitidas = $cuentasAutorizadas->where('rol', 'INQUILINO')
            ->pluck('cuenta')->map(static fn ($c) => (string) $c)->flip();
        $propPermitidas = $cuentasAutorizadas->where('rol', 'PROPIETARIO')
            ->pluck('cuenta')->map(static fn ($c) => (string) $c)->flip();

        $contratosPorPar = [];
        foreach ($vinculosOperativos['contratos'] ?? collect() as $contrato) {
            $inq = trim((string) ($contrato->cuenta_inquilino ?? ''));
            $prop = trim((string) ($contrato->cuenta_propietario ?? ''));
            if ($inqPermitidas->has($inq) && self::parValido($inq, $prop)) {
                $contratosPorPar[$inq.'|'.$prop][] = ['id' => (int) $contrato->id,
                    'inicio' => self::fechaClave($contrato->fecha_inicio ?? null)];
            }
        }
        foreach ($vinculosOperativos['inquilinos'] ?? collect() as $vinculo) {
            $inq = trim((string) ($vinculo->cuenta_inquilino ?? ''));
            $prop = trim((string) ($vinculo->cuenta_propietario ?? ''));
            if ($propPermitidas->has($prop) && self::parValido($inq, $prop)) {
                $contratosPorPar[$inq.'|'.$prop][] = ['id' => (int) $vinculo->contrato_id,
                    'inicio' => self::fechaClave($vinculo->fecha_inicio ?? null)];
            }
        }

        if ($contratosPorPar === []) {
            return ['fichas' => [], 'error' => null];
        }

        $cuentasInquilinas = array_values(array_unique(array_map(
            static fn ($par) => explode('|', $par, 2)[0], array_keys($contratosPorPar)
        )));

        try {
            $db = $this->exploracion();
            $registros = $this->filasMasRecientes($db, $cuentasInquilinas);
        } catch (Throwable $e) {
            Log::warning('Ficha contrato COBOL: consulta histórica no disponible', [
                'error' => $e->getMessage(),
            ]);
            return ['fichas' => [], 'error' => 'No se pudo consultar la ficha histórica COBOL.'];
        }

        // Puede haber distintos contratos históricos con el MISMO par de cuentas.
        // No mezclar sus penalidades: si hay fecha de inicio operativa, se
        // exige también la misma fecha del registro COBOL.
        $registrosPorPar = [];
        foreach ($registros as $fila) {
            $par = trim((string) ($fila->cta_inquilino ?? '')).'|'
                .trim((string) ($fila->cta_propietario ?? ''));
            if (isset($contratosPorPar[$par])) {
                $registrosPorPar[$par][] = $fila;
            }
        }
        $fichas = [];
        foreach ($contratosPorPar as $par => $contratos) {
            $candidatos = $registrosPorPar[$par] ?? [];
            foreach ($contratos as $contrato) {
                $elegido = null;
                if ($contrato['inicio'] !== null) {
                    foreach ($candidatos as $fila) {
                        if (self::fechaClave($fila->fecha_inicio ?? null) === $contrato['inicio']) {
                            $elegido = $fila;
                            break;
                        }
                    }
                } elseif (count($candidatos) === 1) {
                    // Sin fecha en el operativo, sólo resulta seguro cuando
                    // existe exactamente un contrato fuente para ese par.
                    $elegido = $candidatos[0];
                }
                if ($elegido !== null) {
                    $ficha = $this->formatear($elegido);
                    $historial = [];
                    $periodosVistos = [];
                    foreach ($candidatos as $version) {
                        if (self::fechaClave($version->fecha_inicio ?? null) !==
                            self::fechaClave($elegido->fecha_inicio ?? null)) {
                            continue;
                        }
                        $periodo = (string) ($version->periodo_ficha ?? '');
                        if (isset($periodosVistos[$periodo])) {
                            continue;
                        }
                        $periodosVistos[$periodo] = true;
                        $historial[] = $this->formatear($version);
                    }
                    // Histórico del MISMO contrato, nunca de otra cuenta/inmueble.
                    $ficha['historial'] = $historial;
                    $fichas[$contrato['id']] = $ficha;
                }
            }
        }
        return ['fichas' => $fichas, 'error' => null];
    }

    /** Solo se permiten cuentas COBOL de once dígitos, de roles coherentes. */
    public static function parValido(string $inquilino, string $propietario): bool
    {
        return preg_match('/^(1103|2103)[0-9]{7}$/D', $inquilino) === 1
            && preg_match('/^(1202|2202)[0-9]{7}$/D', $propietario) === 1
            && substr($inquilino, 0, 1) === substr($propietario, 0, 1);
    }

    /**
     * Versiones del par y fecha de inicio en cierres COBOL importados OK.
     * No mezcla registros de períodos ni trae contratos de otros propietarios.
     *
     * @param list<string> $cuentas
     * @return Collection<int, object>
     */
    private function filasMasRecientes(Connection $db, array $cuentas): Collection
    {
        $candidatos = $db->table('cobol_staging.migraciones_periodos_archivos as pa')
            ->join('cobol_staging.migraciones_periodos as mp', 'mp.id', '=', 'pa.migracion_id')
            ->where('mp.estado', 'OK')
            ->where('pa.grupo', 'cobol')
            ->where('pa.procesado_en_tabla', 'inquilino')
            ->selectRaw('mp.periodo, pa.archivo_id, ROW_NUMBER() OVER (PARTITION BY mp.periodo ORDER BY mp.iniciada_en DESC, mp.id DESC) AS ordinal');

        $archivos = $db->query()->fromSub($candidatos, 'candidatos')
            ->where('ordinal', 1)
            ->select(['periodo', 'archivo_id']);

        $registros = $db->table('cobol_staging.inquilino as i')
            ->joinSub($archivos, 'a', 'a.archivo_id', '=', 'i.archivo_id')
            ->whereIn(DB::raw('TRIM(i.cta_inquilino::text)'), $cuentas)
            ->selectRaw('i.*, a.periodo AS periodo_ficha');

        return $registros->orderByDesc('a.periodo')
            ->orderByDesc('i.numero_linea')->orderByDesc('i.id')->get();
    }

    /** @return array<string, mixed> */
    private function formatear(object $fila): array
    {
        $campos = [
            'fecha_contrato' => 'fecha_contrato',
            'fecha_vencimiento' => 'fecha_vencimiento',
            'fecha_celebracion' => 'fecha_celebracion_redefine',
            'fecha_inicio' => 'fecha_inicio',
            'fecha_primer_ajuste' => 'fecha_primer_ajuste',
            'fecha_baja' => 'fecha_baja',
            'fecha_juicio' => 'fecha_juicio',
            'plazo_meses' => 'plazo',
            'plazo_dias' => 'plazo_dias',
            'destino' => 'destino',
            'nro_liquidacion' => 'nro_liquidacion',
            'marca_intimacion' => 'marca_intimacion',
            'marca_baja' => 'marca_baja',
            'indice' => 'indice',
            'tipo_ajuste' => 'tipo_ajuste',
            'administracion_responsable' => 'administracion_responsable',
            'penal_porcentaje' => 'penal_porcentaje',
            'comision_anterior' => 'comision_anterior',
            'reparacion' => 'reparacion',
            'dias_reparacion' => 'dias_reparacion',
            'abogado' => 'abogado',
            'direccion_finca' => 'direccion_finca',
        ];

        $resultado = [
            'periodo' => (string) ($fila->periodo_ficha ?? ''),
            'cuenta_inquilino' => trim((string) ($fila->cta_inquilino ?? '')),
            'cuenta_propietario' => trim((string) ($fila->cta_propietario ?? '')),
        ];
        foreach ($campos as $destino => $origen) {
            $resultado[$destino] = self::valor($fila->{$origen} ?? null);
        }
        foreach (['cuota_1', 'cuota_2', 'alquiler_inicial', 'cuota_2_dolar',
            'penal_importe', 'comision_importe', 'acumulado_penalidad'] as $campo) {
            $resultado[$campo] = self::numeroV99($fila->{$campo} ?? null);
        }
        for ($n = 1; $n <= 8; $n++) {
            $resultado['ajuste_'.$n.'_fecha'] = self::valor($fila->{'ajuste_'.$n.'_fecha'} ?? null);
            $resultado['ajuste_'.$n.'_porcentaje'] = self::numeroV1ConSigno(
                $fila->{'ajuste_'.$n.'_porcentaje'} ?? null
            );
        }
        return $resultado;
    }

    private static function valor(mixed $valor): ?string
    {
        $s = trim((string) ($valor ?? ''));
        return $s === '' ? null : $s;
    }

    /** Compara fechas de la fuente COBOL con fechas DATE operativas. */
    public static function fechaClave(mixed $valor): ?string
    {
        $s = trim((string) ($valor ?? ''));
        if ($s === '' || preg_match('/^0+$/D', $s) === 1) {
            return null;
        }

        // Primero formatos con separadores (DATE/TIMESTAMP de PostgreSQL).
        if (preg_match('/^((?:19|20)[0-9]{2})-([0-9]{2})-([0-9]{2})(?:[ T].*)?$/D', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? $m[1].$m[2].$m[3]
                : null;
        }

        if (preg_match('/^[0-9]{8}$/D', $s) !== 1) {
            return null;
        }

        // Una fecha COBOL como 20082026 (20/08/2026) parece comenzar
        // por el año 2008. Validar mes y día ANTES de tomarla por AAAAMMDD.
        $anio = (int) substr($s, 0, 4);
        $mes = (int) substr($s, 4, 2);
        $dia = (int) substr($s, 6, 2);
        if ($anio >= 1900 && $anio <= 2099 && checkdate($mes, $dia, $anio)) {
            return $s;
        }

        // Fecha original COBOL DDMMAAAA, sin modificar la evidencia.
        $dia = (int) substr($s, 0, 2);
        $mes = (int) substr($s, 2, 2);
        $anio = (int) substr($s, 4, 4);
        if ($anio >= 1900 && $anio <= 2099 && checkdate($mes, $dia, $anio)) {
            return sprintf('%04d%02d%02d', $anio, $mes, $dia);
        }

        return null;
    }

    /** Decimal implícito PIC 9(...)V99: mismo criterio que el importador anterior. */
    public static function numeroV99(mixed $valor): ?float
    {
        $s = self::valor($valor);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^[0-9]+$/D', $s) === 1) {
            return ((float) $s) / 100;
        }
        $s = str_replace(',', '.', $s);
        return preg_match('/^[0-9]+\.[0-9]+$/D', $s) === 1 ? (float) $s : null;
    }

    /** Porcentajes PIC S9(...)V9, signo al final en algunos registros COBOL. */
    public static function numeroV1ConSigno(mixed $valor): ?float
    {
        $s = self::valor($valor);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^([0-9]+)([+-])$/D', $s, $m) === 1) {
            return ((float) $m[1]) / 10 * ($m[2] === '-' ? -1 : 1);
        }
        return preg_match('/^[+-]?[0-9]+$/D', $s) === 1 ? ((float) $s) / 10 : null;
    }

    private function exploracion(): Connection
    {
        $base = config('database.connections.pgsql');
        if (! is_array($base)) {
            throw new \RuntimeException('No existe la conexión PostgreSQL base.');
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $campo) {
            $valor = config('gei.exploracion.'.$campo);
            if ($valor === null || $valor === '') {
                throw new \RuntimeException("Falta gei.exploracion.{$campo}.");
            }
            $base[$campo] = $valor;
        }
        $base['search_path'] = 'public';
        config(['database.connections.gei_exploracion_ficha_contratos' => $base]);
        return DB::connection('gei_exploracion_ficha_contratos');
    }
}
