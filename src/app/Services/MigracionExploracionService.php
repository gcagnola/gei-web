<?php

namespace App\Services;

use App\Exceptions\MigracionExploracionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class MigracionExploracionService
{
    private const ARCHIVOS = [
        'cobol/PROPIETAR.TXT',
        'cobol/INQUILINO.TXT',
        'cobol/CTACTEPRO.TXT',
        'cobol/INQCTACTE.TXT',
        'liquidaciones/pliqloc.sf.txt',
        'liquidaciones/pliqloc.st.txt',
    ];

    private const ARCHIVO_ESTADO = 'migracion_gei_exploracion.json';

    public function estado(string $periodo): array
    {
        if (! $this->periodoValido($periodo)) {
            return $this->estadoNoDisponible('Período inválido.');
        }

        $faltantes = $this->archivosFaltantes($periodo);

        if ($faltantes !== []) {
            return [
                ...$this->estadoNoDisponible('Faltan archivos para migrar.'),
                'faltantes' => $faltantes,
            ];
        }

        $hash = $this->hashLote($periodo);
        $guardado = $this->leerEstadoGuardado($periodo);

        if ($guardado === null) {
            return [
                'disponible' => true,
                'estado' => 'PENDIENTE',
                'etiqueta' => 'Pendiente',
                'mensaje' => 'El período todavía no fue migrado.',
                'hash' => $hash,
                'resultado' => null,
            ];
        }

        if (($guardado['hash'] ?? null) !== $hash) {
            return [
                'disponible' => true,
                'estado' => 'MODIFICADO',
                'etiqueta' => 'Archivos modificados',
                'mensaje' => 'Los archivos cambiaron desde la última migración.',
                'hash' => $hash,
                'resultado' => $guardado['resultado'] ?? null,
            ];
        }

        if (($guardado['estado'] ?? null) === 'OK') {
            return [
                'disponible' => true,
                'estado' => 'OK',
                'etiqueta' => 'Migrado',
                'mensaje' => $guardado['mensaje'] ?? 'Migración completada.',
                'hash' => $hash,
                'resultado' => $guardado['resultado'] ?? null,
            ];
        }

        return [
            'disponible' => true,
            'estado' => 'ERROR',
            'etiqueta' => 'Error',
            'mensaje' => $guardado['mensaje'] ?? 'La última migración terminó con error.',
            'hash' => $hash,
            'resultado' => $guardado['resultado'] ?? null,
        ];
    }

    public function migrar(string $periodo): array
    {
        if (! $this->periodoValido($periodo)) {
            throw new MigracionExploracionException('El período indicado no es válido.');
        }

        $faltantes = $this->archivosFaltantes($periodo);

        if ($faltantes !== []) {
            throw new MigracionExploracionException(
                'No se puede migrar: faltan '.implode(', ', $faltantes).'.'
            );
        }

        $timeout = (int) config('gei.exploracion.timeout', 900);
        $lockStore = (string) config('gei.exploracion.lock_store', 'file');
        $lock = Cache::store($lockStore)->lock(
            "gei:migracion-exploracion:{$periodo}",
            $timeout + 60
        );

        if (! $lock->get()) {
            throw new MigracionExploracionException(
                "El período {$periodo} ya se está migrando."
            );
        }

        try {
            return $this->ejecutar($periodo, $timeout);
        } finally {
            $lock->release();
        }
    }

    private function ejecutar(string $periodo, int $timeout): array
    {
        $script = (string) config('gei.exploracion.script');
        $directorio = $this->directorioPeriodo($periodo);
        $hash = $this->hashLote($periodo);

        if (! is_file($script) || ! is_readable($script)) {
            throw new MigracionExploracionException(
                "No se encontró el script de migración: {$script}"
            );
        }

        $command = ['/bin/bash', $script, $directorio];
        $process = new Process(
            $command,
            base_path(),
            [
                'PGHOST' => (string) config('gei.exploracion.host'),
                'PGPORT' => (string) config('gei.exploracion.port'),
                'PGUSER' => (string) config('gei.exploracion.username'),
                'PGPASSWORD' => (string) config('gei.exploracion.password'),
                'GEI_DB' => (string) config('gei.exploracion.database'),
                'GEI_SCHEMA' => (string) config('gei.exploracion.schema'),
            ],
            null,
            $timeout
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $mensaje = "La migración superó el tiempo máximo de {$timeout} segundos.";
            $this->guardarEstado($periodo, $hash, 'ERROR', $mensaje);

            throw new MigracionExploracionException(
                $mensaje,
                $process->getExitCode(),
                $process->getOutput(),
                $process->getErrorOutput()
            );
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        Log::info('Migración a gei_exploracion finalizada.', [
            'periodo' => $periodo,
            'exit_code' => $exitCode,
            'stderr' => $stderr,
        ]);

        if (! $process->isSuccessful()) {
            $mensaje = $this->mensajeError($stderr);
            $this->guardarEstado($periodo, $hash, 'ERROR', $mensaje);

            throw new MigracionExploracionException(
                $mensaje,
                $exitCode,
                $stdout,
                $stderr
            );
        }

        $resultado = $this->extraerResultado($stdout);
        $mensaje = sprintf(
            'Migración completada: %d registros cargados y %d omitidos.',
            (int) ($resultado['registros_cargados'] ?? 0),
            (int) ($resultado['registros_omitidos'] ?? 0)
        );

        $this->guardarEstado($periodo, $hash, 'OK', $mensaje, $resultado);

        return $resultado;
    }

    private function extraerResultado(string $stdout): array
    {
        if (! preg_match('/^RESULTADO_JSON=(\{.*\})$/m', $stdout, $coincidencia)) {
            throw new MigracionExploracionException(
                'El script terminó, pero no devolvió RESULTADO_JSON.',
                0,
                $stdout
            );
        }

        try {
            return json_decode($coincidencia[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MigracionExploracionException(
                'El script devolvió un resultado JSON inválido.',
                0,
                $stdout
            );
        }
    }

    private function archivosFaltantes(string $periodo): array
    {
        $base = "liquidaciones/periodos/{$periodo}";

        return collect(self::ARCHIVOS)
            ->reject(fn (string $archivo) => Storage::exists("{$base}/{$archivo}"))
            ->map(fn (string $archivo) => basename($archivo))
            ->values()
            ->all();
    }

    private function hashLote(string $periodo): string
    {
        $contexto = hash_init('sha256');
        $base = "liquidaciones/periodos/{$periodo}";

        foreach (self::ARCHIVOS as $archivo) {
            hash_update($contexto, $archivo."\0");
            hash_update_file($contexto, Storage::path("{$base}/{$archivo}"));
        }

        return hash_final($contexto);
    }

    private function leerEstadoGuardado(string $periodo): ?array
    {
        $ruta = $this->rutaEstado($periodo);

        if (! Storage::exists($ruta)) {
            return null;
        }

        try {
            $estado = json_decode(
                Storage::get($ruta),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            return null;
        }

        return is_array($estado) ? $estado : null;
    }

    private function guardarEstado(
        string $periodo,
        string $hash,
        string $estado,
        string $mensaje,
        ?array $resultado = null
    ): void {
        Storage::put(
            $this->rutaEstado($periodo),
            json_encode([
                'periodo' => $periodo,
                'hash' => $hash,
                'estado' => $estado,
                'mensaje' => $mensaje,
                'resultado' => $resultado,
                'fecha' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
        );
    }

    private function directorioPeriodo(string $periodo): string
    {
        return Storage::path("liquidaciones/periodos/{$periodo}");
    }

    private function rutaEstado(string $periodo): string
    {
        return "liquidaciones/periodos/{$periodo}/".self::ARCHIVO_ESTADO;
    }

    private function periodoValido(string $periodo): bool
    {
        return preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) === 1;
    }

    private function mensajeError(string $stderr): string
    {
        $lineas = array_values(array_filter(array_map('trim', preg_split('/\R/', $stderr))));

        return $lineas === []
            ? 'El script no pudo completar la migración.'
            : end($lineas);
    }

    private function estadoNoDisponible(string $mensaje): array
    {
        return [
            'disponible' => false,
            'estado' => 'NO_DISPONIBLE',
            'etiqueta' => 'No disponible',
            'mensaje' => $mensaje,
            'hash' => null,
            'resultado' => null,
        ];
    }
}
