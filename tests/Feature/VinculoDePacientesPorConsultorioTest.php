<?php

namespace Tests\Feature;

use App\Actions\Pacientes\CrearPaciente;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * **El vínculo paciente ↔ consultorio es del tenant** (Fase 1 del cambio de `reg_medico`).
 *
 * Lo que fija, y que antes no era así:
 *
 *  - `medico_pacientes` tiene **una sola fila por `(reg_medico, paciente_id)`**: el mismo paciente puede
 *    estar en dos consultorios (dos filas), pero no dos veces en el mismo. La unicidad vieja
 *    `(medico_id, paciente_id)` ya no existe: dos consultorios del **mismo** médico son dos vínculos.
 *  - `medico_id` sigue existiendo y sigue diciendo **quién lo creó** (el doctor o el centro): no es la
 *    clave del vínculo y, cuando el paciente ya estaba vinculado a ese consultorio, no se pisa.
 *  - `pacientes.reg_medico` es el tenant del **primer** vínculo: se completa al crear la ficha y no se
 *    toca al enlazar por cédula una ficha que ya existía.
 *  - El sync viejo, cuando el payload **no trae `reg_medico`**, lo deriva del `medico_id` (la ficha del
 *    médico) y hace **upsert** del vínculo: dos envíos no duplican ni rompen.
 */
class VinculoDePacientesPorConsultorioTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(string $prefijo, bool $centro = false): Medico
    {
        return Medico::create([
            'name'          => $prefijo,
            'lastname'      => 'De Prueba',
            'reg_medico'    => 'test-vc-' . $prefijo . '-' . substr(uniqid(), -6),
            'es_centro'     => $centro,
        ]);
    }

    private function paciente(?string $cedula = null): Paciente
    {
        return Paciente::create([
            'cedula'    => $cedula ?? (string) random_int(10000000, 99999999),
            'nombres'   => 'Paciente',
            'apellidos' => 'De Prueba',
        ]);
    }

    public function test_el_vinculo_es_unico_por_consultorio_y_no_por_medico(): void
    {
        $medico = $this->medico('uno');
        $paciente = $this->paciente();

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => '5',
        ]);

        // La misma fila otra vez (mismo paciente y consultorio): la clave única lo impide.
        $this->expectException(QueryException::class);

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => '6',
        ]);
    }

    public function test_el_mismo_medico_puede_tener_al_paciente_en_dos_consultorios(): void
    {
        $medico = $this->medico('dos');
        $paciente = $this->paciente();

        // Las dos filas tienen el MISMO `medico_id`: lo que cambia es el consultorio. Con la unicidad vieja
        // `(medico_id, paciente_id)` esto era imposible (por eso `asignarRegistro` copiaba y chocaba).
        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => '1',
        ]);
        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico'  => $medico->reg_medico . '-b',
            'numhistoria' => '9',
        ]);

        $this->assertSame(2, MedicoPaciente::where('paciente_id', $paciente->id)->count());
        $this->assertSame(['1', '9'], MedicoPaciente::where('paciente_id', $paciente->id)
            ->orderBy('id')->pluck('numhistoria')->all());
    }

    public function test_crear_paciente_deja_el_reg_medico_del_primer_vinculo(): void
    {
        $primero = $this->medico('primero');
        $segundo = $this->medico('segundo');
        $accion = app(CrearPaciente::class);

        // El alta en el primer consultorio: la ficha nace con su tenant.
        $paciente = $accion->ejecutar([
            'cedula'    => 'VC-' . substr(uniqid(), -6),
            'nombres'   => 'Nuevo',
            'apellidos' => 'Paciente',
        ], $primero, $primero->reg_medico);

        $this->assertSame($primero->reg_medico, $paciente->fresh()->reg_medico);
        $this->assertSame($primero->id, (int) MedicoPaciente::where('reg_medico', $primero->reg_medico)
            ->where('paciente_id', $paciente->id)->value('medico_id'));

        // El segundo consultorio enlaza **la misma ficha** (por cédula) y suma su vínculo, pero el
        // `reg_medico` del paciente **no se pisa**: el primero gana.
        $enlazado = $accion->ejecutar([
            'cedula'    => $paciente->cedula,
            'nombres'   => 'Otro',
            'apellidos' => 'Nombre',
        ], $segundo, $segundo->reg_medico);

        $this->assertSame($paciente->id, $enlazado->id);
        $this->assertSame($primero->reg_medico, $enlazado->fresh()->reg_medico);
        $this->assertSame(2, MedicoPaciente::where('paciente_id', $paciente->id)->count());
        $this->assertSame(
            [$primero->reg_medico, $segundo->reg_medico],
            MedicoPaciente::where('paciente_id', $paciente->id)->orderBy('id')->pluck('reg_medico')->all()
        );
    }

    public function test_crear_paciente_no_duplica_el_vinculo_del_mismo_consultorio(): void
    {
        $medico = $this->medico('repetido');
        $centro = $this->medico('centro', true);
        $cedula = 'VC-' . substr(uniqid(), -6);

        // El vínculo ya existe y lo creó el **centro**: el alta del médico no lo duplica ni le pisa el
        // `medico_id` (el vínculo es del consultorio).
        $paciente = $this->paciente($cedula);
        MedicoPaciente::create([
            'medico_id'   => $centro->id,
            'paciente_id' => $paciente->id,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => '7001',
        ]);

        $accion = app(CrearPaciente::class);
        $accion->ejecutar([
            'cedula'    => $cedula,
            'nombres'   => 'Paciente',
            'apellidos' => 'De Prueba',
        ], $medico, $medico->reg_medico);
        $accion->ejecutar([
            'cedula'    => $cedula,
            'nombres'   => 'Paciente',
            'apellidos' => 'De Prueba',
        ], $medico, $medico->reg_medico);

        $this->assertSame(1, MedicoPaciente::where('paciente_id', $paciente->id)->count());
        $this->assertSame($centro->id, (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('medico_id'));
        $this->assertSame('7001', MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria'));
    }

    /**
     * **El app viejo**: el payload del sync no trae `reg_medico`, sólo el `medico_id`. El consultorio se
     * deriva de la ficha de ese médico (`medicos.reg_medico`) y el vínculo se escribe con **upsert**: dos
     * envíos del mismo paciente no duplican la fila ni rompen la clave única, y el `medico_id` que ya
     * tenía el vínculo se conserva.
     */
    public function test_el_sync_viejo_deriva_el_reg_medico_del_medico_id_y_hace_upsert(): void
    {
        $medico = $this->medico('sync');
        $centro = $this->medico('sincrocentro', true);
        $cedula = 'VC-' . substr(uniqid(), -6);

        // El paciente ya está vinculado a ese consultorio, con el `medico_id` del centro.
        $paciente = $this->paciente($cedula);
        MedicoPaciente::create([
            'medico_id'   => $centro->id,
            'paciente_id' => $paciente->id,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => null,
        ]);

        $enviar = fn (string $numhistoria) => $this->withHeader('X-API-KEY', (string) config('app.sync_api_key'))
            ->postJson('/api/pacientes/sincronizar', [
                // **Sin `reg_medico`**: sólo el médico, como lo manda el escritorio viejo.
                'pacientes' => [[
                    'medico_id'   => $medico->id,
                    'cedula'      => $cedula,
                    'nombres'     => 'Sincronizado',
                    'apellidos'   => 'Desde el escritorio',
                    'numhistoria' => $numhistoria,
                ]],
            ]);

        $enviar('77')->assertOk()->assertJson(['success' => true, 'procesados' => 1]);

        $vinculo = MedicoPaciente::where('paciente_id', $paciente->id)->get();
        $this->assertCount(1, $vinculo, 'el vínculo es uno por paciente y consultorio');
        $this->assertSame($medico->reg_medico, $vinculo->first()->reg_medico, 'el consultorio se derivó del medico_id');
        $this->assertSame($centro->id, (int) $vinculo->first()->medico_id, 'el medico_id del vínculo existente se conserva');
        $this->assertSame('77', $vinculo->first()->numhistoria);

        // La historia también es del consultorio, no una por médico.
        $this->assertSame('77', Historia::where('reg_medico', $medico->reg_medico)
            ->where('paciente_id', $paciente->id)->value('numhistoria'));

        // El mismo paciente, otra vez: no duplica ni rompe.
        $enviar('78')->assertOk()->assertJson(['success' => true, 'procesados' => 1]);

        $this->assertCount(1, MedicoPaciente::where('paciente_id', $paciente->id)->get());
        $this->assertSame('78', MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria'));
        $this->assertSame(1, Historia::where('reg_medico', $medico->reg_medico)->where('paciente_id', $paciente->id)->count());
        $this->assertSame(1, Paciente::where('cedula', $cedula)->count());
    }
}
