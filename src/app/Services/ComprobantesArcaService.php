<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class ComprobantesArcaService
{
    private const PATRON = '/^([A-Z]{2})-(\d{4})-(\d{8})-(\d{11})\.pdf$/i';

    /**
     * Lee directamente el directorio AAAA/MM del período y agrupa por cuenta COBOL.
     * No depende de la tabla comprobantes_arca ni recorre otros períodos.
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function porPeriodo(string $periodo): Collection
    {
        $directorio = $this->directorioPeriodo($periodo);
        if ($directorio === null || ! is_dir($directorio) || ! is_readable($directorio)) {
            return collect();
        }

        $nombres = @scandir($directorio, SCANDIR_SORT_NONE);
        if (! is_array($nombres)) {
            return collect();
        }

        $grupos = [];
        foreach ($nombres as $nombre) {
            if ($nombre === '.' || $nombre === '..') {
                continue;
            }

            if (preg_match(self::PATRON, $nombre, $m) !== 1) {
                continue;
            }

            $cuenta = $m[4];
            $grupos[$cuenta][] = (object) [
                'cuenta_cobol' => $cuenta,
                'tipo_codigo' => strtoupper($m[1]),
                'punto_venta' => $m[2],
                'numero_comprobante' => $m[3],
                'nombre_archivo' => $nombre,
                'ruta_relativa' => $this->rutaRelativa($periodo, $nombre),
            ];
        }

        foreach ($grupos as &$comprobantes) {
            usort(
                $comprobantes,
                static fn (object $a, object $b): int => strcmp(
                    $b->tipo_codigo.'-'.$b->punto_venta.'-'.$b->numero_comprobante,
                    $a->tipo_codigo.'-'.$a->punto_venta.'-'.$a->numero_comprobante,
                )
            );
        }
        unset($comprobantes);

        return collect($grupos)
            ->map(static fn (array $items): Collection => collect($items));
    }

    /**
     * @param list<string> $cuentas
     * @return Collection<string, Collection<int, object>>
     */
    public function porCuentasYPeriodo(array $cuentas, string $periodo): Collection
    {
        $cuentas = array_values(array_unique(array_filter(
            array_map([$this, 'normalizarCuenta'], $cuentas),
            static fn (string $cuenta): bool => $cuenta !== ''
        )));

        if ($cuentas === []) {
            return collect();
        }

        $buscadas = array_fill_keys($cuentas, true);

        return $this->porPeriodo($periodo)
            ->filter(
                static fn (Collection $items, string $cuenta): bool => isset($buscadas[$cuenta])
            );
    }

    /** @return Collection<int, object> */
    public function paraCuentaPeriodo(string $cuenta, string $periodo): Collection
    {
        $cuenta = $this->normalizarCuenta($cuenta);
        if ($cuenta === '') {
            return collect();
        }

        return $this->porPeriodo($periodo)->get($cuenta, collect());
    }


    /**
     * Devuelve los períodos AAAAMM que existen físicamente bajo AAAA/MM.
     *
     * @return Collection<int, string>
     */
    public function periodosDisponibles(): Collection
    {
        $root = rtrim((string) config('filesystems.disks.arca_facturas.root'), DIRECTORY_SEPARATOR);
        if ($root === '' || ! is_dir($root) || ! is_readable($root)) {
            return collect();
        }

        $periodos = [];
        $anios = @scandir($root, SCANDIR_SORT_NONE);
        if (! is_array($anios)) {
            return collect();
        }

        foreach ($anios as $anio) {
            if (preg_match('/^(19|20)\d{2}$/', $anio) !== 1) {
                continue;
            }

            $rutaAnio = $root.DIRECTORY_SEPARATOR.$anio;
            if (! is_dir($rutaAnio) || ! is_readable($rutaAnio)) {
                continue;
            }

            $meses = @scandir($rutaAnio, SCANDIR_SORT_NONE);
            if (! is_array($meses)) {
                continue;
            }

            foreach ($meses as $mes) {
                if (preg_match('/^(0[1-9]|1[0-2])$/', $mes) !== 1) {
                    continue;
                }

                $rutaMes = $rutaAnio.DIRECTORY_SEPARATOR.$mes;
                if (is_dir($rutaMes) && is_readable($rutaMes)) {
                    $periodos[] = $anio.$mes;
                }
            }
        }

        rsort($periodos, SORT_STRING);

        return collect(array_values(array_unique($periodos)));
    }

    public function periodoDisponible(string $periodo): bool
    {
        $directorio = $this->directorioPeriodo($periodo);

        return $directorio !== null && is_dir($directorio) && is_readable($directorio);
    }

    public function rutaRelativa(string $periodo, string $nombre): ?string
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            return null;
        }

        if ($nombre !== basename($nombre) || preg_match(self::PATRON, $nombre) !== 1) {
            return null;
        }

        return substr($periodo, 0, 4).'/'.substr($periodo, 4, 2).'/'.$nombre;
    }

    public function archivoDisponible(string $periodo, string $nombre): bool
    {
        $ruta = $this->rutaRelativa($periodo, $nombre);
        if ($ruta === null) {
            return false;
        }

        return Storage::disk('arca_facturas')->exists($ruta)
            && (int) Storage::disk('arca_facturas')->size($ruta) > 0;
    }

    public function normalizarCuenta(string $cuenta): string
    {
        return preg_replace('/\D+/', '', $cuenta) ?? '';
    }

    private function directorioPeriodo(string $periodo): ?string
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            return null;
        }

        $root = rtrim((string) config('filesystems.disks.arca_facturas.root'), DIRECTORY_SEPARATOR);
        if ($root === '') {
            return null;
        }

        return $root
            .DIRECTORY_SEPARATOR.substr($periodo, 0, 4)
            .DIRECTORY_SEPARATOR.substr($periodo, 4, 2);
    }
}
