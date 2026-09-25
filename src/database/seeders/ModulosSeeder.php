<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\Perfil;
use Illuminate\Database\Seeder;

class ModulosSeeder extends Seeder
{
    public function run(): void
    {
        $definiciones = [
            ['codigo' => 'ARCHIVO_IMPORTAR', 'seccion' => 'Archivo', 'nombre' => 'Importar', 'orden' => 10],
            ['codigo' => 'ARCHIVO_INMUEBLES', 'seccion' => 'Archivo', 'nombre' => 'Inmuebles', 'orden' => 20],
            ['codigo' => 'ARCHIVO_CLIENTES', 'seccion' => 'Archivo', 'nombre' => 'Clientes', 'orden' => 30],
            ['codigo' => 'ARCHIVO_IMPRESIONES_COBOL', 'seccion' => 'Archivo', 'nombre' => 'Impresiones COBOL', 'orden' => 40],
            ['codigo' => 'PROPIETARIOS_LIQUIDACIONES', 'seccion' => 'Propietarios', 'nombre' => 'Liquidaciones', 'orden' => 50],
            ['codigo' => 'OPCIONES_USUARIOS', 'seccion' => 'Opciones', 'nombre' => 'Usuarios', 'orden' => 60],
            ['codigo' => 'OPCIONES_PERFILES', 'seccion' => 'Opciones', 'nombre' => 'Perfiles', 'orden' => 70],
            ['codigo' => 'OPCIONES_PERMISOS', 'seccion' => 'Opciones', 'nombre' => 'Permisos', 'orden' => 80],
            ['codigo' => 'PARAMETROS_SEDES', 'seccion' => 'Opciones', 'nombre' => 'Sedes', 'orden' => 90],
            ['codigo' => 'OPCIONES_SETEOS', 'seccion' => 'Opciones', 'nombre' => 'Seteos', 'orden' => 100],
        ];

        foreach ($definiciones as $definicion) {
            Modulo::query()->updateOrCreate(
                ['codigo' => $definicion['codigo']],
                $definicion + ['activo' => true]
            );
        }

        $todos = Modulo::query()
            ->where('activo', true)
            ->pluck('id')
            ->all();

        $administrador = Perfil::query()->where('codigo', 'ADMINISTRADOR')->first();
        if ($administrador) {
            $administrador->modulos()->sync($todos);
        }

        // Valores iniciales solamente para una instalación/perfil OPERADOR sin
        // permisos configurados. Si ya se administró la matriz, el seeder no
        // pisa la selección realizada desde la interfaz.
        $operador = Perfil::query()->where('codigo', 'OPERADOR')->first();
        if ($operador && ! $operador->modulos()->exists()) {
            $permitidos = Modulo::query()
                ->whereIn('codigo', [
                    'ARCHIVO_INMUEBLES',
                    'ARCHIVO_CLIENTES',
                    'ARCHIVO_IMPRESIONES_COBOL',
                    'PROPIETARIOS_LIQUIDACIONES',
                ])
                ->pluck('id')
                ->all();

            $operador->modulos()->sync($permitidos);
        }
    }
}
