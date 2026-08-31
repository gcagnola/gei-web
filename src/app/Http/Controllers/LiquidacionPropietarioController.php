<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarLiquidacionPropietarioJob;
use App\Models\LiquidacionPropietario;
use App\Models\LiquidacionPropietarioEnvio;
use App\Services\ComprobantesArcaService;
use App\Services\ImpuestosGarantizadosPdfService;
use App\Services\LiquidacionesPropietariosService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class LiquidacionPropietarioController extends Controller
{
    private const DOCUMENTOS = ['LIQUIDACION', 'IMPUESTOS', 'AMBOS', 'ARCA', 'TODOS'];

    public function __construct(
        private readonly ImpuestosGarantizadosPdfService $impuestosGarantizados,
        private readonly ComprobantesArcaService $comprobantesArca,
    ) {
    }

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
        $cantidadEnviablesPorDocumento = [
            'LIQUIDACION' => 0,
            'IMPUESTOS' => 0,
            'AMBOS' => 0,
            'ARCA' => 0,
            'TODOS' => 0,
        ];
        $registroEnviosDisponible = Schema::hasTable('liquidaciones_propietarios_envios');
        $registroDocumentosDisponible = $registroEnviosDisponible
            && Schema::hasColumn('liquidaciones_propietarios_envios', 'documentos');

        $arcaPeriodoDisponible = $this->comprobantesArca->periodoDisponible($periodo);
        $comprobantesArcaPorCuenta = $arcaPeriodoDisponible
            ? $this->comprobantesArca->porPeriodo($periodo)
            : collect();

        if (Schema::hasTable('liquidaciones_propietarios')) {
            $numerosIniciales = DB::table('liquidaciones_propietarios')
                ->selectRaw('periodo, MIN(numero_interno) AS numero_inicial')
                ->groupBy('periodo')
                ->pluck('numero_inicial', 'periodo')
                ->map(fn ($numero): int => (int) $numero)
                ->all();

            $consulta = $this->consultaLiquidaciones($periodo, $filtros);

            foreach (
                (clone $consulta)
                    ->select([
                        'liquidaciones_propietarios.id',
                        'liquidaciones_propietarios.periodo',
                        'liquidaciones_propietarios.cuenta',
                        'liquidaciones_propietarios.cuenta_impresa',
                        'liquidaciones_propietarios.estado',
                        'liquidaciones_propietarios.pdf_ruta',
                        'clientes.email as email_destino',
                    ])
                    ->orderBy('liquidaciones_propietarios.id')
                    ->cursor() as $registro
            ) {
                $email = trim((string) $registro->email_destino);
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $disponibilidad = $this->disponibilidadDocumentos(
                    $registro,
                    $comprobantesArcaPorCuenta
                );
                if ($disponibilidad['LIQUIDACION']) {
                    $cantidadEnviablesPorDocumento['LIQUIDACION']++;
                }
                if ($disponibilidad['IMPUESTOS']) {
                    $cantidadEnviablesPorDocumento['IMPUESTOS']++;
                }
                if ($disponibilidad['LIQUIDACION'] && $disponibilidad['IMPUESTOS']) {
                    $cantidadEnviablesPorDocumento['AMBOS']++;
                }
                if ($disponibilidad['ARCA']) {
                    $cantidadEnviablesPorDocumento['ARCA']++;
                }
                if (
                    $disponibilidad['LIQUIDACION']
                    && $disponibilidad['IMPUESTOS']
                    && $disponibilidad['ARCA']
                ) {
                    $cantidadEnviablesPorDocumento['TODOS']++;
                }
            }

            if ($registroEnviosDisponible) {
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

                if ($registroDocumentosDisponible) {
                    $consulta->addSelect([
                        'ultimo_envio_documentos' => DB::table('liquidaciones_propietarios_envios')
                            ->select('documentos')
                            ->whereColumn(
                                'liquidaciones_propietarios_envios.liquidacion_propietario_id',
                                'liquidaciones_propietarios.id'
                            )
                            ->latest('id')
                            ->limit(1),
                    ]);
                }
            }

            $liquidaciones = $consulta
                ->orderByRaw('LOWER(liquidaciones_propietarios.propietario)')
                ->orderBy('liquidaciones_propietarios.propietario')
                ->orderBy('liquidaciones_propietarios.numero_interno')
                ->paginate(30)
                ->withQueryString();

            foreach ($liquidaciones->items() as $liquidacion) {
                $liquidacion->pdf_disponible = $liquidacion->estado === 'PDF_GENERADO'
                    && ! empty($liquidacion->pdf_ruta)
                    && Storage::disk('liquidaciones')->exists((string) $liquidacion->pdf_ruta);

                $rutaImpuestos = $this->impuestosGarantizados->rutaPdfParaLiquidacion(
                    (string) $liquidacion->periodo,
                    $liquidacion->pdf_ruta ? (string) $liquidacion->pdf_ruta : null,
                    (string) ($liquidacion->cuenta_impresa ?: $liquidacion->cuenta),
                );
                $liquidacion->impuestos_pdf_ruta = $rutaImpuestos;
                $liquidacion->impuestos_pdf_disponible = $rutaImpuestos !== null
                    && Storage::disk('liquidaciones')->exists($rutaImpuestos);

                $cuentaArca = $this->comprobantesArca->normalizarCuenta(
                    (string) ($liquidacion->cuenta_impresa ?: $liquidacion->cuenta)
                );
                $liquidacion->comprobantes_arca = $comprobantesArcaPorCuenta->get(
                    $cuentaArca,
                    collect()
                );
                $liquidacion->comprobantes_arca_cantidad = $liquidacion->comprobantes_arca->count();
            }

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
            'cantidadEnviables' => $cantidadEnviablesPorDocumento['LIQUIDACION'],
            'cantidadEnviablesPorDocumento' => $cantidadEnviablesPorDocumento,
            'registroEnviosDisponible' => $registroEnviosDisponible,
            'registroDocumentosDisponible' => $registroDocumentosDisponible,
            'arcaPeriodoDisponible' => $arcaPeriodoDisponible,
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
            $analisisImpuestos = $this->impuestosGarantizados->analizar($datos['periodo']);
            if (! ($analisisImpuestos['validacion_ok'] ?? false)) {
                throw new \RuntimeException(sprintf(
                    'DAILOC tiene %d diferencia(s) de validación y %d error(es). No se inicia el procesamiento.',
                    (int) ($analisisImpuestos['validaciones_con_diferencia'] ?? 0),
                    (int) ($analisisImpuestos['errores'] ?? 0),
                ));
            }

            $resultado = $service->procesar(
                $datos['periodo'],
                isset($datos['numero_inicial']) ? (int) $datos['numero_inicial'] : null
            );

            $resultadoImpuestos = $this->impuestosGarantizados->generar($datos['periodo']);
        } catch (Throwable $error) {
            report($error);

            return redirect()
                ->route('propietarios.liquidaciones.index', ['periodo' => $datos['periodo']])
                ->withErrors(['liquidaciones' => $error->getMessage()]);
        }

        $pdfImpuestos = (int) (
            $resultadoImpuestos['pdf_generados']
            ?? $resultadoImpuestos['pdf_escritos']
            ?? $resultadoImpuestos['pdfs_generados']
            ?? 0
        );

        $mensaje = sprintf(
            'Período %s procesado: %d insertadas, %d actualizadas, %d omitidas, %d PDF de liquidación y %d PDF de impuestos garantizados generados.',
            $resultado['periodo'],
            $resultado['insertadas'],
            $resultado['actualizadas'],
            $resultado['omitidas'],
            $resultado['pdf_generados'],
            $pdfImpuestos,
        );

        $actividad = is_array($resultado['actividad_clientes'] ?? null)
            ? $resultado['actividad_clientes']
            : [];

        if (($actividad['aplicada'] ?? false) === true) {
            $mensaje .= sprintf(
                ' Actividad de clientes: %d activos y %d pasivos; %d activados y %d desactivados en este reproceso.',
                (int) ($actividad['clientes_activos'] ?? 0),
                (int) ($actividad['clientes_pasivos'] ?? 0),
                (int) ($actividad['clientes_activados_cambio'] ?? 0),
                (int) ($actividad['clientes_desactivados_cambio'] ?? 0),
            );
        } elseif (($actividad['motivo'] ?? '') !== '') {
            $mensaje .= ' Actividad de clientes no modificada: '.(string) $actividad['motivo'];
        }

        return redirect()
            ->route('propietarios.liquidaciones.index', ['periodo' => $datos['periodo']])
            ->with('estado', $mensaje);
    }

    public function enviarEmail(Request $request, LiquidacionPropietario $liquidacion): RedirectResponse
    {
        $documentos = $this->documentosSolicitados($request);

        if (! Schema::hasTable('liquidaciones_propietarios_envios')) {
            return back()->withErrors([
                'envios' => 'Falta ejecutar la migración del registro y la cola de emails.',
            ]);
        }

        if (! Schema::hasColumn('liquidaciones_propietarios_envios', 'documentos')) {
            return back()->withErrors([
                'envios' => 'Falta ejecutar la migración que habilita la selección de documentos para email.',
            ]);
        }

        $liquidacion->load('cliente');
        $email = trim((string) $liquidacion->cliente?->email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors([
                'envios' => 'El propietario no tiene un email válido asociado.',
            ]);
        }

        $disponibilidad = $this->disponibilidadDocumentos($liquidacion);
        if (! $this->seleccionDisponible($documentos, $disponibilidad)) {
            return back()->withErrors([
                'envios' => $this->mensajeDocumentoNoDisponible($documentos),
            ]);
        }

        if ($this->tieneEnvioPendiente($liquidacion->id)) {
            return back()->withErrors([
                'envios' => 'Esta liquidación ya tiene un envío pendiente o en proceso.',
            ]);
        }

        try {
            $envio = $this->crearEnvio($liquidacion, $email, 'INDIVIDUAL', $documentos);
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
            'El envío de '.$this->etiquetaDocumentos($documentos).' a '.$email.' fue agregado a la cola de emails.'
        );
    }

    public function enviarEmails(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'nombre' => ['nullable', 'string', 'max:160'],
            'cuenta' => ['nullable', 'string', 'max:30'],
            'comprobante' => ['nullable', 'string', 'max:20'],
            'documentos' => ['nullable', Rule::in(self::DOCUMENTOS)],
        ]);
        $documentos = (string) ($datos['documentos'] ?? 'AMBOS');
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

        if (! Schema::hasColumn('liquidaciones_propietarios_envios', 'documentos')) {
            return redirect()
                ->route('propietarios.liquidaciones.index', $parametrosRetorno)
                ->withErrors(['envios' => 'Falta ejecutar la migración que habilita la selección de documentos para email.']);
        }

        $programados = 0;
        $sinEmail = 0;
        $sinPdf = 0;
        $yaPendientes = 0;
        $errores = 0;

        $comprobantesArcaPorCuenta = $this->comprobantesArca->periodoDisponible($datos['periodo'])
            ? $this->comprobantesArca->porPeriodo($datos['periodo'])
            : collect();

        $consulta = $this->consultaLiquidaciones($datos['periodo'], $filtros)
            ->select([
                'liquidaciones_propietarios.id',
                'liquidaciones_propietarios.cliente_id',
                'liquidaciones_propietarios.periodo',
                'liquidaciones_propietarios.cuenta',
                'liquidaciones_propietarios.cuenta_impresa',
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

            $disponibilidad = $this->disponibilidadDocumentos(
                $registro,
                $comprobantesArcaPorCuenta
            );
            if (! $this->seleccionDisponible($documentos, $disponibilidad)) {
                $sinPdf++;
                continue;
            }

            if ($this->tieneEnvioPendiente((int) $registro->id)) {
                $yaPendientes++;
                continue;
            }

            try {
                $liquidacion = LiquidacionPropietario::query()->findOrFail($registro->id);
                $envio = $this->crearEnvio($liquidacion, $email, 'MASIVO', $documentos);
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
            'Envío masivo de %s programado: %d emails en cola, %d sin email válido, %d sin los PDF requeridos, %d ya pendientes y %d errores de programación.',
            $this->etiquetaDocumentos($documentos),
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

    public function verImpuestos(int $liquidacion): StreamedResponse
    {
        return $this->respuestaPdfImpuestos($liquidacion, 'inline');
    }

    public function descargarImpuestos(int $liquidacion): StreamedResponse
    {
        return $this->respuestaPdfImpuestos($liquidacion, 'attachment');
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

    private function respuestaPdfImpuestos(int $id, string $disposicion): StreamedResponse
    {
        $liquidacion = DB::table('liquidaciones_propietarios')->find($id);
        abort_if($liquidacion === null, 404);

        $ruta = $this->impuestosGarantizados->rutaPdfParaLiquidacion(
            (string) $liquidacion->periodo,
            $liquidacion->pdf_ruta ? (string) $liquidacion->pdf_ruta : null,
            (string) ($liquidacion->cuenta_impresa ?: $liquidacion->cuenta),
        );

        abort_if($ruta === null, 404);
        abort_unless(Storage::disk('liquidaciones')->exists($ruta), 404);

        return Storage::disk('liquidaciones')->response(
            $ruta,
            basename($ruta),
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
        string $tipo,
        string $documentos,
    ): LiquidacionPropietarioEnvio {
        return LiquidacionPropietarioEnvio::query()->create([
            'liquidacion_propietario_id' => $liquidacion->id,
            'cliente_id' => $liquidacion->cliente_id,
            'usuario_id' => auth()->id(),
            'email_destino' => mb_strtolower(trim($email)),
            'tipo_envio' => $tipo,
            'documentos' => $documentos,
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

    /** @return array{LIQUIDACION: bool, IMPUESTOS: bool, ARCA: bool} */
    private function disponibilidadDocumentos(
        object $liquidacion,
        ?Collection $comprobantesArcaPorCuenta = null,
    ): array {
        $liquidacionDisponible = ($liquidacion->estado ?? null) === 'PDF_GENERADO'
            && ! empty($liquidacion->pdf_ruta)
            && Storage::disk('liquidaciones')->exists((string) $liquidacion->pdf_ruta);

        $rutaImpuestos = $this->impuestosGarantizados->rutaPdfParaLiquidacion(
            (string) ($liquidacion->periodo ?? ''),
            ! empty($liquidacion->pdf_ruta) ? (string) $liquidacion->pdf_ruta : null,
            (string) (($liquidacion->cuenta_impresa ?? null) ?: ($liquidacion->cuenta ?? '')),
        );
        $impuestosDisponible = $rutaImpuestos !== null
            && Storage::disk('liquidaciones')->exists($rutaImpuestos);

        $cuentaArca = $this->comprobantesArca->normalizarCuenta(
            (string) (($liquidacion->cuenta_impresa ?? null) ?: ($liquidacion->cuenta ?? ''))
        );

        if ($comprobantesArcaPorCuenta !== null) {
            $arcaDisponible = $cuentaArca !== ''
                && $comprobantesArcaPorCuenta->get($cuentaArca, collect())->isNotEmpty();
        } else {
            $arcaDisponible = $cuentaArca !== ''
                && $this->comprobantesArca
                    ->paraCuentaPeriodo($cuentaArca, (string) ($liquidacion->periodo ?? ''))
                    ->isNotEmpty();
        }

        return [
            'LIQUIDACION' => $liquidacionDisponible,
            'IMPUESTOS' => $impuestosDisponible,
            'ARCA' => $arcaDisponible,
        ];
    }

    /** @param array{LIQUIDACION: bool, IMPUESTOS: bool, ARCA: bool} $disponibilidad */
    private function seleccionDisponible(string $documentos, array $disponibilidad): bool
    {
        return match ($documentos) {
            'LIQUIDACION' => $disponibilidad['LIQUIDACION'],
            'IMPUESTOS' => $disponibilidad['IMPUESTOS'],
            'AMBOS' => $disponibilidad['LIQUIDACION'] && $disponibilidad['IMPUESTOS'],
            'ARCA' => $disponibilidad['ARCA'],
            'TODOS' => $disponibilidad['LIQUIDACION']
                && $disponibilidad['IMPUESTOS']
                && $disponibilidad['ARCA'],
            default => false,
        };
    }

    private function documentosSolicitados(Request $request): string
    {
        $datos = $request->validate([
            'documentos' => ['nullable', Rule::in(self::DOCUMENTOS)],
        ]);

        return (string) ($datos['documentos'] ?? 'LIQUIDACION');
    }

    private function etiquetaDocumentos(string $documentos): string
    {
        return match ($documentos) {
            'IMPUESTOS' => 'impuestos garantizados',
            'AMBOS' => 'liquidación e impuestos garantizados',
            'ARCA' => 'comprobantes ARCA',
            'TODOS' => 'liquidación, impuestos garantizados y comprobantes ARCA',
            default => 'liquidación de propietario',
        };
    }

    private function mensajeDocumentoNoDisponible(string $documentos): string
    {
        return match ($documentos) {
            'IMPUESTOS' => 'No está disponible el PDF de impuestos garantizados para esta liquidación.',
            'AMBOS' => 'No están disponibles ambos PDF para esta liquidación.',
            'ARCA' => 'No hay comprobantes ARCA disponibles para esta cuenta y período.',
            'TODOS' => 'No están disponibles la liquidación, los impuestos garantizados y los comprobantes ARCA para esta cuenta y período.',
            default => 'La liquidación no tiene un PDF disponible para enviar.',
        };
    }

    private function escaparLike(string $valor): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valor);
    }
}
