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
        private ?string $seguro = null,
    ) {
    }

    /**
     * El mismo ViewModel con el **nombre** del seguro ya resuelto contra el catálogo del consultorio.
     *
     * Es un `with` y no un dato del constructor porque el catálogo se consulta por el código que el propio
     * paciente trae (`codesegemp`): resolverlo afuera obligaría a leer dos veces lo mismo. El ViewModel
     * sigue sin saber de base de datos.
     */
    public function conSeguro(?string $seguro): self
    {
        return new self($this->paciente, $this->consultas, $seguro);
    }

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->paciente->apellidos, $this->paciente->nombres);
    }

    /** El id del paciente: lo necesita la vista para el enlace a "Editar la ficha" (WEB-3.2). */
    public function id(): int
    {
        return (int) $this->paciente->id;
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

    public function direccion(): ?string
    {
        return CamposDePaciente::texto($this->paciente->direccion ?? null);
    }

    public function profesion(): ?string
    {
        return CamposDePaciente::texto($this->paciente->profesion ?? null);
    }

    public function ocupacion(): ?string
    {
        return CamposDePaciente::texto($this->paciente->ocupacion ?? null);
    }

    /**
     * El código del seguro que tiene guardado el paciente (`codesegemp`), limpio. Es lo que el
     * controlador cruza con el catálogo del consultorio para armar `$seguro`.
     */
    public function codigoDelSeguro(): ?string
    {
        return CamposDePaciente::texto($this->paciente->codesegemp);
    }

    /**
     * El seguro/empresa aseguradora para mostrar: el **nombre** del catálogo del consultorio, o el código
     * tal cual si no está en él (un catálogo que todavía no bajó no puede hacer desaparecer el dato).
     *
     * `00` es **PERSONAL** en el catálogo del legado —el default del alta del escritorio,
     * `w_agregar_pacientes.srw:378`—: es el paciente que se atiende por su cuenta, así que no se muestra
     * como si tuviera una empresa.
     */
    public function seguro(): ?string
    {
        $codigo = $this->codigoDelSeguro();

        if ($codigo === null || $codigo === '00') {
            return null;
        }

        return $this->seguro ?? $codigo;
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
