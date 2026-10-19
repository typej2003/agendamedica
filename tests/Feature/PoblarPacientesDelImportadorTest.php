<?php

namespace Tests\Feature;

use App\Actions\Pacientes\PoblarPacientesDelImportador;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * **El importador de SQL del `/admin` no puede dejar dos pacientes con la misma historia.**
 *
 * El panel carga un dump del legado y después puebla `medico_pacientes` e `historias`. Escribía el vínculo
 * **antes** que la historia: con un número que ya era de otro paciente del mismo consultorio, la clave
 * única de `historias` cortaba el proceso y el vínculo quedaba escrito —dos pacientes con la misma
 * historia y ninguna pantalla capaz de decir cuál es el bueno (informe del usuario, 2026-10-19).
 *
 * Ahora la regla mira antes de escribir: el número ajeno se **omite** y se informa, y el paciente queda
 * sin número (una salida real: la ficha le asigna uno con `AsignarHistoria`).
 *
 * El número que usa el importador es `pacientes.numhistoria` cuando la base lo tiene (las armadas con el
 * dump) y, si no, el `id` del paciente. La base del repo no tiene esa columna, así que los tests trabajan
 * con el camino del `id`: el paciente del dump choca si **su `id`** ya es el número de otro.
 */
class PoblarPacientesDelImportadorTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(): Medico
    {
        $regMedico = 'import-' . substr(uniqid(), -6);

        $medico = Medico::create([
            'name'       => 'Doctor',
            'lastname'   => 'Importador',
            'reg_medico' => $regMedico,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function paciente(): Paciente
    {
        return Paciente::create([
            'cedula'    => (string) random_int(10000000, 99999999),
            'nombres'   => 'Paciente',
            'apellidos' => 'Del dump',
        ]);
    }

    /** Deja al paciente vinculado y con su historia en `$numero`. */
    private function conHistoria(Medico $medico, Paciente $paciente, string $numero): void
    {
        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'numhistoria' => $numero,
            'reg_medico'  => $medico->reg_medico,
        ]);

        Historia::create([
            'numhistoria' => $numero,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $paciente->id,
            'medico_id'   => $medico->id,
        ]);
    }

    public function test_omite_al_paciente_cuyo_numero_ya_es_de_otro_y_no_toca_nada(): void
    {
        $medico = $this->medico();

        // El que choca con el dump: su `id` es el número que el importador le va a querer dar.
        $chocador = $this->paciente();
        $numero = (string) $chocador->id;

        // Y ese número ya es de otro paciente del mismo consultorio (el chamo importado, el real).
        $duenio = $this->paciente();
        $this->conHistoria($medico, $duenio, $numero);

        $resultado = app(PoblarPacientesDelImportador::class)
            ->ejecutar($medico->id, $medico->reg_medico, Paciente::whereIn('id', [$chocador->id])->get());

        // No se escribe nada para el que choca: ni vínculo ni historia.
        $this->assertNull(MedicoPaciente::where('paciente_id', $chocador->id)->value('id'));
        $this->assertSame(0, Historia::where('paciente_id', $chocador->id)->count());

        // Y se informa con el número y el dueño, para poder repararlo.
        $this->assertCount(1, $resultado['omitidos']);
        $this->assertSame($numero, $resultado['omitidos'][0]['numhistoria']);
        $this->assertSame((int) $chocador->id, $resultado['omitidos'][0]['paciente_id']);
        $this->assertStringContainsString((string) $duenio->id, $resultado['omitidos'][0]['motivo']);

        // El dueño queda como estaba.
        $this->assertSame(1, Historia::where('paciente_id', $duenio->id)->count());
        $this->assertSame($numero, MedicoPaciente::where('paciente_id', $duenio->id)->value('numhistoria'));
    }

    public function test_deja_el_vinculo_y_la_historia_cuando_el_numero_esta_libre(): void
    {
        $medico = $this->medico();
        $paciente = $this->paciente();
        $numero = (string) $paciente->id;

        $resultado = app(PoblarPacientesDelImportador::class)
            ->ejecutar($medico->id, $medico->reg_medico, Paciente::where('id', $paciente->id)->get());

        // Las **dos** filas: el número no puede vivir sólo en el vínculo (trampa 143).
        $this->assertSame($numero, MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria'));
        $this->assertSame($medico->reg_medico, MedicoPaciente::where('paciente_id', $paciente->id)->value('reg_medico'));

        $historia = Historia::where('reg_medico', $medico->reg_medico)->where('numhistoria', $numero)->first();
        $this->assertNotNull($historia, 'el número tiene que quedar registrado en `historias`');
        $this->assertSame((int) $paciente->id, (int) $historia->paciente_id);

        $this->assertSame(1, $resultado['creados']);
        $this->assertSame([], $resultado['omitidos']);
    }

    public function test_completa_la_historia_de_un_paciente_que_ya_estaba_vinculado_con_ese_numero(): void
    {
        $medico = $this->medico();
        $paciente = $this->paciente();
        $numero = (string) $paciente->id;

        // El vínculo ya existía con el número y la historia faltaba (trampa 143): se completa, no se omite.
        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'numhistoria' => $numero,
            'reg_medico'  => $medico->reg_medico,
        ]);

        $resultado = app(PoblarPacientesDelImportador::class)
            ->ejecutar($medico->id, $medico->reg_medico, Paciente::where('id', $paciente->id)->get());

        $this->assertSame(1, Historia::where('paciente_id', $paciente->id)->where('numhistoria', $numero)->count());
        $this->assertSame(1, $resultado['existentes']);
        $this->assertSame([], $resultado['omitidos']);
    }
}
