<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Agenda\LectorDeAgenda;
use App\Clinica\Agenda\ParametrosDeAgenda;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Vistas\InicioVista;
use App\Clinica\Impresion\DocumentosPorCita;
use App\Cumpleanos\CumpleanosDeHoy;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * La pantalla de entrada de la web clínica: **las consultas de hoy del médico** (A-12).
 *
 * La lista del día la lee `LectorDeAgenda` —el mismo armador de la agenda— con la fecha de hoy y la sede del
 * contexto, y `InicioVista` la ordena (atendidas primero) y la viste con la fila de la agenda. Acá no hay SQL ni
 * formato: el controlador sólo decide **si esta pantalla es la que corresponde** y arma el ViewModel.
 *
 * **Quién entra y quién no.** La pantalla es del médico que atiende: la secretaría no atiende pacientes, así que
 * `/clinica` la manda directo a la agenda, que es su pantalla de trabajo (y donde el menú lateral le ofrece todo
 * lo suyo). **Root** hace lo mismo: es la cuenta de administración, su lugar es `/admin` y no tiene consultas
 * propias; si entra a `/clinica` y elige un contexto, la agenda es lo primero que le sirve. Una cuenta Root que
 * además tenga el rol `Medico` sí ve el inicio del médico, porque atiende. El redirect no puede entrar en bucle:
 * la agenda no vuelve a `/clinica`.
 */
class ShellController extends Controller
{
    public function index(
        Request $request,
        LectorDeAgenda $lector,
        DocumentosPorCita $documentos,
        CumpleanosDeHoy $cumpleanos,
    ) {
        $actual = $request->attributes->get('contexto_clinico');

        if (! $request->user()->hasRole('Medico')) {
            return redirect()->route('clinica.agenda');
        }

        // Hoy y la sede del contexto, sin leer nada de la URL: esta pantalla no cambia de día ni de sede (A-12).
        // Sin sede elegida quedan las del médico, que es lo que ya hace la agenda por defecto.
        $parametros = new ParametrosDeAgenda(
            'dia',
            FechaDelConsultorio::hoyComoFecha(),
            $actual['office']->id ?? null,
            null,
        );

        $lectura = $lector->leer($parametros, $actual);

        // El aviso de cumpleaños del día (WEB-2.9): la pantalla de la que cuelga la acción no es un módulo del
        // menú, así que el inicio es lo único que lo pone delante del médico. Ahora es una línea sobre la lista.
        $cuantos = $cumpleanos->delMedico($actual['reg_medico'], FechaDelConsultorio::hoy())->count();

        return view('clinica.shell', [
            'vm' => new InicioVista(
                $lectura,
                $actual,
                $cuantos,
                (bool) $request->user()?->trabajaLaConsulta(),
                // «Imprimir documentos» de una cita atendida (WEB-5.2/5.3a): la misma lectura en lote que usa la agenda.
                $documentos->conDocumentos($actual['reg_medico'], $lectura->jornadas->flatMap(fn ($jornada) => $jornada->citas)),
            ),
        ]);
    }
}
