<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pacientes\GuardarRepresentante;
use App\Clinica\Pacientes\CatalogoDeParentescos;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Pacientes\RepresentanteDelPaciente;
use App\Clinica\Pacientes\RepresentantePorCedula;
use App\Clinica\Pacientes\Vistas\RepresentanteDePacienteVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * El **representante del paciente** (WEB-3.4): el adulto que responde por él —para pediatría y para los
 * casos generales—, con su parentesco y su contacto.
 *
 * Controlador propio y no un método más de `PacienteController` porque el representante **cuelga de la
 * ficha**: la ficha lo muestra (el bloque) y esta ruta lo guarda. La guía de la web pide dividir por
 * responsabilidad, no por pantalla (`Docs/Web/guia-arquitectura-web.md`): "listado, ficha, alta/edición y
 * número de historia" ya son cuatro en `PacienteController`, y el representante es otra cosa —una fila de
 * la tabla legada `representante`, con su propia clave (`numhistoria`)—.
 *
 * **Quién puede**: la misma vara que la ficha y el alta (WEB-3.2/3.3). El legado trata el contacto del
 * paciente como dato administrativo —la secretaría lo carga al recibirlo— y el grupo de rutas ya es
 * `role:Root|Medico|Secretaria`; no se agrega ningún permiso nuevo.
 *
 * **De quién es el paciente lo dice el vínculo** (`medico_pacientes`), y el `reg_medico` **no viaja en el
 * POST**: es el tenant y lo elige el contexto, no el formulario. Un paciente de otro médico da **404**
 * sin confirmar que exista, igual que su ficha.
 */
