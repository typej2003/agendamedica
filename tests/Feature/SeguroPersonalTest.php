<?php

namespace Tests\Feature;

use App\Actions\Catalogos\AsegurarElSeguroPersonal;
use App\Actions\Medicos\AprobarSolicitudDeMedico;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SolicitudMedico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **El consultorio siempre tiene al menos el seguro «Personal»** (O-60, Alexander, 2026-11-05): la migración
 * que rellena lo que ya existe, la Action que lo deja en el alta y el alta del médico —la solicitud aprobada—
 * sin pasar por el panel.
 *
 * El consultorio se identifica por su `reg_medico`, así que todos los registros de prueba son cortos: la columna
 * `seg_emp.reg_medico` del legado es `varchar(20)`.
 */
class SeguroPersonalTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRACION = '2026_11_05_150000_sembrar_el_seguro_personal_en_los_consultorios.php';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();
    }

    // ------------------------------------------------------------------------------------------ Ayudas

    /** Una ficha de médico (un consultorio) con ese `reg_medico`. */
    private function consultorio(string $reg): Medico
    {
        return Medico::create([
            'name' => 'Doc', 'lastname' => 'Seguro', 'reg_medico' => $reg, 'is_active' => true,
        ]);
    }

    private function seguro(string $reg, string $codigo, string $nombre): void
    {
        DB::table('seg_emp')->insert([
            'reg_medico' => $reg, 'codesegemp' => $codigo, 'nombre' => $nombre,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int,array{0:string,1:string}> los seguros del consultorio como `[código, nombre]`, por código */
    private function seguros(string $reg): array
    {
        return DB::table('seg_emp')
            ->where('reg_medico', $reg)
            ->orderBy('codesegemp')
            ->get(['codesegemp', 'nombre'])
            ->map(fn ($fila) => [trim((string) $fila->codesegemp), trim((string) $fila->nombre)])
            ->all();
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin Seguros', 'email' => 'admin-seg-' . substr(uniqid(), -7) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Administrador');

        return $user;
    }

    private function solicitud(array $cambios = []): SolicitudMedico
    {
        return SolicitudMedico::create(array_merge([
            'prefijo' => 'Dr.', 'nombres' => 'Nuevo', 'apellidos' => 'Colega',
            'cedula' => '9' . random_int(1000000, 9999999), 'mpps' => (string) random_int(100000, 999999),
            'telefono' => '0414-5556677', 'email' => 'seguro-' . uniqid() . '@example.com',
            'ciudad' => 'Caracas', 'estado' => SolicitudMedico::PENDIENTE,
        ], $cambios));
    }

    // ------------------------------------------------------------------------------------------ La migración

    public function test_la_migracion_le_deja_personal_al_consultorio_que_no_tiene_seguros(): void
    {
        $this->consultorio('seg-mig-1');
        $this->consultorio('seg-mig-2');
        // El segundo ya tiene seguros propios: no se toca, aunque ninguno se llame «Personal».
        $this->seguro('seg-mig-2', '05', 'PDVSA');

        (require database_path('migrations/' . self::MIGRACION))->up();

        $this->assertSame([['00', 'PERSONAL']], $this->seguros('seg-mig-1'));
        $this->assertSame([['05', 'PDVSA']], $this->seguros('seg-mig-2'), 'el consultorio con seguros no se toca');

        // Idempotente: una segunda corrida no agrega otra fila.
        (require database_path('migrations/' . self::MIGRACION))->up();
        $this->assertCount(1, $this->seguros('seg-mig-1'));
    }

    public function test_la_migracion_no_toca_el_consultorio_cuyo_unico_seguro_no_es_personal(): void
    {
        $this->consultorio('seg-mig-3');
        $this->seguro('seg-mig-3', '09', 'SEGUROS CARONI');

        (require database_path('migrations/' . self::MIGRACION))->up();

        $this->assertSame([['09', 'SEGUROS CARONI']], $this->seguros('seg-mig-3'));
    }

    // ------------------------------------------------------------------------------------------ La Action

    public function test_la_accion_es_idempotente_y_respeta_al_consultorio_con_seguros(): void
    {
        $accion = app(AsegurarElSeguroPersonal::class);

        $this->assertTrue($accion->paraRegistro('seg-act-1'));
        $this->assertSame([['00', 'PERSONAL']], $this->seguros('seg-act-1'));

        // Dos llamadas, una fila.
        $this->assertFalse($accion->paraRegistro('seg-act-1'));
        $this->assertCount(1, $this->seguros('seg-act-1'));

        // Con seguros propios no se agrega nada.
        $this->seguro('seg-act-2', '07', 'CLINICA METROPOLITANA');
        $this->assertFalse($accion->paraRegistro('seg-act-2'));
        $this->assertSame([['07', 'CLINICA METROPOLITANA']], $this->seguros('seg-act-2'));

        // Un registro vacío o uno que no entra en la columna del legado no escriben nada.
        $this->assertFalse($accion->paraRegistro('   '));
        $this->assertFalse($accion->paraRegistro(str_repeat('x', 21)));
        $this->assertSame(0, DB::table('seg_emp')->whereIn('reg_medico', ['', str_repeat('x', 21)])->count());
    }

    public function test_para_el_medico_recorre_todos_sus_consultorios(): void
    {
        $medico = $this->consultorio('seg-med-1');
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => 'seg-med-2']);
        // El segundo consultorio ya tiene el suyo: sólo el primero estrena.
        $this->seguro('seg-med-2', '03', 'HOSPITAL DE CLINICAS');

        $accion = app(AsegurarElSeguroPersonal::class);

        $this->assertSame(1, $accion->paraElMedico($medico));
        $this->assertSame([['00', 'PERSONAL']], $this->seguros('seg-med-1'));
        $this->assertSame([['03', 'HOSPITAL DE CLINICAS']], $this->seguros('seg-med-2'));

        // Idempotente.
        $this->assertSame(0, $accion->paraElMedico($medico->fresh()));
        $this->assertCount(1, $this->seguros('seg-med-1'));
    }

    // ------------------------------------------------------------------------------------------ El alta

    public function test_el_alta_de_una_solicitud_deja_el_seguro_personal_sin_pasar_por_el_panel(): void
    {
        $especialidad = Specialty::where('slug', 'medicina-general')->firstOrFail();
        $solicitud = $this->solicitud(['specialty_id' => $especialidad->id]);
        $reg = 'SEG-' . random_int(100000, 999999);

        app(AprobarSolicitudDeMedico::class)->ejecutar($solicitud, $this->admin(), $reg);

        $this->assertSame(SolicitudMedico::APROBADA, $solicitud->fresh()->estado);
        $this->assertSame([['00', 'PERSONAL']], $this->seguros($reg));
    }

    public function test_el_alta_sin_especialidad_tambien_deja_el_seguro_personal(): void
    {
        // Sin `specialty_id` no corre la copia del catálogo inicial: el seguro tiene que salir igual.
        $solicitud = $this->solicitud();
        $reg = 'SEG2-' . random_int(100000, 999999);

        app(AprobarSolicitudDeMedico::class)->ejecutar($solicitud, $this->admin(), $reg);

        $this->assertSame([['00', 'PERSONAL']], $this->seguros($reg));
    }

    public function test_el_alta_no_le_agrega_el_seguro_al_medico_del_escritorio(): void
    {
        $email = 'escritorio-' . uniqid() . '@example.com';
        $reg = 'ESC-' . random_int(100000, 999999);
        $ficha = Medico::create([
            'name' => 'Del', 'lastname' => 'Escritorio', 'reg_medico' => $reg, 'email' => $email, 'is_active' => true,
        ]);
        DB::table('sync_cargas')->insert([
            'reg_medico' => $reg, 'medico_id' => $ficha->id, 'estado' => 'completa',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $solicitud = $this->solicitud(['email' => $email]);
        app(AprobarSolicitudDeMedico::class)->ejecutar($solicitud, $this->admin(), $reg);

        // La ficha del escritorio recibe su cuenta, pero no el seguro: lo trae su carga inicial.
        $this->assertNotNull($ficha->fresh()->user_id);
        $this->assertSame([], $this->seguros($reg));
    }
}
