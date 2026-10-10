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
}
