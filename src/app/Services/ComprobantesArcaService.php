<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ComprobantesArcaService
{
    /**
     * @return Collection<string, Collection<int, object>>
     */
    public function porPeriodo(string $periodo): Collection
    {
        if (! $this->periodoValido($periodo) || ! $this->kngDisponible()) {
            return collect();
        }

        return $this->consultarPeriodo($periodo);
    }

    /**
     * @param list<string> $cuentas
     * @return Collection<string, Collection<int, object>>
     */
    public function porCuentasYPeriodo(array $cuentas, string $periodo): Collection
    {
        if (! $this->periodoValido($periodo) || ! $this->kngDisponible()) {
            return collect();
        }

        $cuentas = collect($cuentas)
            ->map(fn ($cuenta): string => $this->normalizarCuenta((string) $cuenta))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($cuentas === []) {
            return collect();
        }

        return $this->consultarPeriodo($periodo, $cuentas);
    }

    /** @return Collection<int, object> */
    public function paraCuentaPeriodo(string $cuenta, string $periodo): Collection
    {
        $cuenta = $this->normalizarCuenta($cuenta);

        if ($cuenta === '') {
            return collect();
        }

        return $this->porCuentasYPeriodo([$cuenta], $periodo)
            ->get($cuenta, collect());
    }

    /**
     * @return Collection<int, string>
     */
    public function periodosDisponibles(): Collection
    {
        if (! $this->kngDisponible()) {
            return collect();
        }

        try {
            return collect(DB::select(<<<'SQL'
select distinct replace(left(kf.datos->>'FECHA', 7), '-', '') as periodo
from kng_facturas kf
where kf.eliminado = false
  and coalesce(kf.datos->>'FECHA', '') ~ '^[12][0-9]{3}-(0[1-9]|1[0-2])-'
order by periodo desc
SQL))
                ->pluck('periodo')
                ->map(fn ($periodo): string => (string) $periodo)
                ->filter(fn (string $periodo): bool => $this->periodoValido($periodo))
                ->values();
        } catch (Throwable) {
            return collect();
        }
    }

    public function periodoDisponible(string $periodo): bool
    {
        if (! $this->periodoValido($periodo) || ! $this->kngDisponible()) {
            return false;
        }

        $mes = substr($periodo, 0, 4).'-'.substr($periodo, 4, 2);

        try {
            return DB::table('kng_facturas')
                ->where('eliminado', false)
                ->whereRaw("left(coalesce(datos->>'FECHA', ''), 7) = ?", [$mes])
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Conserva la firma histórica del servicio.
     *
     * Los comprobantes ya no viven en AAAA/MM: se resuelven físicamente
     * dentro de KNG/Facturas, preferentemente en lote_<número>.
     */
    public function rutaRelativa(string $periodo, string $nombre): ?string
    {
        $ruta = $this->rutaFisica($periodo, $nombre);

        return $ruta !== null ? $nombre : null;
    }

    public function archivoDisponible(string $periodo, string $nombre): bool
    {
        return $this->rutaFisica($periodo, $nombre) !== null;
    }

    /**
     * Devuelve la ruta física real del PDF KNG.
     */
    public function rutaFisica(string $periodo, string $nombre): ?string
    {
        if (
            ! $this->periodoValido($periodo)
            || ! $this->nombrePdfValido($nombre)
            || ! $this->kngDisponible()
        ) {
            return null;
        }

        if (preg_match('/^([A-Z]{2})-(\d{4})-(\d{8})-(\d{11})\.pdf$/i', $nombre, $m) !== 1) {
            return null;
        }

        $tipoCodigo = strtoupper($m[1]);
        $puntoVenta = (string) ((int) $m[2]);
        $numero = (string) ((int) $m[3]);
        $cuenta = $this->normalizarCuenta($m[4]);
        $mes = substr($periodo, 0, 4).'-'.substr($periodo, 4, 2);

        try {
            $filas = DB::select(
                <<<'SQL'
select
    kf.datos->>'LOTE' as lote,
    kf.datos->>'TIPO' as tipo
from kng_facturas kf
where kf.eliminado = false
  and left(coalesce(kf.datos->>'FECHA', ''), 7) = ?
  and nullif(regexp_replace(coalesce(kf.datos->>'P_VENTA', ''), '[^0-9]', '', 'g'), '')::bigint = ?::bigint
  and nullif(regexp_replace(coalesce(kf.datos->>'ID_FACTURA', ''), '[^0-9]', '', 'g'), '')::bigint = ?::bigint
  and (
        case
            when regexp_replace(coalesce(kf.datos->>'ID_INQ', ''), '[^0-9]', '', 'g') not in ('', '0')
                then regexp_replace(kf.datos->>'ID_INQ', '[^0-9]', '', 'g')
            else regexp_replace(coalesce(kf.datos->>'CTA_ORIG', ''), '[^0-9]', '', 'g')
        end
      ) = ?
order by nullif(regexp_replace(coalesce(kf.datos->>'LOTE', ''), '[^0-9]', '', 'g'), '')::bigint desc nulls last
SQL,
                [$mes, $puntoVenta, $numero, $cuenta]
            );
        } catch (Throwable) {
            return null;
        }

        foreach ($filas as $fila) {
            if ($this->codigoTipo((string) ($fila->tipo ?? '')) !== $tipoCodigo) {
                continue;
            }

            $ruta = $this->buscarPdfFisico((string) ($fila->lote ?? ''), $nombre);
            if ($ruta !== null) {
                return $ruta;
            }
        }

        return null;
    }

    public function normalizarCuenta(string $cuenta): string
    {
        return preg_replace('/\D+/', '', $cuenta) ?? '';
    }

    /**
     * @param list<string>|null $cuentas
     * @return Collection<string, Collection<int, object>>
     */
    private function consultarPeriodo(string $periodo, ?array $cuentas = null): Collection
    {
        $mes = substr($periodo, 0, 4).'-'.substr($periodo, 4, 2);
        $filtroCuentas = '';
        $params = [$mes];

        if ($cuentas !== null) {
            $placeholders = implode(',', array_fill(0, count($cuentas), '?'));
            $filtroCuentas = "
  and (
        case
            when regexp_replace(coalesce(kf.datos->>'ID_INQ', ''), '[^0-9]', '', 'g') not in ('', '0')
                then regexp_replace(kf.datos->>'ID_INQ', '[^0-9]', '', 'g')
            else regexp_replace(coalesce(kf.datos->>'CTA_ORIG', ''), '[^0-9]', '', 'g')
        end
      ) in ({$placeholders})";
            $params = [...$params, ...$cuentas];
        }

        $sql = "
select
    case
        when regexp_replace(coalesce(kf.datos->>'ID_INQ', ''), '[^0-9]', '', 'g') not in ('', '0')
            then regexp_replace(kf.datos->>'ID_INQ', '[^0-9]', '', 'g')
        else regexp_replace(coalesce(kf.datos->>'CTA_ORIG', ''), '[^0-9]', '', 'g')
    end as cuenta_cobol,

    kf.datos->>'P_VENTA' as punto_venta,
    kf.datos->>'ID_FACTURA' as id_factura,
    nullif(kf.datos->>'FECHA', '') as fecha,
    nullif(kf.datos->>'TOTAL', '') as total,
    nullif(kf.datos->>'GRAVADO', '') as gravado,
    nullif(kf.datos->>'NO_GRAVADO', '') as no_gravado,
    nullif(kf.datos->>'IVA', '') as iva,
    nullif(kf.datos->>'PERCEPCION', '') as percepcion,
    nullif(kf.datos->>'TIPO', '') as tipo,
    nullif(kf.datos->>'CAE', '') as cae,
    nullif(kf.datos->>'VTO_CAE', '') as vto_cae,
    kf.datos->>'LOTE' as lote,
    nullif(kf.datos->>'ID_INQ', '') as id_inq,
    nullif(kf.datos->>'PROPIETA', '') as propieta,
    nullif(kf.datos->>'CTA_ORIG', '') as cta_orig,

    coalesce(
        nullif(kl.datos->>'DETALLE', ''),
        nullif(kl.datos->>'DESCRIPCION', ''),
        nullif(kl.datos->>'DESCRI', '')
    ) as detalle_lote,
    nullif(kl.datos->>'DESDE', '') as lote_desde,
    nullif(kl.datos->>'HASTA', '') as lote_hasta

from kng_facturas kf
left join kng_lotes kl
  on kl.eliminado = false
 and coalesce(kl.datos->>'ID_LOTE', kl.datos->>'LOTE') = kf.datos->>'LOTE'

where kf.eliminado = false
  and left(coalesce(kf.datos->>'FECHA', ''), 7) = ?
{$filtroCuentas}

order by
    cuenta_cobol,
    coalesce(kf.datos->>'FECHA', '') desc,
    nullif(regexp_replace(coalesce(kf.datos->>'LOTE', ''), '[^0-9]', '', 'g'), '')::bigint desc nulls last,
    nullif(regexp_replace(coalesce(kf.datos->>'ID_FACTURA', ''), '[^0-9]', '', 'g'), '')::bigint desc nulls last
";

        try {
            $filas = DB::select($sql, $params);
        } catch (Throwable) {
            return collect();
        }

        return collect($filas)
            ->map(fn (object $fila): object => $this->normalizarComprobante($fila))
            ->filter(fn (object $fila): bool => $fila->cuenta_cobol !== '')
            ->groupBy('cuenta_cobol')
            ->map(fn (Collection $items): Collection => $items->values());
    }

    private function normalizarComprobante(object $fila): object
    {
        $fila->cuenta_cobol = $this->normalizarCuenta(
            (string) ($fila->cuenta_cobol ?? '')
        );

        $fila->punto_venta = str_pad(
            (string) ((int) preg_replace('/\D+/', '', (string) ($fila->punto_venta ?? ''))),
            4,
            '0',
            STR_PAD_LEFT
        );

        $numero = preg_replace('/\D+/', '', (string) ($fila->id_factura ?? '')) ?: '';
        $fila->numero_comprobante = $numero !== ''
            ? str_pad($numero, 8, '0', STR_PAD_LEFT)
            : null;

        $fila->tipo_codigo = $this->codigoTipo((string) ($fila->tipo ?? ''));

        $fila->nombre_archivo = null;
        $fila->ruta_relativa = null;
        $fila->ruta_fisica = null;
        $fila->pdf_disponible = false;
        $fila->comprobante = null;

        if (
            $fila->tipo_codigo !== ''
            && $fila->punto_venta !== '0000'
            && $fila->numero_comprobante !== null
            && $fila->cuenta_cobol !== ''
        ) {
            $nombreEsperado = sprintf(
                '%s-%04d-%08d-%011d.pdf',
                $fila->tipo_codigo,
                (int) $fila->punto_venta,
                (int) $fila->numero_comprobante,
                (int) $fila->cuenta_cobol
            );

            $ruta = $this->buscarPdfFisico((string) ($fila->lote ?? ''), $nombreEsperado);

            // Aunque el PDF físico no exista, conservamos el nombre esperado
            // para que la interfaz pueda informar "Sin PDF".
            $fila->nombre_archivo = $ruta !== null ? basename($ruta) : $nombreEsperado;
            $fila->ruta_fisica = $ruta;
            $fila->pdf_disponible = $ruta !== null;
            $fila->comprobante = preg_replace(
                '/-\d{11}\.pdf$/i',
                '',
                $fila->nombre_archivo
            );
        }

        return $fila;
    }

    private function buscarPdfFisico(string $lote, string $nombreEsperado): ?string
    {
        if (! $this->nombrePdfValido($nombreEsperado)) {
            return null;
        }

        $base = $this->directorioFacturasKng();
        if ($base === null) {
            return null;
        }

        $lote = preg_replace('/\D+/', '', $lote) ?? '';
        $candidatas = [];

        if ($lote !== '') {
            $candidatas[] = $base.DIRECTORY_SEPARATOR.'lote_'.$lote.DIRECTORY_SEPARATOR.$nombreEsperado;
        }

        $candidatas[] = $base.DIRECTORY_SEPARATOR.$nombreEsperado;

        foreach ($candidatas as $ruta) {
            if (is_file($ruta) && is_readable($ruta) && filesize($ruta) > 0) {
                $real = realpath($ruta);
                if ($real !== false && $this->rutaDentroDe($real, $base)) {
                    return $real;
                }
            }
        }

        // Compatibilidad con PDFs históricos cuyo nombre no se pudo reconstruir
        // exactamente: se busca dentro del lote por PV + cuenta y, si es posible,
        // por el mismo prefijo fiscal.
        if (
            $lote !== ''
            && preg_match('/^([A-Z]{2})-(\d{4})-\d{8}-(\d{11})\.pdf$/i', $nombreEsperado, $m) === 1
        ) {
            $directorioLote = $base.DIRECTORY_SEPARATOR.'lote_'.$lote;
            if (is_dir($directorioLote) && is_readable($directorioLote)) {
                $prefijo = strtoupper($m[1]).'-'.$m[2].'-';
                $sufijo = '-'.$m[3].'.pdf';

                foreach (scandir($directorioLote, SCANDIR_SORT_ASCENDING) ?: [] as $archivo) {
                    if (
                        str_starts_with(strtoupper($archivo), $prefijo)
                        && str_ends_with(strtolower($archivo), strtolower($sufijo))
                        && $this->nombrePdfValido($archivo)
                    ) {
                        $ruta = $directorioLote.DIRECTORY_SEPARATOR.$archivo;
                        if (is_file($ruta) && is_readable($ruta) && filesize($ruta) > 0) {
                            $real = realpath($ruta);
                            if ($real !== false && $this->rutaDentroDe($real, $base)) {
                                return $real;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    private function directorioFacturasKng(): ?string
    {
        $root = rtrim((string) config('gei.kng.root', '/archivo-kng'), DIRECTORY_SEPARATOR);
        $dir = trim((string) config('gei.kng.facturas_dir', 'Facturas'), DIRECTORY_SEPARATOR);

        if ($root === '' || $dir === '') {
            return null;
        }

        $base = $root.DIRECTORY_SEPARATOR.$dir;

        return is_dir($base) && is_readable($base) ? $base : null;
    }

    private function rutaDentroDe(string $ruta, string $base): bool
    {
        $realBase = realpath($base);

        return $realBase !== false
            && ($ruta === $realBase || str_starts_with($ruta, $realBase.DIRECTORY_SEPARATOR));
    }

    private function codigoTipo(string $tipo): string
    {
        $tipo = strtoupper(trim($tipo));

        if (in_array($tipo, ['FA', 'CA', 'FB', 'CB'], true)) {
            return $tipo;
        }

        return match ((int) $tipo) {
            1 => 'FA',
            3 => 'CA',
            6 => 'FB',
            8 => 'CB',
            default => '',
        };
    }

    private function nombrePdfValido(string $nombre): bool
    {
        return $nombre !== ''
            && $nombre === basename($nombre)
            && preg_match('/^[A-Z]{2}-\d{4}-\d{8}-\d{11}\.pdf$/i', $nombre) === 1;
    }

    private function kngDisponible(): bool
    {
        try {
            return Schema::hasTable('kng_facturas');
        } catch (Throwable) {
            return false;
        }
    }

    private function periodoValido(string $periodo): bool
    {
        return preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) === 1;
    }
}
