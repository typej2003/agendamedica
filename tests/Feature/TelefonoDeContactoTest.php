<?php

namespace Tests\Feature;

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
}
