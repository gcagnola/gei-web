<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarLiquidacionPropietarioJob;
use App\Models\LiquidacionPropietario;
use App\Models\LiquidacionPropietarioEnvio;
use App\Services\LiquidacionesPropietariosService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class LiquidacionPropietarioController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = $this->validarFiltros($request);
        $nombreBuscado = $filtros['nombre'];
        $cuentaBuscada = $filtros['cuenta'];
        $comprobanteBuscado = $filtros['comprobante'];

        $periodosDisponibles = collect(Storage::directories('liquidaciones/periodos'))
            ->map(fn (string $directorio): string => basename($directorio))
            ->filter(fn (string $periodo): bool => preg_match('/^(19|20)\d{4}$/', $periodo) === 1)
            ->sortDesc()
            ->values();
        $periodo = (string) $request->query('periodo', $periodosDisponibles->first() ?? '');
        $liquidaciones = null;
        $ultimoProceso = null;
        $numerosIniciales = [];
        $cantidadEnviables = 0;

        if (Schema::hasTable('liquidaciones_propietarios')) {
            $numerosIniciales = DB::table('liquidaciones_propietarios')
                ->selectRaw('periodo, MIN(numero_interno) AS numero_inicial')
                ->groupBy('periodo')
                ->pluck('numero_inicial', 'periodo')
                ->map(fn ($numero): int => (int) $numero)
                ->all();

            $consulta = $this->consultaLiquidaciones($periodo, $filtros);

            $cantidadEnviables = (clone $consulta)
                ->where('liquidaciones_propietarios.estado', 'PDF_GENERADO')
                ->whereNotNull('liquidaciones_propietarios.pdf_ruta')
                ->whereNotNull('clientes.email')
                ->whereRaw("BTRIM(clientes.email) <> ''")
                ->pluck('email_destino')
                ->filter(fn ($email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->count();

            if (Schema::hasTable('liquidaciones_propietarios_envios')) {
                $consulta->addSelect([
                    'ultimo_envio_estado' => DB::table('liquidaciones_propietarios_envios')
                        ->select('estado')
                        ->whereColumn(
                            'liquidaciones_propietarios_envios.liquidacion_propietario_id',
                            'liquidaciones_propietarios.id'
                        )
                        ->latest('id')
                        ->limit(1),
                    'ultimo_envio_at' => DB::table('liquidaciones_propietarios_envios')
                        ->selectRaw('COALESCE(enviado_at, intentado_at, created_at)')
                        ->whereColumn(
                            'liquidaciones_propietarios_envios.liquidacion_propietario_id',
                            'liquidaciones_propietarios.id'
                        )
                        ->latest('id')
                        ->limit(1),
                ]);
            }

            $liquidaciones = $consulta
                ->orderByRaw('LOWER(liquidaciones_propietarios.propietario)')
                ->orderBy('liquidaciones_propietarios.propietario')
                ->orderBy('liquidaciones_propietarios.numero_interno')
                ->paginate(30)
                ->withQueryString();

            $ultimoProceso = DB::table('liquidaciones_propietarios_procesos')
                ->when($periodo !== '', fn ($query) => $query->where('periodo', $periodo))
                ->latest('id')
                ->first();
        }

        return view('propietarios.liquidaciones.index', [
            'periodosDisponibles' => $periodosDisponibles,
            'periodo' => $periodo,
            'liquidaciones' => $liquidaciones,
            'ultimoProceso' => $ultimoProceso,
            'numeroSugerido' => $numerosIniciales[$periodo]
                ?? (int) config('gei.liquidaciones_propietarios.numero_inicial', 25194),
            'numerosIniciales' => $numerosIniciales,
            'numeroInicialPredeterminado' => (int) config('gei.liquidaciones_propietarios.numero_inicial', 25194),
            'filtros' => $filtros,
            'hayFiltros' => $nombreBuscado !== '' || $cuentaBuscada !== '' || $comprobanteBuscado !== '',
            'cantidadEnviables' => $cantidadEnviables,
            'registroEnviosDisponible' => Schema::hasTable('liquidaciones_propietarios_envios'),
        ]);
    }

    public function procesar(
        Request $request,
        LiquidacionesPropietariosService $service
    ): RedirectResponse {
        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'numero_inicial' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $resultado = $service->procesar(
                $datos['periodo'],
                isset($datos['numero_inicial']) ? (int) $datos['numero_inicial'] : null
            );
        } catch (Throwable $error) {
            report($error);

            return redirect()
                ->route('propietarios.liquidaciones.index', ['periodo' => $datos['periodo']])
                ->withErrors(['liquidaciones' => $error->getMessage()]);
        }

        $mensaje = sprintf(
            'Período %s procesado: %d insertadas, %d actualizadas, %d omitidas y %d PDF generados.',
            $resultado['periodo'],
            $resultado['insertadas'],
            $resultado['actualizadas'],
            $resultado['omitidas'],
            $resultado['pdf_generados']
        );

        return redirect()
            ->route('propietarios.liquidaciones.index', ['periodo' => $datos['periodo']])
            ->with('estado', $mensaje);
    }

    public function enviarEmail(LiquidacionPropietario $liquidacion): RedirectResponse
    {
        if (! Schema::hasTable('liquidaciones_propietarios_envios')) {
            return back()->withErrors([
                'envios' => 'Falta ejecutar la migración del registro y la cola de emails.',
            ]);
        }

        $liquidacion->load('cliente');
        $email = trim((string) $liquidacion->cliente?->email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors([
                'envios' => 'El propietario no tiene un email válido asociado.',
            ]);
        }

        if (
            $liquidacion->estado !== 'PDF_GENERADO'
            || ! $liquidacion->pdf_ruta
            || ! Storage::disk('liquidaciones')->exists($liquidacion->pdf_ruta)
        ) {
            return back()->withErrors([
                'envios' => 'La liquidación no tiene un PDF disponible para enviar.',
            ]);
        }

        if ($this->tieneEnvioPendiente($liquidacion->id)) {
            return back()->withErrors([
                'envios' => 'Esta liquidación ya tiene un envío pendiente o en proceso.',
            ]);
        }

        try {
            $envio = $this->crearEnvio($liquidacion, $email, 'INDIVIDUAL');
            EnviarLiquidacionPropietarioJob::dispatch($envio->id);
        } catch (Throwable $error) {
            report($error);

            if (isset($envio)) {
                $envio->forceFill([
                    'estado' => 'ERROR',
                    'mensaje_error' => mb_substr($error->getMessage(), 0, 2000),
                ])->save();
            }

            return back()->withErrors([
                'envios' => 'No se pudo programar el email: '.$error->getMessage(),
            ]);
        }

        return back()->with(
            'estado',
            'El envío a '.$email.' fue agregado a la cola de emails.'
        );
    }

    public function enviarEmails(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'nombre' => ['nullable', 'string', 'max:160'],
            'cuenta' => ['nullable', 'string', 'max:30'],
            'comprobante' => ['nullable', 'string', 'max:20'],
        ]);
        $filtros = [
            'nombre' => trim((string) ($datos['nombre'] ?? '')),
            'cuenta' => trim((string) ($datos['cuenta'] ?? '')),
            'comprobante' => trim((string) ($datos['comprobante'] ?? '')),
        ];
        $parametrosRetorno = array_filter([
            'periodo' => $datos['periodo'],
            ...$filtros,
        ], fn ($valor): bool => $valor !== '');

        if (! Schema::hasTable('liquidaciones_propietarios_envios')) {
            return redirect()
                ->route('propietarios.liquidaciones.index', $parametrosRetorno)
                ->withErrors(['envios' => 'Falta ejecutar la migración del registro y la cola de emails.']);
        }

        $programados = 0;
        $sinEmail = 0;
        $sinPdf = 0;
        $yaPendientes = 0;
        $errores = 0;

        $consulta = $this->consultaLiquidaciones($datos['periodo'], $filtros)
            ->select([
                'liquidaciones_propietarios.id',
                'liquidaciones_propietarios.cliente_id',
                'liquidaciones_propietarios.estado',
                'liquidaciones_propietarios.pdf_ruta',
                'clientes.email as email_destino',
            ])
            ->orderBy('liquidaciones_propietarios.id');

        foreach ($consulta->cursor() as $registro) {
            $envio = null;
            $email = trim((string) $registro->email_destino);

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $sinEmail++;
                continue;
            }

            if (
                $registro->estado !== 'PDF_GENERADO'
                || ! $registro->pdf_ruta
                || ! Storage::disk('liquidaciones')->exists($registro->pdf_ruta)
            ) {
                $sinPdf++;
                continue;
            }

            if ($this->tieneEnvioPendiente((int) $registro->id)) {
                $yaPendientes++;
                continue;
            }

            try {
                $liquidacion = LiquidacionPropietario::query()->findOrFail($registro->id);
                $envio = $this->crearEnvio($liquidacion, $email, 'MASIVO');
                EnviarLiquidacionPropietarioJob::dispatch($envio->id);
                $programados++;
            } catch (Throwable $error) {
                report($error);
                $errores++;

                if ($envio !== null) {
                    $envio->forceFill([
                        'estado' => 'ERROR',
                        'mensaje_error' => mb_substr($error->getMessage(), 0, 2000),
                    ])->save();
                }
            }
        }

        $mensaje = sprintf(
            'Envío masivo programado: %d emails en cola, %d sin email válido, %d sin PDF, %d ya pendientes y %d errores de programación.',
            $programados,
            $sinEmail,
            $sinPdf,
            $yaPendientes,
            $errores
        );

        return redirect()
            ->route('propietarios.liquidaciones.index', $parametrosRetorno)
            ->with($errores > 0 ? 'advertencia' : 'estado', $mensaje);
    }

    public function ver(int $liquidacion): StreamedResponse
    {
        return $this->respuestaPdf($liquidacion, 'inline');
    }

    public function descargar(int $liquidacion): StreamedResponse
    {
        return $this->respuestaPdf($liquidacion, 'attachment');
    }

    private function respuestaPdf(int $id, string $disposicion): StreamedResponse
    {
        $liquidacion = DB::table('liquidaciones_propietarios')->find($id);
        abort_if($liquidacion === null || ! $liquidacion->pdf_ruta, 404);
        abort_unless(Storage::disk('liquidaciones')->exists($liquidacion->pdf_ruta), 404);
        $nombre = basename($liquidacion->pdf_ruta);

        return Storage::disk('liquidaciones')->response(
            $liquidacion->pdf_ruta,
            $nombre,
            ['Content-Type' => 'application/pdf'],
            $disposicion
        );
    }

    /** @return array{nombre: string, cuenta: string, comprobante: string} */
    private function validarFiltros(Request $request): array
    {
        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:160'],
            'cuenta' => ['nullable', 'string', 'max:30'],
            'comprobante' => ['nullable', 'string', 'max:20'],
        ]);

        return [
            'nombre' => trim((string) ($datos['nombre'] ?? '')),
            'cuenta' => trim((string) ($datos['cuenta'] ?? '')),
            'comprobante' => trim((string) ($datos['comprobante'] ?? '')),
        ];
    }

    /** @param array{nombre: string, cuenta: string, comprobante: string} $filtros */
    private function consultaLiquidaciones(string $periodo, array $filtros): Builder
    {
        $cuentaDigitos = preg_replace('/\D+/', '', $filtros['cuenta']) ?? '';

        return DB::table('liquidaciones_propietarios')
            ->leftJoin('clientes', 'clientes.id', '=', 'liquidaciones_propietarios.cliente_id')
            ->select([
                'liquidaciones_propietarios.*',
                'clientes.email as email_destino',
            ])
            ->when(
                $periodo !== '',
                fn (Builder $query) => $query->where('liquidaciones_propietarios.periodo', $periodo)
            )
            ->when(
                $filtros['nombre'] !== '',
                fn (Builder $query) => $query->whereRaw(
                    'liquidaciones_propietarios.propietario ILIKE ?',
                    ['%'.$this->escaparLike($filtros['nombre']).'%']
                )
            )
            ->when(
                $cuentaDigitos !== '',
                fn (Builder $query) => $query->where(function (Builder $cuentas) use ($cuentaDigitos): void {
                    $patron = '%'.$this->escaparLike($cuentaDigitos).'%';
                    $cuentas
                        ->whereRaw(
                            "regexp_replace(COALESCE(liquidaciones_propietarios.cuenta, ''), '[^0-9]', '', 'g') LIKE ?",
                            [$patron]
                        )
                        ->orWhereRaw(
                            "regexp_replace(COALESCE(liquidaciones_propietarios.cuenta_impresa, ''), '[^0-9]', '', 'g') LIKE ?",
                            [$patron]
                        );
                })
            )
            ->when(
                $filtros['comprobante'] !== '',
                fn (Builder $query) => $query->whereRaw(
                    'liquidaciones_propietarios.comprobante ILIKE ?',
                    ['%'.$this->escaparLike($filtros['comprobante']).'%']
                )
            );
    }

    private function crearEnvio(
        LiquidacionPropietario $liquidacion,
        string $email,
        string $tipo
    ): LiquidacionPropietarioEnvio {
        return LiquidacionPropietarioEnvio::query()->create([
            'liquidacion_propietario_id' => $liquidacion->id,
            'cliente_id' => $liquidacion->cliente_id,
            'usuario_id' => auth()->id(),
            'email_destino' => mb_strtolower(trim($email)),
            'tipo_envio' => $tipo,
            'estado' => 'PENDIENTE',
            'intentos' => 0,
        ]);
    }

    private function tieneEnvioPendiente(int $liquidacionId): bool
    {
        return LiquidacionPropietarioEnvio::query()
            ->where('liquidacion_propietario_id', $liquidacionId)
            ->whereIn('estado', ['PENDIENTE', 'PROCESANDO'])
            ->exists();
    }

    private function escaparLike(string $valor): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valor);
    }
}
