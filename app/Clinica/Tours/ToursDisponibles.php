<?php

namespace App\Clinica\Tours;

use App\Models\User;

/**
 * **Los tours guiados de la web clínica** (A-15): una lista de pasos por pantalla, cada uno con el
 * `data-tour` del elemento real que señala, un título y dos líneas de texto.
 *
 * Es la **fuente única**. Agregar un paso es una línea acá más el `data-tour` en la vista: el parcial
 * `clinica/_tour` pinta el HTML de cada paso y `public/js/clinica/tour.js` sólo recorre los selectores
 * (no conoce ningún texto ni ningún elemento).
 *
 * `PANTALLAS` dice qué rutas son cada pantalla; `PASOS` los pasos de cada una. Un paso cuyo elemento no
 * está en el HTML —una fila que no existe, el navegador 1…N de un paciente con una sola consulta, la
 * barra lateral fuera de pantalla en el celular— se salta solo en el navegador, así que la lista puede
 * ser más larga que lo que se ve en un caso concreto.
 *
 * **Quién lo ve.** La consulta y los catálogos son del médico (la secretaría recibe 403 en sus rutas) y
 * el inicio del médico tampoco es el de la secretaría: a ella `/clinica` la manda a la agenda (A-12). Por
 * eso `VISIBLE_PARA` filtra la pantalla entera antes de pintar nada; si un tour queda sin pasos, no se
 * muestra.
 */
final class ToursDisponibles
{
    public const INICIO = 'inicio';
    public const AGENDA = 'agenda';
    public const CONSULTA = 'consulta';
    public const CATALOGOS = 'catalogos';
    public const CONSULTORIOS = 'consultorios';

    /**
     * Las rutas de cada pantalla. La consulta y los catálogos se listan pantalla por pantalla a propósito:
     * un catálogo nuevo agrega su ruta y hereda el tour, pero un editor o una acción no lo hereda por error.
     */
    private const PANTALLAS = [
        self::INICIO        => ['clinica.inicio'],
        self::AGENDA        => ['clinica.agenda'],
        self::CONSULTA      => ['clinica.consulta'],
        self::CATALOGOS     => [
            'clinica.catalogos',
            'clinica.catalogos.medicamentos',
            'clinica.catalogos.tratamientos',
            'clinica.catalogos.diagnosticos',
            'clinica.catalogos.motivos-consulta',
            'clinica.catalogos.motivos-cita',
            'clinica.catalogos.examenes',
            'clinica.catalogos.tipos-examen',
            'clinica.catalogos.medicos-referidos',
            'clinica.catalogos.plantillas-texto',
        ],
        // Los horarios de A-10 se cargan desde «Sedes y consultorios» (`clinica.sedes` desde el 2026-10-20; antes
        // `clinica.consultorios`, que ahora redirige), pero la pantalla vieja de horarios del consultorio sigue
        // existiendo: las dos son la misma pantalla para el tour (se marca una sola vez) y cada una muestra los
        // pasos que tiene. La clave sigue siendo `consultorios` para no volver a mostrarle el tour a quien ya lo vio.
        self::CONSULTORIOS  => ['clinica.sedes', 'clinica.consultorios.horarios'],
    ];

    /**
     * Con qué alcanza para ver cada pantalla:
     *
     *  - `medico`: el rol Médico (el inicio del médico; Root sin rol Médico va a la agenda).
     *  - `consulta`: Root o Médico, que es quien puede abrir la consulta y los catálogos.
     *  - `null`: cualquiera que trabaje en el consultorio (agenda y consultorios los ve también la secretaría).
     */
    private const VISIBLE_PARA = [
        self::INICIO       => 'medico',
        self::CONSULTA     => 'consulta',
        self::CATALOGOS    => 'consulta',
        self::AGENDA       => null,
        self::CONSULTORIOS => null,
    ];

