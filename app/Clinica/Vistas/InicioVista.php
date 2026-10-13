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
     * @param  int  $cumpleanosDeHoy  cuántos pacientes del médico cumplen años hoy (WEB-2.9)
     */
    public function __construct(
        private array $contexto,
        private Collection $modulos,
        private int $cumpleanosDeHoy = 0,
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

    /**
     * El aviso de cumpleaños del día (WEB-2.9). Va en Inicio y no en el menú porque la pantalla no es un
     * módulo —es una herramienta que además es **provisional hasta WEB-8.2**—, pero el aviso tiene que
     * estar donde el médico entra: es lo único que lo lleva a mandar el saludo.
     */
    public function hayCumpleanosHoy(): bool
    {
        return $this->cumpleanosDeHoy > 0;
    }

    public function textoCumpleanos(): string
    {
        if ($this->cumpleanosDeHoy === 1) {
            return 'Hoy cumple años 1 paciente.';
        }

        return 'Hoy cumplen años ' . $this->cumpleanosDeHoy . ' pacientes.';
    }

    public function urlCumpleanos(): string
    {
        return route('clinica.cumpleanos');
    }
}
