<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\ReportesEscritorio as Bandeja;
use App\Models\ReporteEscritorio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Reportes de fallo del escritorio**: el endpoint público que usan el launcher y el instalador, y la
 * bandeja del panel donde se leen y se borran.
 *
 * Lo que fijan estos tests:
 *  - el reporte entra sin credencial (el que falla puede no tenerla), con sus campos y el detalle recortado;
 *  - se rechaza lo que no es un reporte (tipo inventado, mensaje vacío, detalle gigante);
 *  - la bandeja es del panel (Root/Administrador) y borra.
 */
class ReportesEscritorioTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name'             => 'Usuario de prueba',
            'email'            => 'reportes-' . substr(uniqid(), -6) . '@example.com',
            'password'         => Hash::make('secreto123'),
            'is_active'        => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function reporte(array $cambios = []): ReporteEscritorio
    {
        return ReporteEscritorio::create(array_merge([
            'tipo'    => ReporteEscritorio::LAUNCHER,
            'etapa'   => 'descarga: bridge/sync.exe',
            'mensaje' => 'el archivo llego incompleto',
            'equipo'  => 'CONSULTORIO-1',
        ], $cambios));
    }

    public function test_el_reporte_entra_sin_credencial(): void
    {
        $respuesta = $this->postJson('/api/escritorio/reportes', [
            'tipo'          => 'instalador',
            'etapa'         => '4. Base de datos',
            'mensaje'       => 'no se pudo abrir la base por el DSN Datoped',
            'reg_medico'    => 'ped-0001',
            'aplicacion'    => 'pediareport.exe',
            'equipo'        => 'CONSULTORIO-1',
            'version_canal' => '20261023.201359',
            'windows'       => 'Windows 7 SP1 x86',
            'detalle'       => "ERROR inesperado...\ncodigo -2147024680",
        ]);

        $respuesta->assertStatus(201)->assertJson(['ok' => true]);

        $this->assertDatabaseHas('reportes_escritorio', [
            'tipo'       => 'instalador',
            'reg_medico' => 'ped-0001',
            'equipo'     => 'CONSULTORIO-1',
        ]);
    }

    public function test_rechaza_lo_que_no_es_un_reporte(): void
    {
        $this->postJson('/api/escritorio/reportes', ['tipo' => 'inventado', 'mensaje' => 'x'])
            ->assertStatus(422);

        $this->postJson('/api/escritorio/reportes', ['tipo' => 'launcher'])
            ->assertStatus(422);

        $this->postJson('/api/escritorio/reportes', ['tipo' => 'launcher', 'mensaje' => 'x', 'detalle' => str_repeat('a', 70000)])
            ->assertStatus(422);
    }

    public function test_el_detalle_se_recorta(): void
    {
        $this->postJson('/api/escritorio/reportes', [
            'tipo'    => 'sincronizacion',
            'mensaje' => 'x',
            'detalle' => str_repeat('b', 63990),
        ])->assertStatus(201);

        $this->assertSame(63990, mb_strlen(ReporteEscritorio::latest('id')->first()->detalle));
    }

    public function test_la_bandeja_es_del_panel(): void
    {
        $this->actingAs($this->usuario('Medico'))
            ->get('/admin/reportes-escritorio')
            ->assertStatus(403);

        $this->actingAs($this->usuario('Root'))
            ->get('/admin/reportes-escritorio')
            ->assertOk();
    }

    public function test_la_bandeja_lista_y_borra(): void
    {
        $reporte = $this->reporte(['reg_medico' => 'gine-0002']);

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(Bandeja::class)
            ->assertSee('gine-0002')
            ->call('confirmarBorrado', $reporte->id)
            ->call('eliminar', $reporte->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('reportes_escritorio', ['id' => $reporte->id]);
    }

    public function test_la_bandeja_filtra_por_origen_y_texto(): void
    {
        $this->reporte(['tipo' => 'launcher', 'equipo' => 'PC-UNO']);
        $this->reporte(['tipo' => 'instalador', 'equipo' => 'PC-DOS']);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Bandeja::class)
            ->set('tipo', 'instalador')
            ->assertSee('PC-DOS')
            ->assertDontSee('PC-UNO')
            ->set('tipo', '')
            ->set('buscar', 'PC-UNO')
            ->assertSee('PC-UNO')
            ->assertDontSee('PC-DOS');
    }
}
