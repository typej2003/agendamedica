<?php

namespace App\Clinica\Catalogos;

use App\Actions\Catalogos\ReglasDelMotivoDeCita;
use App\Models\MotivoCita;

/**
 * **Los motivos de cita que una especialidad sugiere** (WEB-7.4.4f): pediatría sugiere «Control de niño sano», el acceso rápido a la consulta de
 * niño sano (la consulta con *Mediciones*, el cuestionario, la alimentación, el desarrollo y las vacunas; el legado **no** trae un calendario de
 * controles propio: pregunta 9 de `preguntas-para-alexander.md`).
 *
 * **No se siembran solos.** El catálogo de motivos de cita es de cada médico (con su precio y su sede), así que el sistema sólo **ofrece** agregarlos
 * —un botón en `/clinica/catalogos/motivos-cita`— y el médico decide. Lo que ya tiene (con otras mayúsculas o tildes) no se vuelve a ofrecer.
 */
final class MotivosDeCitaSugeridos
{
    /** `fragmento del slug de la especialidad => razones sugeridas`. */
    public const POR_ESPECIALIDAD = [
        'pediatr' => ['Control de niño sano'],
    ];

    /**
     * Las razones sugeridas para esa especialidad que el médico todavía no tiene (cualquier sede).
     *
     * @return array<int,string>
     */
    public function faltantes(string $regMedico, ?string $slugDeLaEspecialidad): array
    {
        $sugeridas = self::sugeridas($slugDeLaEspecialidad);

        if ($sugeridas === []) {
            return [];
        }

        $tiene = MotivoCita::where('reg_medico', $regMedico)->pluck('tipo_atencion')
            ->map(fn ($razon) => ReglasDelMotivoDeCita::clave($razon))->all();

        return array_values(array_filter($sugeridas, fn ($razon) => ! in_array(ReglasDelMotivoDeCita::clave($razon), $tiene, true)));
    }

    /** @return array<int,string> */
    public static function sugeridas(?string $slugDeLaEspecialidad): array
    {
        foreach (self::POR_ESPECIALIDAD as $fragmento => $razones) {
            if (str_contains((string) $slugDeLaEspecialidad, $fragmento)) {
                return $razones;
            }
        }

        return [];
    }
}
