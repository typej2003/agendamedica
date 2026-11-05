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

    /**
     * ¿El POST viene del formulario mínimo de la próxima cita de un récipe? Es lo único que agrega la regla
     * «mañana en adelante»: el formulario completo de la agenda —también el que se abre con «Hacer cita»— sigue
     * pudiendo agendar para hoy, que es como se atiende a alguien que llega sin cita.
     */
    private function vieneDeLaProximaCitaDelRecipe(): bool
    {
        return $this->input('origen') === 'proxima-cita';
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        $pediatria = $this->esPediatria();

        return [
            'sede'            => ['nullable', 'integer'],
            // El formulario mínimo de la próxima cita (2026-10-28) manda `origen=proxima-cita`: desde ahí la cita es
            // de **mañana en adelante**, porque la hoja del récipe imprime la próxima cita con `fecha > hoy`
            // (`ProximaCitaDelRecipe`) y agendar hoy dejaría la hoja sin esa línea.
            'fecha'           => array_merge(
                ['required', 'date_format:Y-m-d'],
                $this->vieneDeLaProximaCitaDelRecipe() ? ['after:' . FechaDelConsultorio::hoyComoTexto()] : [],
            ),
            'origen'          => ['nullable', 'string', 'max:30'],
            'hora'            => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'bloque'          => ['nullable', 'integer', 'min:0'],
            'motivo'          => ['nullable', 'string', 'max:50'],
            'monto'           => ['nullable', 'numeric', 'min:0'],
            'paciente_id'     => ['nullable', 'integer'],
            // El alta rápida pide **nombre, apellido y teléfono** (Alexander, 2026-10-27: la primera cita de un paciente no se
            // puede frenar por la cédula o la fecha de nacimiento; el resto se completa cuando llega, y `FiliacionPendiente`
            // lo exige antes de terminar la consulta). La cédula, si se escribe, sigue enlazando con la ficha que ya exista.
            'nuevo_cedula'    => ['nullable', 'string', 'max:20'],
            // En pediatría el nombre es uno solo, el **completo** (PediaReport no tiene apellidos) y el
            // apellido no se pide. Fuera de pediatría siguen siendo dos y el apellido es obligatorio.
            'nuevo_nombres'   => ['nullable', 'required_without:paciente_id', 'string', 'max:200'],
            'nuevo_apellidos' => array_merge(['nullable'], $pediatria ? [] : ['required_with:nuevo_nombres'], ['string', 'max:100']),
            // En pediatría el teléfono que importa es el del representante; el del niño es opcional.
            'nuevo_telefono'  => array_merge(['nullable'], $pediatria ? [] : ['required_with:nuevo_nombres'], ['string', 'max:30']),
            'representante_nombre'     => array_merge(['nullable'], $pediatria ? ['required_with:nuevo_nombres'] : [], ['string', 'max:40']),
            'representante_telefono'   => array_merge(['nullable'], $pediatria ? ['required_with:nuevo_nombres'] : [], ['string', 'max:20']),
            'representante_parentesco' => ['nullable', 'string', 'max:1'],
            'representante_cedula'     => ['nullable', 'regex:/^[0-9]{1,10}$/'],
            'nuevo_email'     => ['nullable', 'email', 'max:120'],
            'nuevo_sexo'      => ['nullable', 'in:F,M'],
            // La fecha de nacimiento, la nacionalidad, el lugar de nacimiento y la dirección (2026-10-23) son opcionales: son las
            // columnas `fnacimiento`, `nac`, `lnacimiento` y `direccion` de `pacientes`. En pediatría la fecha de nacimiento
            // **sí** es obligatoria (Alexander, 2026-11-03): es el dato que ubica al niño en las curvas y en el grupo etario.
            'nuevo_fnacimiento' => array_merge(
                ['nullable'],
                $pediatria ? ['required_with:nuevo_nombres'] : [],
                ['date_format:Y-m-d', 'before_or_equal:' . FechaDelConsultorio::hoyComoTexto()],
            ),
            'nuevo_nac'         => ['nullable', 'in:V,E'],
            'nuevo_lnacimiento' => ['nullable', 'string', 'max:100'],
            'nuevo_direccion'   => ['nullable', 'string', 'max:200'],
            // **Los campos de PediaReport** (2026-11-03): sólo se piden en pediatría. Los que no tienen
            // columna en `pacientes` se guardan en `paciente_chamo` (la ficha de la especialidad).
            'nuevo_fingreso'           => ['nullable', 'date_format:Y-m-d'],
            'nuevo_grupo_sanguineo'    => ['nullable', 'in:O-,O+,A-,A+,B-,B+,AB-,AB+'],
            'nuevo_grupo_etario'       => ['nullable', 'string', 'max:100'],
            'nuevo_historia_anterior'  => ['nullable', 'string', 'max:20'],
            'nuevo_tipo'               => ['nullable', 'in:V,C'],
            'nuevo_referido'           => ['nullable', 'string', 'max:2000'],
            'nuevo_foto'               => ['nullable', 'image', 'max:4096'],
            // El representante de PediaReport: tipo (madre/padre), celular, grupo sanguíneo familiar,
            // ocupación, empresa, situación de pareja y el «S.C.» (sin cédula).
            'representante_tipo'               => ['nullable', 'in:F,M'],
            'representante_sin_cedula'         => ['nullable', 'boolean'],
            'representante_celular'            => ['nullable', 'string', 'max:12'],
            'representante_email'              => ['nullable', 'email', 'max:100'],
            'representante_grupo_sanguineo'    => ['nullable', 'in:O-,O+,A-,A+,B-,B+,AB-,AB+'],
            'representante_ocupacion'          => ['nullable', 'string', 'max:60'],
            'representante_empresa'            => ['nullable', 'string', 'max:2'],
            'representante_direccion'          => ['nullable', 'string', 'max:60'],
            'representante_situacion_pareja'   => ['nullable', 'in:E,S,V,D'],
            'decision'        => ['nullable', 'in:mover,cancelar'],
            // De qué consulta se vino con "Hacer cita" (WEB-5.2): sólo para volver a sus récipes al guardar.
            'volver_consulta' => ['nullable', 'integer'],
            // Y a qué pantalla de esa sección se vuelve (O-65): la lista de récipes (`lista`), el editor de uno nuevo
            // (`nuevo`), el de la pareja (`pareja`) o el de un récipe (su número). Las mismas claves de la URL de la
            // sección; cualquiera otra se rechaza en lugar de inventar un destino.
            'volver_recipe'   => ['nullable', 'string', 'regex:/^(lista|nuevo|pareja|[0-9]{1,4})$/'],
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
            'fecha.after'                   => 'La próxima cita del récipe es a partir de mañana: elige un día posterior a hoy.',
            'hora.regex'                    => 'La hora no tiene un formato válido: escríbela con AM/PM (por ejemplo, 9:30 AM).',
            'bloque.integer'                => 'El bloque de horario elegido no es válido.',
            'bloque.min'                    => 'El bloque de horario elegido no es válido.',
            'motivo.max'                    => 'El motivo no puede pasar de 50 caracteres.',
            'monto.numeric'                 => 'El monto debe ser un número.',
            'monto.min'                     => 'El monto no puede ser negativo.',
            'paciente_id.integer'           => 'El paciente elegido no es válido: vuelve a elegirlo de la lista.',
            'nuevo_cedula.max'              => 'La cédula no puede pasar de 20 caracteres.',
            'nuevo_nombres.required_without' => 'Elige un paciente de la lista o carga uno nuevo (con su nombre, apellido y teléfono).',
            'nuevo_nombres.max'             => 'El nombre del paciente no puede pasar de 200 caracteres.',
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
            'representante_tipo.in'                => 'El representante debe ser la madre o el padre.',
            'representante_sin_cedula.boolean'     => 'La opción "sin cédula" del representante no es válida.',
            'representante_celular.max'            => 'El celular del representante no puede pasar de 12 caracteres.',
            'representante_email.email'            => 'Revisa el correo del representante: no tiene forma de correo.',
            'representante_email.max'              => 'El correo del representante no puede pasar de 100 caracteres.',
            'representante_grupo_sanguineo.in'     => 'El grupo sanguíneo del representante no es válido.',
            'representante_ocupacion.max'          => 'La ocupación del representante no puede pasar de 60 caracteres.',
            'representante_empresa.max'            => 'La empresa del representante no es válida.',
            'representante_direccion.max'          => 'La dirección del representante no puede pasar de 60 caracteres.',
            'representante_situacion_pareja.in'    => 'La situación de pareja no es válida.',
            'nuevo_email.email'             => 'Revisa el correo: no tiene forma de correo.',
            'nuevo_email.max'               => 'El correo no puede pasar de 120 caracteres.',
            'nuevo_sexo.in'                 => 'El sexo del paciente nuevo debe ser femenino o masculino.',
            'nuevo_fnacimiento.required_with'  => 'En pediatría la fecha de nacimiento es obligatoria.',
            'nuevo_fnacimiento.date_format'    => 'La fecha de nacimiento no tiene un formato válido.',
            'nuevo_fnacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'nuevo_nac.in'                     => 'La nacionalidad debe ser venezolano o extranjero.',
            'nuevo_lnacimiento.max'            => 'El lugar de nacimiento no puede pasar de 100 caracteres.',
            'nuevo_direccion.max'              => 'La dirección no puede pasar de 200 caracteres.',
            'nuevo_fingreso.date_format'       => 'La fecha de ingreso no tiene un formato válido.',
            'nuevo_grupo_sanguineo.in'         => 'El grupo sanguíneo no es válido.',
            'nuevo_grupo_etario.max'           => 'El grupo etario no puede pasar de 100 caracteres.',
            'nuevo_historia_anterior.max'      => 'La historia anterior no puede pasar de 20 caracteres.',
            'nuevo_tipo.in'                    => 'El tipo de atención debe ser vacuna o consulta.',
            'nuevo_referido.max'               => 'El referido no puede pasar de 2.000 caracteres.',
            'nuevo_foto.image'                 => 'La foto del paciente tiene que ser una imagen.',
            'nuevo_foto.max'                   => 'La foto del paciente no puede pasar de 4 MB.',
            'decision.in'                   => 'Elige una de las opciones que se ofrecen.',
            'volver_consulta.integer'       => 'No se sabe a qué consulta volver: ábrela de nuevo desde su lista.',
            'volver_recipe.regex'           => 'No se sabe a qué parte de los récipes volver: ábrela de nuevo desde la consulta.',
        ];
    }
}
