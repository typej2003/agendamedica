<?php

namespace App\Clinica\Vacunas;

use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Vacunas\Vistas\CalendarioDeVacunasVista;
use Closure;

/**
 * **La pantalla de vacunas de un niño, armada** (WEB-7.4.4a; O-68/O-69): la lectura de sus filas, la lista del paciente, el
 * esquema del catálogo, el catálogo para el diálogo de «Agregar una vacuna» y las URLs que necesita la pantalla, en un solo paso.
 *
 * Existe porque las vacunas se ven en **dos** lugares y tienen que ser las mismas (O-63): la pantalla del módulo
 * (`/clinica/pacientes/{paciente}/vacunas`, `VacunasDelPacienteController::ver`) y la sección *Inmunizaciones* de la consulta
 * (`?seccion=inmunizaciones`, `ConsultaController::inmunizaciones`). Si cada una armara su ViewModel, la primera corrección se
 * haría en un lado y el otro seguiría mostrando la pantalla vieja. Lo único que cambia entre las dos es la URL de cada cosa
 * —dónde vuelve un filtro, dónde se registra o se programa la dosis y cuál es el carnet—, y las pone el controlador.
 *
 * No decide nada clínico: la lectura es `VacunasDelPaciente`, las filas las arma `ListaDeVacunasDelPaciente` y el esquema,
 * `CalendarioDeVacunas`.
 */
final class CalendarioDelNino
{
    public function __construct(
        private VacunasDelPaciente $lectura,
        private CalendarioDeVacunas $calendario,
        private ListaDeVacunasDelPaciente $lista,
    ) {
    }

    /**
     * @param  object  $ficha  la fila de `pacientes` con `nombres`, `apellidos`, `sexo`, `fnacimiento` y `numhistoria`
     * @param  string  $filtro  `todas` | `pendientes` (ya normalizado por `CalendarioDeVacunasVista::normalizarFiltro`)
     * @param  Closure(string):string  $urlFiltro  arma el enlace de cada filtro
     * @param  string  $urlFicha  a dónde vuelve la pantalla del módulo (la ficha del paciente); la sección no lo usa
     * @param  string|null  $urlRegistrar  a dónde postea el registro de una dosis; `null` = pantalla de sólo lectura
     * @param  string|null  $urlCarnet  la hoja imprimible del carnet
     * @param  int|null  $consulta  la consulta abierta, cuando la pantalla es una sección de ella (O-63)
     * @param  string|null  $hoy  el día del consultorio (para las pruebas)
     * @param  string|null  $urlProgramar  a dónde postea la programación de una dosis (O-68/O-69)
     * @param  Closure(int):?string|null  $urlQuitar  arma el enlace para quitar una fila programada de la lista
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
        ?string $urlProgramar = null,
        ?Closure $urlQuitar = null,
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
            $this->lista->de($datos['aplicadas'], $datos['vacunas'], $datos['tipos'], $urlQuitar),
            $urlProgramar,
            $this->calendario->catalogo($ficha->sexo, $datos['vacunas'], $datos['dosis']),
        );
    }
}
