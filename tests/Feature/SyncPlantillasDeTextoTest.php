<?php

namespace Tests\Feature;

use App\Actions\Catalogos\QuitarPlantillaDeTexto;
use App\Models\FormatoPrint;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **A1 de PLAN-MOVIL.md §11: que las plantillas de texto del médico bajen al teléfono.**
 *
 * `formato_print` es la tabla del legado donde viven las plantillas de texto (WEB-8.4): un `titulo` y un
 * texto (`descripcion`) de un `tipo`. La web las edita en `/clinica/catalogos/plantillas-texto` y las
 * ofrece en la consulta; el teléfono ahora las **usa** en la enfermedad actual (`EA`), la evolución (`EV`)
 * y el tratamiento (`TR`), pero todavía **no las crea** (ver PLAN-MOVIL §11).
 *
 * Lo que fija este test:
 *
 *  1. **Delta** (`since`): bajan las del médico, con su tipo y su texto, y **no** las de otro `reg_medico`.
 *  2. **La baja desde la web** llega por `eliminados`: si no, el teléfono seguiría ofreciendo una plantilla
 *     que ya no existe.
 *  3. **La clave existe siempre**: el pedido puntual de una ficha (`volcado_paciente`) no las necesita por
 *     paciente, pero el app recorre una lista fija de tablas y no puede distinguir "vacía" de "ausente".
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncPlantillasDeTextoTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(string $sufijo = '', bool $autenticar = true): Medico
    {
        // `reg_medico` es VARCHAR(20) en el legado: prefijo + 6 tiene que entrar (trampa 141).
        $regMedico = 'tst-plant-' . ($sufijo !== '' ? $sufijo : substr(uniqid(), -6));
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'plantillas-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
            'lastname' => 'De Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);
        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    /** Una plantilla del médico, como la deja el ABM de la web (o el escritorio). */
    private function plantilla(Medico $medico, string $codigo, string $tipo, string $titulo, string $texto): FormatoPrint
    {
        return FormatoPrint::create([
            'reg_medico' => $medico->reg_medico,
            'codigo' => $codigo,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'descripcion' => $texto,
        ]);
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    public function test_las_plantillas_bajan_por_delta_y_solo_las_del_medico(): void
    {
        $medico = $this->medico();
        $this->plantilla($medico, '0001', 'EV', 'CONTROL MENSUAL', 'Paciente estable, sin cambios.');
        $this->plantilla($medico, '0002', 'EA', 'DOLOR PELVICO', 'Dolor pélvico de tres días de evolución.');

        // Otro médico con el mismo tipo y el mismo título: no puede llegar a este teléfono. (Se crea sin
        // volver a autenticar: el segundo `actingAs` cambiaría el usuario de la petición.)
        $otro = $this->medico('otro', false);
        $this->plantilla($otro, '0001', 'EV', 'CONTROL MENSUAL', 'Texto del otro médico.');
        // Y una del médico de un tipo que el app todavía no ofrece: baja igual (el app filtra por tipo).
        $this->plantilla($medico, '0003', 'CO', 'COLPOSCOPIA NORMAL', 'Epitelio maduro.');

        $r = $this->sync(['since' => now()->subMinute()->toIso8601String()]);

        $titulos = array_column($r->json('formato_print'), 'titulo');
        sort($titulos);
        $this->assertSame(['COLPOSCOPIA NORMAL', 'CONTROL MENSUAL', 'DOLOR PELVICO'], $titulos);
        $this->assertNotContains('Texto del otro médico.', array_column($r->json('formato_print'), 'descripcion'));

        $evolucion = collect($r->json('formato_print'))->firstWhere('tipo', 'EV');
        $this->assertSame('EV', $evolucion['tipo']);
        $this->assertSame('Paciente estable, sin cambios.', $evolucion['descripcion']);
        $this->assertArrayHasKey('codigo', $evolucion);
        $this->assertArrayHasKey('updated_at', $evolucion);
    }

    public function test_quitar_una_plantilla_en_la_web_llega_como_baja(): void
    {
        $medico = $this->medico();
        $fila = $this->plantilla($medico, '0001', 'TR', 'TRATAMIENTO BASE', 'Reposo y analgésicos.');

        SyncChange::query()->delete();
        $desde = now()->subMinute();
        app(QuitarPlantillaDeTexto::class)->ejecutar($fila);

        $r = $this->sync(['since' => $desde->toIso8601String()]);
        $eliminados = collect($r->json('eliminados'));

        $this->assertTrue(
            $eliminados->contains(fn ($e) => $e['table_name'] === 'formato_print' && (int) $e['record_id'] === (int) $fila->id),
            'la baja de la plantilla tiene que viajar en `eliminados`',
        );
        $this->assertCount(0, $r->json('formato_print'), 'una plantilla borrada no vuelve a bajar');
        $this->assertSame(0, DB::table('formato_print')->where('id', $fila->id)->count());
    }

    public function test_el_pedido_de_una_ficha_trae_la_clave_aunque_no_haya_plantillas(): void
    {
        $medico = $this->medico();

        // Un paciente del médico sin historia clínica: alcanza para que el pedido puntual no dé 404.
        $paciente = \App\Models\Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999),
            'nombres' => 'Paciente',
            'apellidos' => 'De Prueba',
        ]);
        \App\Models\MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => '987654',
        ]);
        \App\Models\Historia::create([
            'numhistoria' => '987654',
            'reg_medico' => $medico->reg_medico,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);

        $cuerpo = $this->sync(['volcado_paciente' => 987654])->json();

        $this->assertArrayHasKey('formato_print', $cuerpo);
        $this->assertSame([], $cuerpo['formato_print']);
    }
}
