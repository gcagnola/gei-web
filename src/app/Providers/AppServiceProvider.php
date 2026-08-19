<?php

namespace App\Providers;

use App\Models\Usuario;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        Gate::define('administrar-unificaciones', static function (Usuario $usuario): bool {
            return $usuario->activo
                && $usuario->perfil?->activo
                && $usuario->perfil?->codigo === 'ADMINISTRADOR';
        });
    }
}
