<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **Volcado paginado** de `/app/sync-app-data` (2026-10-14).
 *
 * La primera sincronización de un médico con años de historia no entra en una sola respuesta: el volcado
 * de un pediatra (6.893 pacientes, 24.762 consultas y 24.785 citas) mató a PHP en `json_encode` con 512 MB.
 * Ahora el app puede pedir el dataset entero de a tramos, siguiendo un cursor.
 *
 * Lo que se fija acá, en orden de importancia:
 *  1. **La unión de los tramos es idéntica al volcado de una sola vez**: es la garantía de que paginar no
 *     pierde ni repite nada (el bug que este diseño podría introducir sin que se note).
 *  2. Que el cursor recorra todas las tablas y termine en `null`.
 *  3. Que **sin** `volcado` la respuesta sea la de siempre — un app ya instalado no conoce el paginado y
 *     tiene que seguir recibiendo el dataset completo.
 */
class SyncVolcadoPaginadoTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';

    private function escritorio(string $ruta, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$ruta}", $cuerpo, ['X-API-KEY' => self::API_KEY])->assertOk();
    }

    /**
     * Un médico con carga inicial: dos pacientes con su historia, una cita cada uno y tres documentos, más
     * los catálogos. Alcanza para que el volcado tenga varias tablas con filas.
     */
    private function medicoConDatos(string $reg): User
    {
        $user = User::create(['name' => 'Médico', 'email' => "{$reg}@example.com", 'password' => Hash::make('x')]);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Médico', 'lastname' => 'Paginado', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        $filas = [
            'pacientes' => [
                ['numhistoria' => 1, 'cedula' => "P1-{$reg}", 'apellidos' => 'UNO', 'nombres' => 'ANA', 'nac' => 'V', 'sexo' => 'F'],
                ['numhistoria' => 2, 'cedula' => "P2-{$reg}", 'apellidos' => 'DOS', 'nombres' => 'BEA', 'nac' => 'V', 'sexo' => 'F'],
                ['numhistoria' => 3, 'cedula' => "P3-{$reg}", 'apellidos' => 'TRES', 'nombres' => 'CAR', 'nac' => 'V', 'sexo' => 'M'],
            ],
            'consultas' => [
                ['numhistoria' => 1, 'nroconsulta' => 1, 'fecha' => '2026-03-10'],
                ['numhistoria' => 2, 'nroconsulta' => 1, 'fecha' => '2026-03-11'],
            ],
            'cola' => [
                ['fecha' => '2026-03-10', 'hora_ini' => '08:00', 'numhistoria' => 1, 'atendido' => 1],
                ['fecha' => '2026-03-11', 'hora_ini' => '09:00', 'numhistoria' => 2, 'atendido' => 0],
            ],
            'constancia_obs' => [['numhistoria' => 1, 'numconsulta' => 1, 'observacion01' => 'Asistió']],
            'diagnostico_paciente' => [['nrohistoria' => 1, 'nroconsulta' => 1, 'codediagnostico' => 'D01', 'orden' => 1]],
            'diagnosticos' => [['codediagnostico' => 'D01', 'descripcion' => 'CONTROL']],
            'motivo_cita' => [['codigo' => '01', 'razon' => 'CONSULTA', 'precio' => 100]],
        ];

        $carga = $this->escritorio('carga-inicial/iniciar', [
            'reg_medico' => $reg,
            'tablas' => array_map('count', $filas),
        ])->json('carga_id');

        foreach ($filas as $tabla => $lote) {
            $this->escritorio('carga-inicial/lote', [
                'carga_id' => $carga, 'tabla' => $tabla, 'desde' => 0, 'filas' => $lote,
            ]);
        }
        $this->escritorio('carga-inicial/finalizar', ['carga_id' => $carga]);

        return $user;
    }

    /** Las claves del volcado que el app vuelca en su copia local. */
    private const CLAVES = [
        'pacientes', 'historias', 'colas', 'consultas', 'motivos', 'motivos_consulta',
        'motivo_consulta_paciente', 'recipes', 'vademecum', 'tratamientos', 'tratamientos_detalle',
        'constancia_obs', 'reposo_paciente', 'referencia', 'informe', 'diagnostico_paciente',
        'diagnosticos', 'doctores', 'especial', 'centros_medicos', 'offices', 'office_schedules',
    ];

    private function sync(array $cuerpo)
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    public function test_el_volcado_paginado_recorre_todas_las_tablas_y_termina(): void
    {
        Sanctum::actingAs($this->medicoConDatos('pag-a-' . uniqid()), ['*'], 'api');

        $cursor = 'inicio';
        $paginas = 0;
        $vistas = [];

        while ($cursor !== null && $paginas < 200) {
            $respuesta = $this->sync(['volcado' => $cursor, 'volcado_limite' => 2]);
            $cuerpo = $respuesta->json();

            foreach (self::CLAVES as $clave) {
                $this->assertArrayHasKey($clave, $cuerpo, "la respuesta tiene que traer {$clave} (vacía si no toca)");
                foreach ((array) $cuerpo[$clave] as $fila) {
                    $vistas[$clave][] = $fila['id'];
                }
            }

            $cursor = $cuerpo['volcado_siguiente'];
            $paginas++;
        }

        $this->assertLessThan(200, $paginas, 'el volcado tiene que terminar');
        // Con tramos de 2 filas y tres pacientes, `pacientes` tiene que haber venido en más de una página.
        $this->assertGreaterThan(1, $paginas);
        $this->assertCount(3, $vistas['pacientes'], 'los tres pacientes, cada uno una vez');
        $this->assertSame($vistas['pacientes'], array_values(array_unique($vistas['pacientes'])), 'sin repetidos');
        $this->assertSame(
            collect($vistas['pacientes'])->sort()->values()->all(),
            $vistas['pacientes'],
            'y en orden de id (es lo que hace el cursor)'
        );
        $this->assertCount(2, $vistas['consultas']);
        $this->assertCount(2, $vistas['colas']);
        $this->assertCount(1, $vistas['constancia_obs']);
    }

    /**
     * **La prueba que importa**: lo que se arma juntando los tramos tiene que ser exactamente lo mismo que
     * el volcado de una sola vez. Sin esto, paginar podría perder filas o mandarlas dos veces sin que se
     * note (el teléfono sobreescribe, así que un tramo perdido sólo se vería como datos que faltan).
     */
    public function test_la_union_de_los_tramos_es_igual_al_volcado_de_una_sola_vez(): void
    {
        Sanctum::actingAs($this->medicoConDatos('pag-b-' . uniqid()), ['*'], 'api');

        // 1) De una sola vez (el camino de siempre, sin `volcado`).
        $completo = $this->sync([])->json();

        // 2) De a tramos.
        $cursor = 'inicio';
        $paginado = [];
        while ($cursor !== null) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 1])->json();
            foreach (self::CLAVES as $clave) {
                foreach ((array) $cuerpo[$clave] as $fila) {
                    $paginado[$clave][] = $fila;
                }
            }
            $cursor = $cuerpo['volcado_siguiente'];
        }

        foreach (self::CLAVES as $clave) {
            $deUna = collect($completo[$clave])->sortBy('id')->values()->all();
            $deTramos = collect($paginado[$clave] ?? [])->sortBy('id')->values()->all();
            $this->assertSame(
                $deUna, $deTramos,
                "las filas de {$clave} tienen que ser las mismas por los dos caminos"
            );
        }
    }

    public function test_sin_volcado_la_respuesta_es_la_de_siempre(): void
    {
        Sanctum::actingAs($this->medicoConDatos('pag-c-' . uniqid()), ['*'], 'api');

        $cuerpo = $this->sync([])->json();

        $this->assertNull($cuerpo['volcado_siguiente'], 'sin volcado paginado no hay cursor');
        $this->assertCount(3, $cuerpo['pacientes'], 'el volcado completo llega entero, como antes');
        $this->assertNotEmpty($cuerpo['consultas']);
        $this->assertNotEmpty($cuerpo['historias']);
    }

    public function test_un_cursor_invalido_se_rechaza(): void
    {
        Sanctum::actingAs($this->medicoConDatos('pag-d-' . uniqid()), ['*'], 'api');

        foreach (['cualquier-cosa', '0', '99:0', 'a:b'] as $malo) {
            $this->postJson('/api/app/sync-app-data', ['volcado' => $malo])->assertStatus(400);
        }
    }
}
