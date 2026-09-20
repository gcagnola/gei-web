<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;

final class ComprobantesArcaService
{
    /**
     * @return Collection<string, Collection<int, object>>
     */
    public function porPeriodo(string $periodo): Collection
    {
        if (! $this->periodoValido($periodo)) {
            return collect();
        }

        $pdo = $this->pdo();
        if ($pdo === null) {
            return collect();
        }

        $mesSqlite = substr($periodo, 0, 4).'-'.substr($periodo, 4, 2);

        $sql = <<<'SQL'
select
    cast(
        case
            when f.id_inq is not null and f.id_inq <> 0 then f.id_inq
            else f.cta_orig
        end
        as text
    ) as cuenta_cobol,

    f.p_venta as punto_venta,
    f.id_factura,
    f.fecha,
    f.total,
    f.gravado,
    f.no_gravado,
    f.iva,
    f.percepcion,
    f.tipo,
    f.cae,
    f.vto_cae,
    f.lote,
    f.id_inq,
    f.propieta,
    f.cta_orig,

    l.detalle as detalle_lote,
    l.desde as lote_desde,
    l.hasta as lote_hasta,

    p.archivo as nombre_archivo,
    p.tipo_archivo,
    p.numero as numero_comprobante

from facturas f

left join lotes l
    on l.id_lote = f.lote

left join archivos_pdf p
    on p.lote = f.lote
   and p.cuenta_cobol = cast(
        case
            when f.id_inq is not null and f.id_inq <> 0 then f.id_inq
            else f.cta_orig
        end
        as text
   )
   and p.punto_venta = f.p_venta

where substr(coalesce(f.fecha, ''), 1, 7) = ?

order by
    cuenta_cobol,
    case when f.fecha is null then '' else f.fecha end desc,
    f.lote desc,
    f.id_factura desc,
    p.numero desc
SQL;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$mesSqlite]);
            $filas = $stmt->fetchAll();
        } catch (Throwable) {
            return collect();
        }

        return collect($filas)
            ->map(fn (object $fila): object => $this->normalizarComprobante($fila, $periodo))
            ->filter(fn (object $fila): bool => $fila->cuenta_cobol !== '')
            ->groupBy('cuenta_cobol')
            ->map(fn (Collection $items): Collection => $items->values());
    }

    /**
     * @param list<string> $cuentas
     * @return Collection<string, Collection<int, object>>
     */
    public function porCuentasYPeriodo(array $cuentas, string $periodo): Collection
    {
        if (! $this->periodoValido($periodo)) {
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

        $pdo = $this->pdo();
        if ($pdo === null) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($cuentas), '?'));
        $mesSqlite = substr($periodo, 0, 4).'-'.substr($periodo, 4, 2);

        $sql = "
select
    cast(
        case
            when f.id_inq is not null and f.id_inq <> 0 then f.id_inq
            else f.cta_orig
        end
        as text
    ) as cuenta_cobol,

    f.p_venta as punto_venta,
    f.id_factura,
    f.fecha,
    f.total,
    f.gravado,
    f.no_gravado,
    f.iva,
    f.percepcion,
    f.tipo,
    f.cae,
    f.vto_cae,
    f.lote,
    f.id_inq,
    f.propieta,
    f.cta_orig,

    l.detalle as detalle_lote,
    l.desde as lote_desde,
    l.hasta as lote_hasta,

    p.archivo as nombre_archivo,
    p.tipo_archivo,
    p.numero as numero_comprobante

from facturas f

left join lotes l
    on l.id_lote = f.lote

left join archivos_pdf p
    on p.lote = f.lote
   and p.cuenta_cobol = cast(
        case
            when f.id_inq is not null and f.id_inq <> 0 then f.id_inq
            else f.cta_orig
        end
        as text
   )
   and p.punto_venta = f.p_venta

where (
       cast(f.id_inq as text) in ({$placeholders})
    or cast(f.cta_orig as text) in ({$placeholders})
)
and substr(coalesce(f.fecha, ''), 1, 7) = ?

order by
    cuenta_cobol,
    case when f.fecha is null then '' else f.fecha end desc,
    f.lote desc,
    f.id_factura desc,
    p.numero desc
";

        $params = [...$cuentas, ...$cuentas, $mesSqlite];

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $filas = $stmt->fetchAll();
        } catch (Throwable) {
            return collect();
        }

        return collect($filas)
            ->map(fn (object $fila): object => $this->normalizarComprobante($fila, $periodo))
            ->filter(fn (object $fila): bool => $fila->cuenta_cobol !== '')
            ->groupBy('cuenta_cobol')
            ->map(fn (Collection $items): Collection => $items->values());
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
        $pdo = $this->pdo();
        if ($pdo === null) {
            return collect();
        }

        try {
            $stmt = $pdo->query(<<<'SQL'
select distinct replace(substr(fecha, 1, 7), '-', '') as periodo
from facturas
where fecha is not null
  and fecha glob '[12][0-9][0-9][0-9]-[01][0-9]*'
order by periodo desc
SQL);

            return collect($stmt->fetchAll())
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
        if (! $this->periodoValido($periodo)) {
            return false;
        }

        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }

        $mesSqlite = substr($periodo, 0, 4).'-'.substr($periodo, 4, 2);

        try {
            $stmt = $pdo->prepare(
                "select 1
                   from facturas
                  where substr(coalesce(fecha,''),1,7) = ?
                  limit 1"
            );
            $stmt->execute([$mesSqlite]);

            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public function rutaRelativa(string $periodo, string $nombre): ?string
    {
        if (! $this->periodoValido($periodo)) {
            return null;
        }

        if ($nombre === '' || $nombre !== basename($nombre)) {
            return null;
        }

        return substr($periodo, 0, 4)
            .'/'.substr($periodo, 4, 2)
            .'/'.$nombre;
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

    private function normalizarComprobante(object $fila, string $periodo): object
    {
        $fila->cuenta_cobol = $this->normalizarCuenta(
            (string) ($fila->cuenta_cobol ?? '')
        );

        $fila->punto_venta = str_pad(
            (string) ($fila->punto_venta ?? ''),
            4,
            '0',
            STR_PAD_LEFT
        );

        $fila->numero_comprobante = isset($fila->numero_comprobante)
            && $fila->numero_comprobante !== null
            && $fila->numero_comprobante !== ''
                ? str_pad((string) $fila->numero_comprobante, 8, '0', STR_PAD_LEFT)
                : null;

        $fila->tipo_codigo = strtoupper(
            trim((string) ($fila->tipo_archivo ?? $fila->tipo ?? ''))
        );

        $fila->ruta_relativa = ! empty($fila->nombre_archivo)
            ? $this->rutaRelativa($periodo, (string) $fila->nombre_archivo)
            : null;

        $fila->pdf_disponible = $fila->nombre_archivo
            ? $this->archivoDisponible($periodo, (string) $fila->nombre_archivo)
            : false;

        $fila->comprobante = $fila->nombre_archivo
            ? preg_replace(
                '/-\d{11}\.pdf$/i',
                '',
                (string) $fila->nombre_archivo
            )
            : null;

        return $fila;
    }

    private function pdo(): ?PDO
    {
        $dbPath = (string) config('kng.cache_db');

        if ($dbPath === '' || ! is_file($dbPath) || ! is_readable($dbPath)) {
            return null;
        }

        try {
            return new PDO('sqlite:'.$dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function periodoValido(string $periodo): bool
    {
        return preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) === 1;
    }
}
