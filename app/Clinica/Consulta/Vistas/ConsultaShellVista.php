<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\SeccionesDeLaConsulta;
use App\Clinica\Crecimiento\EdadEnTexto;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;

/**
 * **El shell de la consulta** (WEB-4.1): el encabezado con el paciente, su historia, la consulta y la
 * fecha, y el contenido de la sección elegida.
 *
 * Los ítems del legado —con su grupo, su icono, su detalle y su estado— viven en
 * `App\Clinica\Consulta\SeccionesDeLaConsulta`, que es la **fuente única** del catálogo: la usan el shell
 * (para saber qué sección abrir y cuál es su parcial) y el grupo «Consulta» del menú lateral (revisión de
 * Alexander, 2026-10-17), que reemplazó al panel de la pantalla. Acá **no hay contenido**: cada sección llega
 * con su parcial y su ViewModel.
 *
 * Dos cosas que el ViewModel decide y no la vista:
 *
 *  - **La sección activa** (`?seccion=`), que se normaliza contra el catálogo: un valor que no se entiende se
 *    trata como "ninguna".
 *  - **El estado vacío del contenido**, que ahora manda al menú lateral y no a un panel.
 *
 * El ViewModel se prueba **sin base de datos** (`tests/Unit/Clinica/`): todo lo que necesita llega por
 * el constructor ya leído y las URLs las resuelve el controlador (mismo criterio que la ficha).
 */
final class ConsultaShellVista
{
    // Las claves de `?seccion=` son las del catálogo (ver `SeccionesDeLaConsulta`); se reexportan acá porque los
    // controladores y las rutas las usan con este nombre desde WEB-4.2. No hay dos listas: son alias de la misma
    // constante.
    /** La clave de la sección de motivos en `?seccion=`. */
    public const SECCION_MOTIVOS = SeccionesDeLaConsulta::SECCION_MOTIVOS;

    /** La clave de la sección de enfermedad actual en `?seccion=`. */
    public const SECCION_ENFERMEDAD = SeccionesDeLaConsulta::SECCION_ENFERMEDAD;

    /** La clave de la sección de evaluación física en `?seccion=` (WEB-4.3). */
    public const SECCION_EVALUACION = SeccionesDeLaConsulta::SECCION_EVALUACION;

    /**
     * La clave de la sección **Exámenes/Estudios** en `?seccion=` (ítem 6 de la consulta pediátrica, 2026-10-19):
     * un ítem propio, **no** una solapa de la evaluación física.
     */
    public const SECCION_EXAMENES_ESTUDIOS = SeccionesDeLaConsulta::SECCION_EXAMENES_ESTUDIOS;

    /**
     * La clave de la sección **Alimentación** en `?seccion=` (ítem 9 de la consulta pediátrica, 2026-10-19): la dieta y
     * las recomendaciones, que en ginecología y las demás especialidades vive como pestaña de *Solicitudes*.
     */
    public const SECCION_ALIMENTACION = SeccionesDeLaConsulta::SECCION_ALIMENTACION;

    /** La clave de la sección de antecedentes en `?seccion=` (WEB-4.4). */
    public const SECCION_ANTECEDENTES = SeccionesDeLaConsulta::SECCION_ANTECEDENTES;

    /** La clave de la sección de diagnósticos en `?seccion=` (WEB-4.5). */
    public const SECCION_DIAGNOSTICO = SeccionesDeLaConsulta::SECCION_DIAGNOSTICO;

    /** La clave de la sección de evolución en `?seccion=` (WEB-4.6). */
    public const SECCION_EVOLUCION = SeccionesDeLaConsulta::SECCION_EVOLUCION;

    /**
     * La clave de la sección de exámenes e imágenes en `?seccion=`, **retirada en O-22 (2026-10-17)**: se
     * conserva para que el controlador redirija los enlaces viejos a *Evaluación física*.
     */
    public const SECCION_EXAMENES = SeccionesDeLaConsulta::SECCION_EXAMENES;

    /** La clave de la sección de récipes en `?seccion=` (WEB-5.1). */
    public const SECCION_RECIPES = SeccionesDeLaConsulta::SECCION_RECIPES;

    /** La clave de la sección de solicitudes (laboratorio, estudios, constancia, reposo, referencia, hospitalización, informe, dieta) en `?seccion=` (WEB-5.3a, WEB-5.3b y WEB-5.4). */
    public const SECCION_SOLICITUDES = SeccionesDeLaConsulta::SECCION_SOLICITUDES;