    /**
     * Los pasos, en el orden en que se muestran. `selector` es el `data-tour` de la vista; va sin comillas
     * porque el valor es un identificador CSS (`[data-tour=agenda-barra]`), así el HTML no lleva entidades.
     *
     * Los textos van en tuteo (guía de UI §Lenguaje) y en dos líneas: qué es el elemento y para qué sirve.
     */
    private const PASOS = [
        self::INICIO => [
            [
                'selector' => '[data-tour=inicio-nueva-cita]',
                'titulo'   => 'Nueva cita',
                'texto'    => 'Con este botón agendas una cita: abre el formulario con el día y la sede de hoy ya elegidos.',
            ],
            [
                'selector' => '[data-tour=inicio-cumpleanos]',
                'titulo'   => 'Cumpleaños de hoy',
                'texto'    => 'Cuando un paciente cumple años, este aviso aparece arriba. Entra para ver los saludos y enviarlos.',
            ],
            [
                'selector' => '[data-tour=inicio-lista] tbody tr',
                'titulo'   => 'Los pacientes de hoy',
                'texto'    => 'Esta es tu agenda del día: turno, hora, paciente y estado de cada cita. Desde cada fila confirmas, atiendes o cobras.',
            ],
        ],

        self::AGENDA => [
            [
                'selector' => '[data-tour=agenda-barra]',
                'titulo'   => 'La fecha y la vista',
                'texto'    => 'Las flechas mueven el día y «Hoy» vuelve al presente. A la derecha eliges Día, Semana o Mes y filtras por sede y razón.',
            ],
            [
                'selector' => '[data-tour=agenda-nueva]',
                'titulo'   => 'Nueva cita',
                'texto'    => 'Con este botón agendas una cita: eliges el paciente, el día y la hora. También puedes hacerlo desde «Hacer cita» en el buscador.',
            ],
        ],

        self::CONSULTA => [
            [
                'selector' => '[data-tour=menu-consulta]',
                'titulo'   => 'Las secciones de la consulta',
                'texto'    => 'El grupo «Consulta» del menú lateral reúne los ítems de la atención: motivos, enfermedad, exámenes, diagnósticos, récipes y documentos.',
            ],
            [
                'selector' => '[data-tour=consulta-encabezado]',
                'titulo'   => 'La fila de la consulta',
                'texto'    => 'Aquí están el paciente, su historia y la fecha. La flecha vuelve a la agenda y «Terminar la consulta» cierra la atención.',
            ],
            [
                'selector' => '[data-tour=consulta-navegador]',
                'titulo'   => 'Las consultas anteriores',
                'texto'    => 'Los números cambian entre las consultas de esta historia: sirven para revisar lo anterior o copiarlo a la consulta de hoy.',
            ],
            [
                'selector' => '[data-tour=consulta-plantillas]',
                'titulo'   => 'Plantillas de texto',
                'texto'    => 'Arriba de cada campo de texto está «Plantillas»: abre tus textos guardados y, al pie, el enlace para administrarlas.',
            ],
            [
                'selector' => '[data-tour=consulta-guardar]',
                'titulo'   => 'Guardar',
                'texto'    => 'Guarda lo escrito en la sección abierta. Si cambias de sección o sales, también se guarda solo.',
            ],
        ],

        self::CATALOGOS => [
            [
                'selector' => '[data-tour=menu-catalogos]',
                'titulo'   => 'Los catálogos del consultorio',
                'texto'    => 'Este grupo del menú lateral reúne todos los catálogos. Se cambia de uno a otro sin volver atrás.',
            ],
            [
                'selector' => '[data-tour=menu-plantillas-de-texto]',
                'titulo'   => 'Plantillas de texto',
                'texto'    => 'Aquí se administran los textos que ofreces en la consulta: los creas, los corriges y ves en qué pantalla se usan.',
            ],
            [
                'selector' => '[data-tour=catalogos-nuevo]',
                'titulo'   => 'Agregar al catálogo',
                'texto'    => 'Con este botón creas un elemento nuevo. El buscador de arriba filtra la lista por nombre o código.',
            ],
            [
                'selector' => '[data-tour=catalogos-lista] tbody tr',
                'titulo'   => 'El catálogo',
                'texto'    => 'Cada fila se corrige con «Editar» y se quita desde su menú; si el elemento está en uso, el sistema lo avisa y no lo borra.',
            ],
        ],

        self::CONSULTORIOS => [
            [
                'selector' => '[data-tour=sedes-nueva]',
                'titulo'   => 'Una sede nueva',
                'texto'    => 'La sede es el lugar donde atiendes. Al registrarla te ofrece cargar ahí mismo tu consultorio.',
            ],
            [
                'selector' => '[data-tour=consultorios-lista] .sede-consultorio',
                'titulo'   => 'Tus consultorios',
                'texto'    => 'Cada sede muestra los consultorios donde atiendes, con la modalidad (orden de llegada u hora de cita) y la duración de la cita.',
            ],
            [
                'selector' => '[data-tour=consultorios-bloques]',
                'titulo'   => 'Los horarios',
                'texto'    => 'Los bloques dicen qué días y a qué horas atiendes, y el cupo máximo de pacientes. Sin bloques, la agenda no pone tope.',
            ],
            [
                'selector' => '[data-tour=consultorios-alta]',
                'titulo'   => 'Agregar un bloque',
                'texto'    => 'Eliges los días, desde y hasta qué hora y el cupo (vacío = sin tope) en un panel, sin salir de esta pantalla.',
            ],
            [
                'selector' => '[data-tour=horarios-lista] tbody tr',
                'titulo'   => 'Todos los bloques',
                'texto'    => 'La pantalla del consultorio lista todos sus bloques y deja corregirlos o quitarlos uno por uno.',
            ],
            [
                'selector' => '[data-tour=horarios-alta]',
                'titulo'   => 'Un bloque nuevo',
                'texto'    => 'Carga el día, la hora de inicio y la de fin. El cupo vacío significa sin tope.',
            ],
        ],
    ];

    /** La pantalla a la que pertenece una ruta (por su nombre), o `null` si esa ruta no tiene tour. */
    public static function pantallaDe(?string $ruta): ?string
    {
        if ($ruta === null) {
            return null;
        }

        foreach (self::PANTALLAS as $pantalla => $rutas) {
            if (in_array($ruta, $rutas, true)) {
                return $pantalla;
            }
        }

        return null;
    }

    /** ¿La clave es una pantalla conocida? (El endpoint la valida antes de escribir.) */
    public static function existe(string $pantalla): bool
    {
        return isset(self::PASOS[$pantalla]);
    }

    /** ¿Este usuario puede ver el tour de esta pantalla? */
    public static function visiblePara(string $pantalla, ?User $usuario): bool
    {
        if ($usuario === null || ! self::existe($pantalla)) {
            return false;
        }

        return match (self::VISIBLE_PARA[$pantalla] ?? null) {
            'medico'   => $usuario->hasRole('Medico'),
            'consulta' => $usuario->trabajaLaConsulta(),
            default    => true,
        };
    }

    /**
     * Los pasos de una pantalla, ya filtrados por lo que el usuario puede ver. Un tour que queda vacío no
     * se muestra.
     *
     * @return array<int,array{selector:string, titulo:string, texto:string}>
     */
    public static function pasos(string $pantalla, ?User $usuario = null): array
    {
        if (! self::visiblePara($pantalla, $usuario)) {
            return [];
        }

        return self::PASOS[$pantalla];
    }
}
