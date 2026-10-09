<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use App\Sync\Escritorio\RestauracionEscritorio;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Restauración manual nube → escritorio (lo inverso de la carga inicial).
 *
 * El médico se arma con los endpoints REALES de la carga inicial, así que lo que se exporta es lo
 * mismo que subió el escritorio, pasado por las traducciones del sync (estado/atendido de las
 * citas, pacientes por número de historia, columnas excluidas de `evolucion`).
 */
class RestauracionEscritorioTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';
    private const REG = 'restaura-001';

    private Medico $medico;
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create(['name' => 'Médico restauración', 'email' => 'restaura-' . uniqid() . '@example.com', 'password' => Hash::make('x')]);
        $this->medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Médico', 'lastname' => 'Restauración', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => self::REG,
        ]);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => self::REG]);

        $carga = $this->post_('carga-inicial/iniciar', ['reg_medico' => self::REG, 'tablas' => [
            'pacientes' => 2, 'cola' => 2, 'consultas' => 1, 'evolucion' => 1, 'antece_paciente' => 1,
        ]])->assertOk()->json('carga_id');

        $lote = fn ($tabla, $filas) => $this->post_('carga-inicial/lote', [
            'carga_id' => $carga, 'tabla' => $tabla, 'desde' => 0, 'filas' => $filas,
        ])->assertOk();

        $lote('pacientes', [
            ['numhistoria' => 1, 'cedula' => 'REST-1', 'nac' => 'V', 'apellidos' => 'MUÑOZ', 'nombres' => 'José María', 'sexo' => 'M'],
            ['numhistoria' => 2, 'cedula' => 'REST-2', 'nac' => 'V', 'apellidos' => 'PEREZ', 'nombres' => 'Ana', 'sexo' => 'F', 'telefono' => null],
        ]);
        $lote('cola', [
            // Facturada en el escritorio (`estado = 1`): tiene que volver con estado 1.
            ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'motivo' => 'CONTROL', 'estado' => 1],
            // Movida a otro día (`atendido = 2`): tiene que volver con atendido 2.
            ['fecha' => '2026-10-02', 'hora_ini' => '09:00:00', 'numhistoria' => 2, 'motivo' => 'ECO', 'atendido' => 2],
        ]);
        $lote('consultas', [['numhistoria' => 1, 'nroconsulta' => 1, 'fecha' => '2026-10-01', 'enfermedadactual' => 'Texto largo']]);
        // Credenciales y logo: el API los guarda afuera (o no los guarda) y NO pueden volver.
        $lote('evolucion', [['clave' => 1, 'especialidad' => 'Ginecología', 'rif' => 'J-123', 'contrasena' => 'SECRETA', 'sms_clave' => 'SMS-CLAVE', 'logo' => 'C:\\logo.jpg']]);
        $lote('antece_paciente', [['numhistoria' => 1, 'codeantecedente' => 'A1', 'detalles' => "linea1\nlinea2\tfin"]]);
        $this->post_('carga-inicial/finalizar', ['carga_id' => $carga])->assertOk();

        $this->carpeta = storage_path('app/restauracion-test-' . uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);
        parent::tearDown();
    }

    private function post_(string $ruta, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$ruta}", $cuerpo, ['X-API-KEY' => self::API_KEY]);
    }

    private function exportar(): array
    {
        return app(RestauracionEscritorio::class)->exportar(self::REG, $this->carpeta);
    }

    /** @return string[] */
    private function manifiesto(): array
    {
        return explode("\n", str_replace("\r\n", "\n", File::get($this->carpeta . '/manifiesto.txt')));
    }

    /** La línea del manifiesto de una tabla: [tipo, tabla, archivo, columnas, filas]. */
    private function lineaDe(string $tabla): ?array
    {
        foreach ($this->manifiesto() as $linea) {
            $campos = explode("\t", $linea);
            if (count($campos) >= 2 && $campos[0] === 'tabla' && $campos[1] === $tabla) {
                return $campos;
            }
        }

        return null;
    }

    /** @return string[] */
    private function filasDe(string $tabla): array
    {
        $linea = $this->lineaDe($tabla);
        $this->assertNotNull($linea, "El manifiesto no tiene la tabla {$tabla}.");

        $contenido = str_replace("\r\n", "\n", File::get($this->carpeta . '/' . $linea[2]));

        return array_values(array_filter(explode("\n", $contenido), fn ($l) => $l !== ''));
    }

    /* ------------------------------------------------------------------ */

    public function test_el_manifiesto_identifica_el_formato_el_medico_y_las_tablas(): void
    {
        $resultado = $this->exportar();

        $this->assertSame('ddr-restauracion-1', $this->manifiesto()[0]);
        $this->assertContains('medico' . "\t" . self::REG, $this->manifiesto());
        $this->assertSame(5, count($resultado['archivos']));
        $this->assertArrayHasKey('pacientes', $resultado['archivos']);
        $this->assertArrayHasKey('bancos', array_flip($resultado['vacias']));
        $this->assertFileExists($this->carpeta . '/datos/pacientes.tsv');
        // Una tabla sin filas no deja archivo.
        $this->assertFileDoesNotExist($this->carpeta . '/datos/bancos.tsv');
    }

    public function test_pacientes_vuelve_con_el_numero_de_historia_del_escritorio(): void
    {
        $this->exportar();

        $linea = $this->lineaDe('pacientes');
        $columnas = explode(',', $linea[3]);
        $this->assertContains('numhistoria', $columnas);
        // Columnas del API que el escritorio no conoce.
        $this->assertNotContains('user_id', $columnas);
        $this->assertNotContains('password', $columnas);
        $this->assertNotContains('id', $columnas);

        $filas = $this->filasDe('pacientes');
        $this->assertCount(2, $filas);

        $posHistoria = array_search('numhistoria', $columnas, true);
        $posNombres = array_search('nombres', $columnas, true);
        $porHistoria = [];
        foreach ($filas as $fila) {
            $valores = explode("\t", $fila);
            $porHistoria[$valores[$posHistoria]] = $valores[$posNombres];
        }

        $this->assertSame('José María', $porHistoria['1']);
        $this->assertSame('Ana', $porHistoria['2']);
    }

    public function test_cola_vuelve_con_la_semantica_del_escritorio(): void
    {
        $this->exportar();

        $columnas = explode(',', $this->lineaDe('cola')[3]);
        $this->assertNotContains('facturada_escritorio', $columnas);
        $this->assertNotContains('movida_escritorio', $columnas);
        $this->assertNotContains('paciente_sinhistoria_id', $columnas);
        $this->assertNotContains('clave_escritorio', $columnas);

        $posEstado = array_search('estado', $columnas, true);
        $posAtendido = array_search('atendido', $columnas, true);
        $posMotivo = array_search('motivo', $columnas, true);

        $porMotivo = [];
        foreach ($this->filasDe('cola') as $fila) {
            $valores = explode("\t", $fila);
            $porMotivo[$valores[$posMotivo]] = ['estado' => $valores[$posEstado], 'atendido' => $valores[$posAtendido]];
        }

        // La cita facturada vuelve con estado 1; la otra, con 0.
        $this->assertSame('1', $porMotivo['CONTROL']['estado']);
        $this->assertSame('0', $porMotivo['ECO']['estado']);
        // La cita movida vuelve con atendido 2 (el escritorio la esconde del día).
        $this->assertSame('2', $porMotivo['ECO']['atendido']);
        $this->assertSame('0', $porMotivo['CONTROL']['atendido']);
    }

    public function test_el_formato_escapa_tabuladores_saltos_y_nulos(): void
    {
        $this->exportar();

        $columnas = explode(',', $this->lineaDe('antece_paciente')[3]);
        $posDetalles = array_search('detalles', $columnas, true);
        $valores = explode("\t", $this->filasDe('antece_paciente')[0]);

        $this->assertSame('linea1\nlinea2\tfin', $valores[$posDetalles]);

        // El teléfono nulo del segundo paciente viaja como \N.
        $pacientes = $this->filasDe('pacientes');
        $columnasPaciente = explode(',', $this->lineaDe('pacientes')[3]);
        $posTelefono = array_search('telefono', $columnasPaciente, true);
        $this->assertSame('\N', explode("\t", $pacientes[1])[$posTelefono]);
    }

    public function test_evolucion_no_devuelve_credenciales_ni_logo(): void
    {
        $resultado = $this->exportar();

        $columnas = explode(',', $this->lineaDe('evolucion')[3]);
        foreach (['contrasena', 'sms_clave', 'sms_user', 'logo'] as $columna) {
            $this->assertNotContains($columna, $columnas);
            $this->assertContains($columna, $resultado['omitidas']['evolucion']);
        }

        $contenido = File::get($this->carpeta . '/datos/evolucion.tsv');
        $this->assertStringNotContainsString('SECRETA', $contenido);
        $this->assertStringNotContainsString('SMS-CLAVE', $contenido);
        // La configuración que sí se puede devolver viaja.
        $this->assertStringContainsString('Ginecología', $contenido);
    }

    public function test_el_comando_exporta_a_la_carpeta_indicada(): void
    {
        $this->artisan('sync:restaurar-escritorio', [
            '--reg-medico' => self::REG,
            '--salida'     => $this->carpeta,
        ])->assertExitCode(0);

        $this->assertFileExists($this->carpeta . '/manifiesto.txt');
        $this->assertFileExists($this->carpeta . '/datos/cola.tsv');
    }

    public function test_el_comando_avisa_si_el_medico_no_existe(): void
    {
        $this->artisan('sync:restaurar-escritorio', [
            '--reg-medico' => 'no-existe-' . uniqid(),
            '--salida'     => $this->carpeta,
        ])->expectsConfirmation('¿Exportar igual?', false)
          ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->carpeta . '/manifiesto.txt');
    }

    public function test_el_comando_exporta_igual_con_si_para_un_medico_no_registrado(): void
    {
        // --si es para instalaciones desatendidas: no puede quedar esperando una respuesta.
        $this->artisan('sync:restaurar-escritorio', [
            '--reg-medico' => 'no-existe-' . uniqid(),
            '--salida'     => $this->carpeta,
            '--si'         => true,
        ])->assertExitCode(0);

        $this->assertFileExists($this->carpeta . '/manifiesto.txt');
    }
}
