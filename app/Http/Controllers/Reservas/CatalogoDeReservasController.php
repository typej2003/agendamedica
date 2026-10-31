<?php

namespace App\Http\Controllers\Reservas;

use App\Http\Controllers\Controller;
use App\Models\Medico;
use App\Models\MotivoCita;
use App\Reservas\CatalogoDeMedicos;
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

    public function medico(Medico $medico, CatalogoDeMedicos $catalogo, DisponibilidadPublica $disponibilidad)
    {
        abort_unless($medico->is_active, 404);

        return view('reservas.medico', [
            'medico' => $medico,
            'especialidades' => $medico->specialties()->where('activo', true)->orderBy('name')->get(),
            'sedes' => $catalogo->sedes($medico),
            'opciones' => $disponibilidad->opciones($medico),
            // El motivo con precio es lo que le da precio a la cita y, con el porcentaje de «Cita
            // Online», el monto que se cobra al reservar. Sin motivos con precio la reserva sigue igual,
            // sin cobro. El servidor vuelve a validar el motivo contra la sede elegida.
            'motivos' => MotivoCita::where('reg_medico', $medico->regMedicoPrincipal())
                ->whereNotNull('precio')
                ->where('precio', '>', 0)
                ->orderBy('tipo_atencion')
                ->get()
                ->unique('codigo')
                ->values(),
        ]);
    }
}
