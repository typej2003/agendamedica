<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use App\Sync\Escritorio\TablasLegado;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
     * Un médico con carga inicial: tres pacientes con su historia, una cita cada uno y tres documentos, más
     * los catálogos. Alcanza para que el volcado tenga varias tablas con filas.
     *
     * Las fechas son **dentro de la ventana del volcado** (ver `DIAS_DE_VENTANA`): así lo que baja el
     * volcado paginado tiene que ser exactamente lo mismo que el volcado de una sola vez, que es lo que
     * compara el test de la unión.
     */
    private function medicoConDatos(string $reg, ?string $fecha = null): User
    {
        $user = User::create(['name' => 'Médico', 'email' => "{$reg}@example.com", 'password' => Hash::make('x')]);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Médico', 'lastname' => 'Paginado', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        $cita = $fecha ?? now()->subDays(10)->toDateString();

        $filas = [
            'pacientes' => [
                ['numhistoria' => 1, 'cedula' => "P1-{$reg}", 'apellidos' => 'UNO', 'nombres' => 'ANA', 'nac' => 'V', 'sexo' => 'F'],
                ['numhistoria' => 2, 'cedula' => "P2-{$reg}", 'apellidos' => 'DOS', 'nombres' => 'BEA', 'nac' => 'V', 'sexo' => 'F'],
                ['numhistoria' => 3, 'cedula' => "P3-{$reg}", 'apellidos' => 'TRES', 'nombres' => 'CAR', 'nac' => 'V', 'sexo' => 'M'],
            ],
            'consultas' => [
                ['numhistoria' => 1, 'nroconsulta' => 1, 'fecha' => $cita],
                ['numhistoria' => 2, 'nroconsulta' => 1, 'fecha' => $cita],
            ],
            'cola' => [
                ['fecha' => $cita, 'hora_ini' => '08:00', 'numhistoria' => 1, 'atendido' => 1],
                ['fecha' => $cita, 'hora_ini' => '09:00', 'numhistoria' => 2, 'atendido' => 0],
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
        $indiceAnterior = -1;
        $desdeAnterior = -1;

        while ($cursor !== null && $paginas < 200) {
            // El cursor **nunca vuelve atrás**: el índice de tabla no puede bajar y, dentro de la misma
            // tabla, el id tiene que crecer. Es lo que garantiza que una tabla no se repita en páginas
            // salteadas (una tabla grande ocupa varias páginas **seguidas**, que es lo correcto).
            [$indice, $desde] = array_map('intval', explode(':', $cursor === 'inicio' ? '0:0' : $cursor));
            $this->assertGreaterThanOrEqual($indiceAnterior, $indice, "el cursor volvió atrás: {$cursor}");
            if ($indice === $indiceAnterior) {
                $this->assertGreaterThan($desdeAnterior, $desde, "el id del cursor no avanzó: {$cursor}");
            }
            $indiceAnterior = $indice;
            $desdeAnterior = $desde;

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

    /**
     * Un pedido lleva **varias tablas** (y las vacías no cuestan nada): con una tabla por pedido, la carga
     * inicial eran decenas de micro-pedidos al servidor.
     */
    public function test_un_pedido_lleva_varias_tablas_y_las_vacias_no_cuestan(): void
    {
        Sanctum::actingAs($this->medicoConDatos('pag-e-' . uniqid()), ['*'], 'api');

        // Con un cupo grande, un médico chico entra entero en un solo pedido.
        $cuerpo = $this->sync(['volcado' => 'inicio', 'volcado_limite' => 500])->json();

        $this->assertNull($cuerpo['volcado_siguiente'], 'entra todo en un pedido');
        $conFilas = array_filter(self::CLAVES, fn ($clave) => count((array) $cuerpo[$clave]) > 0);
        $this->assertGreaterThanOrEqual(5, count($conFilas), 'varias tablas en el mismo pedido');
        // Y lo que no tiene filas viaja vacío, no como un pedido aparte.
        $this->assertSame([], (array) $cuerpo['office_schedules']);
    }

    public function test_un_cursor_invalido_se_rechaza(): void
    {
        Sanctum::actingAs($this->medicoConDatos('pag-d-' . uniqid()), ['*'], 'api');

        foreach (['cualquier-cosa', '0', '99:0', 'a:b'] as $malo) {
            $this->postJson('/api/app/sync-app-data', ['volcado' => $malo])->assertStatus(400);
        }
    }

    /**
     * **La ventana del volcado** (2026-10-14): el teléfono no necesita 15 años de historia. Del volcado
     * bajan todas las fichas —para poder buscar a cualquiera sin conexión—, las citas de los últimos meses
     * y las futuras, y la historia **completa** de los pacientes que se atienden en ese período. Lo clínico
     * de los demás no viaja: se pide en línea cuando hace falta.
     */
    public function test_el_volcado_trae_todos_los_pacientes_pero_solo_la_clinica_de_la_ventana(): void
    {
        $reg = 'pag-ventana-' . uniqid();
        Sanctum::actingAs($this->medicoConDatos($reg), ['*'], 'api');

        // Un paciente que no se atiende desde hace medio año: su cita y su consulta son viejas.
        $viejo = now()->subDays(200)->toDateString();
        $this->escritorio('cambios/subir', ['reg_medico' => $reg, 'cambios' => [
            ['id' => 1, 'tabla' => 'cola', 'op' => 'I', 'fecha' => $viejo . ' 08:00:00',
                'clave' => ['numhistoria' => 3, 'fecha' => $viejo],
                'fila' => ['fecha' => $viejo, 'hora_ini' => '08:00', 'numhistoria' => 3, 'atendido' => 1]],
            ['id' => 2, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => $viejo . ' 08:30:00',
                'clave' => ['numhistoria' => 3, 'nroconsulta' => 1],
                'fila' => ['numhistoria' => 3, 'nroconsulta' => 1, 'fecha' => $viejo, 'enfermedadactual' => 'VIEJA']],
        ]]);

        // Su ficha viaja igual: sin ella no se lo podría encontrar en el buscador sin conexión.
        $paginas = $this->recorrerVolcado();
        $cedulas = array_column($paginas['pacientes'], 'cedula');
        $this->assertContains("P3-{$reg}", $cedulas, 'la ficha del paciente viejo baja aunque no se atienda');

        // Y lo clínico del paciente viejo no viaja: su cita vieja queda afuera (y más abajo se ve que su
        // consulta tampoco).
        $fechas = array_map(fn ($f) => substr((string) $f, 0, 10), array_column($paginas['colas'], 'fecha'));
        $this->assertNotContains($viejo, $fechas, 'la cita vieja no viaja');
        $this->assertNotContains('VIEJA', array_column($paginas['consultas'], 'enfermedadactual'));

        // ...pero la de los pacientes de la ventana sí, y **completa**: una consulta vieja de un paciente
        // que se atiende hoy tiene que bajar (el médico la necesita para atenderlo).
        $this->escritorio('cambios/subir', ['reg_medico' => $reg, 'cambios' => [
            ['id' => 3, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => $viejo . ' 09:00:00',
                'clave' => ['numhistoria' => 1, 'nroconsulta' => 2],
                'fila' => ['numhistoria' => 1, 'nroconsulta' => 2, 'fecha' => $viejo, 'enfermedadactual' => 'VIEJA DEL DE LA VENTANA']],
        ]]);

        // Récipes: uno de cada paciente, para comprobar el filtro por `nrohistoria` (las tablas clínicas no
        // todas usan `numhistoria`). Se insertan directo: acá se prueba **el filtro de la ventana**, no el
        // camino del escritorio.
        foreach ([[1, 'RECIPE VENTANA'], [3, 'RECIPE VIEJO']] as [$historia, $descripcion]) {
            DB::table('recipes')->insert([
                'reg_medico' => $reg, 'nrohistoria' => $historia, 'nroconsulta' => 1, 'recipe' => 1,
                'codemedicina' => 'MED-01', 'descripcion' => $descripcion,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $paginas = $this->recorrerVolcado();
        $enfermedades = array_column($paginas['consultas'], 'enfermedadactual');
        $this->assertContains('VIEJA DEL DE LA VENTANA', $enfermedades, 'el historial del paciente de la ventana va entero');
        $this->assertNotContains('VIEJA', $enfermedades, 'y el del paciente que no se atiende, no');

        // Las tablas que se filtran por `nrohistoria` (récipes, informes, diagnósticos…) tienen que
        // respetar la ventana igual que las de `numhistoria`: es lo que se vio mal contra el servidor real.
        $descripciones = array_column($paginas['recipes'], 'descripcion');
        $this->assertContains('RECIPE VENTANA', $descripciones, 'el récipe del paciente de la ventana viaja');
        $this->assertNotContains('RECIPE VIEJO', $descripciones, 'el del paciente viejo, no');
    }

    /**
     * Un volcado **sin** paginado (un app viejo, que no manda `volcado`) sigue bajando todo, sin ventana:
     * el filtro es del app nuevo y no se le puede cambiar el comportamiento a una versión ya instalada.
     */
    public function test_sin_volcado_paginado_no_se_aplica_la_ventana(): void
    {
        $reg = 'pag-legado-' . uniqid();
        Sanctum::actingAs($this->medicoConDatos($reg, now()->subDays(200)->toDateString()), ['*'], 'api');

        $cuerpo = $this->sync([])->json();

        $this->assertCount(2, $cuerpo['colas'], 'las citas viejas también, como hasta hoy');
        $this->assertCount(2, $cuerpo['consultas']);
        $this->assertNull($cuerpo['ventana_desde'], 'sin volcado paginado no hay ventana que informar');
    }

    /** Junta el volcado paginado entero (una fila por clave) siguiendo el cursor. */
    private function recorrerVolcado(): array
    {
        $todo = array_fill_keys(self::CLAVES, []);
        $cursor = 'inicio';

        while ($cursor !== null) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 50])->json();
            foreach (self::CLAVES as $clave) {
                foreach ((array) $cuerpo[$clave] as $fila) {
                    $todo[$clave][] = $fila;
                }
            }
            $cursor = $cuerpo['volcado_siguiente'];
        }

        return $todo;
    }

    /**
     * El mapa de qué columna lleva la historia en cada tabla tiene que seguir siendo cierto: si una tabla
     * cambia de nombre de columna (o el mapa se copia mal), cualquier filtro que lo use corta de más o de
     * menos sin que nadie se entere. La trampa 122 nació justo de eso.
     */
    public function test_el_mapa_de_columnas_de_historia_sigue_siendo_cierto(): void
    {
        foreach (TablasLegado::columnasDeHistoria() as $tabla => $columna) {
            $this->assertTrue(Schema::hasTable($tabla), "la tabla {$tabla} existe en el API");
            $this->assertTrue(Schema::hasColumn($tabla, $columna), "la columna {$tabla}.{$columna} existe");
        }

        // `cola` tiene las DOS en el API, pero la que lleva el número de historia es `numhistoria`:
        // el `nrohistoria` de esa tabla es viejo y no lo lleva (trampa 122).
        $this->assertSame('numhistoria', TablasLegado::columnaHistoria('cola'));
        $this->assertSame('nrohistoria', TablasLegado::columnaHistoria('recipes'));
    }
}