<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\ContextoTrabajo;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Historia\HistoriaDelPaciente;
use App\Clinica\Historia\Vistas\HistoriaDePacienteVista;
use App\Clinica\Historia\Vistas\ResumenDeHistoriaVista;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * La **historia del paciente** (WEB-3.5): sus consultas, sus antecedentes y sus documentos, con la
 * impresión del resumen. Es una pantalla de **sólo lectura**: la escritura de la consulta es F4 y la de los
 * documentos, F5.
 *
 * Controlador propio y no un método más de `PacienteController`: son dos responsabilidades distintas —la
 * ficha (filiación y contacto, que administra la secretaría) y la historia clínica (lo que pasó en las
 * consultas)—, y es la misma partición que ya hace el escritorio entre *Mantenimiento de pacientes* y la
 * consulta. El listado y la ficha siguen en `PacienteController`.
 *
 * **Quién es el paciente lo dicen los vínculos** (`medico_pacientes`), y el `numhistoria` **del consultorio
 * que se está mirando**, no la URL: un paciente de otro médico responde **404** sin confirmar que exista,
 * igual que su ficha.
 *
 * **«Ver otra historia»** (Fase 2 del cambio de `reg_medico`, 2026-10-20): un paciente del doctor puede
 * tener historia en más de uno de sus consultorios (el personal y los centros donde trabaja), y cada una
 * es **otra** historia, con su número. `?reg_medico=` elige cuál se lee, validado contra los registros del
 * doctor —nunca un consultorio ajeno—; sin él se abre la del **contexto**, que es donde se atiende. No se
 * pregunta al abrir: el selector es de sólo lectura y viene con la que corresponde.
 *
 * La **consulta** (`/clinica/consulta/{consulta}`) no cambia: es del contexto.
 */