    /** El tratamiento: un texto libre de la consulta (`consultas.laringe`), el ítem TRATAMIENTO del panel del legado. */
    public const SECCION_TRATAMIENTO = SeccionesDeLaConsulta::SECCION_TRATAMIENTO;

    /** Los datos personales del paciente, editables dentro de la consulta (WEB-11.25). */
    public const SECCION_DATOS = SeccionesDeLaConsulta::SECCION_DATOS;

    /** La clave de la sección del **estudio ecográfico** (ítem 5 del panel) en `?seccion=` (WEB-6.1). */
    public const SECCION_ESTUDIO = SeccionesDeLaConsulta::SECCION_ESTUDIO;

    /** La clave de la sección del **control de embarazo** (ítem 6 del panel) en `?seccion=` (WEB-6.6). */
    public const SECCION_EMBARAZO = SeccionesDeLaConsulta::SECCION_EMBARAZO;

    /** La clave de la sección de **Indicadores incidentes** (nefrología pediátrica, ítem 5 de pediatría) en `?seccion=`. */
    public const SECCION_INDICADORES = SeccionesDeLaConsulta::SECCION_INDICADORES;

    /** La clave de la sección de **Procedimientos** (gastroenterología pediátrica, ítem 8 de pediatría) en `?seccion=`. */
    public const SECCION_PROCEDIMIENTOS = SeccionesDeLaConsulta::SECCION_PROCEDIMIENTOS;

    /** La clave de la sección de la **pre-factura** (F9) en `?seccion=`. */
    public const SECCION_PREFACTURA = SeccionesDeLaConsulta::SECCION_PREFACTURA;

    /** La clave de la sección de **I.Q.** (cirugía pediátrica, último ítem de pediatría) en `?seccion=`. */
    public const SECCION_QUIRURGICO = SeccionesDeLaConsulta::SECCION_QUIRURGICO;

    /** El valor de `?recipe=` que abre el récipe de la pareja (WEB-5.1b): la segunda pestaña de la sección de récipes. */
    public const RECIPE_PAREJA = SeccionesDeLaConsulta::RECIPE_PAREJA;

    public function __construct(
        private object $paciente,
        private int $nroconsulta,
        private ?string $fecha,
        private ?string $urlPaciente = null,
        private ?string $urlHistoria = null,
        private ?string $urlVolver = null,
        // La URL de esta misma pantalla: es la base de los enlaces del panel que abren una sección
        // (`?seccion=…`). Sin ella, esos ítems quedan sin enlace y el panel no los ofrece.
        private ?string $urlConsulta = null,
        private ?string $seccion = null,
        // "Terminar la consulta" (WEB-4.8): la acción primaria de la pantalla y su disponibilidad. El
        // controlador las resuelve —la URL es de la acción y la bandera sale de la cita del día— y acá
        // sólo se decide qué se muestra (la guía de UI: el estado se ve, no se lee).
        private ?string $urlTerminar = null,
        private bool $terminable = false,
        // El navegador 1…N (WEB-11.5): las consultas de la misma historia, `[id, nroconsulta, fecha, url]`, y la actual.
        private array $consultasDelPaciente = [],
        private ?int $consultaActual = null,
        // Las alertas de antecedentes del paciente (WEB-11.6): `[nombre, detalle]` de cada una, y si toca avisarlas con el diálogo.
        private array $alertas = [],
        private bool $avisarAlertas = false,
        // El `slug` de la especialidad del contexto: decide qué ítems del panel se ofrecen (WEB-12.1). Sin él, el panel completo.
        private ?string $especialidad = null,
        // La pestaña abierta de la sección de solicitudes (`?solicitud=`), normalizada. Es lo que decide si el
        // encabezado ofrece el panel con el resumen del paciente (O-30: sólo donde se redacta un documento).
        private ?string $pestanaDeSolicitudes = null,
        // Las secciones de la consulta que encienden los **postgrados** del médico (2026-10-19), resueltas por el
        // contexto: el catálogo las inserta en el lugar del legado.
        private array $seccionesPostgrados = [],
    ) {
    }

