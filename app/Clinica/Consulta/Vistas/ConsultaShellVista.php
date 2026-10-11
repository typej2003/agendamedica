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
    /**
     * El panel del legado, ítem por ítem: qué es, con qué tarea llega y —los que ya existen— a dónde
     * lleva hoy.
     *
     * La clave es el **número del ítem en la wiki** (`04-historia-clinica.md`, "Ítems de la historia
     * clínica", 1–13), que es lo que lo ata a su fuente y lo que hace que este mapa se pueda leer contra
     * el documento. El orden del panel es el del legado y no se reordena.
     *
     * `tarea` es la tarea de la web que lo completa; `estado` es `listo` (ya hay pantalla a la que
     * entrar) o `proximamente`. La única `listo` al cerrar 4.1 es **Datos personales**, porque la ficha
     * del paciente es F3 y ya está hecha: editar la filiación dentro de la consulta no necesita una
     * pantalla nueva, necesita el enlace.
     */
    private const PANEL = [
        ['numero' => 1, 'grupo' => 'Consulta', 'titulo' => 'Motivos de consulta', 'icono' => 'bi-list-check',
            'detalle' => 'Catálogo del médico, varios por consulta.', 'tarea' => 'WEB-4.2', 'estado' => 'proximamente'],
        ['numero' => 2, 'grupo' => 'Consulta', 'titulo' => 'Enfermedad actual', 'icono' => 'bi-file-text',
            'detalle' => 'Texto libre o por plantilla.', 'tarea' => 'WEB-4.2', 'estado' => 'proximamente'],
        ['numero' => 3, 'grupo' => 'Consulta', 'titulo' => 'Antecedentes', 'icono' => 'bi-clipboard2-pulse',
            'detalle' => 'Del paciente, por categorías, con la casilla de alerta.', 'tarea' => 'WEB-4.4', 'estado' => 'proximamente'],
        ['numero' => 4, 'grupo' => 'Consulta', 'titulo' => 'Evaluación física', 'icono' => 'bi-heart-pulse',
            'detalle' => 'Antropometría, examen por aparatos y ginecológico.', 'tarea' => 'WEB-4.3', 'estado' => 'proximamente'],
        ['numero' => 5, 'grupo' => 'Consulta', 'titulo' => 'Estudio ecográfico', 'icono' => 'bi-activity',
            'detalle' => 'Ecos ginecológicos, obstétricos, doppler y mamarío.', 'tarea' => 'F6', 'estado' => 'proximamente'],
        ['numero' => 6, 'grupo' => 'Consulta', 'titulo' => 'Control de embarazo', 'icono' => 'bi-gender-female',
            'detalle' => 'Prenatal, laboratorio, controles y reporte.', 'tarea' => 'F6', 'estado' => 'proximamente'],
        ['numero' => 7, 'grupo' => 'Consulta', 'titulo' => 'Exámenes', 'icono' => 'bi-droplet',
            'detalle' => 'Laboratorio de rutina de la consulta.', 'tarea' => 'WEB-4.7', 'estado' => 'proximamente'],
        ['numero' => 8, 'grupo' => 'Consulta', 'titulo' => 'Diagnóstico', 'icono' => 'bi-clipboard2-check',
            'detalle' => 'Catálogo del médico; se propaga a constancias y presupuesto.', 'tarea' => 'WEB-4.5', 'estado' => 'proximamente'],
        ['numero' => 9, 'grupo' => 'Consulta', 'titulo' => 'Evolución', 'icono' => 'bi-activity',
            'detalle' => 'Texto libre o por plantilla.', 'tarea' => 'WEB-4.6', 'estado' => 'proximamente'],
        ['numero' => 10, 'grupo' => 'Documentos', 'titulo' => 'Récipes', 'icono' => 'bi-prescription2',
            'detalle' => 'Los del paciente ya se listan en su historia.', 'tarea' => 'F5', 'estado' => 'proximamente'],
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

    /** Cuántos ítems ya tienen pantalla: hoy 1 de 13 (Datos personales). */
    public function totalListos(): int
    {
        return count(array_filter(self::PANEL, fn (array $item) => $item['estado'] === 'listo'));
    }

    /** El estado vacío del panel de contenido: dice qué falta y que llega por tareas. */
    public function sinContenido(): array
    {
        return [
            'icono' => 'bi-tools',
            'titulo' => 'Todavía no hay secciones para cargar',
            'texto' => 'Este es el armazón de la consulta. Los 13 ítems del panel del escritorio se van '
                . 'habilitando con las tareas siguientes (motivos y enfermedad actual, evaluación física, '
                . 'antecedentes, diagnósticos, evolución, estudios y documentos).',
        ];
    }

    /** @param array<string,string> $item */
    private function item(array $item): array
    {
        $listo = $item['estado'] === 'listo';

        return [
            'numero' => (int) $item['numero'],
            'titulo' => $item['titulo'],
            'detalle' => $item['detalle'],
            'icono' => $item['icono'],
            'tarea' => $item['tarea'],
            'listo' => $listo,
            // `Datos personales` es el único que ya tiene pantalla: la ficha del paciente (F3).
            'url' => $listo ? $this->urlPaciente : null,
            'estado' => $listo
                ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Disponible']
                : ['tono' => 'neutro', 'icono' => 'bi-hourglass-split', 'texto' => 'Próximamente'],
            'titulo_estado' => $listo
                ? 'Ya está en la web'
                : 'Llega con ' . $item['tarea'] . ': todavía no se puede cargar',
        ];
    }
}
