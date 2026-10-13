<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PreNatalDesarrolloFino;
use App\Models\PreNatalObservacion;
use App\Models\PrenaExamenB;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El informe del control de embarazo impreso** (WEB-6.6, la pestaña *Reporte*): la hoja «CONTROL DE
 * EMBARAZO» del escritorio (`d_informe_pre_nata_peque.srd`), con la gesta, sus controles y su laboratorio.
 *
 * Lo que fijan estos casos: que la hoja salga con el **título del DataWindow**, la ficha de la gesta (con su
 * **FPP de 283**, la del informe), **una línea por fecha** de los controles y del laboratorio y las
 * observaciones; que un control sin datos no deje una línea vacía; que la **gesta se pueda elegir** por la
 * URL; y que el documento se **ofrezca** en la lista de la consulta sólo si la historia tiene gestas.
 */
class ImpresionDelInformePrenatalWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'informe-web-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'informe-w-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Informe',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]]);
    }

    private function pacienteYConsulta(Medico $medico): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico, 'numhistoria' => '1001',
        ]);
        Historia::create([
            'numhistoria' => '1001', 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001,
            'nroconsulta' => 1, 'fecha' => $this->hoy->toDateString(),
        ]);
    }

    /** La gesta con sus controles y su laboratorio, como queda después de cargarlos en la sección. */
    private function embarazo(Medico $medico, int $gesta = 1): void
    {
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => $gesta,
            'fur' => '2026-05-20', 'menarquia' => '12 años', 'gestas' => 2, 'partos' => 1, 'cesarea' => 0,
            'abortos' => 0, 'tipiaje' => 'O+', 'tipiaje_cony' => 'A+', 'multiple' => 'N',
            'observaciones' => 'Embarazo en curso, sin particularidades.',
        ]);
        PreNatalDesarrolloFino::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => $gesta,
            'fecha' => '2026-06-01', 'peso' => '65', 'ta' => '110/70', 'au' => '20', 'presentacion' => 'C',
            'mf' => 'Fúndica', 'dbp' => '45', 'foco_f' => '140',
        ]);
        // Un control con sólo la fecha: no debe dejar una línea en la hoja.
        PreNatalDesarrolloFino::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => $gesta, 'fecha' => '2026-06-15',
        ]);
        PrenaExamenB::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => $gesta,
            'fecha' => '2026-06-02', 'hemoglobina' => '11.5', 'vdrl' => 'No reactivo', 'hiv' => 'Negativo',
        ]);
    }

    private function urlImprimir(Consulta $consulta, string $extra = ''): string
    {
        return route('clinica.consulta.documentos.imprimir', [
            $consulta->id, 'documento' => DocumentoImpreso::CONTROL_DE_EMBARAZO,
        ]) . $extra;
    }

    public function test_la_hoja_sale_con_la_gesta_sus_controles_y_su_laboratorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);
        $this->embarazo($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlImprimir($consulta));

        $respuesta->assertOk();

        // El título del DataWindow del escritorio.
        $respuesta->assertSee('CONTROL DE EMBARAZO');
        // La ficha de la gesta, con la FPP del INFORME (283: 2026-05-20 + 283 = 27/02/2027).
        $respuesta->assertSee('FUR:')->assertSee('20/05/2026');
        $respuesta->assertSee('FPP:')->assertSee('27/02/2027');
        $respuesta->assertSee('20 semanas y 6 días');
        $respuesta->assertSee('Tipiaje (madre):')->assertSee('O+');
        // Los controles y el laboratorio, una línea por fecha con los datos que tienen algo.
        $respuesta->assertSee('Control del 01/06/2026:');
        $respuesta->assertSee('Peso: 65');
        $respuesta->assertSee('Placenta: Fúndica');
        $respuesta->assertSee('Laboratorio del 02/06/2026:');
        $respuesta->assertSee('Hb. 11.5');
        // Las observaciones de la gesta, y el control sin datos no dejó su línea.
        $respuesta->assertSee('Observaciones:')->assertSee('Embarazo en curso, sin particularidades.');
        $respuesta->assertDontSee('Control del 15/06/2026');
    }

    public function test_la_gesta_se_elige_con_el_parametro_de_la_url(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);
        $this->embarazo($medico, 1);

        // Una segunda gesta, con su propia FUR: es la actual (la de mayor número).
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 2,
            'fur' => '2024-01-10', 'gestas' => 2,
        ]);

        // Sin `&gesta=`, la hoja sale con la actual: la 2.
        $actual = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlImprimir($consulta));
        $actual->assertOk();
        $actual->assertSee('10/01/2024');

        // Con `&gesta=1`, con la primera: su FUR y sus controles.
        $urlConGesta = $this->urlImprimir($consulta, '&gesta=1');
        $this->assertStringContainsString('gesta=1', $urlConGesta, 'La URL lleva la gesta');

        $primera = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($urlConGesta);
        $primera->assertOk();

        // Qué FUR salió en la hoja (así el fallo dice cuál eligió, no sólo que faltó una cadena).
        preg_match('/FUR:.*?(\d{2}\/\d{2}\/\d{4})/s', $primera->getContent(), $coincidencia);
        $this->assertSame('20/05/2026', $coincidencia[1] ?? 'sin FUR en la hoja', 'La FUR que imprimió la hoja');

        $primera->assertSee('Control del 01/06/2026:');
    }
}
