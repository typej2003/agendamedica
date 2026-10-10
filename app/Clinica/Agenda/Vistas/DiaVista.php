<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\LecturaDeAgenda;

/**
 * El cuerpo de la vista de día, que es la pantalla del mostrador: el buscador de pacientes y las jornadas
 * (una por sede) con sus citas.
 */
final class DiaVista
{
    /** @param  array<int,JornadaVista>  $jornadas */
    public function __construct(
        private LecturaDeAgenda $lectura,
        private array $jornadas,
    ) {
    }

    /** @return array<int,JornadaVista> */
    public function jornadas(): array
    {
        return $this->jornadas;
    }

    public function estaVacio(): bool
    {
        return $this->jornadas === [];
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        $conSede = $this->lectura->parametros->sedeId !== null;
        $conRazon = $this->lectura->razon !== null;

        return [
            'icono'  => 'bi-calendar-x',
            'titulo' => 'No hay citas para este día' . ($conSede ? ' en la sede elegida' : '') . ($conRazon ? ' con esa razón' : ''),
            'texto'  => 'Prueba con otra fecha, cambia la sede del filtro' . ($conRazon ? ' o quita el filtro de razón' : '') . '.',
        ];
    }

    // -------------------------------------------------------------- Buscador de pacientes de la agenda

    /** El endpoint JSON del buscador (el mismo del alta de cita). */
    public function urlBuscar(): string
    {
        return route('clinica.pacientes.buscar');
    }

    /** El alta con la fecha y la sede que se están mirando; el script le agrega `&paciente_id=`. */
    public function urlNueva(): string
    {
        return route('clinica.agenda.nueva', array_filter([
            'fecha' => $this->lectura->parametros->fecha->toDateString(),
            'sede'  => $this->lectura->parametros->sedeId,
        ]));
    }
}
