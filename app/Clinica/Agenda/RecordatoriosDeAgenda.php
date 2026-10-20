<?php

namespace App\Clinica\Agenda;

use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\Office;
use App\Notificaciones\MensajeDeRecordatorio;
use App\Support\FechaClinica;
use App\Support\HoraLocal;
use Illuminate\Support\Collection;

/**
 * El texto del recordatorio de cada cita, ya armado, para que la pantalla (el diálogo de una cita y el
 * envío del día) no lo resuelva fila por fila.
 *
 * Es la regla del móvil (Paso 22.B/23): una secretaría manda recordatorios de citas de varios médicos
 * del consultorio y cada uno sale con las palabras de su propio médico (`evolucion.plantilla_cita`).
 *
 * **De dónde sale el `{doctor}`** (Fase 3 del cambio de `reg_medico`, 2026-10-21): del **doctor asignado a
 * la cita** (`cola.medico_id`, que es la fuente de verdad), y su plantilla es la de su `evolucion`. Si la
 * cita no tiene doctor —la del centro, que nace a nombre del centro— queda el nombre del médico del
 * registro (el centro en un consultorio de centro) y la plantilla de la configuración del consultorio, que
 * es lo que ya hacía. **`cola.medico` ya no se usa como nombre** cuando la cita es de un centro: ahí esa
 * columna lleva el `medicos.id` del centro y podría coincidir con la clave de otro médico. Como respaldo
 * queda para las citas que sí traen una `clave` de `evolucion`, que son las que creó la web hasta la Fase 2.
 */
class RecordatoriosDeAgenda
{
    public function __construct(private MensajeDeRecordatorio $mensajes)
    {
    }

    /**
     * @param  Collection<int,CitaDeAgenda|Cola>  $citas
     * @param  Collection<int,Office>  $sedes
     * @return array<int,string>  id de cita => mensaje
     */
    public function mensajesPara(string $regMedico, Collection $citas, Collection $sedes): array
    {
        $plantillas = $this->plantillasYMedicos($regMedico);
        $centros = $this->centrosDe($sedes);

        $mensajes = [];

        foreach ($citas as $cita) {
            // El doctor de la cita, en este orden: el asignado (`medico_id`), la clave legacy si no es el
            // id del centro, y si no hay ninguno, el médico del registro (el centro o el doctor del
            // consultorio personal). La cita puede llegar como `CitaDeAgenda` (la pantalla) o como `Cola`
            // (el envío del día), así que el id se lee de las dos formas.
            $medicoId = $cita->medicoId ?? $cita->medico_id ?? null;

            $doctor = $medicoId !== null
                ? ($plantillas['porMedico'][$medicoId] ?? null)
                : ($plantillas['porClave'][(int) $cita->medico] ?? null);

            $mensajes[$cita->id] = $this->mensajes->armar(
                $doctor['plantilla'] ?? $plantillas['plantillaDelRegistro'],
                [
                    'paciente' => $cita->paciente,
                    'fecha'    => FechaClinica::formato($cita->fecha, 'd/m/Y'),
                    'hora'     => HoraLocal::hora12($cita->horaIni),
                    'doctor'   => ($doctor['nombre'] ?? '') !== '' ? $doctor['nombre'] : $plantillas['medicoDelRegistro'],
                    'centro'   => $centros[$cita->centroId ?? ''] ?? '',
                ]
            );
        }

        return $mensajes;
    }

    /**
     * La plantilla y el nombre de cada médico **que configura el consultorio**, resueltos en lote y en las
     * tres formas en que una cita los necesita:
     *
     *  - `porClave`: por la `clave` de `evolucion` (lo que guarda `cola.medico` en las citas de la web y las
     *    del legado); se saltea el `medicos.id` del centro, que no es una clave;
     *  - `porMedico`: por `medicos.id` (lo que guarda `cola.medico_id`), para el doctor asignado;
     *  - y los dos valores del registro (`medicoDelRegistro` y `plantillaDelRegistro`, WEB-8.4) para la cita
     *    que no se resuelve por ninguno de los dos.
     *
     * @return array{porClave: array<int,array{plantilla:string,nombre:string}>, porMedico: array<int,array{plantilla:string,nombre:string}>, medicoDelRegistro: string, plantillaDelRegistro: ?string}
     */
    private function plantillasYMedicos(string $regMedico): array
    {
        $evoluciones = Evolucion::where('reg_medico', $regMedico)->whereNotNull('clave')->get();
        $medicosPorCorreo = Medico::whereIn('email', $evoluciones->pluck('correo_med')->filter()->unique()->all())
            ->get()
            ->keyBy('email');

        $centroId = (int) (Medico::where('reg_medico', $regMedico)->where('es_centro', true)->value('id') ?? 0);
        $porClave = [];
        $porMedico = [];

        foreach ($evoluciones as $evolucion) {
            $clave = (int) $evolucion->clave;
            $medico = $evolucion->correo_med ? $medicosPorCorreo->get($evolucion->correo_med) : null;
            $entrada = [
                'plantilla' => (string) $evolucion->plantilla_cita,
                'nombre'    => $medico?->nombreMostrar ?? '',
            ];

            if ($clave !== $centroId) {
                $porClave[$clave] = $entrada;
            }

            if ($medico !== null) {
                $porMedico[(int) $medico->id] = $entrada;
            }
        }

        return [
            'porClave'             => $porClave,
            'porMedico'            => $porMedico,
            'medicoDelRegistro'    => (string) (Medico::where('reg_medico', $regMedico)->orderBy('id')->first()?->nombreMostrar ?? ''),
            'plantillaDelRegistro' => $evoluciones->sortBy('id')->first()?->plantilla_cita,
        ];
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
