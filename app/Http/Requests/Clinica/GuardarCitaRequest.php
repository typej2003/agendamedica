<?php

namespace App\Http\Requests\Clinica;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\FechaDelConsultorio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que se acepta del formulario de la cita (alta y edición). El paciente es **o** uno elegido del
 * listado **o** una ficha nueva con sus datos mínimos (nombres, apellidos y teléfono: lo que hace falta para
 * agendarle y avisarle).
 *
 * La autorización la resuelve el middleware de la web clínica (sesión y contexto): acá solo se valida.
 */
class GuardarCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** ¿El consultorio del contexto es de pediatría? Ahí el alta mínima pide el representante y su teléfono, no el del niño. */
    private function esPediatria(): bool
    {
        return AlcanceDeLaEspecialidad::de($this->attributes->get('contexto_clinico')['specialty']?->slug ?? null)->esPediatria();
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        $pediatria = $this->esPediatria();

        return [
            'sede'            => ['nullable', 'integer'],
            'fecha'           => ['required', 'date_format:Y-m-d'],
            'hora'            => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'bloque'          => ['nullable', 'integer', 'min:0'],
            'motivo'          => ['nullable', 'string', 'max:50'],
            'monto'           => ['nullable', 'numeric', 'min:0'],
            'paciente_id'     => ['nullable', 'integer'],
            // El alta rápida pide **nombre, apellido y teléfono** (Alexander, 2026-10-27: la primera cita de un paciente no se
            // puede frenar por la cédula o la fecha de nacimiento; el resto se completa cuando llega, y `FiliacionPendiente`
            // lo exige antes de terminar la consulta). La cédula, si se escribe, sigue enlazando con la ficha que ya exista.
            'nuevo_cedula'    => ['nullable', 'string', 'max:20'],
            'nuevo_nombres'   => ['nullable', 'required_without:paciente_id', 'string', 'max:100'],
            'nuevo_apellidos' => ['nullable', 'required_with:nuevo_nombres', 'string', 'max:100'],
            // En pediatría el teléfono que importa es el del representante; el del niño es opcional.
            'nuevo_telefono'  => array_merge(['nullable'], $pediatria ? [] : ['required_with:nuevo_nombres'], ['string', 'max:30']),
            'representante_nombre'     => array_merge(['nullable'], $pediatria ? ['required_with:nuevo_nombres'] : [], ['string', 'max:40']),
            'representante_telefono'   => array_merge(['nullable'], $pediatria ? ['required_with:nuevo_nombres'] : [], ['string', 'max:20']),
            'representante_parentesco' => ['nullable', 'string', 'max:1'],
            'representante_cedula'     => ['nullable', 'regex:/^[0-9]{1,10}$/'],
            'nuevo_email'     => ['nullable', 'email', 'max:120'],
            'nuevo_sexo'      => ['nullable', 'in:F,M'],
            // La fecha de nacimiento, la nacionalidad, el lugar de nacimiento y la dirección (2026-10-23) son opcionales: son las
            // columnas `fnacimiento`, `nac`, `lnacimiento` y `direccion` de `pacientes`.
            'nuevo_fnacimiento' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . FechaDelConsultorio::hoyComoTexto()],
            'nuevo_nac'         => ['nullable', 'in:V,E'],
            'nuevo_lnacimiento' => ['nullable', 'string', 'max:100'],
            'nuevo_direccion'   => ['nullable', 'string', 'max:200'],
            'decision'        => ['nullable', 'in:mover,cancelar'],
            // De qué consulta se vino con "Hacer cita" (WEB-5.2): sólo para volver a sus récipes al guardar.
            'volver_consulta' => ['nullable', 'integer'],
        ];
    }

    /**
     * Los avisos, **uno por regla**. El `locale` de la app es `en` (la API comparte esa configuración y no se
     * toca por una pantalla), así que una regla sin texto propio sale en inglés y nombrando el campo interno
     * ("The nuevo cedula field is required when paciente id is not present"). Si se agrega una regla acá,
     * se agrega su aviso: `AgendaWebTest::test_todos_los_errores_del_formulario_de_cita_estan_en_espanol`
     * lo exige.
     *
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'sede.integer'                  => 'La sede elegida no es válida.',
            'fecha.required'                => 'Elige el día de la cita.',
            'fecha.date_format'             => 'El día de la cita no tiene un formato válido.',
            'hora.regex'                    => 'La hora no tiene un formato válido: escríbela con AM/PM (por ejemplo, 9:30 AM).',
            'bloque.integer'                => 'El bloque de horario elegido no es válido.',
            'bloque.min'                    => 'El bloque de horario elegido no es válido.',
            'motivo.max'                    => 'El motivo no puede pasar de 50 caracteres.',
            'monto.numeric'                 => 'El monto debe ser un número.',
            'monto.min'                     => 'El monto no puede ser negativo.',
            'paciente_id.integer'           => 'El paciente elegido no es válido: vuelve a elegirlo de la lista.',
            'nuevo_cedula.max'              => 'La cédula no puede pasar de 20 caracteres.',
            'nuevo_nombres.required_without' => 'Elige un paciente de la lista o carga uno nuevo (con su nombre, apellido y teléfono).',
            'nuevo_nombres.max'             => 'El nombre del paciente no puede pasar de 100 caracteres.',
            'nuevo_apellidos.required_with' => 'Falta el apellido del paciente nuevo.',
            'nuevo_telefono.required_with'  => 'Falta el teléfono del paciente nuevo.',
            'nuevo_apellidos.max'           => 'El apellido del paciente no puede pasar de 100 caracteres.',
            'nuevo_telefono.max'            => 'El teléfono no puede pasar de 30 caracteres.',
            'representante_nombre.required_with'   => 'Falta el nombre del representante.',
            'representante_nombre.max'             => 'El nombre del representante no puede pasar de 40 caracteres.',
            'representante_telefono.required_with' => 'Falta el teléfono del representante.',
            'representante_telefono.max'           => 'El teléfono del representante no puede pasar de 20 caracteres.',
            'representante_parentesco.max'         => 'El parentesco del representante es un solo carácter.',
            'representante_cedula.regex'           => 'La cédula del representante solo lleva números (hasta 10 dígitos).',
            'nuevo_email.email'             => 'Revisa el correo: no tiene forma de correo.',
            'nuevo_email.max'               => 'El correo no puede pasar de 120 caracteres.',
            'nuevo_sexo.in'                 => 'El sexo del paciente nuevo debe ser femenino o masculino.',
            'nuevo_fnacimiento.date_format'    => 'La fecha de nacimiento no tiene un formato válido.',
            'nuevo_fnacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'nuevo_nac.in'                     => 'La nacionalidad debe ser venezolano o extranjero.',
            'nuevo_lnacimiento.max'            => 'El lugar de nacimiento no puede pasar de 100 caracteres.',
            'nuevo_direccion.max'              => 'La dirección no puede pasar de 200 caracteres.',
            'decision.in'                   => 'Elige una de las opciones que se ofrecen.',
            'volver_consulta.integer'       => 'No se sabe a qué consulta volver: ábrela de nuevo desde su lista.',
        ];
    }
}
