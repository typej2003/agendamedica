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
        private ?string $urlAsignarHistoria = null,
    ) {
    }

    /**
     * El mismo ViewModel con la **URL de la acción que asigna la historia** ya resuelta (WEB-3.3).
     *
     * La URL no se arma acá adentro a propósito: el ViewModel se prueba **sin base de datos y sin
     * contenedor** (`tests/Unit/Clinica/`), y un `route()` adentro obligaría a levantar Laravel para
     * probar la ficha. Es el mismo criterio que `conSeguro()`, que recibe el catálogo ya leído.
     */
    public function conUrlAsignarHistoria(string $url): self
    {
        return new self($this->paciente, $this->consultas, $this->seguro, $url);
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
        return new self($this->paciente, $this->consultas, $seguro, $this->urlAsignarHistoria);
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

    /**
     * ¿Es menor de 18 años? Decide dónde vive el representante en la ficha: para un menor es un bloque propio
     * (pediatría, ginecología de adolescentes); para un adulto —los pocos casos de autismo o discapacidad— es
     * una acción compacta en la cabecera. Sin fecha de nacimiento no se sabe, y cuenta como adulto.
     */
    public function esMenorDeEdad(): bool
    {
        $edad = FechaClinica::edad($this->paciente->fnacimiento);

        return $edad !== null && $edad < 18;
    }

    public function edad(): ?string
    {
        // A los menores de 6 años se les dice con meses y días, como lo lee un pediatra (WEB-7.4.2d); a los demás, en años.
        return FechaClinica::edadDetallada($this->paciente->fnacimiento);
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

    // --------------------------------------------------- Llenar la historia desde la ficha (WEB-3.3)

    /**
     * Si la ficha **ofrece** asignarle el número de historia. Cuando el paciente ya la tiene, la ficha
     * no ofrece nada de esto: sólo la muestra (la Action es idempotente, pero un botón que no hace nada
     * no se pone).
     *
     * La URL la resolvió el controlador (`conUrlAsignarHistoria`); sin ella no hay acción que ofrecer.
     */
    public function puedeAsignarHistoria(): bool
    {
        return ! $this->tieneHistoria() && $this->urlAsignarHistoria !== null;
    }

    public function urlAsignarHistoria(): ?string
    {
        return $this->urlAsignarHistoria;
    }

    /**
     * El bloque de "falta la historia" de la ficha. No es un dato del paciente como los demás: es lo que
     * hay que hacer antes de poder atenderlo (wiki `04-historia-clinica.md`: "No se atiende sin
     * historia"), así que va con título y texto propios y no como una fila más de la rejilla.
     *
     * @return array{titulo:string, texto:string}
     */
    public function sinHistoria(): array
    {
        return [
            'titulo' => 'Falta el número de historia',
            'texto'  => 'Sin número de historia el paciente no se puede atender ni tener consultas. '
                . 'Se lo asigna el servidor con el correlativo de este consultorio, así que todavía no se sabe cuál va a ser.',
        ];
    }

    /** El rótulo del botón que dispara la acción. */
    public function botonAsignarHistoria(): string
    {
        return 'Asignar el número de historia';
    }

    /**
     * La confirmación que se muestra **antes** de la acción (`data-confirmar` de `shell.js`): nombra al
     * paciente y dice el efecto, como la de "Pasar a cita formal" (WEB-2.11). El número no se puede
     * adelantar —lo calcula el servidor—, así que lo que se promete es el efecto, no el número.
     */
    public function confirmarAsignarHistoria(): string
    {
        return '¿Asignarle el número de historia a ' . $this->nombre() . '? Es un número correlativo de '
            . 'este consultorio, no se puede elegir y no se puede deshacer.';
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
