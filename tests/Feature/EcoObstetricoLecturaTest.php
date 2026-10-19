<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Models\Consulta;
use App\Models\EcoObstetrico as EcoObstetricoModelo;
use App\Models\EcoObstetricoTercer;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La lectura del eco obstétrico** (WEB-6.2): la fila de `eco_obstetrico` de una consulta.
 *
 * Lo que fijan estos casos: la clave **`numhistoria` + `nroconsulta`** (una fila por consulta), que la
 * lectura se acota al médico, que una consulta sin estudio devuelve el objeto **vacío** (y no un error) y que
 * la fila para escribir arma la clave sin que la ponga el formulario.
 */
class EcoObstetricoLecturaTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(): Medico
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'eco-obs-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'eco-obs-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Obstétrico',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function consulta(Medico $medico, int $historia = 1001, int $nroconsulta = 1): Consulta
    {
        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $historia,
            'nroconsulta' => $nroconsulta, 'fecha' => '2026-10-13',
        ]);
    }

    public function test_lee_el_estudio_de_esa_consulta(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // Los valores respetan el tipo real de cada columna del escritorio: `saco_gestacional`, `lcc` y `tn`
        // son enteros y `sa_vit` es decimal (en SQLite entraba cualquier texto; en MySQL/MariaDB no).
        EcoObstetricoModelo::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'gesta' => 2, 'fur' => '2026-05-20', 'eg' => '20s + 6d',
            'saco_gestacional' => '1', 'lcc' => '45', 'sa_vit' => '1.5', 't_nucal' => '1.8',
            'embarazo_ectopico' => 'N', 'fecha' => '2026-08-14',
        ]);

        $estudio = (new EcoObstetrico())->de($consulta);

        // El objeto de valores es el mismo del eco pélvico: lee por columna y normaliza.
        $this->assertSame('1', $estudio->valor('saco_gestacional'));
        $this->assertSame('45', $estudio->valor('lcc'));
        $this->assertSame('2026-05-20', $estudio->fur());
        $this->assertTrue($estudio->tieneAlgoDe(['lcc', 'sa_vit', 't_nucal']));
        $this->assertFalse($estudio->tieneAlgoDe(['mola', 'huevo_anembrionado']));

        // Y la fila para escribir es la que ya existe.
        $this->assertSame(1, (new EcoObstetrico())->filaParaEscribir($consulta)->consulta);
    }

    public function test_una_consulta_sin_estudio_devuelve_el_objeto_vacio(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $estudio = (new EcoObstetrico())->de($consulta);

        $this->assertFalse($estudio->tieneAlgoDe(['lcc', 'saco_gestacional', 'fur']));
        $this->assertNull($estudio->fecha());

        // La fila para escribir trae la clave puesta (el formulario no la manda).
        $nueva = (new EcoObstetrico())->filaParaEscribir($consulta);
        $this->assertSame($medico->reg_medico, $nueva->reg_medico);
        $this->assertSame(1001, $nueva->historia);
        $this->assertSame(1, $nueva->consulta);
        $this->assertFalse($nueva->exists);
    }

    public function test_la_lectura_se_acota_al_medico_y_a_la_consulta(): void
    {
        $medico = $this->medico();
        $otro = $this->medico();

        $consulta = $this->consulta($medico);
        $otraConsulta = $this->consulta($medico, 1001, 2);

        // El estudio de otro médico, y el de otra consulta de la misma historia: ninguno se lee.
        EcoObstetricoModelo::create([
            'reg_medico' => $otro->reg_medico, 'historia' => 1001, 'consulta' => 1, 'lcc' => '50',
        ]);
        EcoObstetricoModelo::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 2, 'lcc' => '52',
        ]);

        $this->assertFalse((new EcoObstetrico())->de($consulta)->tieneAlgoDe(['lcc']));
        $this->assertSame('52', (new EcoObstetrico())->de($otraConsulta)->valor('lcc'));
    }

    public function test_el_tercer_trimestre_lee_sus_bloques_y_su_gemelo(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico, 1002);

        EcoObstetricoTercer::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1002, 'consulta' => 1,
            'gesta' => 1, 'fur' => '2026-01-10', 'eg' => '39s + 2d', 'gemelo' => 1,
            'bio_peso_fetal' => '3200', 'bio_femur' => '72', 'ana_corazon' => 'N',
            'fun_dp_grado' => 'II', 'fun_indice_la' => '12', 'conclusion' => 'Embarazo a término.',
        ]);

        $estudio = (new EcoObstetricoTercero())->de($consulta);

        // Los tres bloques del escritorio, por su prefijo.
        $this->assertSame('3200', $estudio->valor('bio_peso_fetal'));
        $this->assertSame('N', $estudio->valor('ana_corazon'));
        $this->assertSame('II', $estudio->valor('fun_dp_grado'));
        // Y lo que este estudio tiene de propio: el gemelo (`gemelo` es un entero, `1` = sí) y el informe.
        $this->assertSame('1', $estudio->valor('gemelo'));
        $this->assertTrue($estudio->tieneAlgoDe(['bio_peso_fetal', 'fun_indice_la']));
        $this->assertFalse($estudio->tieneAlgoDe(['puro_texto']));

        // La clave es la misma que el primer trimestre: una fila por consulta.
        $this->assertSame(1, (new EcoObstetricoTercero())->filaParaEscribir($consulta)->consulta);
        $this->assertSame($medico->reg_medico, (new EcoObstetricoTercero())->filaParaEscribir($consulta)->reg_medico);
    }
}