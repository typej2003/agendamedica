<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **El formulario mínimo para agendar la próxima cita desde el récipe** (2026-10-28, pedido de Alexander): lo que
 * la franja de la próxima cita ofrece, además del atajo al formulario completo de la agenda.
 *
 * El problema que resuelve: hasta ahora, para que la hoja imprimiera «Próxima cita: 03/11/2026» había que salir
 * de la consulta con «Hacer cita», llenar el formulario entero de la agenda (sede, jornada, bloque, motivo, monto,
 * alta de paciente) y volver. Lo que el médico hace de verdad al terminar la consulta es **decidir el día (y la
 * hora, si su sede agenda por hora)**, así que eso es lo que se le pide acá y nada más: tres campos como máximo.
 *
 * ## Lo que NO hace
 *
 *  - **No agrega ninguna regla de agenda.** El `POST` va a la misma ruta del formulario de la cita
 *    (`clinica.agenda.crear`), con el mismo `GuardarCitaRequest` y el mismo `AgendamientoDeCitas`: la cita
 *    pendiente, la jornada, el número, el monto y la cita que el escritorio movió se resuelven donde siempre
 *    (PLAN-WEB, R3). Si el paciente ya tiene una cita pendiente, el servidor devuelve la pregunta de siempre
 *    —mover o cancelar— y al resolverla vuelve a esta sección: es la decisión del 2026-10-28.
 *  - **No consulta la base.** Como todo ViewModel de la web clínica, recibe ya leídas las sedes y los motivos y
 *    devuelve textos y opciones listas. Se prueba sin base de datos.
 *  - **No decide el «desde mañana».** Esa regla vive en el request (`fecha > hoy`, la misma que usa la hoja al
 *    imprimir); acá sólo viaja la fecha mínima para el `min` del control.
 *
 * ## La sede y la hora
 *
 * La consulta **no sabe de qué sede es** (`consultas` no tiene `medical_center_id`), así que la sede se elige
 * cuando el médico atiende en más de una: con una sola se manda oculta. La hora se pide cuando alguna sede agenda
 * **por hora**; es obligatoria si la elegida es de esa modalidad, y opcional si el médico mezcla modalidades
 * (en una sede por orden de llegada la hora de la fila es la del bloque y no hay que preguntarla).
 */
final class ProximaCitaVista
{
    /**
     * @param  array<int,array{valor:string,texto:string}>  $sedes  las sedes del médico, `valor` = `offices.id`
     * @param  array<int,array{valor:string,texto:string}>  $motivos  el catálogo de motivos de cita de esa sede
     * @param  string|null  $sedeElegida  la del contexto, o la única; `null` = hay que elegirla
     * @param  bool  $pideHora  alguna sede del médico agenda por hora (si no, la hora no se pregunta)
     * @param  bool  $horaObligatoria  la sede elegida agenda por hora
     * @param  string  $url  a dónde postea el formulario (la ruta de alta de la agenda)
     * @param  string  $fechaMinima  `AAAA-MM-DD`: mañana del consultorio (la hoja imprime de mañana en adelante)
     */
    public function __construct(
        private array $sedes,
        private array $motivos,
        private ?string $sedeElegida,
        private bool $pideHora,
        private bool $horaObligatoria,
        private string $url,
        private int $pacienteId,
        private int $consultaId,
        private string $fechaMinima,
    ) {
    }

    /** ¿Se puede agendar desde acá? Sin sedes vigentes no hay dónde: se deja sólo el formulario completo. */
    public function puedeAgendar(): bool
    {
        return $this->sedes !== [];
    }

    public function url(): string
    {
        return $this->url;
    }

    public function pacienteId(): int
    {
        return $this->pacienteId;
    }

    public function consultaId(): int
    {
        return $this->consultaId;
    }

    public function fechaMinima(): string
    {
        return $this->fechaMinima;
    }

    /** @return array<int,array{valor:string,texto:string}> */
    public function opcionesDeSede(): array
    {
        return $this->sedes;
    }

    /** ¿Hay que preguntar la sede? Con una sola se manda oculta y la franja no la muestra. */
    public function mostrarSede(): bool
    {
        return count($this->sedes) > 1;
    }

    public function sedeElegida(): ?string
    {
        return $this->sedeElegida;
    }

    /** ¿Se ofrece el campo de la hora? Sólo si alguna sede agenda por hora (en una por orden de llegada no existe). */
    public function pideHora(): bool
    {
        return $this->pideHora;
    }

    /** ¿La hora es obligatoria? Sí cuando la sede elegida agenda por hora: el servidor la exige y no se adivina. */
    public function horaObligatoria(): bool
    {
        return $this->horaObligatoria;
    }

    /** @return array<int,array{valor:string,texto:string}> */
    public function opcionesDeMotivo(): array
    {
        return $this->motivos;
    }

    public function sinMotivos(): bool
    {
        return $this->motivos === [];
    }

    /** La ayuda del campo de la hora, que cambia según la modalidad de la sede. */
    public function ayudaDeLaHora(): ?string
    {
        if (! $this->pideHora) {
            return null;
        }

        return $this->horaObligatoria
            ? 'Tu sede agenda por hora: sale impresa.'
            : 'Sólo si tu sede agenda por hora.';
    }
}
