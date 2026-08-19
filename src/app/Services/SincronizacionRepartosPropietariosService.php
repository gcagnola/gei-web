<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class SincronizacionRepartosPropietariosService
{
    private const PORCENTAJE_100_MICROS = 100_000_000;

    // Los porcentajes de COBOL tienen tres decimales. Esta tolerancia contempla
    // únicamente diferencias mínimas de redondeo; no corrige repartos inválidos.
    private const TOLERANCIA_SUMA_MICROS = 20_000;

    private const ARCHIVOS_REPARTO = [
        'liquida.sf.txt',
        'liquidb.sf.txt',
        'liquida.st.txt',
        'liquidb.st.txt',
    ];

    /**
     * Construye/mantiene el maestro vigente de reparto de cobro a partir de las
     * liquidaciones ya importadas. No altera inmuebles_propietarios: un
     * beneficiario de REPARTO no implica titularidad del inmueble.
     *
     * @param callable(array<string, mixed>):void|null $incidencia
     * @return array<string, int|bool|string>
     */
    public function sincronizar(
        ?string $periodo = null,
        bool $confirmar = false,
        ?callable $incidencia = null
    ): array {
        $this->validarEsquema();
        $periodo = $this->resolverPeriodo($periodo);

        $procesar = fn (): array => $this->procesarPeriodo($periodo, $confirmar, $incidencia);

        return $confirmar
            ? DB::transaction($procesar, 3)
            : $procesar();
    }

    /**
     * @param callable(array<string, mixed>):void|null $incidencia
     * @return array<string, int|bool|string>
     */
    private function procesarPeriodo(
        string $periodo,
        bool $confirmar,
        ?callable $incidencia
    ): array {
        $resultado = [
            'confirmado' => $confirmar,
            'periodo' => $periodo,
            'liquidaciones_fuente' => 0,
            'cuentas_fuente' => 0,
            'cuentas_validas' => 0,
            'cuentas_omitidas' => 0,
            'cuentas_historicas_omitidas' => 0,
            'beneficiarios_fuente' => 0,
            'repartos_creados' => 0,
            'repartos_actualizados' => 0,
            'repartos_desactivados' => 0,
            'repartos_sin_cambios' => 0,
            'incidencias' => 0,
        ];

        $fuentes = $this->cargarFuentes($periodo, $resultado, $incidencia);
        $resultado['cuentas_fuente'] = count($fuentes);

        if ($fuentes === []) {
            throw new RuntimeException("No hay liquidaciones utilizables para el período {$periodo}.");
        }

        foreach ($fuentes as $cuenta => $datosCuenta) {
            if (($datosCuenta['invalida'] ?? false) === true) {
                $resultado['cuentas_omitidas']++;
                continue;
            }

            $beneficiarios = $this->validarCuenta(
                $cuenta,
                $datosCuenta['beneficiarios'],
                $resultado,
                $incidencia
            );

            if ($beneficiarios === null) {
                $resultado['cuentas_omitidas']++;
                continue;
            }

            $ultimoPeriodoExistente = DB::table('repartos_propietarios')
                ->where('cuenta', $cuenta)
                ->max('ultimo_periodo');

            if (is_string($ultimoPeriodoExistente) && $ultimoPeriodoExistente > $periodo) {
                $resultado['cuentas_historicas_omitidas']++;
                continue;
            }

            $resultado['cuentas_validas']++;
            $resultado['beneficiarios_fuente'] += count($beneficiarios);

            $existentes = DB::table('repartos_propietarios')
                ->where('cuenta', $cuenta)
                ->get()
                ->keyBy(fn (object $fila): string => (string) $fila->beneficiario_normalizado);

            $clavesVigentes = [];

            foreach ($beneficiarios as $claveBeneficiario => $beneficiario) {
                $clavesVigentes[$claveBeneficiario] = true;
                $existente = $existentes->get($claveBeneficiario);

                $payload = [
                    'cuenta_impresa' => $beneficiario['cuenta_impresa'],
                    'propietario' => $beneficiario['propietario'],
                    'beneficiario' => $beneficiario['nombre'],
                    'porcentaje' => $this->microsADecimal($beneficiario['porcentaje_micros']),
                    'ultimo_periodo' => $periodo,
                    'periodo_baja' => null,
                    'activo' => true,
                    'origen' => 'LIQUIDACION',
                    'ultima_liquidacion_id' => $beneficiario['ultima_liquidacion_id'],
                    'datos_origen' => json_encode(
                        [
                            'periodo' => $periodo,
                            'liquidacion_ids' => $beneficiario['liquidacion_ids'],
                            'archivos' => $beneficiario['archivos'],
                            'codigos_beneficiario' => $beneficiario['codigos_beneficiario'] ?? [],
                        ],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'updated_at' => now(),
                ];

                if ($existente === null) {
                    $resultado['repartos_creados']++;

                    if ($confirmar) {
                        DB::table('repartos_propietarios')->insert([
                            'cuenta' => $cuenta,
                            'beneficiario_normalizado' => $claveBeneficiario,
                            'cliente_id' => null,
                            'periodo_desde' => $periodo,
                            'created_at' => now(),
                            ...$payload,
                        ]);
                    }

                    continue;
                }

                if ($this->requiereActualizacion($existente, $payload)) {
                    $resultado['repartos_actualizados']++;

                    if ($confirmar) {
                        // cliente_id se preserva: puede haberse vinculado manualmente.
                        DB::table('repartos_propietarios')
                            ->where('id', $existente->id)
                            ->update($payload);
                    }
                } else {
                    $resultado['repartos_sin_cambios']++;
                }
            }

            foreach ($existentes as $claveExistente => $existente) {
                if (! (bool) $existente->activo || isset($clavesVigentes[$claveExistente])) {
                    continue;
                }

                $resultado['repartos_desactivados']++;

                if ($confirmar) {
                    DB::table('repartos_propietarios')
                        ->where('id', $existente->id)
                        ->update([
                            'activo' => false,
                            'periodo_baja' => $periodo,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        return $resultado;
    }

    /**
     * @param array<string, int|bool|string> $resultado
     * @param callable(array<string, mixed>):void|null $incidencia
     * @return array<string, array{invalida:bool, beneficiarios:array<string, array<string, mixed>>}>
     */
    private function cargarFuentes(
        string $periodo,
        array &$resultado,
        ?callable $incidencia
    ): array {
        // Las liquidaciones ya emitidas no se modifican. Para reconstruir el
        // REPARTO usamos primero las líneas de los TXT COBOL del período, que
        // conservan información que algunos parsers históricos pudieron no
        // persistir (por ejemplo: "GIARDINO MARIA B 273088 PESOS 50,000%...").
        $fuentes = $this->cargarRepartosExplicitosDesdeTxt($periodo);
        $cuentasConRepartoTxt = array_fill_keys(array_keys($fuentes), true);

        // Fallback para períodos cuyos TXT ya no estén disponibles: mantenemos
        // la interpretación de las liquidaciones persistidas en PostgreSQL.
        $cuentasConRepartoExplicitoDb = [];

        DB::table('liquidaciones_propietarios')
            ->where('periodo', $periodo)
            ->where(function ($query): void {
                $query->where(function ($subquery): void {
                    $subquery->whereNotNull('porcentaje')
                        ->whereRaw("BTRIM(porcentaje) <> ''");
                })->orWhere(function ($subquery): void {
                    $subquery->whereNotNull('copropietario')
                        ->whereRaw("BTRIM(copropietario) <> ''");
                });
            })
            ->pluck('cuenta')
            ->each(function ($cuenta) use (&$cuentasConRepartoExplicitoDb): void {
                $normalizada = $this->normalizarCuenta((string) $cuenta);

                if ($normalizada !== '') {
                    $cuentasConRepartoExplicitoDb[$normalizada] = true;
                }
            });

        $liquidaciones = DB::table('liquidaciones_propietarios')
            ->where('periodo', $periodo)
            ->select([
                'id',
                'cuenta',
                'cuenta_impresa',
                'propietario',
                'copropietario',
                'porcentaje',
                'archivo_origen',
            ])
            ->orderBy('cuenta')
            ->orderBy('id')
            ->cursor();

        foreach ($liquidaciones as $liquidacion) {
            $resultado['liquidaciones_fuente']++;

            $cuenta = $this->normalizarCuenta((string) $liquidacion->cuenta);
            $copropietario = trim((string) ($liquidacion->copropietario ?? ''));
            $propietario = trim((string) ($liquidacion->propietario ?? ''));

            // Si el TXT contiene el reparto explícito de esta cuenta, esa es la
            // fuente autoritativa. La fila PostgreSQL sólo enriquece trazabilidad
            // (propietario, cuenta impresa e ID de la liquidación cuando coincide
            // el beneficiario). Nunca invalida un reparto que el TXT sí contiene.
            if ($cuenta !== '' && isset($cuentasConRepartoTxt[$cuenta])) {
                foreach ($fuentes[$cuenta]['beneficiarios'] as &$datosBeneficiario) {
                    if (($datosBeneficiario['propietario'] ?? null) === null && $propietario !== '') {
                        $datosBeneficiario['propietario'] = $propietario;
                    }

                    $cuentaImpresa = trim((string) ($liquidacion->cuenta_impresa ?? ''));
                    if ($cuentaImpresa !== '') {
                        $datosBeneficiario['cuenta_impresa'] = $cuentaImpresa;
                    }
                }
                unset($datosBeneficiario);

                if ($copropietario !== '') {
                    $claveDb = $this->normalizarNombre($copropietario);

                    if ($claveDb !== '' && isset($fuentes[$cuenta]['beneficiarios'][$claveDb])) {
                        $fuentes[$cuenta]['beneficiarios'][$claveDb]['liquidacion_ids'][] = (int) $liquidacion->id;

                        $archivo = trim((string) ($liquidacion->archivo_origen ?? ''));
                        if ($archivo !== '') {
                            $fuentes[$cuenta]['beneficiarios'][$claveDb]['archivos'][$archivo] = true;
                        }
                    }
                }

                continue;
            }

            $beneficiario = $copropietario !== '' ? $copropietario : $propietario;
            $claveBeneficiario = $this->normalizarNombre($beneficiario);
            $porcentajeMicros = $this->porcentajeAMicros($liquidacion->porcentaje);

            // Sin reparto explícito, la liquidación del titular representa 100%.
            // Con reparto explícito conocido sólo por PostgreSQL, una fila vacía
            // es auxiliar y no constituye un beneficiario adicional.
            if ($porcentajeMicros === null && $copropietario === '' && $propietario !== '') {
                if (isset($cuentasConRepartoExplicitoDb[$cuenta])) {
                    continue;
                }

                $porcentajeMicros = self::PORCENTAJE_100_MICROS;
            }

            if ($cuenta === '' || $claveBeneficiario === '' || $porcentajeMicros === null) {
                if ($cuenta !== '') {
                    $fuentes[$cuenta] ??= ['invalida' => false, 'beneficiarios' => []];
                    $fuentes[$cuenta]['invalida'] = true;
                }

                $this->registrarIncidencia($resultado, $incidencia, [
                    'tipo' => 'LIQUIDACION_REPARTO_INVALIDA',
                    'cuenta' => $cuenta,
                    'beneficiario' => $beneficiario,
                    'detalle' => 'No se pudo normalizar cuenta, beneficiario o porcentaje.',
                ]);
                continue;
            }

            $fuentes[$cuenta] ??= ['invalida' => false, 'beneficiarios' => []];
            $fuentes[$cuenta]['beneficiarios'][$claveBeneficiario] ??= [
                'nombre' => $beneficiario,
                'propietario' => $propietario !== '' ? $propietario : null,
                'cuenta_impresa' => trim((string) ($liquidacion->cuenta_impresa ?? '')) ?: $cuenta,
                'porcentajes' => [],
                'liquidacion_ids' => [],
                'archivos' => [],
                'codigos_beneficiario' => [],
            ];

            $fuentes[$cuenta]['beneficiarios'][$claveBeneficiario]['porcentajes'][$porcentajeMicros] = true;
            $fuentes[$cuenta]['beneficiarios'][$claveBeneficiario]['liquidacion_ids'][] = (int) $liquidacion->id;

            $archivo = trim((string) ($liquidacion->archivo_origen ?? ''));
            if ($archivo !== '') {
                $fuentes[$cuenta]['beneficiarios'][$claveBeneficiario]['archivos'][$archivo] = true;
            }
        }

        return $fuentes;
    }

    /**
     * Lee únicamente la información de REPARTO desde los TXT originales del
     * período. No modifica ni vuelve a generar las liquidaciones/PDF existentes.
     *
     * @return array<string, array{invalida:bool, beneficiarios:array<string, array<string, mixed>>}>
     */
    private function cargarRepartosExplicitosDesdeTxt(string $periodo): array
    {
        $directorio = Storage::path("liquidaciones/periodos/{$periodo}/liquidaciones");
        $fuentes = [];

        foreach (self::ARCHIVOS_REPARTO as $nombreArchivo) {
            $ruta = "{$directorio}/{$nombreArchivo}";

            if (! is_file($ruta) || ! is_readable($ruta)) {
                continue;
            }

            $archivo = fopen($ruta, 'rb');
            if ($archivo === false) {
                continue;
            }

            $cuentaActual = '';
            $cuentaImpresa = '';

            try {
                while (($linea = fgets($archivo)) !== false) {
                    $linea = $this->aUtf8($linea);

                    if (preg_match('/(?<!\\d)(\\d{4}\\/\\d{5}\\/\\d{2})(?!\\d)/', $linea, $cuentaMatch) === 1) {
                        $cuentaImpresa = $cuentaMatch[1];
                        $cuentaActual = $this->normalizarCuenta($cuentaImpresa);
                    }

                    if ($cuentaActual === '') {
                        continue;
                    }

                    $reparto = $this->extraerLineaReparto($linea);
                    if ($reparto === null) {
                        continue;
                    }

                    $claveBeneficiario = $this->normalizarNombre($reparto['beneficiario']);
                    if ($claveBeneficiario === '') {
                        continue;
                    }

                    $fuentes[$cuentaActual] ??= [
                        'invalida' => false,
                        'beneficiarios' => [],
                    ];

                    $fuentes[$cuentaActual]['beneficiarios'][$claveBeneficiario] ??= [
                        'nombre' => $reparto['beneficiario'],
                        'propietario' => null,
                        'cuenta_impresa' => $cuentaImpresa !== '' ? $cuentaImpresa : $cuentaActual,
                        'porcentajes' => [],
                        'liquidacion_ids' => [],
                        'archivos' => [],
                        'codigos_beneficiario' => [],
                    ];

                    $porcentajeMicros = $this->porcentajeAMicros($reparto['porcentaje']);
                    if ($porcentajeMicros === null) {
                        $fuentes[$cuentaActual]['invalida'] = true;
                        continue;
                    }

                    $fuentes[$cuentaActual]['beneficiarios'][$claveBeneficiario]['porcentajes'][$porcentajeMicros] = true;
                    $fuentes[$cuentaActual]['beneficiarios'][$claveBeneficiario]['archivos'][$nombreArchivo] = true;

                    if ($reparto['codigo'] !== null) {
                        $fuentes[$cuentaActual]['beneficiarios'][$claveBeneficiario]['codigos_beneficiario'][$reparto['codigo']] = true;
                    }
                }
            } finally {
                fclose($archivo);
            }
        }

        return $fuentes;
    }

    /** @return array{beneficiario:string, porcentaje:string, codigo:?string}|null */
    private function extraerLineaReparto(string $linea): ?array
    {
        if (stripos($linea, 'PESOS') === false || ! str_contains($linea, '%')) {
            return null;
        }

        if (preg_match(
            '/^\\s*(?<prefijo>.+?)\\s+PESOS\\s+(?<porcentaje>\\d{1,3}(?:[.,]\\d{1,6})?)%/iu',
            $linea,
            $partes
        ) !== 1) {
            return null;
        }

        $prefijo = trim((string) $partes['prefijo']);
        $codigo = null;

        // REPARTO puede imprimir un código interno después del nombre. No forma
        // parte de la identidad textual del beneficiario.
        if (preg_match('/^(?<nombre>.+?)\\s+(?<codigo>\\d{4,10})$/u', $prefijo, $codigoMatch) === 1) {
            $prefijo = trim((string) $codigoMatch['nombre']);
            $codigo = (string) $codigoMatch['codigo'];
        }

        if ($prefijo === '') {
            return null;
        }

        return [
            'beneficiario' => $prefijo,
            'porcentaje' => (string) $partes['porcentaje'].'%',
            'codigo' => $codigo,
        ];
    }

    private function aUtf8(string $linea): string
    {
        if (preg_match('//u', $linea) === 1) {
            return $linea;
        }

        $convertida = @iconv('Windows-1252', 'UTF-8//IGNORE', $linea);

        return $convertida !== false ? $convertida : $linea;
    }

    /**
     * @param array<string, array<string, mixed>> $beneficiarios
     * @param array<string, int|bool|string> $resultado
     * @param callable(array<string, mixed>):void|null $incidencia
     * @return array<string, array<string, mixed>>|null
     */
    private function validarCuenta(
        string $cuenta,
        array $beneficiarios,
        array &$resultado,
        ?callable $incidencia
    ): ?array {
        if ($beneficiarios === []) {
            return null;
        }

        $validados = [];
        $sumaMicros = 0;

        foreach ($beneficiarios as $clave => $datos) {
            $porcentajes = array_map('intval', array_keys($datos['porcentajes']));

            if (count($porcentajes) !== 1) {
                $this->registrarIncidencia($resultado, $incidencia, [
                    'tipo' => 'PORCENTAJE_CONFLICTIVO',
                    'cuenta' => $cuenta,
                    'beneficiario' => $datos['nombre'],
                    'detalle' => 'El mismo beneficiario tiene más de un porcentaje dentro del período.',
                ]);

                return null;
            }

            $porcentajeMicros = $porcentajes[0];
            $sumaMicros += $porcentajeMicros;

            $ids = array_values(array_unique(array_map('intval', $datos['liquidacion_ids'])));
            sort($ids);
            $archivos = array_keys($datos['archivos']);
            sort($archivos);

            $validados[$clave] = [
                'nombre' => $datos['nombre'],
                'propietario' => $datos['propietario'],
                'cuenta_impresa' => $datos['cuenta_impresa'],
                'porcentaje_micros' => $porcentajeMicros,
                'liquidacion_ids' => $ids,
                'ultima_liquidacion_id' => $ids !== [] ? max($ids) : null,
                'archivos' => $archivos,
                'codigos_beneficiario' => array_values(array_keys($datos['codigos_beneficiario'] ?? [])),
            ];
        }

        if (abs($sumaMicros - self::PORCENTAJE_100_MICROS) > self::TOLERANCIA_SUMA_MICROS) {
            $this->registrarIncidencia($resultado, $incidencia, [
                'tipo' => 'SUMA_PORCENTAJES_INVALIDA',
                'cuenta' => $cuenta,
                'beneficiario' => '',
                'detalle' => sprintf(
                    'La suma del reparto es %s%% y no aproximadamente 100%%.',
                    $this->microsATexto($sumaMicros)
                ),
            ]);

            return null;
        }

        return $validados;
    }

    /** @param array<string, mixed> $payload */
    private function requiereActualizacion(object $existente, array $payload): bool
    {
        if (! (bool) $existente->activo || $existente->periodo_baja !== null) {
            return true;
        }

        if ((string) $existente->ultimo_periodo !== (string) $payload['ultimo_periodo']) {
            return true;
        }

        if ((string) $existente->beneficiario !== (string) $payload['beneficiario']
            || (string) ($existente->propietario ?? '') !== (string) ($payload['propietario'] ?? '')
            || (string) ($existente->cuenta_impresa ?? '') !== (string) ($payload['cuenta_impresa'] ?? '')) {
            return true;
        }

        $actual = $this->porcentajeAMicros($existente->porcentaje);
        $nuevo = $this->porcentajeAMicros($payload['porcentaje']);

        return $actual !== $nuevo
            || (int) ($existente->ultima_liquidacion_id ?? 0) !== (int) ($payload['ultima_liquidacion_id'] ?? 0);
    }

    private function normalizarCuenta(string $cuenta): string
    {
        return preg_replace('/\D+/', '', $cuenta) ?? '';
    }

    private function normalizarNombre(string $nombre): string
    {
        $nombre = Str::upper(Str::ascii(trim($nombre)));
        $nombre = preg_replace('/[^A-Z0-9]+/', ' ', $nombre) ?? '';

        return trim(preg_replace('/\s+/', ' ', $nombre) ?? '');
    }

    private function porcentajeAMicros(mixed $valor): ?int
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }

        $texto = str_replace(['%', ' '], '', $texto);

        if (str_contains($texto, ',')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,6}))?$/', $texto, $partes) !== 1) {
            return null;
        }

        $entero = (int) $partes[1];
        $decimales = str_pad($partes[2] ?? '', 6, '0');
        $micros = ($entero * 1_000_000) + (int) $decimales;

        return $micros >= 0 && $micros <= self::PORCENTAJE_100_MICROS
            ? $micros
            : null;
    }

    private function microsADecimal(int $micros): string
    {
        return sprintf('%d.%06d', intdiv($micros, 1_000_000), $micros % 1_000_000);
    }

    private function microsATexto(int $micros): string
    {
        $texto = number_format($micros / 1_000_000, 6, ',', '.');

        return rtrim(rtrim($texto, '0'), ',');
    }

    /**
     * @param array<string, int|bool|string> $resultado
     * @param callable(array<string, mixed>):void|null $incidencia
     * @param array<string, mixed> $detalle
     */
    private function registrarIncidencia(
        array &$resultado,
        ?callable $incidencia,
        array $detalle
    ): void {
        $resultado['incidencias']++;
        $incidencia?->__invoke($detalle);
    }

    private function resolverPeriodo(?string $periodo): string
    {
        if ($periodo !== null && $periodo !== '') {
            if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
                throw new RuntimeException('El período indicado no es válido. Usá AAAAMM.');
            }

            return $periodo;
        }

        $ultimo = DB::table('liquidaciones_propietarios')->max('periodo');
        if (! is_string($ultimo) || $ultimo === '') {
            throw new RuntimeException('No hay liquidaciones de propietarios importadas.');
        }

        return $ultimo;
    }

    private function validarEsquema(): void
    {
        foreach (['liquidaciones_propietarios', 'repartos_propietarios'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                throw new RuntimeException("Falta la tabla {$tabla}. Ejecutá las migraciones antes de sincronizar.");
            }
        }
    }
}
