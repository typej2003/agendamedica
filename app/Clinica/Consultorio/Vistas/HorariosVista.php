<?php

namespace App\Clinica\Consultorio\Vistas;

use App\Clinica\Vistas\ConValoresDeFormulario;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Support\Collection;

/** La pantalla de **bloques de horario** de un consultorio (`/clinica/consultorios/{id}/horarios`). */
final class HorariosVista
{
    use ConValoresDeFormulario;

    /** @param  Collection<int,OfficeSchedule>  $bloques  ya ordenados de lunes a domingo */
    public function __construct(
        private Office $consultorio,
        private Collection $bloques,
        private ?OfficeSchedule $editando,
    ) {
    }

    /** "San José · 101-A". */
    public function consultorio(): string
    {
        return ($this->consultorio->medicalCenter->name ?? 'Sede')
            . ($this->consultorio->office_number ? ' · ' . $this->consultorio->office_number : '');
    }

    public function subtitulo(): string
    {
        $modalidad = Office::modalidades()[$this->consultorio->modalidad] ?? $this->consultorio->modalidad;

        return $this->consultorio() . ' — ' . $modalidad
            . ($this->consultorio->duracion_cita ? ' · ' . $this->consultorio->duracion_cita . ' min por cita' : '')
            . '. Cada bloque es una jornada: la agenda agrupa las citas por fecha, sede y bloque, y el cupo se mide contra el bloque, no contra el día.';
    }

    public function urlVolver(): string
    {
        return route('clinica.consultorios');
    }

    public function urlGuardar(): string
    {
        return route('clinica.consultorios.horarios.guardar', $this->consultorio);
    }

    public function urlCancelar(): string
    {
        return route('clinica.consultorios.horarios', $this->consultorio);
    }

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el bloque' : 'Nuevo bloque de horario';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar cambios' : 'Agregar el bloque';
    }

    /** @return array<int,array{valor:int, texto:string, elegido:bool}> */
    public function opcionesDeDia(): array
    {
        $elegido = (int) $this->valor('dia_semana');

        return collect(OfficeSchedule::DIAS)
            ->map(fn ($nombre, $numero) => ['valor' => (int) $numero, 'texto' => $nombre, 'elegido' => $elegido === (int) $numero])
            ->values()
            ->all();
    }

    /** La hora como `HH:MM` (el formulario guarda `HH:MM:SS`). */
    public function hora(string $campo): ?string
    {
        $valor = $this->valor($campo);

        return $valor ? substr((string) $valor, 0, 5) : null;
    }

    public function estaVacio(): bool
    {
        return $this->bloques->isEmpty();
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->bloques->map(function (OfficeSchedule $bloque) {
            $descripcion = $bloque->nombreDelDia() . ' de ' . $bloque->rango();

            return [
                'dia'         => $bloque->nombreDelDia(),
                'horario'     => $bloque->rango(),
                'cupo'        => $bloque->cupo ? $bloque->cupo . ' pacientes' : null,
                'urlEditar'   => route('clinica.consultorios.horarios', [$this->consultorio, 'editar' => $bloque->id]),
                'urlEliminar' => route('clinica.consultorios.horarios.eliminar', [$this->consultorio, $bloque]),
                'confirmar'   => '¿Quitar el bloque del ' . $descripcion . '?',
            ];
        })->all();
    }
}
