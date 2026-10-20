<?php

namespace App\Clinica\Vistas;

use App\Clinica\Catalogos\CatalogosDisponibles;
use App\Clinica\Consulta\SeccionesDeLaConsulta;
use App\Clinica\Listados\ListadosDisponibles;
use App\Especialidades\ConfiguracionDeLaEspecialidad;
use App\Support\IconoModulo;
use App\Support\LogotipoEspecialidad;
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
        // El `?listado=` de la petición (WEB-6.8): «Listados» es un grupo y sus hijos se marcan por ruta **y** por la
        // clave del reporte, porque las tres pantallas comparten la misma ruta. Fuera de los listados no se usa.
        private ?string $listadoActual = null,
        // La consulta que se está atendiendo (revisión de Alexander): `['id' => int, 'seccion' => ?string]`. Sólo se
        // usa en la ruta de la consulta, donde el shell le pasa el ítem abierto al grupo «Consulta» del menú.
        private ?array $consultaEnCurso = null,
        // ¿La pantalla de elegir consultorio tiene algo que ofrecer? (2026-10-19). Sin nada que elegir esa
        // pantalla se salta, así que tampoco se ofrece «Cambiar consultorio». Lo resuelve el composer con
        // `ContextoAutomatico`; sin contexto (sedes, la propia pantalla) queda en `true`.
        private bool $hayAlgoQueElegir = true,
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
     * El **logo de la especialidad** del contexto, el mismo del escritorio de esa especialidad. Va en la barra
     * lateral, encima del pie con el botón de plegar.
     *
     * Sale del `slug` de la especialidad del contexto (`LogotipoEspecialidad`), así que no hay ninguna lista
     * escrita a mano: una especialidad sin logo —o la pantalla sin contexto, como elegir consultorio— no
     * dibuja nada.
     *
     * @return array{url:string, texto:string}|null
     */
    public function logotipo(): ?array
    {
        $especialidad = $this->contexto['specialty'] ?? null;
        $url = LogotipoEspecialidad::url($especialidad->slug ?? null);

        return $url === null ? null : ['url' => $url, 'texto' => $especialidad->name ?? ''];
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

        // «Cambiar consultorio» sólo si hay algo que elegir: con un solo médico, una sola especialidad y uno
        // o ningún consultorio la pantalla se salta (2026-10-19), así que el acceso no llevaría a ninguna parte.
        if ($this->hayAlgoQueElegir) {
            $accesos[] = ['texto' => 'Cambiar consultorio', 'icono' => 'bi-arrow-repeat', 'url' => route('clinica.contexto'), 'primario' => false];
        }

        if ($this->esAdministrador) {
            $accesos[] = ['texto' => 'Panel', 'icono' => 'bi-speedometer2', 'url' => route('dashboard'), 'primario' => true];
        }

        return $accesos;
    }

    /**
     * El menú lateral: Inicio y un ítem por módulo. Un módulo declarado sin pantalla se muestra apagado.
     *
     * Un ítem con `hijos` es un **grupo plegable** (hoy "Catálogos", "Listados", "Configuración" y —dentro de una
     * consulta— "Consulta"): no navega por sí mismo, se abre solo cuando uno de sus hijos es el activo y marca
     * `activo` al padre.
     *
     * El manifiesto de la especialidad puede declarar en `menu.ocultos` los módulos que **no** se ofrecen acá
     * (O-32: Crecimiento y Vacunas en pediatría), con su motivo: son entradas de un paciente y su acción
     * redirige a Pacientes, así que desde el menú parecían botones muertos. No se toca `modulosVisibles`: el
     * módulo sigue existiendo para la historia, la consulta, los permisos y su ruta. Una especialidad que no
     * declara nada —ginecología— no cambia ni un ítem.
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

        // **Los ítems de la consulta** (revisión de Alexander): la tarjeta «Secciones de la consulta» se fue de la
        // pantalla y sus 14 ítems viven en este grupo plegable, con los mismos nombres y su icono, **sólo dentro de
        // una consulta** y sólo para quien la trabaja (la consulta es del médico: `role:Root|Medico`). Afuera de la
        // consulta el menú queda como siempre. El catálogo y los enlaces salen de `SeccionesDeLaConsulta`, la misma
        // fuente que usa el shell: agregar una sección es una línea, y un ítem sin pantalla se dibujaría deshabilitado
        // en vez de ofrecer un enlace muerto.
        if ($this->tieneContexto() && $this->trabajaLaConsulta && $this->enLaConsulta()) {
            $consultaId = (int) ($this->consultaEnCurso['id'] ?? 0);
            $seccion = $this->consultaEnCurso['seccion'] ?? null;

            if ($consultaId > 0) {
                $menu[] = [
                    'texto'  => 'Consulta',
                    'icono'  => 'bi-journal-medical',
                    'url'    => null,
                    'activo' => true,
                    'hijos'  => SeccionesDeLaConsulta::submenu(
                        $consultaId,
                        is_string($seccion) ? $seccion : null,
                        $this->contexto['specialty']->slug ?? null,
                        $this->contexto['secciones_postgrados'] ?? [],
                    ),
                ];
            }
        }

        $fueraDelMenu = (array) ConfiguracionDeLaEspecialidad::valor(
            $this->contexto['specialty'] ?? null,
            'menu.ocultos',
            [],
        );

        foreach ($this->modulos as $modulo) {
            if (array_key_exists($modulo->slug, $fueraDelMenu)) {
                continue;
            }

            $ruta = 'clinica.' . $modulo->slug;
            $existe = Route::has($ruta);

            $menu[] = [
                'texto'  => $modulo->nombre,
                'icono'  => IconoModulo::para($modulo->slug),
                'url'    => $existe ? route($ruta) : null,
                'activo' => $existe && $this->rutaActual !== null && ($this->rutaActual === $ruta || str_starts_with($this->rutaActual, $ruta . '.')),
            ];
        }

        // Los **listados clínicos entre fechas** (WEB-6.8: embarazos / partos, pacientes y consultas por rango) son
        // reportes del médico: la secretaría recibe 403 en la ruta, así que la entrada sólo se ofrece a quien puede
        // abrirlos —la misma regla que «Catálogos» y «Configuración»—.
        // Desde la revisión de Alexander es un **grupo plegable** con un hijo por reporte, como «Catálogos»: los tres
        // comparten la misma ruta y se eligen con `?listado=`, así que el activo lo decide `ListadosDisponibles`.
        // Y desde el 2026-10-18 el grupo **depende de la especialidad**: el listado de embarazos y partos es de obstetricia y no
        // se le ofrece a pediatría (`ListadosDisponibles` es el catálogo que lo declara, igual que `CatalogosDisponibles`).
        $listados = $this->tieneContexto() && $this->trabajaLaConsulta
            ? ListadosDisponibles::submenu($this->rutaActual, $this->listadoActual, $this->contexto['specialty']->slug ?? null)
            : [];

        if ($listados !== []) {
            $menu[] = [
                'texto'  => 'Listados',
                'icono'  => 'bi-clipboard2-data',
                'url'    => null,
                'activo' => in_array(true, array_column($listados, 'activo'), true),
                'hijos'  => $listados,
            ];
        }

        // Los catálogos clínicos (medicamentos, tratamientos…, WEB-8.1) son **del médico** —los de su contexto— y la
        // secretaría no los administra: la entrada sólo se ofrece a quien puede abrirlos (el resto recibiría un 403).
        // Va en la barra lateral y no en los accesos de la barra superior: ésa ya ocupa todo el ancho a 768 px con las
        // fichas del contexto, y un acceso más la desborda (medido al verificar WEB-8.1a; trampa 58 de `TRAMPAS.md`).
        // "Catálogos" es un grupo con un submenú por catálogo (`CatalogosDisponibles` es la fuente única de cuáles hay).
        $catalogos = $this->tieneContexto() && $this->trabajaLaConsulta ? CatalogosDisponibles::submenu($this->rutaActual, $this->contexto['specialty']->slug ?? null) : [];

        if ($catalogos !== []) {
            $menu[] = [
                'texto'  => 'Catálogos',
                'icono'  => 'bi-book',
                'url'    => null,
                'activo' => $this->rutaActual !== null && str_starts_with($this->rutaActual, 'clinica.catalogos'),
                'hijos'  => $catalogos,
            ];
        }

        // «Configuración» (WEB-8.2) es un grupo para lo que se ajusta del propio consultorio: el formato de impresión (membrete,
        // logo, firma y sello), los mensajes a pacientes (WEB-8.4: el recordatorio de cita y el saludo de cumpleaños), los usuarios
        // (WEB-8.5: su secretaria) y la bitácora de cambios (WEB-8.5).
        //
        // **No es un grupo sólo del médico** (revisión de Alexander, A-10): la duración de la cita y los bloques de horario viven
        // en `clinica.consultorios` y los días no laborables en `clinica.agenda.no-laborables`, y las dos rutas aceptan a la
        // secretaría (es ella la que carga los horarios y marca las ausencias en la vida real). Por eso el grupo se arma con lo
        // que cada rol **puede abrir** —las dos primeras entradas, las del consultorio y la agenda, para los dos; el resto, que
        // responde 403 a la secretaría, sólo para el médico—, en vez de esconderlo entero. La barra superior conserva
        // «Consultorios» como atajo.
        if ($this->tieneContexto()) {
            $hijos = [];

            foreach ([
                ['clinica.consultorios', 'Consultorios y horarios', 'bi-clock-history'],
                ['clinica.agenda.no-laborables', 'Días no laborables', 'bi-calendar-x'],
            ] as [$ruta, $texto, $icono]) {
                $hijos[] = [
                    'texto'  => $texto,
                    'icono'  => $icono,
                    'url'    => route($ruta),
                    'activo' => $this->rutaActual !== null && str_starts_with($this->rutaActual, $ruta),
                ];
            }

            if ($this->trabajaLaConsulta) {
                foreach ([
                    ['clinica.formato', 'Formato de impresión', 'bi-printer'],
                    ['clinica.mensajes', 'Mensajes a pacientes', 'bi-chat-text'],
                    ['clinica.usuarios', 'Usuarios', 'bi-people'],
                    ['clinica.bitacora', 'Bitácora', 'bi-clock-history'],
                ] as [$ruta, $texto, $icono]) {
                    $hijos[] = [
                        'texto'  => $texto,
                        'icono'  => $icono,
                        'url'    => route($ruta),
                        'activo' => $this->rutaActual !== null && str_starts_with($this->rutaActual, $ruta),
                    ];
                }
            }

            $menu[] = [
                'texto'  => 'Configuración',
                'icono'  => 'bi-gear',
                'url'    => null,
                'activo' => in_array(true, array_column($hijos, 'activo'), true),
                'hijos'  => $hijos,
            ];

            // "Ayúdanos a mejorar" (ROADMAP §9): el comentario lo puede mandar cualquiera que trabaje en el
            // consultorio —el médico y su secretaría—, así que va al final del menú, después de "Configuración".
            $menu[] = [
                'texto'  => 'Ayúdanos a mejorar',
                'icono'  => 'bi-chat-heart',
                'url'    => Route::has('clinica.ayuda') ? route('clinica.ayuda') : null,
                'activo' => $this->rutaActual === 'clinica.ayuda',
            ];

        }

        return $menu;
    }

    /**
     * **La barra de contexto no se pinta dentro de la consulta** (A-11, decisión del usuario). La consulta es una
     * pantalla de trabajo: el médico ya sabe con quién, en qué sede y con qué registro está, y la fila de arriba sólo
     * le robaba alto. Lo que **no** se pinta es la barra superior completa (las fichas del médico, «Sedes»,
     * «Consultorios», «Cambiar consultorio» y el menú de la cuenta): dentro de la consulta no se cambia de consultorio
     * ni se cierra sesión, y no queda ningún enlace ni atajo a esa pantalla. Al salir a la agenda la barra vuelve. El
     * botón del menú lateral en el celular sigue existiendo, porque sin él no habría cómo navegar en una pantalla
     * táctil (lo pinta `clinica/layout`).
     */
    public function muestraLaBarraDeContexto(): bool
    {
        return ! $this->enLaConsulta();
    }

    /**
     * ¿La petición es la pantalla de una consulta (o una de sus secciones)? Las secciones **no son rutas**: comparten
     * la del shell y viajan en `?seccion=`, así que la ruta `clinica.consulta` alcanza; el `clinica.consulta.` cubre las
     * acciones que vuelven a ella. El prefijo se compara con el punto a propósito: `clinica.consultorios` empieza con
     * las mismas letras y **no** es una consulta.
     */
    private function enLaConsulta(): bool
    {
        return $this->rutaActual !== null
            && ($this->rutaActual === 'clinica.consulta' || str_starts_with($this->rutaActual, 'clinica.consulta.'));
    }
}
