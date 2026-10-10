<?php

namespace App\Clinica\Pacientes\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;
use Illuminate\Support\Collection;

/**
 * Lo que muestra la ficha de un paciente (`/clinica/pacientes/{id}`): el encabezado con sus estados, los
 * datos personales ya formateados y la lista de consultas. La vista solo lo recorre.
 *
 * Los campos del legado se leen con `CamposDePaciente`: una cédula `S.C.` es "sin cédula" y un
 * `numhistoria` vacío es "sin historia". `fnacimiento` se formatea con `FechaClinica`, que tolera
 * `0000-00-00` y basura sin romper la pantalla.
 */
final class FichaDePacienteVista
{
    public function __construct(
        private object $paciente,
        private Collection $consultas,
    ) {
    }

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->paciente->apellidos, $this->paciente->nombres);
    }

    public function tieneHistoria(): bool
    {
        return $this->numeroDeHistoria() !== null;
    }

    public function subtitulo(): string
    {
        return $this->tieneHistoria() ? 'Historia N.º ' . $this->numeroDeHistoria() : 'Todavía sin número de historia';
    }

    /**
     * Los estados del encabezado: historia, edad y sexo. Cada uno con tono e icono (nunca solo texto).
     *
     * @return array<int, array{tono:string, icono:string, texto:string}>
     */
    public function estados(): array
    {
        $estados = [$this->tieneHistoria()
            ? ['tono' => 'marca', 'icono' => 'bi-journal-medical', 'texto' => 'Historia ' . $this->numeroDeHistoria()]
            : ['tono' => 'aviso', 'icono' => 'bi-exclamation-triangle', 'texto' => 'Sin historia']];

        if ($this->edad() !== null) {
            $estados[] = ['tono' => 'neutro', 'icono' => 'bi-cake2', 'texto' => $this->edad()];
        }

        if ($this->sexo() !== null) {
            $estados[] = ['tono' => 'neutro', 'icono' => 'bi-person', 'texto' => $this->sexo()];
        }

        return $estados;
    }

    public function edad(): ?string
    {
        $edad = FechaClinica::edad($this->paciente->fnacimiento);

        return $edad === null ? null : $edad . ($edad === 1 ? ' año' : ' años');
    }

    public function sexo(): ?string
    {
        $sexo = CamposDePaciente::texto($this->paciente->sexo);

        return match (strtoupper((string) $sexo)) {
            ''  => null,
            'F' => 'Femenino',
            'M' => 'Masculino',
            default => $sexo,
        };
    }

    public function cedula(): ?string
    {
        return CamposDePaciente::cedula($this->paciente->cedula);
    }

    /** La fecha de nacimiento legible, o `null` si el legado no la trae o no se puede interpretar. */
    public function nacimiento(): ?string
    {
        $fecha = FechaClinica::formato($this->paciente->fnacimiento);

        return $fecha === '—' ? null : $fecha;
    }

    public function numeroDeHistoria(): ?string
    {
        return CamposDePaciente::numeroDeHistoria($this->paciente->numhistoria);
    }

    public function telefono(): ?string
    {
        return CamposDePaciente::texto($this->paciente->telefono);
    }

    public function correo(): ?string
    {
        return CamposDePaciente::texto($this->paciente->email);
    }

    public function totalConsultas(): int
    {
        return $this->consultas->count();
    }

    /** La frase del estado vacío de las consultas, que depende de por qué no hay ninguna. */
    public function sinConsultas(): array
    {
        return $this->tieneHistoria()
            ? ['icono' => 'bi-journal-text', 'titulo' => 'Sin consultas registradas', 'texto' => 'Las consultas aparecen aquí cuando el paciente es atendido.']
            : ['icono' => 'bi-journal-medical', 'titulo' => 'El paciente todavía no tiene historia', 'texto' => 'Sin número de historia no hay consultas asociadas.'];
    }

    /** @return array<int, array{fecha:string, numero:string, resumen:?string}> */
    public function consultas(): array
    {
        return $this->consultas->map(fn ($consulta) => [
            'fecha'   => FechaClinica::formato($consulta->fecha),
            'numero'  => 'Consulta ' . $consulta->nroconsulta,
            'resumen' => CamposDePaciente::texto($consulta->enfermedadactual),
        ])->all();
    }
}
