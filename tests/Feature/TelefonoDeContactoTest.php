<?php

namespace Tests\Feature;

use App\Clinica\Pacientes\CorreoDeContacto;
use App\Clinica\Pacientes\FamiliaDelPaciente;
use App\Clinica\Pacientes\FiliacionPendiente;
use App\Clinica\Pacientes\TelefonoDeContacto;
use App\Cumpleanos\CumpleanosDeHoy;
use App\Models\Specialty;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El teléfono de quien responde por el niño (WEB-7.4.2c): el que usan los recordatorios, los cumpleaños, la agenda y el dato obligatorio
 * de pediatría cuando el paciente no tiene un teléfono válido propio.
 */
class TelefonoDeContactoTest extends TestCase
{
    use DatabaseTransactions;

    private string $reg = 'tel-contacto-1';

    private function chamo(int $historia, int $familia, ?string $reg = null): void
    {
        DB::table('paciente_chamo')->insert([
            'reg_medico' => $reg ?? $this->reg, 'familia' => $familia, 'historia' => $historia, 'nombre' => 'NIÑO ' . $historia,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function familia(int $familia, array $telefonos, ?string $reg = null): void
    {
        DB::table('familia_bebe')->insert(array_merge(
            ['reg_medico' => $reg ?? $this->reg, 'familia' => $familia, 'created_at' => now(), 'updated_at' => now()],
            $telefonos,
        ));
    }

    public function test_el_orden_es_representante_luego_madre_padre_y_otro(): void
    {
        $this->chamo(1, 10);
        $this->familia(10, ['celu_madre' => '', 'cel_padre' => '04125550000', 'telefono' => '02125551111']);
        $resolver = new TelefonoDeContacto();

        // Madre vacía: sigue el padre.
        $this->assertSame('04125550000', $resolver->de($this->reg, '1'));

        // El representante cargado a mano manda sobre la familia.
        DB::table('representante')->insert([
            'reg_medico' => $this->reg, 'numhistoria' => 1, 'nombre' => 'TÍA', 'telefono' => '04141112222',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('04141112222', $resolver->de($this->reg, '1'));
    }

    public function test_sin_nadie_con_telefono_no_inventa_uno(): void
    {
        $this->chamo(2, 20);
        $this->familia(20, ['celu_madre' => null, 'cel_padre' => '  ', 'telefono' => null]);

        $this->assertNull((new TelefonoDeContacto())->de($this->reg, '2'));
        $this->assertNull((new TelefonoDeContacto())->de($this->reg, null));
        $this->assertNull((new TelefonoDeContacto())->de($this->reg, '999'));
    }

    public function test_varias_historias_de_una_vez_y_sin_mezclar_consultorios(): void
    {
        $this->chamo(3, 30);
        $this->chamo(4, 40);
        $this->familia(30, ['celu_madre' => '04140000003']);
        $this->familia(40, ['celu_madre' => '04140000004']);
        // Otro consultorio, misma familia y misma historia: no cuenta.
        $this->chamo(3, 30, 'otro-reg');
        $this->familia(30, ['celu_madre' => '04149999999'], 'otro-reg');

        $this->assertSame(
            [3 => '04140000003', 4 => '04140000004'],
            (new TelefonoDeContacto())->deLasHistorias($this->reg, ['3', '4', '00005']),
        );
    }

    public function test_un_nino_con_la_familia_cargada_no_queda_con_datos_pendientes_en_pediatria(): void
    {
        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $this->chamo(6, 60);
        $paciente = (object) ['nombres' => 'Ana', 'apellidos' => 'Pérez', 'fnacimiento' => '2022-01-01', 'telefono' => null, 'cedula' => null];

        $this->assertSame(['teléfono del representante'], FiliacionPendiente::de($paciente, $this->reg, '6', $pediatria->id));

        $this->familia(60, ['celu_madre' => '04140000006']);
        $this->assertSame([], FiliacionPendiente::de($paciente, $this->reg, '6', $pediatria->id));
    }

    public function test_el_saludo_de_cumpleanos_de_un_nino_sin_celular_va_al_telefono_de_la_familia(): void
    {
        $hoy = Carbon::parse('2026-10-17');
        $this->chamo(7, 70);
        $this->familia(70, ['celu_madre' => '04140000007']);
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Luis', 'apellidos' => 'Niño', 'fnacimiento' => '2022-10-17', 'telefono' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $medico = DB::table('medicos')->value('id') ?? null;
        DB::table('medico_pacientes')->insert([
            'medico_id' => $medico, 'paciente_id' => $pacienteId, 'numhistoria' => '7', 'reg_medico' => $this->reg,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cumpleanos = (new CumpleanosDeHoy())->delMedico($this->reg, $hoy);

        $this->assertCount(1, $cumpleanos);
        $this->assertSame('04140000007', $cumpleanos->first()->telefono());
        $this->assertTrue($cumpleanos->first()->tieneTelefono());
    }

    /**
     * **O-66 (2026-11-05): la regla del aviso se invierte en pediatría.** Al niño no se lo contacta nunca
     * directamente: aunque tenga teléfono propio (la excepción), el aviso va al representante o a la familia y, si
     * el adulto no tiene, no hay destino. Fuera de pediatría manda el del paciente y el del adulto es el respaldo,
     * como siempre.
     */
    public function test_el_telefono_del_aviso_es_el_del_adulto_en_pediatria_y_el_del_paciente_fuera(): void
    {
        $this->chamo(8, 80);
        $this->familia(80, ['celu_madre' => '04140000008']);
        $resolver = new TelefonoDeContacto();
        $familias = new FamiliaDelPaciente();

        $this->assertTrue($familias->esUnNino($this->reg, '8'));
        $this->assertFalse($familias->esUnNino($this->reg, '999'));
        $this->assertFalse($familias->esUnNino($this->reg, null));

        // En pediatría el teléfono propio del niño no se usa: va al de la familia.
        $this->assertSame('04140000008', $resolver->paraElAviso($this->reg, '8', '04147777777', true));

        // Y el representante cargado a mano manda sobre la familia.
        DB::table('representante')->insert([
            'reg_medico' => $this->reg, 'numhistoria' => 8, 'nombre' => 'TÍA', 'telefono' => '04141112222',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('04141112222', $resolver->paraElAviso($this->reg, '8', '04147777777', true));

        // Fuera de pediatría manda el del paciente, y el del adulto es el respaldo de siempre.
        $this->assertSame('04147777777', $resolver->paraElAviso($this->reg, '8', '04147777777', false));
        $this->assertSame('04141112222', $resolver->paraElAviso($this->reg, '8', null, false));

        // Un niño sin adulto con teléfono: nada, aunque tenga el suyo.
        $this->chamo(9, 90);
        $this->assertNull($resolver->paraElAviso($this->reg, '9', '04148888888', true));
    }

    /** El correo del aviso: el del representante en pediatría (`representante.email`), el del paciente fuera de ella. */
    public function test_el_correo_del_aviso_es_el_del_representante_en_pediatria(): void
    {
        $this->chamo(10, 100);
        $correos = new CorreoDeContacto();

        $this->assertNull($correos->paraElAviso($this->reg, '10', 'nino@example.com', true));

        DB::table('representante')->insert([
            'reg_medico' => $this->reg, 'numhistoria' => 10, 'nombre' => 'MAMÁ', 'email' => 'mama@example.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('mama@example.com', $correos->paraElAviso($this->reg, '10', 'nino@example.com', true));
        $this->assertSame('nino@example.com', $correos->paraElAviso($this->reg, '10', 'nino@example.com', false));
        $this->assertSame([10 => 'mama@example.com'], $correos->deLasHistorias($this->reg, ['10', '999']));
    }
}
