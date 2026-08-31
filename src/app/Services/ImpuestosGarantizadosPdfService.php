<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class ImpuestosGarantizadosPdfService
{
    /** @var array<string, array<string, array<int, int>>> */
    private array $indiceDailocCache = [];

    /** @var array<string, array<int, array<int, string>>> */
    private array $pdfsDailocCache = [];

    /** @return array<string, mixed> */
    public function analizar(string $periodo): array
    {
        return $this->ejecutar($periodo, 'analizar');
    }

    /**
     * @return list<string>
     */
    public function cuentasPeriodo(string $periodo): array
    {
        $this->validarPeriodo($periodo);

        return array_values(array_keys($this->indiceDailocPorCuenta($periodo)));
    }

    public function rutaPdfParaLiquidacion(
        string $periodo,
        ?string $rutaLiquidacion,
        ?string $cuenta = null,
    ): ?string {
        if (preg_match('/^(19|20)\\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            return null;
        }

        $cuentaDigitos = preg_replace('/\\D+/', '', trim((string) $cuenta)) ?? '';
        if ($cuentaDigitos === '') {
            return null;
        }

        $indice = $this->indiceDailocPorCuenta($periodo);
        $numeros = $indice[$cuentaDigitos] ?? [];

        // Una cuenta debe resolver a un único detalle DAILOC. Ante cualquier
        // ambigüedad no se adjunta ni se muestra un PDF posiblemente incorrecto.
        if (count($numeros) !== 1) {
            return null;
        }

        $numero = (int) $numeros[0];
        $pdfs = $this->pdfsDailocPorNumero($periodo);
        $rutas = $pdfs[$numero] ?? [];

        return count($rutas) === 1 ? $rutas[0] : null;
    }

    /** @return array<string, mixed> */
    public function generar(string $periodo): array
    {
        $analisis = $this->analizar($periodo);

        if (! ($analisis['validacion_ok'] ?? false)) {
            throw new RuntimeException(sprintf(
                'DAILOC tiene %d diferencia(s) de validación y %d error(es). No se generan los PDF de impuestos.',
                (int) ($analisis['validaciones_con_diferencia'] ?? 0),
                (int) ($analisis['errores'] ?? 0),
            ));
        }

        return $this->ejecutar($periodo, 'generar');
    }

    /** @return array<string, mixed> */
    private function ejecutar(string $periodo, string $accion): array
    {
        $this->validarPeriodo($periodo);

        $python = $this->python();
        $script = $this->script();
        $directorio = Storage::path("liquidaciones/periodos/{$periodo}/liquidaciones");

        if ($this->buscarDailoc($directorio) === null) {
            throw new RuntimeException("Falta dailoc.SF.txt para el período {$periodo}.");
        }

        $salida = Storage::disk('liquidaciones')->path(sprintf(
            '%s/%s/impuestos_garantizados',
            substr($periodo, 0, 4),
            substr($periodo, 4, 2),
        ));
        $resultadoPath = storage_path(
            "app/private/liquidaciones/tmp/dailoc_{$accion}_{$periodo}_".uniqid('', true).'.json'
        );

        File::ensureDirectoryExists(dirname($resultadoPath));
        if ($accion === 'generar') {
            File::ensureDirectoryExists($salida);
        }

        $process = new Process([
            $python,
            $script,
            $accion,
            '--directorio',
            $directorio,
            '--periodo',
            $periodo,
            '--salida',
            $salida,
            '--resultado',
            $resultadoPath,
            '--encoding',
            (string) config('gei.impuestos_garantizados.encoding', 'cp1252'),
        ], base_path());
        $process->setTimeout((int) config(
            'gei.impuestos_garantizados.timeout',
            config('gei.liquidaciones_propietarios.timeout', 1800)
        ));

        try {
            $process->run();

            $resultado = null;
            if (is_file($resultadoPath)) {
                try {
                    $resultado = json_decode(
                        (string) File::get($resultadoPath),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                } catch (JsonException $error) {
                    throw new RuntimeException(
                        'El resultado de DAILOC no es JSON válido.',
                        0,
                        $error
                    );
                }
            }

            if (! $process->isSuccessful()) {
                $mensaje = trim($process->getErrorOutput());
                if ($mensaje === '') {
                    $mensaje = trim($process->getOutput());
                }
                if ($mensaje === '' && is_array($resultado)) {
                    $mensaje = sprintf(
                        'DAILOC terminó con %d diferencia(s) y %d error(es).',
                        (int) ($resultado['validaciones_con_diferencia'] ?? 0),
                        (int) ($resultado['errores'] ?? 0),
                    );
                }
                throw new RuntimeException($mensaje !== '' ? $mensaje : 'Falló el proceso DAILOC.');
            }

            if (! is_array($resultado)) {
                throw new RuntimeException('DAILOC no devolvió un resultado verificable.');
            }

            return [
                ...$resultado,
                'salida_relativa' => sprintf(
                    '%s/%s/impuestos_garantizados/pdf',
                    substr($periodo, 0, 4),
                    substr($periodo, 4, 2),
                ),
            ];
        } finally {
            File::delete($resultadoPath);
        }
    }

    /** @return array<string, array<int, int>> */
    private function indiceDailocPorCuenta(string $periodo): array
    {
        if (array_key_exists($periodo, $this->indiceDailocCache)) {
            return $this->indiceDailocCache[$periodo];
        }

        $indice = [];
        $relativa = sprintf(
            '%s/%s/impuestos_garantizados/detalles.jsonl',
            substr($periodo, 0, 4),
            substr($periodo, 4, 2),
        );

        if (Storage::disk('liquidaciones')->exists($relativa)) {
            $lineas = preg_split(
                '/\\R/u',
                Storage::disk('liquidaciones')->get($relativa),
                -1,
                PREG_SPLIT_NO_EMPTY
            ) ?: [];

            foreach ($lineas as $linea) {
                try {
                    $detalle = json_decode($linea, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }

                if (! is_array($detalle)) {
                    continue;
                }

                $cuenta = preg_replace('/\\D+/', '', (string) ($detalle['cuenta'] ?? '')) ?? '';
                $numero = (int) ($detalle['numero'] ?? 0);
                if ($cuenta === '' || $numero <= 0) {
                    continue;
                }

                $indice[$cuenta][] = $numero;
            }
        }

        // Compatibilidad con períodos que tengan los PDF generados pero no el
        // manifiesto JSONL: el encabezado DAILOC contiene cuenta + número de detalle.
        if ($indice === []) {
            $indice = $this->indiceDesdeArchivoDailoc($periodo);
        }

        foreach ($indice as $cuenta => $numeros) {
            $indice[$cuenta] = array_values(array_unique(array_map('intval', $numeros)));
        }

        return $this->indiceDailocCache[$periodo] = $indice;
    }

    /** @return array<string, array<int, int>> */
    private function indiceDesdeArchivoDailoc(string $periodo): array
    {
        $directorio = Storage::path("liquidaciones/periodos/{$periodo}/liquidaciones");
        $origen = $this->buscarDailoc($directorio);
        if ($origen === null || ! is_file($origen)) {
            return [];
        }

        $contenido = (string) File::get($origen);
        $paginas = preg_split('/\\f/', str_replace("\\r", '', $contenido)) ?: [];
        $indice = [];

        foreach ($paginas as $pagina) {
            $lineas = explode("\\n", $pagina);
            foreach (array_slice($lineas, 0, 8) as $linea) {
                $izquierda = rtrim(substr($linea, 0, 114));
                if (preg_match('/\\b(\\d{9}\\/\\d{2})\\b.*?(\\*+\\d+)\\s*$/', $izquierda, $match) !== 1) {
                    continue;
                }

                $cuenta = preg_replace('/\\D+/', '', $match[1]) ?? '';
                $numero = (int) (preg_replace('/\\D+/', '', $match[2]) ?? '0');
                if ($cuenta !== '' && $numero > 0) {
                    $indice[$cuenta][] = $numero;
                }
                break;
            }
        }

        return $indice;
    }

    /** @return array<int, array<int, string>> */
    private function pdfsDailocPorNumero(string $periodo): array
    {
        if (array_key_exists($periodo, $this->pdfsDailocCache)) {
            return $this->pdfsDailocCache[$periodo];
        }

        $directorio = sprintf(
            '%s/%s/impuestos_garantizados/pdf',
            substr($periodo, 0, 4),
            substr($periodo, 4, 2),
        );
        $indice = [];

        foreach (Storage::disk('liquidaciones')->files($directorio) as $ruta) {
            if (preg_match('/ I0000-(\\d{8})\\.pdf$/iu', basename($ruta), $match) !== 1) {
                continue;
            }

            $indice[(int) $match[1]][] = $ruta;
        }

        return $this->pdfsDailocCache[$periodo] = $indice;
    }

    private function validarPeriodo(string $periodo): void
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            throw new RuntimeException('El período debe tener formato AAAAMM.');
        }
    }

    private function python(): string
    {
        $python = (string) config(
            'gei.impuestos_garantizados.python',
            config('gei.liquidaciones_propietarios.python')
        );

        if (! is_file($python) || ! is_executable($python)) {
            throw new RuntimeException("No se encontró el runtime Python para DAILOC: {$python}");
        }

        return $python;
    }

    private function script(): string
    {
        $script = (string) config(
            'gei.impuestos_garantizados.script',
            base_path('python/impuestos_garantizados/generar.py')
        );

        if (! is_file($script)) {
            throw new RuntimeException("No se encontró el generador DAILOC: {$script}");
        }

        return $script;
    }

    private function buscarDailoc(string $directorio): ?string
    {
        foreach (['dailoc.SF.txt', 'dailoc.sf.txt'] as $nombre) {
            $path = $directorio.DIRECTORY_SEPARATOR.$nombre;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
