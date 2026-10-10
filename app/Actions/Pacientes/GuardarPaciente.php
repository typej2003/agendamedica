<?php

namespace App\Actions\Pacientes;

use App\Models\Medico;
use App\Models\Paciente;
use InvalidArgumentException;

/**
 * Alta y edición de la ficha de un paciente desde la web clínica (WEB-3.2), con la regla del legado:
 * **la cédula existente enlaza y al completar una ficha ajena solo se llenan los campos vacíos.**
 *
 * Por qué esta Action existe al lado de `CrearPaciente` y no adentro:
 *
 *  - `CrearPaciente` es la regla del **alta** y la comparten la web (el alta mínima de la cita) y el sync
 *    del móvil y del escritorio. Su contrato —enlazar por cédula, completar solo lo vacío, **no crear
 *    `historia`**— lo leen otras tres capas y no se toca. Acá el alta **delega** en ella: la regla sigue
 *    viviendo en un solo lugar.
 *  - Lo que agrega es la **edición**, que es una operación distinta: el médico del contexto sí edita los
 *    datos de su paciente (es su ficha, no la de otro). Y agrega la validación de identidad que la
 *    fuente del escritorio **no** hace (`w_agregar_pacientes.srw:90-121` valida cédula/apellidos/nombres
 *    y nada más): una cédula que ya es de otro paciente **se rechaza** con el mismo mensaje que el
 *    cambio de cédula (`CambiarCedula`, WEB-2.15), porque es la misma regla —`IdentidadDePaciente`— y no
 *    puede haber dos.
 *
 * Lo que **no** hace: crear la `historia` ni asignar `numhistoria` (eso es `AsignarHistoria`, WEB-3.3),
 * tocar la foto, el representante o el login del paciente.
 */
final class GuardarPaciente
{
    /**
     * Las columnas de `pacientes` que la ficha de la web escribe. Es una lista blanca a propósito: lo que
     * no está acá (foto, `password`, `user_id`, `sms`, `medico`) no se puede colar desde el formulario.
     *
     * `medico` queda afuera porque es la `clave` del médico **dentro de la instalación del escritorio**
     * (ver `Medico::claveDeEvolucion`), no de la nube: la web escribe el vínculo en `medico_pacientes`,
     * que es lo que leen la agenda, la web y el sync.
     */
    public const COLUMNAS = [
        'nac', 'cedula', 'apellidos', 'nombres', 'sexo', 'fnacimiento', 'lnacimiento',
        'codeestado', 'direccion', 'telefono', 'email', 'profesion', 'ocupacion',
        'escolaridad', 'codesegemp', 'dependencia', 'fingreso',
    ];

    /**
     * @param  array<string,mixed>  $columnas  Columnas de `pacientes` ya validadas por el llamador. La
     *                                         cédula puede venir nula ("sin cédula"); nombres y
     *                                         apellidos son obligatorios.
     * @param  string  $regMedico  El registro del contexto: con ese queda vinculado el paciente.
     * @param  Paciente|null  $paciente  `null` = alta; el paciente = edición de su ficha.
     *
     * @throws InvalidArgumentException con el motivo, para mostrarlo en pantalla
     */
    public function ejecutar(array $columnas, Medico $medico, string $regMedico, ?Paciente $paciente = null): Paciente
    {
        $columnas = array_intersect_key($columnas, array_flip(self::COLUMNAS));
        $columnas = $this->limpiar($columnas);

        $this->exigirNombre($columnas);

        if ($paciente === null) {
            // El alta es la de siempre: si la cédula ya existe, `CrearPaciente` **enlaza** la ficha y solo
            // completa sus campos vacíos (jamás pisa un dato cargado, ni siquiera del mismo médico).
            return app(CrearPaciente::class)->ejecutar($columnas, $medico, $regMedico);
        }

        return $this->actualizar($paciente, $columnas);
    }

    /**
     * La edición: el médico del contexto cambia los datos de **su** paciente.
     *
     * El único dato que no se pisa a ciegas es la cédula, porque es la identidad que comparten todos los
     * consultorios: si la nueva ya es de otro paciente se rechaza. La decisión es **rechazar** (y nombrar
     * al otro) y no "mover al paciente": la cédula es de una persona, no de un consultorio, y el escritorio
     * tiene el mismo caso —el cambio de cédula de WEB-2.15— resuelto igual.
     */
    private function actualizar(Paciente $paciente, array $columnas): Paciente
    {
        $nuevaCedula = $columnas['cedula'] ?? null;

        if ($nuevaCedula !== null && trim((string) $nuevaCedula) !== trim((string) $paciente->cedula)) {
            $otro = IdentidadDePaciente::duenoDe($nuevaCedula, $paciente);

            if ($otro !== null) {
                throw new InvalidArgumentException(IdentidadDePaciente::cedulaOcupada($otro, $nuevaCedula));
            }
        }

        // Solo lo que la ficha muestra: la foto, el `password` y el `user_id` del paciente no se tocan.
        $paciente->fill($columnas)->save();

        return $paciente;
    }

    /** El nombre y el apellido son lo único obligatorio del alta (wiki `04-historia-clinica.md`). */
    private function exigirNombre(array $columnas): void
    {
        if (trim((string) ($columnas['nombres'] ?? '')) === '' || trim((string) ($columnas['apellidos'] ?? '')) === '') {
            throw new InvalidArgumentException('El nombre y el apellido son obligatorios para guardar la ficha.');
        }
    }

    /** Los vacíos del formulario quedan `null`, como los deja el resto de la web (no `''`). */
    private function limpiar(array $columnas): array
    {
        return array_map(function ($valor) {
            if ($valor === null) {
                return null;
            }

            $texto = trim((string) $valor);

            return $texto === '' ? null : $texto;
        }, $columnas);
    }
}
