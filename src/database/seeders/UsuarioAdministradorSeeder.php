<?php

namespace Database\Seeders;

use App\Models\Perfil;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UsuarioAdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $nombreUsuario = config('gei.admin.nombre_usuario');
        $nombre = config('gei.admin.nombre');
        $email = config('gei.admin.email');
        $password = config('gei.admin.password');

        if (
            empty($nombreUsuario) ||
            empty($nombre) ||
            empty($email) ||
            empty($password)
        ) {
            throw new RuntimeException(
                'Faltan variables GEI_ADMIN_* para crear el administrador.'
            );
        }

        $perfil = Perfil::query()->updateOrCreate(
            ['codigo' => 'ADMINISTRADOR'],
            [
                'nombre' => 'Administrador',
                'descripcion' => 'Acceso administrativo completo al sistema.',
                'activo' => true,
            ]
        );

        Usuario::query()->updateOrCreate(
            ['nombre_usuario' => $nombreUsuario],
            [
                'perfil_id' => $perfil->id,
                'nombre' => $nombre,
                'email' => $email,
                'password' => Hash::make($password),
                'activo' => true,
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => null,
            ]
        );
    }
}