<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;

/**
 * **El shell de la consulta** (WEB-4.1): el encabezado con el paciente, su historia, la consulta y la
 * fecha, y el **panel de navegación del legado** con el estado de cada ítem.
 *
 * Los 13 ítems son los del panel de `w_consulta_principal` (wiki `04-historia-clinica.md`, "Ítems de la
 * historia clínica"), en el mismo orden en que el médico los tiene en el escritorio. Acá **no hay
 * contenido**: el armazón y la navegación son el entregable de 4.1, y cada ítem llega con su tarea
 * (F4.2–F4.7, F3, F5, F6 y F9). Dos cosas que el ViewModel decide y no la vista:
 *
 *  - **El estado de cada ítem** (`Próximamente`, o implementado con su enlace). La guía de UI pide que
 *    el estado se vea —color + icono + texto— y no que se lea: por eso cada ítem trae su `estado()`.
 *  - **El grupo de cada ítem** (`Consulta` o `Documentos`), que es cómo el legado los tiene separados en
 *    el panel y lo que hace legible una lista de trece.
 *
 * El ViewModel se prueba **sin base de datos** (`tests/Unit/Clinica/`): todo lo que necesita llega por
 * el constructor ya leído y las URLs las resuelve el controlador (mismo criterio que la ficha).
 */
final class ConsultaShellVista
{
    /** La clave de la sección de motivos en `?seccion=`. */
    public const SECCION_MOTIVOS = 'motivos';

    /** La clave de la sección de enfermedad actual en `?seccion=`. */
    public const SECCION_ENFERMEDAD = 'enfermedad';

    /** La clave de la sección de evaluación física en `?seccion=` (WEB-4.3). */
    public const SECCION_EVALUACION = 'evaluacion';

    /** La clave de la sección de antecedentes en `?seccion=` (WEB-4.4). */
    public const SECCION_ANTECEDENTES = 'antecedentes';

    /** La clave de la sección de diagnósticos en `?seccion=` (WEB-4.5). */
    public const SECCION_DIAGNOSTICO = 'diagnostico';

    /** La clave de la sección de evolución en `?seccion=` (WEB-4.6). */
    public const SECCION_EVOLUCION = 'evolucion';

    /** La clave de la sección de exámenes e imágenes en `?seccion=` (WEB-4.7). */
    public const SECCION_EXAMENES = 'examenes';

    /** La clave de la sección de récipes en `?seccion=` (WEB-5.1). */
    public const SECCION_RECIPES = 'recipes';

