<?php

namespace App\Clinica\Vacunas;

use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Vacunas\Vistas\CalendarioDeVacunasVista;
use Closure;

/**
 * **El calendario de vacunas de un niño, armado** (WEB-7.4.4a): la lectura de sus dosis, el calendario ya calculado y las URLs
 * que necesita la pantalla, en un solo paso.
 *
 * Existe porque el calendario se ve en **dos** lugares y tiene que ser el mismo (O-63): la pantalla del módulo
 * (`/clinica/pacientes/{paciente}/vacunas`, `VacunasDelPacienteController::ver`) y la sección *Inmunizaciones* de la consulta
 * (`?seccion=inmunizaciones`, `ConsultaController::inmunizaciones`). Si cada una armara su ViewModel, la primera corrección se
 * haría en un lado y el otro seguiría mostrando el calendario viejo. Lo único que cambia entre las dos es la URL de cada cosa
 * —dónde vuelve un filtro, dónde se registra la dosis y cuál es el carnet—, y las pone el controlador.
 *
 * No decide nada clínico: la lectura es `VacunasDelPaciente` y el cálculo, `CalendarioDeVacunas`.
 */
final class CalendarioDelNino
{
    public function __construct(
        private VacunasDelPaciente $lectura,
        private CalendarioDeVacunas $calendario,
    ) {
    }

    /**
     * @param  object  $ficha  la fila de `pacientes` con `nombres`, `apellidos`, `sexo`, `fnacimiento` y `numhistoria`
     * @param  string  $filtro  `todas` | `pendientes` (ya normalizado por `CalendarioDeVacunasVista::normalizarFiltro`)
     * @param  Closure(string):string  $urlFiltro  arma el enlace de cada filtro
     * @param  string  $urlFicha  a dónde vuelve la pantalla del módulo (la ficha del paciente); la sección no lo usa
     * @param  string|null  $urlRegistrar  a dónde postea el registro de una dosis; `null` = calendario de sólo lectura
     * @param  string|null  $urlCarnet  la hoja imprimible del carnet
     * @param  int|null  $consulta  la consulta abierta, cuando el calendario es una sección de ella (O-63)
     */
    public function de(
        string $regMedico,
        object $ficha,
        string $filtro,
        Closure $urlFiltro,
        string $urlFicha,
        ?string $urlRegistrar,
        ?string $urlCarnet,
        ?int $consulta = null,
        ?string $hoy = null,
    ): CalendarioDeVacunasVista {
        $datos = $this->lectura->de($regMedico, CamposDePaciente::historiaNumerica($ficha->numhistoria));
        $hoy = $hoy ?? FechaDelConsultorio::hoy()->toDateString();

        return new CalendarioDeVacunasVista(
            $ficha,
            $this->calendario->armar(
                $ficha->fnacimiento ? (string) $ficha->fnacimiento : null,
                $ficha->sexo,
                $hoy,
                $datos['vacunas'],
                $datos['dosis'],
                $datos['aplicadas'],
            ),
            count($datos['vacunas']),
            $filtro,
            $urlFiltro,
            $urlFicha,
            $urlRegistrar,
            $hoy,
            $urlCarnet,
            $consulta,
        );
    }
}
