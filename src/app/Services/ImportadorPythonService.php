<?php

namespace App\Services;

use App\Exceptions\ImportadorPythonException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class ImportadorPythonService
{
    public function validarCobol(int $repositorioId): array
    {
        return $this->ejecutar(
            [
                'importar-cobol',
                '--repositorio-id',
                (string) $repositorioId,
                '--solo-validar',
            ]
        );
    }

    public function compararCobol(int $repositorioId): array
    {
        return $this->ejecutar(
            [
                'comparar-cobol',
                '--repositorio-id',
                (string) $repositorioId,
            ]
        );
    }

    public function validarLoteMigracion(): array
    {
        return $this->ejecutar(['validar']);
    }

    public function importarLoteMigracion(): array
    {
        return $this->ejecutar(['importar']);
    }

    public function reconciliarLoteMigracion(): array
    {
        return $this->ejecutar(['reconciliar']);
    }

    private function ejecutar(array $argumentos): array
    {
        $python = (string) config('gei.importador.python_bin');
        $path = (string) config('gei.importador.path');
        $baseDir = (string) config('gei.importador.base_dir', $path);
        $cobolStoragePath = (string) config('gei.importador.cobol_storage_path');
        $timeout = (int) config('gei.importador.timeout', 120);

        if (! is_file($python) || ! is_executable($python)) {
            throw new ImportadorPythonException(
                "No se encontró un Python ejecutable en la ruta configurada: {$python}"
            );
        }

        if (! is_dir($path)) {
            throw new ImportadorPythonException(
                "No se encontró el importador Python en la ruta configurada: {$path}"
            );
        }

        if (! is_dir($baseDir)) {
            throw new ImportadorPythonException(
                "No se encontró la base de entrada del importador: {$baseDir}"
            );
        }

        if (! is_dir($cobolStoragePath)) {
            throw new ImportadorPythonException(
                "No se encontró la carpeta COBOL configurada: {$cobolStoragePath}"
            );
        }

        $command = [
            $python,
            '-m',
            'gei_importador.cli',
            ...$argumentos,
        ];

        $process = new Process(
            $command,
            $path,
            [
                'GEI_LARAVEL_LIQUIDACIONES_DIR' => dirname($cobolStoragePath),
                'GEI_IMPORTADOR_BASE_DIR' => $baseDir,
                'PYTHONPATH' => $path.'/src',
                'PYTHONDONTWRITEBYTECODE' => '1',
                'PGHOST' => (string) config('database.connections.pgsql.host'),
                'PGPORT' => (string) config('database.connections.pgsql.port'),
                'PGDATABASE' => (string) config('database.connections.pgsql.database'),
                'PGUSER' => (string) config('database.connections.pgsql.username'),
                'PGPASSWORD' => (string) config('database.connections.pgsql.password'),
            ],
            null,
            $timeout
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            Log::error('Timeout ejecutando importador Python.', [
                'timeout' => $timeout,
                'command' => $command,
            ]);

            throw new ImportadorPythonException(
                "El importador Python superó el tiempo máximo de {$timeout} segundos.",
                null,
                $process->getOutput(),
                $process->getErrorOutput(),
            );
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        Log::info('Ejecución de importador Python finalizada.', [
            'exit_code' => $exitCode,
            'command' => $command,
            'stderr' => $stderr,
        ]);

        if (! $process->isSuccessful()) {
            throw new ImportadorPythonException(
                'El importador Python no pudo completar la operación.',
                $exitCode,
                $stdout,
                $stderr,
            );
        }

        try {
            $json = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new ImportadorPythonException(
                'El importador Python respondió, pero la salida no es JSON válido.',
                $exitCode,
                $stdout,
                $stderr,
            );
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'json' => $json,
        ];
    }
}
