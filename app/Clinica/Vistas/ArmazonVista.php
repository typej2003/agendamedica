<?php

namespace App\Clinica\Vistas;

use App\Clinica\Catalogos\CatalogosDisponibles;
use App\Support\IconoModulo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * El armazón de la web clínica (`clinica/layout`): las fichas de contexto de la barra superior, los accesos
 * y el menú lateral. El menú sale de los módulos implementados de la especialidad: nada escrito a mano.
 *
 * Funciona **sin contexto** (las pantallas de sedes y de elegir consultorio no lo tienen): en ese caso la
 * barra no muestra al médico ni el menú de módulos.
 */
final class ArmazonVista
{
    /**
     * @param  array|null  $contexto  el `contexto_clinico` de la petición, si ya se eligió
     * @param  Collection<int,object>  $modulos  los módulos visibles de la especialidad (`slug`, `nombre`)
     */
    public function __construct(
        private ?array $contexto,
        private Collection $modulos,
        private bool $esAdministrador,
        private ?string $rutaActual,
        private bool $trabajaLaConsulta = false,
    ) {
    }

    public function tieneContexto(): bool
    {
        return $this->contexto !== null;
    }

    /**
     * Las fichas de contexto de la barra: médico, especialidad, sede y registro.
     *
     * @return array<int,array{icono:string, texto:string}>
     */
    public function fichas(): array
    {
        if ($this->contexto === null) {
            return [];
        }

        $medico = $this->contexto['medico'];
        $sede = $this->contexto['office'];

        return [
            ['icono' => 'bi-person-badge', 'texto' => trim(($medico->prefix ?? '') . ' ' . $medico->name . ' ' . $medico->lastname)],
            ['icono' => 'bi-clipboard2-pulse', 'texto' => $this->contexto['specialty']->name ?? 'Sin especialidad asignada'],
            ['icono' => 'bi-geo-alt', 'texto' => $sede->office_number ?? ($sede->medicalCenter->name ?? 'Sin sede')],
            ['icono' => 'bi-hash', 'texto' => (string) $this->contexto['reg_medico']],
        ];
    }

    /**
     * Los accesos de la barra superior. Las sedes son el **lugar de la clínica** y el paso previo a crear un
     * consultorio (WEB-2.8b.1); los consultorios son **del médico del contexto** (WEB-2.8b.2), así que solo
     * aparecen cuando ya se eligió con quién trabajar. El panel es de administración: a un médico no se le
     * ofrece una puerta que no le corresponde.
     *
     * @return array<int,array{texto:string, icono:string, url:string, primario:bool}>
     */
    public function accesos(): array
    {
        $accesos = [['texto' => 'Sedes', 'icono' => 'bi-hospital', 'url' => route('clinica.sedes'), 'primario' => false]];

        if ($this->tieneContexto()) {
            $accesos[] = ['texto' => 'Consultorios', 'icono' => 'bi-door-closed', 'url' => route('clinica.consultorios'), 'primario' => false];
        }

        $accesos[] = ['texto' => 'Cambiar consultorio', 'icono' => 'bi-arrow-repeat', 'url' => route('clinica.contexto'), 'primario' => false];

        if ($this->esAdministrador) {
            $accesos[] = ['texto' => 'Panel', 'icono' => 'bi-speedometer2', 'url' => route('dashboard'), 'primario' => true];
        }

        return $accesos;
    }

    /**
     * El menú lateral: Inicio y un ítem por módulo. Un módulo declarado sin pantalla se muestra apagado.
     *
     * Un ítem con `hijos` es un **grupo plegable** (hoy sólo "Catálogos"): no navega por sí mismo, se abre solo cuando
     * uno de sus hijos es el activo y marca `activo` al padre.
     *
     * @return array<int,array{texto:string, icono:string, url:?string, activo:bool, hijos?:array<int,array{texto:string, icono:string, url:string, activo:bool}>}>
     */
    public function menu(): array
    {
        $menu = [[
            'texto'  => 'Inicio',
            'icono'  => 'bi-house-door',
            'url'    => route('clinica.inicio'),
            'activo' => $this->rutaActual === 'clinica.inicio',
        ]];

        foreach ($this->modulos as $modulo) {
            $ruta = 'clinica.' . $modulo->slug;
            $existe = Route::has($ruta);

            $menu[] = [
                'texto'  => $modulo->nombre,
                'icono'  => IconoModulo::para($modulo->slug),
                'url'    => $existe ? route($ruta) : null,
                'activo' => $existe && $this->rutaActual !== null && ($this->rutaActual === $ruta || str_starts_with($this->rutaActual, $ruta . '.')),
            ];
        }

        // Los catálogos clínicos (medicamentos, tratamientos…, WEB-8.1) son **del médico** —los de su contexto— y la
        // secretaría no los administra: la entrada sólo se ofrece a quien puede abrirlos (el resto recibiría un 403).
        // Va en la barra lateral y no en los accesos de la barra superior: ésa ya ocupa todo el ancho a 768 px con las
        // fichas del contexto, y un acceso más la desborda (medido al verificar WEB-8.1a; trampa 58 de `TRAMPAS.md`).
        // "Catálogos" es un grupo con un submenú por catálogo (`CatalogosDisponibles` es la fuente única de cuáles hay).
        $catalogos = $this->tieneContexto() && $this->trabajaLaConsulta ? CatalogosDisponibles::submenu($this->rutaActual) : [];

        if ($catalogos !== []) {
            $menu[] = [
                'texto'  => 'Catálogos',
                'icono'  => 'bi-book',
                'url'    => null,
                'activo' => $this->rutaActual !== null && str_starts_with($this->rutaActual, 'clinica.catalogos'),
                'hijos'  => $catalogos,
            ];
        }

        // «Configuración» (WEB-8.2) es un grupo para lo que el médico ajusta de su propio consultorio; hoy tiene el formato de impresión
        // (membrete, logo, firma y sello) y WEB-8.4 y 8.5 suman sus pantallas acá. Como los catálogos, es del médico y no de la secretaría.
        if ($this->tieneContexto() && $this->trabajaLaConsulta) {
            $esFormato = $this->rutaActual !== null && str_starts_with($this->rutaActual, 'clinica.formato');

            $menu[] = [
                'texto'  => 'Configuración',
                'icono'  => 'bi-gear',
                'url'    => null,
                'activo' => $esFormato,
                'hijos'  => [[
                    'texto'  => 'Formato de impresión',
                    'icono'  => 'bi-printer',
                    'url'    => route('clinica.formato'),
                    'activo' => $esFormato,
                ]],
            ];
        }

        return $menu;
    }
}
