<?php

namespace App\Http\Controllers\Reservas;

use App\Clinica\CitaOnline\ConfiguracionDeCitaOnline;
use App\Clinica\FechaDelConsultorio;
use App\Http\Controllers\Controller;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MotivoCita;
use App\Reservas\CatalogoDeMedicos;
use App\Reservas\CalendarioDeReservas;
use App\Reservas\DisponibilidadPublica;
use Illuminate\Http\Request;

/**
 * El catálogo público de médicos con agenda publicada y la ficha de cada uno con sus próximos horarios.
 *
 * No hay sesión ni cuenta de paciente: es la puerta de entrada para reservar. El único estado que se
 * guarda es el de la reserva en curso, y eso vive en `ReservaPendiente` (sesión) hasta que el código se
 * confirma.
 */
class CatalogoDeReservasController extends Controller
{
    public function index(Request $request, CatalogoDeMedicos $catalogo)
    {
        // El comprobante es de la reserva anterior: al volver al catálogo se descarta, para que otra
        // persona que use el mismo navegador no lo vea.
        $request->session()->forget('reservas.confirmada');

        $especialidad = trim((string) $request->query('especialidad', ''));
        $buscar = trim((string) $request->query('buscar', ''));
        $medicos = $catalogo->medicos($especialidad !== '' ? $especialidad : null, $buscar);

        return view('reservas.catalogo', [
            'medicos' => $medicos,
            // Los nombres de las sedes de cada médico, ya resueltos por el mismo catálogo (que guarda en
            // memoria las sedes por médico): la tarjeta no tiene que consultar nada.
            'sedes' => $medicos->mapWithKeys(
                fn ($medico) => [$medico->id => $catalogo->sedes($medico)->pluck('name')->take(2)->all()],
            ),
            'especialidades' => $catalogo->especialidades(),
            'especialidad' => $especialidad,
            'buscar' => $buscar,
        ]);
    }

    public function medico(Request $request, Medico $medico, CatalogoDeMedicos $catalogo, DisponibilidadPublica $disponibilidad, CalendarioDeReservas $calendario)
    {
        abort_unless($medico->is_active, 404);

        // El calendario del mes que se está mirando (O-52) y el día elegido, si se eligió uno. La agenda se
        // resuelve una sola vez para el mes visible: los días marcados y los horarios salen del mismo mapa.
        $mes = $calendario->mes($request->query('mes'));
        $agenda = $disponibilidad->agendaDelRango(
            $medico,
            $calendario->diasDelMes($mes),
            $calendario->primerDiaVisible($mes),
        );

        $fecha = $calendario->fechaElegida($request->query('fecha'), $agenda);

        // Los horarios salen del **mismo mapa** que el calendario: si se eligió un día, los suyos; si no, los
        // próximos de este mes. Sólo cuando el mes visible quedó sin lugar se mira más allá, con el camino de
        // siempre (que busca hasta 30 días), para no decir «no hay horarios» con el mes que viene vacío.
        $opciones = $fecha !== null
            ? $agenda[$fecha]
            : ($this->primerasDelMapa($agenda, 5) ?: $disponibilidad->opciones($medico));

        $motivos = MotivoCita::where('reg_medico', $medico->regMedicoPrincipal())
            ->whereNotNull('precio')
            ->where('precio', '>', 0)
            ->orderBy('tipo_atencion')
            ->get()
            ->unique('codigo')
            ->values();

        return view('reservas.medico', [
            'medico' => $medico,
            'especialidades' => $medico->specialties()->where('activo', true)->orderBy('name')->get(),
            'sedes' => $catalogo->sedes($medico),
            'opciones' => $opciones,
            // Lo que el médico quiere que el paciente sepa antes de venir (O-52): minutos antes,
            // acompañantes e indicaciones. Sin configurar no se muestra el bloque.
            'citaLinea' => ConfiguracionDeCitaOnline::de(
                Evolucion::where('reg_medico', $medico->regMedicoPrincipal())->first(),
            ),
            'calendario' => $calendario->rejilla($mes, $agenda, $fecha),
            'mes' => $mes,
            'fechaElegida' => $fecha,
            // El motivo con precio es lo que le da precio a la cita y, con el porcentaje de «Cita
            // Online», el monto que se cobra al reservar. Sin motivos con precio la reserva sigue igual,
            // sin cobro. El servidor vuelve a validar el motivo contra la sede elegida.
            'motivos' => $motivos,
            // **El link que compartió el consultorio** (O-52): si viene firmado por nosotros, la ficha abre con
            // el horario y el motivo ya elegidos. Un link vencido o manipulado no impone nada: se abre la ficha
            // normal y se avisa que el enlace ya no sirve.
            'enlace' => $this->enlaceCompartido($request, $opciones, $motivos),
        ]);
    }

    /**
     * **Lo que el link firmado pide pre-elegir** (O-52), o `null` si la visita es normal.
     *
     * La firma es la de `URL::temporarySignedRoute` (`EnlaceDeCitaCompartida`): sin firma válida los parámetros
     * **no se leen**, y un motivo que no es del catálogo del médico se descarta igual que un horario que ya no
     * existe. Nada de esto da permisos: el paciente confirma con su código y el cupo se vuelve a comprobar.
     *
     * @param  array<int,\App\Reservas\OpcionDeReserva>  $opciones
     * @param  \Illuminate\Support\Collection<int,\App\Models\MotivoCita>  $motivos
     * @return array{valido:bool, vencido:bool, fecha:?string, hora:?string, motivo:?string}|null
     */
    private function enlaceCompartido(Request $request, array $opciones, $motivos): ?array
    {
        $traeFirma = $request->has('signature');

        if (! $request->hasValidSignature()) {
            return $traeFirma ? ['valido' => false, 'vencido' => true, 'fecha' => null, 'hora' => null, 'motivo' => null] : null;
        }

        $fecha = trim((string) $request->query('fecha', ''));
        $hora = trim((string) $request->query('hora', ''));
        $motivo = trim((string) $request->query('motivo', ''));

        // El horario se acepta sólo si es uno de los que la ficha está mostrando: si la agenda cambió, no se
        // marca ninguno y el paciente ve los que hay.
        $existe = $fecha !== '' && $hora !== '';
        $existe = $existe && collect($opciones)->contains(
            fn ($opcion) => $opcion->fecha === $fecha && $opcion->hora === $hora,
        );

        return [
            'valido' => true,
            'vencido' => false,
            'fecha' => $existe ? $fecha : null,
            'hora' => $existe ? $hora : null,
            'motivo' => $motivo !== '' && $motivos->contains(fn ($delCatalogo) => (string) $delCatalogo->codigo === $motivo)
                ? $motivo
                : null,
        ];
    }

    /**
     * Las primeras `$cantidad` opciones del mapa del mes, en orden de fecha (el mapa ya viene en ese orden
     * porque se armó día por día).
     *
     * @param  array<string,array<int,\App\Reservas\OpcionDeReserva>>  $agenda
     * @return array<int,\App\Reservas\OpcionDeReserva>
     */
    private function primerasDelMapa(array $agenda, int $cantidad): array
    {
        $opciones = [];

        foreach ($agenda as $delDia) {
            foreach ($delDia as $opcion) {
                $opciones[] = $opcion;

                if (count($opciones) >= $cantidad) {
                    return $opciones;
                }
            }
        }

        return $opciones;
    }
}
