<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pacientes\GuardarFamilia;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * **Guardar la familia del niño** (WEB-7.4.2b): los padres, los teléfonos y el representante del escritorio de pediatría. El bloque que
 * la muestra es de la ficha (WEB-7.4.2a); esta ruta la guarda. La regla vive en `App\Actions\Pacientes\GuardarFamilia`.
 *
 * Quién puede: la misma vara que la ficha y el representante (`role:Root|Medico|Secretaria`): la secretaría carga el contacto al recibir
 * al niño. De quién es el paciente lo dice el vínculo (`medico_pacientes`) y el `reg_medico` **no viaja en el POST**; un paciente de otro
 * consultorio da 404 sin confirmar que exista.
 */
class FamiliaController extends Controller
{
    public function __construct(private PacientesDelConsultorio $pacientes)
    {
    }

    public function guardar(Request $request, int $paciente, GuardarFamilia $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->pacientes->ficha($contexto['reg_medico'], $paciente);

        // Un paciente de otro consultorio ni se toca.
        abort_unless($ficha !== null, 404);

        // Sin número de historia no hay clave con la que guardar: la ficha no ofrece el formulario, así que llegar acá es un POST armado
        // a mano. Se explica y se vuelve, sin escribir nada.
        if ($ficha->numhistoria === null) {
            return redirect()
                ->route('clinica.pacientes.ver', $paciente)
                ->with('error', 'La familia se guarda con el número de historia del niño. Asígnale el número y después se carga.');
        }

        $datos = $request->validate([
            'nom_madre'         => ['nullable', 'string', 'max:120'],
            'fecn_madre'        => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'ocupa_madre'       => ['nullable', 'string', 'max:60'],
            'celu_madre'        => ['nullable', 'string', 'max:12'],
            'nom_padre'         => ['nullable', 'string', 'max:120'],
            'fecn_padre'        => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'ocupa_padre'       => ['nullable', 'string', 'max:60'],
            'cel_padre'         => ['nullable', 'string', 'max:12'],
            'telefono'          => ['nullable', 'string', 'max:12'],
            'ced_representante' => ['nullable', 'regex:/^[0-9]{1,10}$/'],
            'email'             => ['nullable', 'email', 'max:120'],
            'direccion'         => ['nullable', 'string', 'max:2000'],
            'localidad'         => ['nullable', 'string', 'max:200'],
            'dinamica_familiar' => ['nullable', 'string', 'max:5000'],
            'familia_texto'     => ['nullable', 'string', 'max:5000'],
        ], [
            'celu_madre.max'          => 'El celular de la madre no puede pasar de 12 caracteres (es el ancho del escritorio).',
            'cel_padre.max'           => 'El celular del padre no puede pasar de 12 caracteres (es el ancho del escritorio).',
            'telefono.max'            => 'El teléfono no puede pasar de 12 caracteres (es el ancho del escritorio).',
            'ced_representante.regex' => 'La cédula del representante solo lleva números (hasta 10 dígitos).',
            'email.email'             => 'Revisa el correo: no tiene forma de correo.',
            'fecn_madre.before_or_equal' => 'La fecha de nacimiento de la madre no puede ser futura.',
            'fecn_padre.before_or_equal' => 'La fecha de nacimiento del padre no puede ser futura.',
        ]);

        // Un formulario sin nada escrito no es una familia: no crea una fila en blanco (mismo criterio que el representante).
        if (collect($datos)->every(fn ($valor) => trim((string) $valor) === '')) {
            throw ValidationException::withMessages([
                'nom_madre' => 'Escribe al menos un dato de la familia: los padres o un teléfono para contactarlos.',
            ]);
        }

        try {
            $accion->ejecutar($contexto['reg_medico'], $ficha->numhistoria, $datos, $ficha);
        } catch (InvalidArgumentException $excepcion) {
            return redirect()->route('clinica.pacientes.ver', $paciente)->with('error', $excepcion->getMessage());
        } catch (Throwable $excepcion) {
            // El escritorio también escribe estas tablas: si justo subió la fila mientras se guardaba, el insert choca. No se pierde
            // nada —volver a guardar la actualiza— y el aviso dice qué pasó.
            Log::warning('Familia: no se pudo guardar', [
                'reg_medico' => $contexto['reg_medico'], 'paciente' => $paciente, 'error' => $excepcion->getMessage(),
            ]);

            return redirect()->route('clinica.pacientes.ver', $paciente)->with('error', 'No se pudo guardar la familia. Prueba otra vez.');
        }

        return redirect()->route('clinica.pacientes.ver', $paciente)->with('estado', 'Se guardó la familia del paciente.');
    }
}
