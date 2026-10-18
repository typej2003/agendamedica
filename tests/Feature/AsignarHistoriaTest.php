<?php

namespace Tests\Feature;

use App\Actions\Pacientes\AsignarHistoria;
use App\Models\Cola;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * La Action que **llena la historia** de un paciente dado de alta sin número (WEB-2.11).
 *
 * Es la regla que antes vivía adentro del sync del móvil (`CreacionesClinicas::crearHistoria()`): el
 * correlativo del médico, la idempotencia y el enlace de las citas que estaban "sin historia". Acá se
 * prueba sola, que es justamente lo que la extracción buscaba — los tests del sync
 * (`tests/Feature/CreacionesClinicasTest.php`) siguen cubriendo el camino del teléfono **sin tocarse**.
 *
 * Necesita base de datos (escribe historias, el pivote y citas), así que va en `Feature` con
 * `DatabaseTransactions`, como el resto de la web clínica, y no en `Unit/Clinica/` como los ViewModels.
 */
class AsignarHistoriaTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(array $registrosExtra = []): Medico
    {
        $regMedico = 'test-ah-' . substr(uniqid(), -6);
        $medico = Medico::create([
            'name'       => 'Doctora',
            'lastname'   => 'De Prueba',
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);
        foreach ($registrosExtra as $extra) {
            MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $extra]);
        }

        return $medico;
    }

    /** @return array<int,string> los registros del médico, como los resuelven el sync y la web */
    private function registros(Medico $medico): array
    {
        return array_values(array_unique(array_filter(
            MedicoRegistro::where('medico_id', $medico->id)->pluck('reg_medico')->all()
        )));
    }

    /** Un paciente vinculado al médico, con historia (`$numhistoria`) o sin ella. */
    private function paciente(Medico $medico, ?int $numhistoria = null): Paciente
    {
        $paciente = Paciente::create([
            'cedula'    => (string) random_int(10000000, 99999999),
            'nombres'   => 'Paciente',
            'apellidos' => 'De Prueba',
        ]);

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria === null ? null : (string) $numhistoria,
        ]);

        if ($numhistoria !== null) {
            Historia::create([
                'numhistoria' => (string) $numhistoria,
                'reg_medico'  => $medico->reg_medico,
                'paciente_id' => $paciente->id,
                'medico_id'   => $medico->id,
            ]);
        }

        return $paciente;
    }

    public function test_le_asigna_el_correlativo_siguiente_y_deja_la_cita_enlazada(): void
    {
        $medico = $this->medico();
        $this->paciente($medico, 4);
        $nuevo = $this->paciente($medico);
        $cita = Cola::create([
            'reg_medico'              => $medico->reg_medico,
            'fecha'                   => '2026-10-05',
            'hora_ini'                => '09:00:00',
            'paciente_sinhistoria_id' => $nuevo->id,
        ]);

        $historia = app(AsignarHistoria::class)->ejecutar($medico, $this->registros($medico), $nuevo->id);

        // La última (4) + 1, guardada como texto como en el legado.
        $this->assertSame('5', $historia->numhistoria);
        $this->assertSame($medico->id, $historia->medico_id);
        $this->assertSame($nuevo->id, $historia->paciente_id);
        $this->assertSame('5', MedicoPaciente::where('paciente_id', $nuevo->id)->value('numhistoria'));

        // Es **la misma cita**, ahora enlazada por `numhistoria` (lo que leen la agenda y el sync).
        $this->assertSame(5, (int) $cita->fresh()->numhistoria);
        $this->assertSame($nuevo->id, (int) $cita->fresh()->paciente_sinhistoria_id);
        $this->assertSame(1, Cola::where('id', $cita->id)->count());
    }

    public function test_el_correlativo_mira_las_historias_y_el_pivote_y_compara_como_entero(): void
    {
        $medico = $this->medico();

        // Una historia del médico y un número que solo vive en el pivote (el que dejó el escritorio).
        $this->paciente($medico, 3);
        $soloPivote = $this->paciente($medico);
        MedicoPaciente::where('paciente_id', $soloPivote->id)->update(['numhistoria' => '10']);

        $nuevo = $this->paciente($medico);

        // "10" > "9" solo si se compara como entero: con texto, `max()` devolvería "9".
        $this->assertSame(11, app(AsignarHistoria::class)->siguienteNumero($medico, $this->registros($medico)));
        $this->assertSame(11, (int) app(AsignarHistoria::class)->ejecutar($medico, $this->registros($medico), $nuevo->id)->numhistoria);
    }

    public function test_si_el_paciente_ya_tiene_historia_devuelve_la_suya_sin_numerar_de_nuevo(): void
    {
        $medico = $this->medico();
        $paciente = $this->paciente($medico, 12);
        $otro = $this->paciente($medico);

        $accion = app(AsignarHistoria::class);
        $historia = $accion->ejecutar($medico, $this->registros($medico), $paciente->id);

        // Idempotente: la que tenía, y ninguna fila nueva.
        $this->assertSame(12, (int) $historia->numhistoria);
        $this->assertSame(1, Historia::where('paciente_id', $paciente->id)->count());

        // Y el doble clic sobre el mismo paciente tampoco numera dos veces.
        $repetida = $accion->ejecutar($medico, $this->registros($medico), $paciente->id);
        $this->assertSame($historia->id, $repetida->id);
        $this->assertSame(1, Historia::where('paciente_id', $paciente->id)->count());

        // La del otro paciente sigue esperando su número (no se corrió el correlativo).
        $this->assertNull(MedicoPaciente::where('paciente_id', $otro->id)->value('numhistoria'));
    }

    public function test_un_paciente_de_otro_medico_no_se_numera(): void
    {
        $medico = $this->medico();
        $ajeno = $this->paciente($this->medico(), 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ese paciente no es de este médico.');

        app(AsignarHistoria::class)->ejecutar($medico, $this->registros($medico), $ajeno->id);
    }

    public function test_no_toca_las_citas_sin_numero_de_otro_registro(): void
    {
        $medico = $this->medico();
        $otro = $this->medico();

        // El mismo paciente, vinculado a los dos médicos, con una cita "sin historia" en cada uno.
        $paciente = $this->paciente($medico);
        MedicoPaciente::create([
            'medico_id'   => $otro->id,
            'paciente_id' => $paciente->id,
            'reg_medico'  => $otro->reg_medico,
            'numhistoria' => null,
        ]);

        $propia = Cola::create([
            'reg_medico' => $medico->reg_medico, 'fecha' => '2026-10-05', 'hora_ini' => '09:00:00',
            'paciente_sinhistoria_id' => $paciente->id,
        ]);
        $ajena = Cola::create([
            'reg_medico' => $otro->reg_medico, 'fecha' => '2026-10-05', 'hora_ini' => '10:00:00',
            'paciente_sinhistoria_id' => $paciente->id,
        ]);

        app(AsignarHistoria::class)->ejecutar($medico, $this->registros($medico), $paciente->id);

        $this->assertSame(1, (int) $propia->fresh()->numhistoria);
        $this->assertNull($ajena->fresh()->numhistoria, 'la cita del otro registro no se toca');
    }
}
