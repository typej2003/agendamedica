<?php

namespace App\Clinica\Agenda;

use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\Office;
use App\Notificaciones\MensajeDeRecordatorio;
use App\Support\FechaClinica;
use Illuminate\Support\Collection;

/**
 * El texto del recordatorio de cada cita, ya armado, para que la pantalla (el diálogo de una cita y el
 * envío del día) no lo resuelva fila por fila.
 *
 * Es la regla del móvil (Paso 22.B/23): una secretaría manda recordatorios de citas de varios médicos
 * del consultorio y cada uno sale con las palabras de su propio médico (`evolucion.plantilla_cita`,
 * por `evolucion.clave` = `cola.medico`). Se resuelve en lote porque la agenda de un día tiene muchas
 * filas y la configuración es una por médico.
 */
class RecordatoriosDeAgenda
{
    public function __construct(private MensajeDeRecordatorio $mensajes)
    {
    }

    /**
     * @param  Collection<int,CitaDeAgenda>  $citas
     * @param  Collection<int,Office>  $sedes
     * @return array<int,string>  id de cita => mensaje
     */
    public function mensajesPara(string $regMedico, Collection $citas, Collection $sedes): array
    {
        [$plantillas, $nombres, $medicoPorDefecto] = $this->plantillasYMedicos($regMedico);
        $centros = $this->centrosDe($sedes);

        $mensajes = [];

        foreach ($citas as $cita) {
            $mensajes[$cita->id] = $this->mensajes->armar($plantillas[$cita->medico ?? ''] ?? null, [
                'paciente' => $cita->paciente,
                'fecha'    => FechaClinica::formato($cita->fecha, 'd/m/Y'),
                'hora'     => $cita->horaIni ? substr($cita->horaIni, 0, 5) : '',
                'doctor'   => $nombres[$cita->medico ?? ''] ?? (string) $medicoPorDefecto,
                'centro'   => $centros[$cita->centroId ?? ''] ?? '',
            ]);
        }

        return $mensajes;
    }

    /**
     * La plantilla y el nombre del **médico de cada cita**, por `evolucion.clave` = `cola.medico`.
     *
     * @return array{0: array<int,string>, 1: array<int,string>, 2: ?string}
     *         plantilla por clave, nombre por clave, y el nombre del médico del registro — para las
     *         citas cuya `clave` no está en `evolucion`, que es lo común en los datos reales.
     */
    private function plantillasYMedicos(string $regMedico): array
    {
        $evoluciones = Evolucion::where('reg_medico', $regMedico)->whereNotNull('clave')->get();
        $medicosPorCorreo = Medico::whereIn('email', $evoluciones->pluck('correo_med')->filter()->unique()->all())
            ->get()
            ->keyBy('email');

        $plantillas = [];
        $nombres = [];

        foreach ($evoluciones as $evolucion) {
            $clave = (int) $evolucion->clave;
            $medico = $evolucion->correo_med ? $medicosPorCorreo->get($evolucion->correo_med) : null;

            $plantillas[$clave] = (string) $evolucion->plantilla_cita;
            $nombres[$clave] = $medico?->nombreMostrar ?? '';
        }

        return [$plantillas, $nombres, Medico::where('reg_medico', $regMedico)->first()?->nombreMostrar];
    }

    /** @return array<int,string> `medical_center_id` => nombre, para la etiqueta `{centro}`. */
    private function centrosDe(Collection $sedes): array
    {
        return $sedes
            ->mapWithKeys(fn (Office $sede) => [
                (int) $sede->medical_center_id => (string) ($sede->medicalCenter->name ?? ''),
            ])
            ->all();
    }
}
