<?php

namespace Tests\Feature;

use App\Clinica\Agenda\MedicosDeLaCita;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\User;
use App\Services\CupoDelPlan;
use App\Services\ServicioService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * **El segundo consultorio del mismo doctor** (2026-11-06): una ficha `medicos` marcada `es_centro` que cuelga
 * del consultorio principal (`medico_padre_id`).
 *
 * Lo que fijan estas pruebas: usa **el plan del padre** (y su vencimiento), no recibe prueba ni año de cortesía,
 * no se le contrata un plan propio, el mismo doctor **no ocupa dos asientos** y en la agenda se comporta como
 * consultorio personal (no como centro).
 */
class ConsultorioHijoDelMedicoTest extends TestCase
{
    use DatabaseTransactions;

    private function doctor(string $reg): Medico
    {
        $user = User::create([
            'name' => 'Doctor ' . $reg, 'email' => $reg . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctor', 'lastname' => $reg,
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return $medico;
    }

    /** El consultorio nuevo: ficha sin cuenta, marcada centro y colgada del principal del doctor. */
    private function consultorioHijo(Medico $padre, string $reg): Medico
    {
        $hijo = Medico::create([
            'name' => 'Consultorio', 'lastname' => $reg, 'reg_medico' => $reg,
            'es_centro' => true, 'medico_padre_id' => $padre->id,
        ]);
        MedicoRegistro::create(['medico_id' => $padre->id, 'reg_medico' => $reg]);

        return $hijo;
    }

    private function planDelEquipo(string $reg, string $codigo): void
    {
        app(ServicioService::class)->reemplazar($reg, Plan::porCodigo($codigo), 0, 'prueba');
    }

    public function test_el_consultorio_hijo_usa_el_plan_del_padre(): void
    {
        $padre = $this->doctor('hijo-padre');
        $hijo = $this->consultorioHijo($padre, 'hijo-consultorio');
        $this->planDelEquipo('hijo-padre', 'equipo-2');

        $servicios = app(ServicioService::class);

        $this->assertSame('hijo-padre', $servicios->actual('hijo-consultorio')?->reg_medico);
        $this->assertSame('hijo-padre', $servicios->registroDelPlan('hijo-consultorio'));
        $this->assertSame('hijo-padre', $servicios->registroDelPlan('hijo-padre'), 'El padre no hereda nada.');

        $estado = $servicios->estado('hijo-consultorio');
        $this->assertSame('vigente', $estado['estado']);
        $this->assertSame($servicios->estado('hijo-padre')['vence_el'], $estado['vence_el']);
        $this->assertSame($servicios->estado('hijo-padre')['restricciones'], $estado['restricciones']);
        $this->assertTrue($estado['permite_sync']);
    }

    public function test_si_el_plan_del_padre_vence_el_hijo_tampoco_sincroniza_ni_imprime(): void
    {
        $padre = $this->doctor('hijo-vencido');
        $hijo = $this->consultorioHijo($padre, 'hijo-vencido-2');
        $this->planDelEquipo('hijo-vencido', 'equipo-2');

        RegMedicoServicio::where('reg_medico', 'hijo-vencido')->update([
            'vence_el' => now()->subMonths(2)->toDateString(),
        ]);
        app()->forgetInstance(ServicioService::class);

        $estado = app(ServicioService::class)->estado('hijo-vencido-2');
        $this->assertSame(ServicioService::VENCIDO, $estado['estado']);
        $this->assertFalse($estado['permite_sync']);
        $this->assertFalse($estado['permite_imprimir']);
    }

    public function test_el_hijo_no_recibe_prueba_ni_anio_de_cortesia(): void
    {
        $padre = $this->doctor('hijo-cortesia');
        $hijo = $this->consultorioHijo($padre, 'hijo-cortesia-2');

        $this->assertSame(0, RegMedicoServicio::where('reg_medico', 'hijo-cortesia-2')->count());

        $servicios = app(ServicioService::class);
        $this->assertNull($servicios->otorgarPrueba('hijo-cortesia-2'));
        $this->assertNull($servicios->otorgarPowerBuilder('hijo-cortesia-2'));
        $this->assertSame(0, RegMedicoServicio::where('reg_medico', 'hijo-cortesia-2')->count());
    }

    public function test_al_hijo_no_se_le_contrata_un_plan_propio(): void
    {
        $padre = $this->doctor('hijo-plan');
        $this->consultorioHijo($padre, 'hijo-plan-2');

        $this->expectException(DomainException::class);
        app(ServicioService::class)->otorgar('hijo-plan-2', Plan::porCodigo('equipo-1'), RegMedicoServicio::ORIGEN_MANUAL);
    }

    public function test_el_mismo_doctor_no_ocupa_dos_asientos_en_los_cupos(): void
    {
        $padre = $this->doctor('hijo-cupo');
        $this->consultorioHijo($padre, 'hijo-cupo-2');
        $this->planDelEquipo('hijo-cupo', 'equipo-1');

        $cupos = app(CupoDelPlan::class);

        // El plan es de un médico y el doctor ya está contado: el mismo no suma un asiento.
        $this->assertSame(1, $cupos->conteo('hijo-cupo')['medicos']);
        $this->assertSame(1, $cupos->conteo('hijo-cupo-2')['medicos']);
        $cupos->exigirCupoMedico('hijo-cupo-2', $padre);

        // Otro doctor sí: el plan del padre no tiene lugar para dos.
        $otro = $this->doctor('hijo-otro');
        $this->expectException(ValidationException::class);
        $cupos->exigirCupoMedico('hijo-cupo-2', $otro);
    }

    public function test_un_medico_ajeno_si_consume_el_cupo_de_la_familia(): void
    {
        $padre = $this->doctor('hijo-ajeno');
        $this->consultorioHijo($padre, 'hijo-ajeno-2');
        $this->planDelEquipo('hijo-ajeno', 'equipo-2');

        $otro = $this->doctor('hijo-ajeno-otro');
        app(CupoDelPlan::class)->exigirCupoMedico('hijo-ajeno-2', $otro);

        MedicoRegistro::create(['medico_id' => $otro->id, 'reg_medico' => 'hijo-ajeno-2']);

        $this->assertSame(2, app(CupoDelPlan::class)->conteo('hijo-ajeno')['medicos']);
    }

    public function test_el_consultorio_hijo_no_se_convierte_en_ficha_de_medico(): void
    {
        $padre = $this->doctor('hijo-ficha');
        $hijo = $this->consultorioHijo($padre, 'hijo-ficha-2');

        $this->expectException(ValidationException::class);
        app(\App\Services\CuentaService::class)->configurarCentro($hijo, false);
    }

    public function test_en_la_agenda_el_consultorio_hijo_no_es_un_centro(): void
    {
        $padre = $this->doctor('hijo-agenda');
        $this->consultorioHijo($padre, 'hijo-agenda-2');

        $agenda = app(MedicosDeLaCita::class);

        $this->assertNull($agenda->centro('hijo-agenda-2'), 'El consultorio del doctor no es un centro.');
        $this->assertFalse($agenda->esCentro('hijo-agenda-2'));
        $this->assertSame(
            [$padre->id],
            $agenda->doctores('hijo-agenda-2')->pluck('id')->all(),
            'El único doctor del consultorio es el dueño.',
        );
        $this->assertFalse($agenda->varios('hijo-agenda-2'), 'Con un solo doctor no se muestra la columna.');
    }
}
