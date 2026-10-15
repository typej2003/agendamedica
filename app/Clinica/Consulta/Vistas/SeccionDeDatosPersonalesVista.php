<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Pacientes\Vistas\FormularioDePacienteVista;
use App\Models\Paciente;
use App\Support\FechaClinica;

/**
 * **Los datos personales del paciente dentro de la consulta** (WEB-11.25, ítem 13 del panel): la ficha editable sin salir de la atención,
 * con lo de sólo lectura (historia, edad, fecha de ingreso) a la vista, el **aviso de lo que falta** para poder cerrar la consulta y
 * «Imprimir la ficha».
 *
 * Envuelve al `FormularioDePacienteVista` de la ficha (los mismos campos y la misma validación: se guarda con `PUT /pacientes/{id}` y
 * el campo `volver_a_consulta` hace que se vuelva acá). No consulta nada: recibe lo ya leído.
 */
final class SeccionDeDatosPersonalesVista
{
    /**
     * @param  array<int,string>  $faltantes  lo obligatorio que no está (`DatosObligatoriosDelPaciente`)
     */
    public function __construct(
        private FormularioDePacienteVista $formulario,
        private Paciente $paciente,
        private ?string $numhistoria,
        private array $faltantes,
        private int $consultaId,
    ) {
    }

    public function formulario(): FormularioDePacienteVista
    {
        return $this->formulario;
    }

    /** La historia, sólo lectura (se asigna al atender, no se edita). */
    public function historia(): string
    {
        return $this->numhistoria !== null ? 'H. ' . $this->numhistoria : 'Sin historia';
    }

    public function edad(): string
    {
        $edad = FechaClinica::edad($this->paciente->fnacimiento);

        return $edad === null ? '—' : $edad . ($edad === 1 ? ' año' : ' años');
    }

    public function ingreso(): string
    {
        $fecha = FechaClinica::formato($this->paciente->fingreso);

        return $fecha === '' ? '—' : $fecha;
    }

    /** @return array<int,string> */
    public function faltantes(): array
    {
        return $this->faltantes;
    }

    public function hayFaltantes(): bool
    {
        return $this->faltantes !== [];
    }

    /** «Falta: teléfono y fecha de nacimiento.» */
    public function avisoDeFaltantes(): string
    {
        $lista = $this->faltantes;
        $ultimo = array_pop($lista);

        return 'Para terminar la consulta falta completar: ' . ($lista === [] ? $ultimo : implode(', ', $lista) . ' y ' . $ultimo) . '.';
    }

    public function urlGuardar(): string
    {
        return $this->formulario->urlGuardar();
    }

    public function consultaId(): int
    {
        return $this->consultaId;
    }
}
