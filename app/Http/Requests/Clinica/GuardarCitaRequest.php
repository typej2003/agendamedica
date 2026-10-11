<?php

namespace App\Http\Requests\Clinica;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que se acepta del formulario de la cita (alta y edición). El paciente es **o** uno elegido del
 * listado **o** una ficha nueva con sus datos mínimos (cédula, nombres y apellidos: lo que identifica a
 * la persona).
 *
 * La autorización la resuelve el middleware de la web clínica (sesión y contexto): acá solo se valida.
 */
class GuardarCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'sede'            => ['nullable', 'integer'],
            'fecha'           => ['required', 'date_format:Y-m-d'],
            'hora'            => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'bloque'          => ['nullable', 'integer', 'min:0'],
            'motivo'          => ['nullable', 'string', 'max:50'],
            'monto'           => ['nullable', 'numeric', 'min:0'],
            'paciente_id'     => ['nullable', 'integer'],
            'nuevo_cedula'    => ['nullable', 'required_without:paciente_id', 'string', 'max:20'],
            'nuevo_nombres'   => ['nullable', 'required_with:nuevo_cedula', 'string', 'max:100'],
            'nuevo_apellidos' => ['nullable', 'required_with:nuevo_cedula', 'string', 'max:100'],
            'nuevo_telefono'  => ['nullable', 'string', 'max:30'],
            'nuevo_email'     => ['nullable', 'email', 'max:120'],
            'nuevo_sexo'      => ['nullable', 'in:F,M'],
            'decision'        => ['nullable', 'in:mover,otra,cancelar'],
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
            'hora.regex'                    => 'La hora no tiene un formato válido (por ejemplo, 09:30).',
            'bloque.integer'                => 'El bloque de horario elegido no es válido.',
            'bloque.min'                    => 'El bloque de horario elegido no es válido.',
            'motivo.max'                    => 'El motivo no puede pasar de 50 caracteres.',
            'monto.numeric'                 => 'El monto debe ser un número.',
            'monto.min'                     => 'El monto no puede ser negativo.',
            'paciente_id.integer'           => 'El paciente elegido no es válido: vuelve a elegirlo de la lista.',
            'nuevo_cedula.required_without' => 'Elige un paciente de la lista o carga uno nuevo (con su cédula, nombres y apellidos).',
            'nuevo_cedula.max'              => 'La cédula no puede pasar de 20 caracteres.',
            'nuevo_nombres.required_with'   => 'Falta el nombre del paciente nuevo.',
            'nuevo_nombres.max'             => 'El nombre del paciente no puede pasar de 100 caracteres.',
            'nuevo_apellidos.required_with' => 'Falta el apellido del paciente nuevo.',
            'nuevo_apellidos.max'           => 'El apellido del paciente no puede pasar de 100 caracteres.',
            'nuevo_telefono.max'            => 'El teléfono no puede pasar de 30 caracteres.',
            'nuevo_email.email'             => 'Revisa el correo: no tiene forma de correo.',
            'nuevo_email.max'               => 'El correo no puede pasar de 120 caracteres.',
            'nuevo_sexo.in'                 => 'El sexo del paciente nuevo debe ser femenino o masculino.',
            'decision.in'                   => 'Elige una de las opciones que se ofrecen.',
        ];
    }
}
