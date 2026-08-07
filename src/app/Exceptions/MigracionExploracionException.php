<?php

namespace App\Exceptions;

use RuntimeException;

class MigracionExploracionException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $exitCode = null,
        private readonly string $stdout = '',
        private readonly string $stderr = '',
    ) {
        parent::__construct($message);
    }

    public function context(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
        ];
    }
}
