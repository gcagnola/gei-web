@props(['cuit' => null, 'tipoDocumento' => null, 'documento' => null])
@php($controlCuit = \App\Services\ValidacionCuitService::evaluar($cuit, $tipoDocumento, $documento))
<span class="badge text-bg-{{ $controlCuit['clase'] }} ms-1" title="{{ $controlCuit['detalle'] }}" aria-label="{{ $controlCuit['texto'] }}: {{ $controlCuit['detalle'] }}">{{ $controlCuit['texto'] }}</span>
