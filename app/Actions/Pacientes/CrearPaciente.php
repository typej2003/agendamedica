<?php

namespace App\Actions\Pacientes;

use App\Clinica\FechaDelConsultorio;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Alta de un paciente: la ficha compartida (`pacientes`) y su vínculo con el consultorio
 * (`medico_pacientes`).
 *
 * La regla vive **acá y en un solo lugar**, y la usan las dos superficies: la web (la secretaría
 * cargando la cita) y el sync del móvil (una creación de `pacientes`), igual que las Actions de
 * agenda (PLAN-WEB.md, R3).
 *
 * Cinco cosas que no son obvias y **son** la regla:
 *
 *  - **Un paciente es uno solo para todos los consultorios** (`pacientes`); lo que cambia por
 *    consultorio es el **vínculo** (`medico_pacientes`), que desde la Fase 1 del cambio de `reg_medico`
 *    es **uno por paciente y tenant** (`UNIQUE (reg_medico, paciente_id)`), no uno por médico. Si la
 *    cédula ya existe, se **enlaza**: no se duplica la ficha (es la clave con la que el legado reconoce
 *    a la misma persona entre consultorios).
 *  - Al enlazar, **solo se completan los campos vacíos** de la ficha ajena. Pisarla con lo que
 *    escribió la secretaría sería perder información de otro médico.
 *  - **`pacientes.reg_medico` se completa sólo al crear la ficha**: es el tenant del **primer**
 *    vínculo del paciente y el primero gana. Al enlazar por cédula una ficha que ya existía no se toca
 *    (esa ficha ya es de otro consultorio y su `reg_medico` no cambia porque alguien la enlace).
 *  - **El vínculo se escribe por `(reg_medico, paciente_id)`**, no por `medico_id`: si el paciente ya
 *    estaba vinculado a este consultorio —con el `medico_id` de otro doctor o del centro— la fila se
 *    conserva tal cual (con su `medico_id` y su `numhistoria`) y no se duplica.
 *  - **No se crea `historia`**: el `numhistoria` lo asigna el API cuando la historia se completa
 *    (decisión del 2026-10-09), y hasta entonces el vínculo queda con `numhistoria` nulo y la cita
 *    se ancla por `cola.paciente_sinhistoria_id`.
 *  - **"Sin cédula" no enlaza** (`IdentidadDePaciente::esSinCedula`): el vacío y el marcador `S.C.`
 *    del alta no identifican a nadie, así que un paciente sin documento siempre es una ficha nueva.
 *    Antes, dos altas sin cédula compartían la misma ficha (las dos buscaban `cedula = ''`).
 *  - **Un paciente nuevo nace con la fecha de ingreso de hoy** si quien da el alta no la indicó
 *    (O-02b): el alta mínima de la cita no tiene ese campo y sin él el paciente no aparece en el
 *    listado «pacientes por rango». Es sólo del alta: una ficha enlazada por cédula no se toca.
 */
final class CrearPaciente
{
    /**
     * @param  array<string,mixed>  $columnas  Columnas ya validadas de `pacientes`. El nombre y el apellido
     *                                         son obligatorios; la cédula puede venir nula, que es la
     *                                         opción "sin cédula" (`S.C.`) del alta.
     * @param  string  $regMedico  El consultorio (tenant) con el que queda vinculado (el del contexto en
     *                             la web, el del médico autenticado en el sync).
     *
     * @throws InvalidArgumentException si falta el nombre o el apellido.
     */
    public function ejecutar(array $columnas, Medico $medico, string $regMedico): Paciente
    {
        // El nombre y el apellido son lo único obligatorio del alta (wiki `04-historia-clinica.md`): la
        // cédula **no**, porque "sin cédula" es una opción explícita. Antes esto exigía la cédula y
        // rechazaba a los pacientes sin documento que la web ya deja dar de alta.
        if (trim((string) ($columnas['nombres'] ?? '')) === '' || trim((string) ($columnas['apellidos'] ?? '')) === '') {
            throw new InvalidArgumentException('El nombre y el apellido son obligatorios para dar de alta un paciente.');
        }

        return DB::transaction(function () use ($columnas, $medico, $regMedico) {
            $cedula = trim((string) ($columnas['cedula'] ?? ''));

            // "Sin cédula" (el marcador `S.C.` del alta o el vacío) **no identifica a nadie**: buscar por
            // él enlazaría a dos pacientes distintos en la misma ficha. Sin documento, ficha nueva.
            $paciente = IdentidadDePaciente::esSinCedula($cedula)
                ? null
                : Paciente::where('cedula', $cedula)->first();

            if ($paciente) {
                $completar = [];
                foreach ($columnas as $columna => $valor) {
                    if ($valor !== null && $paciente->{$columna} === null) {
                        $completar[$columna] = $valor;
                    }
                }
                if ($completar !== []) {
                    $paciente->fill($completar)->save();
                }
            } else {
                // **La fecha de ingreso de un paciente nuevo** (O-02b, 2026-10-17): si quien da el alta no
                // la indicó —es el caso del alta mínima del paso 2 de «Nueva cita», que no tiene el
                // campo—, nace con **hoy**, que es el día del consultorio y no el del servidor
                // (`FechaDelConsultorio`). Sin ella el paciente no aparece en «pacientes por rango».
                //
                // Sólo en el alta: al **enlazar por cédula** una ficha que ya existía no se la toca, aunque
                // estuviera vacía —estampar hoy una ficha vieja sería un dato falso—; esa rama es la de
                // arriba y sigue con la regla de «sólo completa vacíos». Y `array_key_exists` (no `??=`)
                // respeta el alta que manda la fecha en blanco a propósito: manda el formulario.
                if (! array_key_exists('fingreso', $columnas)) {
                    $columnas['fingreso'] = FechaDelConsultorio::hoy();
                }

                // **El tenant del paciente es el de su primer vínculo** (Fase 1 del cambio de
                // `reg_medico`). Sólo se escribe al crear la ficha: al enlazar por cédula, la ficha ya
                // es de otro consultorio y su `reg_medico` no se toca.
                $columnas['reg_medico'] = $regMedico;

                $paciente = Paciente::create($columnas);
            }

            // El vínculo es **uno por paciente y consultorio**. Si ya existía (lo creó el escritorio, la
            // web o el propio centro) se deja como está: su `medico_id` —el doctor que lo vinculó o el
            // centro— y su `numhistoria` son los que valen, y pisarlos cambiaría de quién es el vínculo.
            MedicoPaciente::firstOrCreate(
                ['reg_medico' => $regMedico, 'paciente_id' => $paciente->id],
                ['medico_id' => $medico->id, 'numhistoria' => null],
            );

            return $paciente;
        });
    }
}
