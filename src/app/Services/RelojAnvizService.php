<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RelojAnvizService
{
    public function status(): array
    {
        return $this->get('status');
    }

    public function usuarios(string $buscar = ''): array
    {
        return $this->get('users', ['q' => $buscar]);
    }

    public function marcaciones(int $limite, string $usuario = '', string $desde = '', string $hasta = ''): array
    {
        return $this->get('records', [
            'limit' => $limite,
            'user' => $usuario,
            'from' => $desde,
            'to' => $hasta,
        ]);
    }

    public function guardarNombreLocal(int $codigoUsuario, string $nombre): array
    {
        return $this->post('set_local_name', [], [
            'user_code' => $codigoUsuario,
            'name' => $nombre,
        ]);
    }

    public function sincronizarUsuarios(): array
    {
        return $this->post('sync_users');
    }

    public function sincronizarNuevas(int $maximo = 1000): array
    {
        return $this->post('sync_new', ['max' => $maximo]);
    }

    private function get(string $accion, array $parametros = []): array
    {
        return $this->request('GET', $accion, $parametros);
    }

    private function post(string $accion, array $parametros = [], array $json = []): array
    {
        return $this->request('POST', $accion, $parametros, $json);
    }

    private function request(string $metodo, string $accion, array $parametros = [], array $json = []): array
    {
        $baseUrl = (string) config('reloj.base_url');

        if ($baseUrl === '') {
            throw new RuntimeException('No está configurada la URL del backend del reloj.');
        }

        $url = rtrim($baseUrl, '/').'/api.php';
        $query = array_merge(['action' => $accion], $parametros);

        try {
            $cliente = Http::acceptJson()
                ->connectTimeout((int) config('reloj.connect_timeout', 5))
                ->timeout((int) config('reloj.timeout', 20));

            $respuesta = $metodo === 'POST'
                ? $cliente->withQueryParameters($query)->post($url, $json)
                : $cliente->get($url, $query);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo conectar con el servicio del reloj biométrico.', previous: $e);
        }

        if (! $respuesta->successful()) {
            $detalle = $respuesta->json('error') ?: 'HTTP '.$respuesta->status();
            throw new RuntimeException('El servicio del reloj devolvió un error: '.$detalle);
        }

        $datos = $respuesta->json();

        if (! is_array($datos)) {
            throw new RuntimeException('El servicio del reloj devolvió una respuesta inválida.');
        }

        return $datos;
    }
}
