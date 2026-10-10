<?php

namespace App\Clinica\Vistas;

use App\Support\IconoModulo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * La pantalla de entrada del consultorio (`/clinica`): quién trabaja y los módulos de su especialidad como
 * accesos. Los módulos salen de `especialidad_modulo` (los implementados y visibles para los roles del
 * usuario): agregar uno no obliga a tocar esta pantalla.
 */
final class InicioVista
{
    /**
     * @param  array  $contexto  el `contexto_clinico` de la petición
     * @param  Collection<int,object>  $modulos  los módulos visibles (`slug`, `nombre`)
     */
    public function __construct(
        private array $contexto,
        private Collection $modulos,
    ) {
    }

    public function subtitulo(): string
    {
        $medico = $this->contexto['medico'];
        $nombre = trim(($medico->prefix ?? '') . ' ' . ($medico->name ?? '') . ' ' . ($medico->lastname ?? ''));

        return $nombre . ($this->contexto['specialty'] ? ' · ' . $this->contexto['specialty']->name : '');
    }

    public function sinEspecialidad(): bool
    {
        return $this->contexto['specialty'] === null;
    }

    public function sinModulos(): bool
    {
        return $this->modulos->isEmpty();
    }

    /** @return array<int,array{nombre:string, icono:string, url:?string}> */
    public function modulos(): array
    {
        return $this->modulos->map(function ($modulo) {
            $ruta = 'clinica.' . $modulo->slug;

            return [
                'nombre' => $modulo->nombre,
                'icono'  => IconoModulo::para($modulo->slug),
                'url'    => Route::has($ruta) ? route($ruta) : null,
            ];
        })->all();
    }
}