    /**
     * **¿El encabezado ofrece el panel con la historia y el resumen del paciente?** (O-30)
     *
     * Sólo en las pantallas donde se redacta un documento —los récipes y las cinco pestañas de solicitudes que
     * son un documento—, porque es donde salir de la consulta a mirar la historia cuesta el trabajo sin
     * guardar. Fuera de ahí no se ofrece: el resumen se ve desde la ficha del paciente.
     */
    public function ofreceElResumen(): bool
    {
        $seccion = $this->seccion();

        if ($seccion === self::SECCION_RECIPES) {
            return true;
        }

        // La dieta de pediatría tiene sección propia (ítem 9, *Alimentación*): es un documento que se redacta, así que
        // lleva el mismo panel con la historia y el resumen del paciente que la pestaña de *Solicitudes*.
        if ($seccion === self::SECCION_ALIMENTACION) {
            return true;
        }

        return $seccion === self::SECCION_SOLICITUDES && PestanasDeSolicitudes::esDeDocumento($this->pestanaDeSolicitudes);
    }

    /**
     * **El navegador entre las consultas del paciente** (WEB-11.5): un chip por consulta (el número), la actual marcada. El enlace
     * conserva la sección abierta, para cambiar de consulta sin perder el ítem en el que se estaba.
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function navegador(): array
    {
        if (count($this->consultasDelPaciente) < 2) {
            return [];
        }

        return array_map(function (array $consulta) {
            $url = $consulta['url'] . ($this->seccion ? '?seccion=' . urlencode($this->seccion) : '');

            return [
                'texto' => (string) $consulta['nroconsulta'],
                'url' => $url,
                'activa' => (int) $consulta['id'] === $this->consultaActual,
            ];
        }, $this->consultasDelPaciente);
    }

    /**
     * **Las alertas del paciente** (WEB-11.6): todos los antecedentes marcados «Alerta», con su detalle. Un aviso de seguridad clínica
     * (una alergia, un anticoagulante…): se ve fijo en toda la consulta y no sólo en el antecedente.
     *
     * @return array<int,array{nombre:string, detalle:?string}>
     */
    public function alertas(): array
    {
        return $this->alertas;
    }

    public function hayAlertas(): bool
    {
        return $this->alertas !== [];
    }

    /** ¿Se abre el diálogo de «Entendido»? Una vez por atención: no reaparece en cada sección. */
    public function avisarAlertas(): bool
    {
        return $this->avisarAlertas && $this->hayAlertas();
    }

    /** ¿Esta consulta tiene una anterior en su historia? Es lo que ofrece el botón «Anterior» (sólo desde la 2.ª consulta). */
    public function tieneAnterior(): bool
    {
        return $this->consultasDelPaciente !== []
            && $this->consultaActual !== null
            && (int) $this->consultasDelPaciente[0]['id'] !== $this->consultaActual;
    }

    /** La URL de «Anterior» para copiar `motivos`, `diagnosticos` o `recipes` de la consulta anterior (WEB-11.4). */
    public function urlAnterior(string $que): string
    {
        return rtrim((string) $this->urlConsulta, '/') . '/anterior/' . $que;
    }

