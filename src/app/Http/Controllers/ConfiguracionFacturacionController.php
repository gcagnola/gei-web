<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ConfiguracionFacturacionController extends Controller
{
    public function index(): View
    {
        $configuracion = DB::table('configuraciones_facturacion')
            ->orderBy('id_configuracion_facturacion')
            ->first();

        $puntosVenta = DB::table('puntos_venta')
            ->orderBy('numero')
            ->get();

        $numeraciones = DB::table('numeraciones_comprobantes as n')
            ->join(
                'puntos_venta as pv',
                'pv.id_punto_venta',
                '=',
                'n.id_punto_venta'
            )
            ->orderBy('pv.numero')
            ->orderBy('n.tipo_comprobante')
            ->get([
                'n.*',
                'pv.numero as punto_venta_numero',
                'pv.nombre as punto_venta_nombre',
            ]);

        $alicuotas = DB::table('alicuotas_iva')
            ->orderBy('codigo')
            ->get();

        return view('seteos.facturacion', compact(
            'configuracion',
            'puntosVenta',
            'numeraciones',
            'alicuotas'
        ));
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'proximo_numero_lote' => ['required', 'integer', 'min:1'],
            'tope_no_gravado' => ['required', 'numeric', 'min:0'],
            'alicuota_iva_general' => ['required', 'numeric', 'min:0', 'max:100'],
            'decimales_redondeo' => ['required', 'integer', 'min:0', 'max:6'],
            'modo_emision' => ['required', 'in:RECE,WSFE'],
            'ambiente_arca' => ['required', 'in:HOMOLOGACION,PRODUCCION'],
        ]);

        $data['emitir_nc_locadores'] =
            $request->boolean('emitir_nc_locadores');

        $data['updated_at'] = now();

        $id = DB::table('configuraciones_facturacion')
            ->orderBy('id_configuracion_facturacion')
            ->value('id_configuracion_facturacion');

        if ($id) {
            DB::table('configuraciones_facturacion')
                ->where('id_configuracion_facturacion', $id)
                ->update($data);
        } else {
            $data['created_at'] = now();

            DB::table('configuraciones_facturacion')
                ->insert($data);
        }

        return back()->with(
            'ok',
            'Configuración general actualizada.'
        );
    }

    public function updatePuntoVenta(
        Request $request,
        int $puntoVenta
    ): RedirectResponse {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'localidad' => ['nullable', 'string', 'max:100'],
            'modalidad' => ['nullable', 'string', 'max:50'],
        ]);

        $data['activo'] = $request->boolean('activo');
        $data['updated_at'] = now();

        DB::table('puntos_venta')
            ->where('id_punto_venta', $puntoVenta)
            ->update($data);

        return back()->with(
            'ok',
            'Punto de venta actualizado.'
        );
    }

    public function updateAlicuota(
        Request $request,
        int $alicuota
    ): RedirectResponse {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'porcentaje' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
            ],
        ]);

        $data['activo'] = $request->boolean('activo');
        $data['updated_at'] = now();

        DB::table('alicuotas_iva')
            ->where('id_alicuota_iva', $alicuota)
            ->update($data);

        return back()->with(
            'ok',
            'Alícuota actualizada.'
        );
    }
}