    /**
     * El panel del legado, ítem por ítem: qué es, con qué tarea llega y —los que ya existen— a dónde
     * lleva hoy.
     *
     * La clave es el **número del ítem en la wiki** (`04-historia-clinica.md`, "Ítems de la historia
     * clínica", 1–13), que es lo que lo ata a su fuente y lo que hace que este mapa se pueda leer contra
     * el documento. El orden del panel es el del legado y no se reordena.
     *
     * `tarea` es la tarea de la web que lo completa; `estado` es `listo` (ya hay pantalla a la que
     * entrar) o `proximamente`. `seccion` es la clave del contenido que se abre **dentro de esta misma
     * pantalla** (`?seccion=…`): los ítems que traen WEB-4.2 a WEB-4.5 no son otra pantalla —son la tarjeta
     * de contenido del shell—, y su enlace lo arma `item()`. *Datos personales* no tiene `seccion`: su
     * pantalla es la ficha del paciente (F3) y sigue siendo un enlace afuera.
     */
    private const PANEL = [
        ['numero' => 1, 'grupo' => 'Consulta', 'titulo' => 'Motivos de consulta', 'icono' => 'bi-list-check',
            'detalle' => 'Catálogo del médico, varios por consulta.', 'tarea' => 'WEB-4.2', 'estado' => 'listo',
            'seccion' => self::SECCION_MOTIVOS],
        ['numero' => 2, 'grupo' => 'Consulta', 'titulo' => 'Enfermedad actual', 'icono' => 'bi-file-text',
            'detalle' => 'Texto libre o por plantilla.', 'tarea' => 'WEB-4.2', 'estado' => 'listo',
            'seccion' => self::SECCION_ENFERMEDAD],
        ['numero' => 3, 'grupo' => 'Consulta', 'titulo' => 'Antecedentes', 'icono' => 'bi-clipboard2-pulse',
            'detalle' => 'Del paciente, por categorías, con la casilla de alerta.', 'tarea' => 'WEB-4.4', 'estado' => 'listo',
            'seccion' => self::SECCION_ANTECEDENTES],

        ['numero' => 4, 'grupo' => 'Consulta', 'titulo' => 'Evaluación física', 'icono' => 'bi-heart-pulse',
            'detalle' => 'Antropometría, examen por aparatos y ginecológico.', 'tarea' => 'WEB-4.3', 'estado' => 'listo',
            'seccion' => self::SECCION_EVALUACION],
        ['numero' => 5, 'grupo' => 'Consulta', 'titulo' => 'Estudio ecográfico', 'icono' => 'bi-activity',
            'detalle' => 'Ecos ginecológicos, obstétricos, doppler y mamarío.', 'tarea' => 'F6', 'estado' => 'proximamente'],
        ['numero' => 6, 'grupo' => 'Consulta', 'titulo' => 'Control de embarazo', 'icono' => 'bi-gender-female',
            'detalle' => 'Prenatal, laboratorio, controles y reporte.', 'tarea' => 'F6', 'estado' => 'proximamente'],
        ['numero' => 7, 'grupo' => 'Consulta', 'titulo' => 'Exámenes', 'icono' => 'bi-droplet',
            'detalle' => 'Laboratorio de rutina de la consulta y las imágenes de sus estudios.',
            'tarea' => 'WEB-4.7', 'estado' => 'listo', 'seccion' => self::SECCION_EXAMENES],
        ['numero' => 8, 'grupo' => 'Consulta', 'titulo' => 'Diagnóstico', 'icono' => 'bi-clipboard2-check',
            'detalle' => 'Catálogo del médico; se propaga a constancias y presupuesto.', 'tarea' => 'WEB-4.5', 'estado' => 'listo',
            'seccion' => self::SECCION_DIAGNOSTICO],
        ['numero' => 9, 'grupo' => 'Consulta', 'titulo' => 'Evolución', 'icono' => 'bi-activity',
            'detalle' => 'Texto libre o por plantilla.', 'tarea' => 'WEB-4.6', 'estado' => 'listo',
            'seccion' => self::SECCION_EVOLUCION],
        ['numero' => 10, 'grupo' => 'Documentos', 'titulo' => 'Récipes', 'icono' => 'bi-prescription2',
            'detalle' => 'Varios por consulta, por medicamento o por tratamiento; se pueden corregir.',
            'tarea' => 'WEB-5.1', 'estado' => 'listo', 'seccion' => self::SECCION_RECIPES],
        ['numero' => 11, 'grupo' => 'Documentos', 'titulo' => 'Solicitudes', 'icono' => 'bi-send',
            'detalle' => 'Laboratorio, radiología, reposos, referencias, informes.', 'tarea' => 'F5', 'estado' => 'proximamente'],
        ['numero' => 12, 'grupo' => 'Documentos', 'titulo' => 'Datos personales', 'icono' => 'bi-person-vcard',
            'detalle' => 'La filiación y el contacto del paciente.', 'tarea' => 'F3', 'estado' => 'listo'],
        ['numero' => 13, 'grupo' => 'Documentos', 'titulo' => 'Pre-factura', 'icono' => 'bi-receipt',
            'detalle' => 'Los ítems de facturación de la consulta.', 'tarea' => 'F9', 'estado' => 'proximamente'],
    ];

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
    ) {
    }

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->paciente->apellidos ?? null, $this->paciente->nombres ?? null);
    }

    public function id(): int
    {
        return (int) $this->paciente->id;
    }

    /** "Consulta N.º 3 · H. 1001 · 10/10/2026" — lo que identifica la atención que se está abriendo. */
    public function subtitulo(): string
    {
        return collect([
            'Consulta N.º ' . $this->nroconsulta,
            $this->historia(),
            FechaClinica::formato($this->fecha),
        ])->filter()->implode(' · ');
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
        if ($this->seccion === null) {
            return null;
        }

        foreach (self::PANEL as $item) {
            if (($item['seccion'] ?? null) === $this->seccion) {
                return $this->seccion;
            }
        }

        return null;
    }

    /**
     * El parcial de la sección activa (`clinica.consulta._motivos` / `._enfermedad` / `._evaluacion` /
     * `._antecedentes` / `._diagnostico` / `._evolucion` / `._recipes`), o `null` si no hay ninguna. El mapa se arma con
     * las claves del panel, así que agregar una sección es agregar una fila a `PANEL` y su parcial, no
     * tocar la vista.
     */
    public function parcial(): ?string
    {
        $seccion = $this->seccion();

        return $seccion === null ? null : 'clinica.consulta._' . $seccion;
    }

    /**
     * El panel completo, agrupado como el legado: lo de la atención primero y los documentos después.
     *
     * @return array<int,array{titulo:string, grupos:array<int,array{titulo:string, items:array<int,array<string,mixed>>}>}>
     */
    public function panel(): array
    {
        $grupos = [];

        foreach (self::PANEL as $item) {
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
        return count(self::PANEL);
    }

    /** Cuántos ítems ya tienen pantalla: hoy 9 de 13 (motivos, enfermedad actual, antecedentes, evaluación física, exámenes, diagnóstico, evolución, récipes y Datos personales). */
    public function totalListos(): int
    {
        return count(array_filter(self::PANEL, fn (array $item) => $item['estado'] === 'listo'));
    }

    /**
     * El estado vacío del contenido: el shell sin ninguna sección elegida.
     *
     * No dice que "no hay datos" —sería mentira: hay una consulta abierta—, dice qué es la pantalla y que
     * las secciones se abren desde el panel. Con WEB-5.1 ya se pueden cargar los ítems 1, 2, 3, 4, 7, 8, 9,
     * 10 y 12, y el resto sigue llegando por tareas (F5, F6 y F9).
     */
    public function sinContenido(): array
    {
        return [
            'icono' => 'bi-list-ul',
            'titulo' => 'Elige una sección en el panel',
            'texto' => 'Esta es la pantalla de la consulta. Los ítems disponibles del panel '
                . '—motivos de consulta, enfermedad actual, antecedentes, evaluación física, exámenes, '
                . 'diagnóstico, evolución, récipes y los datos personales del paciente— se abren acá; el resto llega '
                . 'con las tareas siguientes (estudios y documentos). Cuando termines la atención, la acción '
                . 'del encabezado la cierra y te devuelve a la agenda del día.',
        ];
    }

    /** @param array<string,string> $item */
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