class HistoriaDelPacienteController extends Controller
{
    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private HistoriaDelPaciente $historia,
        private MembreteDelMedico $membretes,
    ) {
    }

    public function ver(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelContexto($request);
        $elegido = $this->registroElegido($request, $contexto, $registros);

        $ficha = $this->ficha($registros, $elegido, $paciente);
        $consultas = $this->historia->consultas($elegido, $ficha->numhistoria);

        $vista = new HistoriaDePacienteVista(
            $ficha,
            $consultas,
            $this->historia->antecedentes($elegido, $ficha->numhistoria),
            $this->historia->documentos($elegido, $ficha->numhistoria, $consultas),
            // Las URLs las resuelve el controlador: el ViewModel se prueba sin contenedor.
            route('clinica.pacientes.ver', $paciente),
            route('clinica.pacientes.historia.imprimir', array_filter([
                'paciente'   => $paciente,
                'reg_medico' => $elegido === $contexto['reg_medico'] ? null : $elegido,
            ])),
            // La hoja impresa de cada récipe (WEB-5.2): sólo para quien puede abrir la consulta —el médico—.
            // La secretaría imprime desde la fila de la cita en la agenda; este enlace le daría 403.
            $request->user()?->trabajaLaConsulta()
                ? fn (int $consulta, int $numero) => route('clinica.consulta.recipes.imprimir', [$consulta, $numero])
                : null,
            // Y la de la constancia, el reposo, las referencias y el informe (WEB-5.3a), con la misma restricción.
            $request->user()?->trabajaLaConsulta()
                ? fn (int $consulta, string $clave) => route('clinica.consulta.documentos.imprimir', [$consulta, 'documento' => $clave])
                : null,
            // Y la consulta misma, con la misma restricción: el título de cada fila del listado la abre.
            $request->user()?->trabajaLaConsulta()
                ? fn (int $consulta) => route('clinica.consulta', $consulta)
                : null,
        );

        // El selector **«ver otra historia»** de sólo lectura: los consultorios del doctor donde el paciente
        // tiene historia, con su número. Sin historia en ningún lado no hay nada que elegir.
        $vista = $vista
            ->conOtrasHistorias($this->pacientes->registrosConHistoria($registros, $paciente, $elegido))
            ->conUrlOtraHistoria(fn (string $regMedico) => route('clinica.pacientes.historia', [
                'paciente'   => $paciente,
                'reg_medico' => $regMedico,
            ]));

        // **El cajón de la consulta** (O-30): la misma lectura y el mismo ViewModel que la pantalla, en un
        // fragmento sin el armazón, para inyectarlo en el panel lateral mientras el médico escribe. El permiso
        // es el de la ruta (el médico del contexto), así que no hay una segunda puerta de acceso.
        if ($request->boolean('panel')) {
            return view('clinica.historia.panel', ['vm' => $vista]);
        }

        return view('clinica.historia.ver', ['vm' => $vista]);
    }

    /**
     * El **"Resumen de historia clínica"** que imprime el navegador (PLAN-WEB.md, R6), como el listado del
     * día de la agenda: una vista sin el armazón de la web y un CSS con `@page`. No es el PDF del servidor.
     *
     * Un paciente sin historia llega igual a la página (no es un error: la pantalla principal ya lo dice):
     * la impresión muestra el encabezado y avisa que no hay consultas que resumir. Con `?reg_medico=` se
     * imprime la historia de **ese** consultorio del doctor (el mismo que se estaba mirando).
     */
    public function imprimir(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelContexto($request);
        $elegido = $this->registroElegido($request, $contexto, $registros);

        $ficha = $this->ficha($registros, $elegido, $paciente);

        return view('clinica.historia.imprimir', [
            'vm' => new ResumenDeHistoriaVista(
                $ficha,
                // La firma sigue siendo la del médico del contexto; el número y las consultas son los del
                // consultorio elegido. El **encabezado** es el del formato del consultorio de la historia (Fase 5
                // del cambio de `reg_medico`): el del centro si él lo eligió y el suyo si no.
                $this->membretes->deLaImpresion($contexto['medico'], $elegido)->medico,
                $this->historia->consultas($elegido, $ficha->numhistoria),
                // "Hoy" en la zona del consultorio, no en UTC (`FechaDelConsultorio`, WEB-2.9).
                FechaDelConsultorio::hoy(),
                // En pediatría tres columnas del examen físico son los textos de *Exámenes/Estudios* (2026-10-19).
                AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug ?? null)->esPediatria(),
                AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug ?? null)->esMedicinaInterna(),
            ),
        ]);
    }

    /**
     * La ficha del paciente **con el número de historia del consultorio elegido**, o 404 si no es visible en
     * ninguno de los registros del usuario. Es la misma lectura que usa la ficha
     * (`PacientesDelConsultorio::ficha`): un solo lugar decide de quién es un paciente y cómo se limpia su
     * número.
     *
     * @param  array<int,string>  $registros
     */
    private function ficha(array $registros, string $elegido, int $paciente): object
    {
        $ficha = $this->pacientes->ficha($registros, $paciente, $elegido);

        abort_unless($ficha !== null, 404);

        return $ficha;
    }

    /**
     * El consultorio cuya historia se está leyendo: el `?reg_medico=` **si es uno de los registros del
     * usuario** (nunca otro consultorio) y, si no, el del contexto.
     *
     * @param  array<string,mixed>  $contexto
     * @param  array<int,string>  $registros
     */
    private function registroElegido(Request $request, array $contexto, array $registros): string
    {
        $pedido = trim((string) $request->query('reg_medico', ''));

        return $pedido !== '' && in_array($pedido, $registros, true) ? $pedido : $contexto['reg_medico'];
    }

    /**
     * Los `reg_medico` que puede ver el usuario: los del doctor (el suyo y los centros donde trabaja) o, en
     * la secretaría, sólo el del centro. La regla vive en `ContextoTrabajo`.
     *
     * @return array<int,string>
     */
    private function registrosDelContexto(Request $request): array
    {
        return app(ContextoTrabajo::class)->registrosDelContexto($request->attributes->get('contexto_clinico'));
    }
}
