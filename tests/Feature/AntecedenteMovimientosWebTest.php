<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\AntecedenteMovimiento;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El registro de movimientos de los antecedentes desde la web** (`antecedente_movimientos`, decisión del
 * usuario 2026-10-20): agregar, editar y quitar desde «Atender consulta» anotan el movimiento con la
 * consulta, el usuario y el origen `web`; la sección muestra «desde dd/mm/aaaa» y el «Historial».
 */
class AntecedenteMovimientosWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        // 13:00 UTC = 09:00 en Caracas: la fecha que se muestra es la del consultorio.
        $this->hoy = Carbon::parse('2026-10-06 13:00:00');
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
        $regMedico = 'movant-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Dra. Movimientos',
            'email' => 'movant-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Dra.',
            'lastname' => 'Movimientos',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        DB::table('tipo_antecedente')->insert([
            'reg_medico' => $regMedico, 'codetipo' => '01', 'descripcion' => 'PERSONAL', 'tipoantecedente' => 'P',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['0285', 'HIPERTENSION'], ['0013', 'DIABETES MELLITUS']] as [$codigo, $nombre]) {
            DB::table('antecedentes')->insert([
                'reg_medico' => $regMedico, 'codantecedente' => $codigo, 'descripcion' => $nombre, 'codtipo' => '01',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id' => null,
        ]])->actingAs($user);
    }

    private function consulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1, ?string $fecha = null): Consulta
    {
        if (! Historia::where('reg_medico', $medico->reg_medico)->where('numhistoria', (string) $numhistoria)->exists()) {
            $pacienteId = DB::table('pacientes')->insertGetId([
                'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
                'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
            ]);
            MedicoPaciente::create([
                'medico_id' => $medico->id, 'paciente_id' => $pacienteId, 'reg_medico' => $medico->reg_medico,
                'numhistoria' => (string) $numhistoria,
            ]);
            Historia::create([
                'numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico,
                'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
            ]);
        }

        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha' => $fecha ?? $this->hoy->toDateString(),
        ]);
    }

    private function movimientos(Medico $medico)
    {
        return AntecedenteMovimiento::where('reg_medico', $medico->reg_medico)->orderBy('id')->get();
    }

    public function test_agregar_editar_y_quitar_desde_la_consulta_anotan_cada_movimiento_con_la_consulta_y_el_usuario(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $web = fn () => $this->conContexto($user, $medico, $gineco);

        $web()->post(route('clinica.consulta.antecedentes.agregar', $consulta->id), ['codigo' => '0285'])->assertRedirect();

        $fila = DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertNotNull($fila);

        $web()->post(route('clinica.consulta.antecedentes.guardar', $consulta->id), [
            'filas' => [$fila->id => ['detalle' => 'DESDE LOS 40', 'alerta' => 'A']],
        ])->assertRedirect();

        // Guardar otra vez lo mismo no es un movimiento.
        $web()->post(route('clinica.consulta.antecedentes.guardar', $consulta->id), [
            'filas' => [$fila->id => ['detalle' => 'DESDE LOS 40', 'alerta' => 'A']],
        ])->assertRedirect();

        // Un minuto después: el last-write-wins del sync no deja quitar en el mismo instante de la edición.
        Carbon::setTestNow($this->hoy->copy()->addMinute());
        $web()->post(route('clinica.consulta.antecedentes.quitar', $consulta->id), ['codigo' => '0285'])->assertRedirect();

        $movimientos = $this->movimientos($medico);
        $this->assertSame(['agregado', 'editado', 'quitado'], $movimientos->pluck('operacion')->all());

        foreach ($movimientos as $movimiento) {
            $this->assertSame(1001, $movimiento->numhistoria);
            $this->assertSame('0285', $movimiento->codeantecedente);
            $this->assertSame('HIPERTENSION', $movimiento->descripcion);
            $this->assertSame('01', $movimiento->tipo);
            $this->assertSame($consulta->id, $movimiento->consulta_id);
            $this->assertSame('web', $movimiento->origen);
            $this->assertSame($user->id, $movimiento->user_id);
        }
        $this->assertSame('2026-10-06 13:00:00', $movimientos[0]->ocurrido_en->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 13:01:00', $movimientos[2]->ocurrido_en->format('Y-m-d H:i:s'));

        // El alta nace sin detalle; la edición guarda el detalle y la alerta; la baja, lo que tenía.
        $this->assertNull($movimientos[0]->detalles);
        $this->assertFalse($movimientos[0]->alerta);
        $this->assertSame('DESDE LOS 40', $movimientos[1]->detalles);
        $this->assertTrue($movimientos[1]->alerta);
        $this->assertSame('DESDE LOS 40', $movimientos[2]->detalles);
        $this->assertTrue($movimientos[2]->alerta);

        // La fila de `antece_paciente` se borró igual que siempre (la tabla legada no cambia).
        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_seccion_muestra_desde_cuando_esta_cada_antecedente_y_el_historial(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $primera = $this->consulta($medico, 1001, 1, '2026-09-01');
        $consulta = $this->consulta($medico, 1001, 2);

        // Un antecedente agregado en la primera consulta (con fecha) y uno del legado sin movimientos.
        Carbon::setTestNow(Carbon::parse('2026-09-01 15:00:00'));
        $this->conContexto($user, $medico, $gineco)
            ->post(route('clinica.consulta.antecedentes.agregar', $primera->id), ['codigo' => '0285'])->assertRedirect();
        Carbon::setTestNow($this->hoy->copy());

        DB::table('antece_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'codeantecedente' => '0013',
            'descripcion' => 'DIABETES MELLITUS', 'tipo' => '01', 'detalles' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES]))
            ->assertOk()
            ->getContent();

        // «desde» sólo en el que tiene registro: el del legado no lleva fecha inventada.
        $this->assertSame(1, substr_count($html, 'desde 01/09/2026'));
        $this->assertStringContainsString('data-abrir-dialogo="antecedentes-historial"', $html);
        $this->assertStringContainsString('id="antecedentes-historial"', $html);

        // El historial: el movimiento con su fecha local, la consulta enlazada y el usuario.
        $this->assertStringContainsString('01/09/2026 11:00', $html);
        $this->assertStringContainsString('Consulta 1 · 01/09/2026', $html);
        $this->assertStringContainsString(e(route('clinica.consulta', [$primera->id, 'seccion' => 'antecedentes'])), $html);
        $this->assertStringContainsString('Dra. Movimientos · Web', $html);
    }

    public function test_un_quitado_aparece_en_el_historial_y_el_agregado_de_nuevo_reinicia_el_desde(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $web = fn () => $this->conContexto($user, $medico, $gineco);

        Carbon::setTestNow(Carbon::parse('2026-09-01 15:00:00'));
        $web()->post(route('clinica.consulta.antecedentes.agregar', $consulta->id), ['codigo' => '0285']);
        $web()->post(route('clinica.consulta.antecedentes.quitar', $consulta->id), ['codigo' => '0285']);
        Carbon::setTestNow($this->hoy->copy());
        $web()->post(route('clinica.consulta.antecedentes.agregar', $consulta->id), ['codigo' => '0285']);

        $html = $web()->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes']))->assertOk()->getContent();

        $this->assertStringContainsString('desde 06/10/2026', $html);
        $this->assertStringNotContainsString('desde 01/09/2026', $html);
        $this->assertStringContainsString('Quitado', $html);
        // Hecho desde la consulta abierta: no se enlaza a sí misma.
        $this->assertStringContainsString('Esta consulta', $html);
    }

    public function test_un_paciente_sin_movimientos_muestra_el_historial_vacio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes']))
            ->assertOk()
            ->assertSee('Sin movimientos registrados');
    }

    public function test_el_historial_no_muestra_los_movimientos_de_otro_registro_con_el_mismo_numero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        AntecedenteMovimiento::create([
            'reg_medico' => 'otro-registro', 'numhistoria' => 1001, 'codeantecedente' => '9999',
            'descripcion' => 'AJENO DE OTRO MEDICO', 'operacion' => 'agregado', 'origen' => 'web',
            'ocurrido_en' => now(),
        ]);

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes']))
            ->assertOk()
            ->assertDontSee('AJENO DE OTRO MEDICO');
    }
}