class RepresentanteController extends Controller
{
    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private RepresentanteDelPaciente $representantes,
        private CatalogoDeParentescos $parentescos,
        private RepresentantePorCedula $representantesPorCedula,
    ) {
    }

    /**
     * El bloque de representante de la ficha, ya armado: lo lee `PacienteController::ver` al mostrar la
     * ficha (el bloque vive adentro de esa pantalla, no tiene URL propia). Sin historia no hay nada que
     * buscar y la vista lo usa para explicar el motivo en vez de ofrecer un formulario que va a fallar.
     */
    public function bloque(Request $request, int $paciente): RepresentanteDePacienteVista
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];
        $vinculo = $this->pacientes->vinculo($regMedico, $paciente);
        $tieneHistoria = $vinculo !== null && $vinculo->numhistoria !== null;

        // Sin historia no hay representante que leer ni formulario que ofrecer: no se consulta la tabla ni
        // se le pasa una URL de guardado al ViewModel (así la vista no imprime ni un `action=` que no
        // existe). Lo que muestra el bloque en ese caso lo decide `vacio()`.
        if (! $tieneHistoria) {
            return new RepresentanteDePacienteVista(false);
        }

        $representante = $this->representantes->de($regMedico, $vinculo->numhistoria);
        $catalogo = $this->parentescos->todos();

        // **Un representante puede tener varios hijos** (2026-10-19): si la fila trae su cédula, se listan los otros
        // pacientes del consultorio que comparten esa cédula —aunque el escritorio los tenga en familias distintas—. Sin
        // cédula no hay vínculo que seguir: no se consulta nada y el bloque no muestra la lista.
        $otrosHijos = [];

        if ($representante !== null && ($cedula = trim((string) ($representante->cedula ?? ''))) !== '') {
            $otrosHijos = array_map(
                fn (array $hijo) => [
                    'nombre'   => $hijo['nombre'],
                    'historia' => $hijo['historia'],
                    // La URL la arma el controlador: el ViewModel se prueba sin contenedor de Laravel.
                    'url'      => route('clinica.pacientes.ver', $hijo['paciente_id']),
                ],
                $this->representantesPorCedula->otrosPacientes($regMedico, $cedula, $paciente),
            );
        }

        // El nombre del parentesco se resuelve acá, contra el catálogo ya leído (misma decisión que el
        // nombre del seguro en `PacienteController::ver`): el ViewModel no consulta la base. Un código que
        // no esté en el catálogo llega como `null` y el bloque muestra el código crudo.
        return new RepresentanteDePacienteVista(
            true,
            $representante,
            $this->parentescos->nombreDe($catalogo, $representante->codeparentesco ?? null),
            $catalogo,
            route('clinica.pacientes.representante', $paciente),
            $otrosHijos,
        );
    }

    /**
     * Cargar o editar el representante. Es un **upsert por historia**: el esquema real del legado declara
     * `PRIMARY KEY (numhistoria)` en `representante`, así que hay una sola fila por paciente y "cargar" y
     * "editar" son la misma operación (la Action actualiza la fila de la historia o la crea). El botón dice
     * "Cargar" o "Editar" según haya algo, pero el camino es uno.
     */
    public function guardar(Request $request, int $paciente, GuardarRepresentante $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $vinculo = $this->pacientes->vinculo($contexto['reg_medico'], $paciente);

        // Un paciente de otro consultorio ni se toca.
        abort_unless($vinculo !== null, 404);

        // Sin número de historia no hay clave con la que guardar: la ficha no ofrece el formulario, así
        // que llegar acá es un POST armado a mano. Se explica y se vuelve, sin escribir nada.
        if ($vinculo->numhistoria === null) {
            return redirect()
                ->route('clinica.pacientes.ver', $paciente)
                ->with('error', 'El representante se guarda con el número de historia del paciente. '
                    . 'Asígnale el número y después se carga.');
        }

        $datos = $request->validate([
            'nombre'         => ['nullable', 'string', 'max:40'],
            // El parentesco es **un carácter** porque es lo que mide la columna legada. No se restringe a
            // los códigos del catálogo de AppDDR a propósito: un código que subió el escritorio no se
            // rechaza —no hay forma de saber qué significa, y rechazarlo obligaría a borrar un dato para
            // guardar otro—. El catálogo decide el **nombre** que se muestra, no lo que se puede guardar.
            'codeparentesco' => ['nullable', 'string', 'max:1'],
            'cedula'         => ['nullable', 'regex:/^[0-9]{1,10}$/'],
            'telefono'       => ['nullable', 'string', 'max:20'],
            'email'          => ['nullable', 'email', 'max:100'],
            'direccion'      => ['nullable', 'string', 'max:60'],
        ], [
            'codeparentesco.max' => 'El parentesco es un solo carácter.',
            'cedula.regex'       => 'La cédula solo lleva números (hasta 10 dígitos).',
            'email.email'        => 'Revisa el correo: no tiene forma de correo.',
            'direccion.max'      => 'La dirección no puede pasar de 60 caracteres.',
        ]);

        // Un formulario sin nada escrito no es un representante: creaba una fila en blanco ("Representante
        // sin nombre") que avisaba "Se guardó" y hacía desaparecer el "Cargar el representante" de la ficha.
        // La regla es "algo que guardar", no "nombre obligatorio": el legado puede traer un representante
        // sin nombre y con un teléfono, y quien lo completa no debería inventar el nombre. El error va en
        // `nombre` porque es el primer campo del diálogo (y su clave abre el diálogo para que se lea).
        if (collect($datos)->every(fn ($valor) => trim((string) $valor) === '')) {
            throw ValidationException::withMessages([
                'nombre' => 'Escribe el nombre del representante o, al menos, un dato para contactarlo.',
            ]);
        }

        try {
            $accion->ejecutar($contexto['reg_medico'], $vinculo->numhistoria, $datos);
        } catch (InvalidArgumentException $excepcion) {
            return redirect()
                ->route('clinica.pacientes.ver', $paciente)
                ->with('error', $excepcion->getMessage());
        } catch (Throwable $excepcion) {
            // El escritorio también escribe esta tabla (la sube por la lista blanca del sync) y su clave
            // real es `numhistoria`: si justo subió la fila mientras se guardaba, el insert choca contra
            // la fila nueva. No se pierde nada —volver a guardar la actualiza— y el aviso dice qué pasó.
            Log::warning('Representante: no se pudo guardar', [
                'reg_medico' => $contexto['reg_medico'], 'paciente' => $paciente, 'error' => $excepcion->getMessage(),
            ]);

            return redirect()
                ->route('clinica.pacientes.ver', $paciente)
                ->with('error', 'No se pudo guardar el representante. Prueba otra vez.');
        }

        return redirect()
            ->route('clinica.pacientes.ver', $paciente)
            ->with('estado', 'Se guardó el representante del paciente.');
    }
}
