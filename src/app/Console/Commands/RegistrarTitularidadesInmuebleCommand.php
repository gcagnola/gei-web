<?php

namespace App\Console\Commands;

use App\Services\TitularidadesInmuebleService;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * Sólo cambios confirmados por el operador. Nunca se invoca desde COBOL/KNG.
 */
final class RegistrarTitularidadesInmuebleCommand extends Command
{
    protected $signature = 'gei:registrar-titularidades-inmueble
        {--inmueble= : ID físico canónico en public.inmuebles}
        {--periodo= : Fecha efectiva AAAAMM}
        {--titulares= : JSON de la nómina completa, con cliente_cuenta_id y porcentaje}
        {--referencia= : Referencia comprobable de la titularidad}
        {--confirmar : Aplica los cambios; sin esta opción sólo simula}';

    protected $description = 'C4: simular o registrar cambios confirmados de titularidad sin alterar la identidad del inmueble.';

    public function handle(TitularidadesInmuebleService $service): int
    {
        try {
            $json = json_decode((string) $this->option('titulares'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($json) || ! array_is_list($json)) {
                throw new JsonException('La nómina debe ser un arreglo JSON.');
            }
            $resultado = $service->registrarCambioConfirmado(
                (int) $this->option('inmueble'),
                (string) $this->option('periodo'),
                $json,
                (string) $this->option('referencia'),
                (bool) $this->option('confirmar')
            );
            $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            if (! $this->option('confirmar')) {
                $this->warn('SIMULACIÓN: no se grabaron cambios.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