    /** ¿Hay más de una consulta en la historia? (Con una sola, el navegador no se pinta.) */
    public function hayNavegador(): bool
    {
        return count($this->consultasDelPaciente) > 1;
    }

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->paciente->apellidos ?? null, $this->paciente->nombres ?? null);
    }

    public function id(): int
    {
        return (int) $this->paciente->id;
    }

    /**
     * **La edad del paciente a la fecha de la consulta**, o `null` fuera de pediatría.
     *
     * En pediatría la edad es un dato más de la línea del encabezado y **no puede faltar**: si el paciente no
     * tiene una fecha de nacimiento válida (basura del legado, año imposible o futuro) —o la consulta es
     * anterior al nacimiento— se devuelve «sin fecha de nacimiento», que es el aviso de que falta el dato, en
     * el mismo lugar donde iría la edad.
     *
     * El texto es el de pediatría del proyecto (`EdadEnTexto`: «5 días», «3 meses 12 días», «2 años 3 meses
     * 3 días», «8 años») y se cuenta **a la fecha de la consulta**, no a hoy: es la edad que tenía ese día.
     */
    public function edad(): ?string
    {
        if (! AlcanceDeLaEspecialidad::de($this->especialidad)->esPediatria()) {
            return null;
        }

        $nacimiento = FechaClinica::paraInput($this->paciente->fnacimiento ?? null);

        if ($nacimiento === '') {
            return 'sin fecha de nacimiento';
        }

        return EdadEnTexto::entre($nacimiento, $this->fecha) ?? 'sin fecha de nacimiento';
    }

    /**
     * "2 años 3 meses 3 días · H. 1001 · Consulta N.º 3 · 10/10/2026" en pediatría, "H. 1001 · Consulta N.º 3 ·
     * 10/10/2026" en las demás — lo que identifica la atención que se está abriendo.
     *
     * Va detrás del nombre del paciente en la **única fila** del encabezado de la consulta (A-11), en este orden:
     * `NOMBRE · [edad] · H. n.º · Consulta n.º · fecha` (el nombre lo pinta la vista y esto es el resto). La edad
     * abre la línea **sólo en pediatría** y siempre aparece: también cuando falta la fecha de nacimiento
     * («sin fecha de nacimiento»). Fuera de pediatría la línea no cambia.
     */
    public function subtitulo(): string
    {
        return collect([
            $this->edad(),
            $this->historia(),
            'Consulta N.º ' . $this->nroconsulta,
            FechaClinica::formato($this->fecha),
        ])->filter()->implode(' · ');
    }

    /**
     * La fila completa del encabezado, para el `title` con el texto sin recortar: "Pérez, Ana · H. 1001 · …", con la
     * edad del paciente en pediatría detrás del nombre.
     */
    public function lineaDeCabecera(): string
    {
        return collect([$this->nombre(), $this->subtitulo()])->filter()->implode(' · ');
    }

    /** "H. 1001", o `null` si el vínculo no tiene número (no debería pasar: sin historia no se abre). */
    public function historia(): ?string
    {
        $numero = CamposDePaciente::numeroDeHistoria($this->paciente->numhistoria ?? null);

        return $numero === null ? null : 'H. ' . $numero;
    }

    public function nroconsulta(): int
    {
        return $this->nroconsulta;
    }

    public function fecha(): string
    {
        return FechaClinica::formato($this->fecha);
    }

    public function urlPaciente(): ?string
    {
        return $this->urlPaciente;
    }

    public function urlHistoria(): ?string
    {
        return $this->urlHistoria;
    }

    /** Volver a la agenda del día de la consulta (la resuelve el controlador). */
    public function urlVolver(): ?string
    {
        return $this->urlVolver;
    }

    /**
     * **Terminar la consulta** (WEB-4.8): la acción primaria de la pantalla, o `null` si no se ofrece.
     *
     * No se ofrece cuando la consulta no es de hoy. El escritorio sólo marca `atendido` la cita del día
     * (`w_consulta_principal.srw:4418-4420`: `fecha = today()`), así que estrenar una consulta vieja —a la
     * que se entra desde la historia del paciente, sin haber atendido a nadie hoy— no es "terminar una
     * atención" y marcar la cita de esa fecha pasada no significaría nada.
     */
    public function urlTerminar(): ?string
    {
        return $this->terminable ? $this->urlTerminar : null;
    }

    /**
     * El aviso de la confirmación, con el paciente y la consulta ya dentro.
     *
     * Terminar la atención marca la cita como atendida y **no se deshace** (el escritorio tampoco lo
     * deshace: `atendido` es una marca, no un estado que se vuelva atrás desde la consulta), así que la
     * pantalla lo dice antes de hacerlo y nombra lo que cambia.
     */
    public function avisoTerminar(): string
    {
        return '¿Terminar la consulta N.º ' . $this->nroconsulta . ' de ' . $this->nombre()
            . '? La cita de hoy va a quedar marcada como atendida y volverás a la agenda del día. '
            . 'La cita no se puede volver a marcar como no atendida desde acá.';
    }

    /**
     * La sección de contenido que se está mirando (`?seccion=`), o `null` si no se eligió ninguna.
     *
     * Es lo que la vista usa para destacar el ítem del panel, y lo que el controlador consulta para
     * armar el ViewModel de la sección. Un valor desconocido se trata como "ninguna": el panel no tiene
     * una sección que no exista.
     */
    public function seccion(): ?string
    {
        return SeccionesDeLaConsulta::normalizar($this->seccion, $this->especialidad, $this->seccionesPostgrados);
    }

    /**
     * El parcial de la sección activa (`clinica.consulta._motivos` / `._enfermedad` / `._evaluacion` /
     * `._antecedentes` / `._diagnostico` / `._evolucion` / `._recipes` / `._solicitudes` /
     * `._estudio`), o `null` si no hay ninguna. El mapa se arma con
     * las claves del catálogo, así que agregar una sección es agregar una fila a `SeccionesDeLaConsulta` y su
     * parcial, no tocar la vista.
     */
    public function parcial(): ?string
    {
        $seccion = $this->seccion();

        return $seccion === null ? null : 'clinica.consulta._' . $seccion;
    }

    /**
     * El catálogo agrupado como el legado: lo de la atención primero y los documentos después.
     *
     * Ya no lo pinta la pantalla (los ítems viven en el menú lateral), pero es la lectura del catálogo que usan
     * las pruebas del shell y cualquier consumidor que necesite los grupos.
     *
     * @return array<int,array{titulo:string, grupos:array<int,array{titulo:string, items:array<int,array<string,mixed>>}>}>
     */
    public function panel(): array
    {
        $grupos = [];

        foreach (SeccionesDeLaConsulta::todas($this->especialidad, $this->seccionesPostgrados) as $item) {
            $grupos[$item['grupo']][] = $this->item($item);
        }

        $bloques = [];
        foreach ($grupos as $titulo => $items) {
            $bloques[] = ['titulo' => $titulo, 'items' => $items];
        }

        return $bloques;
    }

    public function totalItems(): int
    {
        return SeccionesDeLaConsulta::total($this->especialidad, $this->seccionesPostgrados);
    }

    /** Cuántos ítems ya tienen pantalla: 12 de 13 en ginecología y 13 de 14 en pediatría (no tiene el estudio ecográfico ni el control de embarazo). */
    public function totalListos(): int
    {
        return SeccionesDeLaConsulta::disponibles($this->especialidad, $this->seccionesPostgrados);
    }

    /**
     * El estado vacío del contenido: el shell con una sección que **no existe** (un `?seccion=` desconocido).
     *
     * Desde O-06 la consulta sin `?seccion=` abre en Motivos de consulta, así que este estado dejó de ser el
     * camino normal; no se borra porque sigue siendo el destino de una sección pedida que no está en el
     * catálogo.
     *
     * No dice que "no hay datos" —sería mentira: hay una consulta abierta—, dice qué es la pantalla y que
     * las secciones se abren desde el **menú lateral** (el grupo «Consulta», que reemplazó al panel). Con
     * WEB-6.6 ya se pueden cargar los ítems 1 a 6 y 8 a 13, y el resto sigue llegando por tareas (F9).
     */
    public function sinContenido(): array
    {
        return [
            'icono' => 'bi-list-ul',
            'titulo' => 'Elige una sección en el menú',
            'texto' => 'Esta es la pantalla de la consulta. Los ítems del grupo «Consulta» del menú lateral '
                . '—desde los motivos de consulta hasta la pre-factura— se abren acá. Cuando termines la atención, la acción '
                . 'del encabezado la cierra y te devuelve a la agenda del día.',
        ];
    }

    /** @param array<string,mixed> $item una fila de `SeccionesDeLaConsulta` */
    private function item(array $item): array
    {
        $listo = $item['estado'] === 'listo';
        $seccion = $item['seccion'] ?? null;

        return [
            'numero' => (int) $item['numero'],
            'titulo' => $item['titulo'],
            'detalle' => $item['detalle'],
            'icono' => $item['icono'],
            'tarea' => $item['tarea'],
            'listo' => $listo,
            // Los ítems de WEB-4.2 (motivos y enfermedad actual) se abren **dentro** del shell
            // (`?seccion=…`); *Datos personales* es un enlace a la ficha del paciente (F3).
            'url' => $seccion !== null
                ? $this->urlSeccion($seccion)
                : ($listo ? $this->urlPaciente : null),
            'activo' => $seccion !== null && $seccion === $this->seccion(),
            // Sólo lo que **no** está lleva pastilla: "Próximamente" basta para distinguirlo, y un "Disponible" en
            // cada ítem listo le quitaba al nombre y al detalle el espacio que necesitan. El que sí está se
            // reconoce por su enlace y por su icono de marca.
            'estado' => $listo
                ? null
                : ['tono' => 'neutro', 'icono' => 'bi-hourglass-split', 'texto' => 'Próximamente'],
            'titulo_estado' => $listo
                ? null
                : 'Llega con ' . $item['tarea'] . ': todavía no se puede cargar',
        ];
    }

    /** La URL de una sección dentro de esta misma consulta; `null` si la pantalla no tiene su URL. */
    private function urlSeccion(string $seccion): ?string
    {
        if ($this->urlConsulta === null) {
            return null;
        }

        return $this->urlConsulta . '?seccion=' . $seccion;
    }
}
