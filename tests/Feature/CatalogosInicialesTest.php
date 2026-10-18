<?php

namespace Tests\Feature;

use App\Actions\Catalogos\CopiarCatalogoInicial;
use App\Actions\Catalogos\GuardarFilaDeCatalogoInicial;
use App\Actions\Catalogos\GuardarLineaDeTratamientoInicial;
use App\Actions\Catalogos\QuitarFilaDeCatalogoInicial;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales as Registro;
use App\Http\Livewire\Admin\CatalogosIniciales;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use App\Services\CuentaService;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Los catálogos iniciales** (WEB-12.8): el registro único, la copia genérica al consultorio (`CopiarCatalogoInicial`), el ABM del administrador (un solo componente) y la
 * tarjeta «Cargar el catálogo inicial» de las pantallas del médico. Se fija que copia sólo lo que falta y nunca pisa lo del médico; que los códigos son del médico; que
 * respeta las dependencias (examen→tipo, tratamiento→medicamentos); que cada fila copiada deja su `created`; y que el catálogo inicial sólo lo edita un administrador.
 */
class CatalogosInicialesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Registro::todos() as $def) {
            DB::table($def['tabla'])->whereNull('reg_medico')->delete();
        }
        DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->delete();
    }

    // ------------------------------------------------------------------------------------------ Ayudas

    private function ini(string $tabla, array $fila): int
    {
        return DB::table($tabla)->insertGetId($fila + ['reg_medico' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function propia(string $tabla, string $reg, array $fila): int
    {
        return DB::table($tabla)->insertGetId($fila + ['reg_medico' => $reg, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'cat-ini-' . uniqid();

        $user = User::create(['name' => 'Usuario', 'email' => 'cat-ini-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false]);
        $user->assignRole('Medico');
        $medico = Medico::create(['user_id' => $user->id, 'name' => 'Dra', 'lastname' => 'Inicial', 'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg]);
        $medico->specialties()->attach($esp->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $esp];
    }

    private function conContexto(Medico $medico, Specialty $esp): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $esp->id, 'office_id' => null]]);
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Cat', 'admin-cat-' . uniqid() . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function copiar(string $slug, Medico $medico): array
    {
        return app(CopiarCatalogoInicial::class)->ejecutar($slug, $medico->reg_medico, [$medico->reg_medico]);
    }

    // ------------------------------------------------------------------------------------------ El registro

    public function test_el_registro_declara_lo_que_la_copia_y_el_abm_necesitan(): void
    {
        $this->assertSame(
            ['medicamentos', 'tratamientos', 'diagnosticos', 'motivos-consulta', 'motivos-cita', 'tipos-examen', 'examenes', 'plantillas-texto'],
            array_keys(Registro::todos())
        );

        foreach (Registro::todos() as $slug => $def) {
            foreach (['titulo', 'singular', 'tabla', 'codigo', 'digitos', 'etiqueta', 'identidad', 'columnas', 'campos'] as $clave) {
                $this->assertArrayHasKey($clave, $def, "$slug sin $clave");
            }
            foreach ($def['columnas'] as $columna) {
                $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($def['tabla'], $columna), "$slug: falta {$def['tabla']}.$columna");
            }
            foreach ($def['refs'] ?? [] as $destino) {
                $this->assertTrue(Registro::existe($destino));
            }
        }
    }

    // ------------------------------------------------------------------------------------------ La copia: catálogos simples

    public function test_copia_lo_que_falta_con_codigos_del_medico_y_no_pisa_lo_suyo(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('diagnosticos', ['codediagnostico' => '0001', 'descripcion' => 'ASMA']);
        $this->ini('diagnosticos', ['codediagnostico' => '0002', 'descripcion' => 'OTITIS MEDIA']);
        $this->ini('diagnosticos', ['codediagnostico' => '0003', 'descripcion' => 'asma ']); // repetida en el inicial
        $this->propia('diagnosticos', $medico->reg_medico, ['codediagnostico' => '0007', 'descripcion' => 'Ásma']); // la tiene, con otra forma
        $ajeno = $this->propia('diagnosticos', 'otro-medico', ['codediagnostico' => '0001', 'descripcion' => 'FIEBRE']);

        $r = $this->copiar('diagnosticos', $medico);

        $this->assertSame(1, $r['copiadas']);
        $filas = DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)->orderBy('codediagnostico')->get();
        $this->assertSame(['Ásma', 'OTITIS MEDIA'], $filas->pluck('descripcion')->all());
        $this->assertSame(['0007', '0008'], $filas->pluck('codediagnostico')->all());
        $this->assertSame(2, DB::table('diagnosticos')->whereNull('reg_medico')->count() - 1);
        $this->assertSame('FIEBRE', DB::table('diagnosticos')->where('id', $ajeno)->value('descripcion'));

        // Idempotente, y cada fila copiada deja su `created` (lo que protege las tablas sin clave de la subida del escritorio).
        $this->assertSame(0, $this->copiar('diagnosticos', $medico)['copiadas']);
        $id = $filas->last()->id;
        $rastro = SyncChange::where('table_name', 'diagnosticos')->where('record_id', $id)->first();
        $this->assertSame('created', $rastro->operation);
        $this->assertSame('web', $rastro->source);
        $this->assertSame($medico->reg_medico, $rastro->reg_medico);
    }

    public function test_el_correlativo_se_mide_sobre_todos_los_registros_del_medico(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'CONTROL']);
        $segundo = 'cat-ini-segundo-' . uniqid();
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $segundo]);
        $this->propia('motivos_consulta', $segundo, ['codemotivo' => '0040', 'descripcion' => 'FIEBRE']);

        app(CopiarCatalogoInicial::class)->ejecutar('motivos-consulta', $medico->reg_medico, [$medico->reg_medico, $segundo]);

        $this->assertSame('0041', DB::table('motivos_consulta')->where('reg_medico', $medico->reg_medico)->value('codemotivo'));
    }

    public function test_motivos_de_cita_copian_precio_y_marca_sin_sede_y_con_dos_digitos(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('motivo_cita', ['codigo' => '01', 'tipo_atencion' => 'CONSULTA', 'precio' => 30.5, 'admite_varias_citas_pendientes' => true]);
        $this->ini('motivo_cita', ['codigo' => '02', 'tipo_atencion' => 'CONTROL', 'precio' => null]);
        $this->propia('motivo_cita', $medico->reg_medico, ['codigo' => '05', 'tipo_atencion' => 'Control']);

        $this->assertSame(1, $this->copiar('motivos-cita', $medico)['copiadas']);

        $fila = DB::table('motivo_cita')->where('reg_medico', $medico->reg_medico)->where('tipo_atencion', 'CONSULTA')->first();
        $this->assertSame('06', $fila->codigo);
        $this->assertEquals(30.5, $fila->precio);
        $this->assertEquals(1, $fila->admite_varias_citas_pendientes);
        $this->assertNull($fila->office_id);
    }

    public function test_las_plantillas_se_identifican_por_tipo_y_titulo(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('formato_print', ['codigo' => '0001', 'tipo' => 'EV', 'titulo' => 'CONTROL SANO', 'descripcion' => 'Todo bien.']);
        $this->ini('formato_print', ['codigo' => '0002', 'tipo' => 'DI', 'titulo' => 'CONTROL SANO', 'descripcion' => 'Dieta.']);
        $this->propia('formato_print', $medico->reg_medico, ['codigo' => '0003', 'tipo' => 'EV', 'titulo' => 'Control sano', 'descripcion' => 'Mi texto']);

        $r = $this->copiar('plantillas-texto', $medico);

        $this->assertSame(1, $r['copiadas']);
        $this->assertSame('Dieta.', DB::table('formato_print')->where('reg_medico', $medico->reg_medico)->where('tipo', 'DI')->value('descripcion'));
        $this->assertSame('Mi texto', DB::table('formato_print')->where('reg_medico', $medico->reg_medico)->where('tipo', 'EV')->value('descripcion'));
    }

    public function test_medicamentos_por_nombre_comercial_y_presentacion(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('vademecum', ['codemedicina' => '0001', 'nombrecomercial' => 'ADEVIT', 'presentacion' => 'jarabe', 'nombregenerico' => 'VITAMINA A', 'uso' => 'Una al día', 'totalre' => 99]);
        $this->ini('vademecum', ['codemedicina' => '0002', 'nombrecomercial' => 'ADEVIT', 'presentacion' => 'gotas']);
        $this->propia('vademecum', $medico->reg_medico, ['codemedicina' => '0010', 'nombrecomercial' => 'Adevit', 'presentacion' => 'Jarabe']);

        $this->assertSame(1, $this->copiar('medicamentos', $medico)['copiadas']);

        $nuevo = DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('presentacion', 'gotas')->first();
        $this->assertSame('0011', $nuevo->codemedicina);
    }

    // ------------------------------------------------------------------------------------------ La copia: dependencias

    public function test_un_examen_se_mapea_al_tipo_que_el_medico_ya_tiene_o_copia_el_tipo(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('tipos_examenes', ['codetipo' => '001', 'tipo' => 'LABORATORIO']);
        $this->ini('tipos_examenes', ['codetipo' => '002', 'tipo' => 'IMAGENES']);
        $this->ini('examenes', ['codeexamen' => '001', 'examen' => 'HEMOGRAMA', 'codetipo' => '001']);
        $this->ini('examenes', ['codeexamen' => '002', 'examen' => 'RX DE TORAX', 'codetipo' => '002']);
        // El médico ya tiene «Laboratorio» pero con el código 007.
        $this->propia('tipos_examenes', $medico->reg_medico, ['codetipo' => '007', 'tipo' => 'Laboratorio']);

        $r = $this->copiar('examenes', $medico);

        $this->assertSame(2, $r['copiadas']);
        $this->assertSame(1, $r['dependencias']);
        $hemo = DB::table('examenes')->where('reg_medico', $medico->reg_medico)->where('examen', 'HEMOGRAMA')->first();
        $this->assertSame('007', $hemo->codetipo);
        $rx = DB::table('examenes')->where('reg_medico', $medico->reg_medico)->where('examen', 'RX DE TORAX')->first();
        $tipoCopiado = DB::table('tipos_examenes')->where('reg_medico', $medico->reg_medico)->where('codetipo', $rx->codetipo)->first();
        $this->assertSame('IMAGENES', $tipoCopiado->tipo);
        $this->assertSame('008', $tipoCopiado->codetipo);
        $this->assertSame(0, $this->copiar('examenes', $medico)['copiadas']);
    }

    public function test_un_examen_cuyo_tipo_no_existe_se_omite_y_se_informa(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('examenes', ['codeexamen' => '001', 'examen' => 'HUERFANO', 'codetipo' => '999']);

        $r = $this->copiar('examenes', $medico);

        $this->assertSame(0, $r['copiadas']);
        $this->assertCount(1, $r['omitidas']);
        $this->assertSame(0, DB::table('examenes')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_tratamiento_lleva_su_detalle_y_mapea_los_medicamentos(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('vademecum', ['codemedicina' => '0001', 'nombrecomercial' => 'AMOXIL', 'presentacion' => 'jarabe']);
        $this->ini('vademecum', ['codemedicina' => '0002', 'nombrecomercial' => 'TEMPRA', 'presentacion' => 'gotas']);
        $this->ini('recipe_grupo', ['codigo' => '0001', 'tratamiento' => 'OTITIS']);
        $this->ini('recipe_grupo_detalle', ['codigo' => '0001', 'codemedicina' => '0001', 'cantidad' => 1, 'indicaciones' => 'Cada 8 horas', 'orden' => 0]);
        $this->ini('recipe_grupo_detalle', ['codigo' => '0001', 'codemedicina' => '0002', 'cantidad' => 2, 'indicaciones' => null, 'orden' => 1]);
        $this->ini('recipe_grupo_detalle', ['codigo' => '0001', 'codemedicina' => '0099', 'cantidad' => 1, 'orden' => 2]); // dangling
        // El médico ya tiene AMOXIL/jarabe con otro código.
        $this->propia('vademecum', $medico->reg_medico, ['codemedicina' => '0020', 'nombrecomercial' => 'Amoxil', 'presentacion' => 'Jarabe']);

        $r = $this->copiar('tratamientos', $medico);

        $this->assertSame(1, $r['copiadas']);
        $this->assertSame(1, $r['dependencias']);
        $this->assertSame(2, $r['hijas']);
        $this->assertCount(1, $r['omitidas']);

        $grupo = DB::table('recipe_grupo')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('0001', $grupo->codigo);
        $lineas = DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codigo', $grupo->codigo)->orderBy('orden')->get();
        $this->assertSame(['0020', '0021'], $lineas->pluck('codemedicina')->all());
        $this->assertSame('Cada 8 horas', $lineas[0]->indicaciones);
        $this->assertSame('TEMPRA', DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0021')->value('nombrecomercial'));

        // Cada fila, encabezado y línea, deja su `created` bajo su tabla.
        $this->assertSame(1, SyncChange::where('table_name', 'recipe_grupo')->where('record_id', $grupo->id)->where('operation', 'created')->count());
        $this->assertSame(2, SyncChange::where('table_name', 'recipe_grupo_detalle')->whereIn('record_id', $lineas->pluck('id'))->where('operation', 'created')->count());
        $this->assertSame(0, $this->copiar('tratamientos', $medico)['copiadas']);
    }

    public function test_las_novedades_listan_lo_que_el_medico_no_tiene(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'CONTROL']);
        $this->ini('motivos_consulta', ['codemotivo' => '0002', 'descripcion' => 'FIEBRE']);
        $this->propia('motivos_consulta', $medico->reg_medico, ['codemotivo' => '0001', 'descripcion' => 'Control']);

        $novedades = app(CopiarCatalogoInicial::class)->novedades('motivos-consulta', [$medico->reg_medico]);

        $this->assertSame(['FIEBRE'], $novedades->pluck('descripcion')->all());
    }

    // ------------------------------------------------------------------------------------------ La pantalla del médico

    public function test_la_tarjeta_aparece_con_novedades_y_el_boton_carga_sin_pisar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->ini('diagnosticos', ['codediagnostico' => '0001', 'descripcion' => 'ASMA']);
        $sesion = fn () => $this->conContexto($medico, $pediatria)->actingAs($user);

        $sesion()->get(route('clinica.catalogos.diagnosticos'))->assertOk()->assertSee('id="catalogo-novedades"', false)
            ->assertSee('Cargar el catálogo inicial')->assertSee('ASMA');
        // Otro catálogo sin novedades no la muestra.
        $sesion()->get(route('clinica.catalogos.motivos-consulta'))->assertOk()->assertDontSee('id="catalogo-novedades"', false);

        $sesion()->post(route('clinica.catalogos.cargar-inicial', 'diagnosticos'))
            ->assertRedirect(route('clinica.catalogos.diagnosticos'))->assertSessionHas('estado');
        $this->assertSame('ASMA', DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)->value('descripcion'));

        $sesion()->get(route('clinica.catalogos.diagnosticos'))->assertOk()->assertDontSee('id="catalogo-novedades"', false);
        $sesion()->post(route('clinica.catalogos.cargar-inicial', 'diagnosticos'))->assertSessionHas('estado', 'Ya tienes todo lo que trae el catálogo inicial de diagnósticos: no se agregó nada.');
        $sesion()->post(route('clinica.catalogos.cargar-inicial', 'dietas'))->assertNotFound();
        $sesion()->post(route('clinica.catalogos.cargar-inicial', 'inexistente'))->assertNotFound();
    }

    public function test_la_secretaria_no_carga_el_catalogo_inicial(): void
    {
        [, $medico, $pediatria] = $this->medicoConAcceso();
        $secretaria = User::create(['name' => 'Secre', 'email' => 'cat-ini-secre-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->post(route('clinica.catalogos.cargar-inicial', 'diagnosticos'))->assertForbidden();
    }

    // ------------------------------------------------------------------------------------------ El ABM del administrador

    public function test_la_accion_guarda_valida_y_no_repite_en_el_catalogo_inicial(): void
    {
        $accion = app(GuardarFilaDeCatalogoInicial::class);

        $id = $accion->ejecutar('diagnosticos', ['descripcion' => ' asma  bronquial ']);
        $fila = DB::table('diagnosticos')->where('id', $id)->first();
        $this->assertNull($fila->reg_medico);
        $this->assertSame('ASMA BRONQUIAL', $fila->descripcion);
        $this->assertSame('0001', $fila->codediagnostico);
        $this->assertSame('0002', DB::table('diagnosticos')->where('id', $accion->ejecutar('diagnosticos', ['descripcion' => 'otitis']))->value('codediagnostico'));

        foreach ([
            [['descripcion' => 'Ásma Bronquial'], 'otra fila igual'],
            [['descripcion' => ''], 'Escribe'],
            [['descripcion' => str_repeat('x', 51)], 'no puede pasar de 50'],
        ] as [$datos, $mensaje]) {
            try {
                $accion->ejecutar('diagnosticos', $datos);
                $this->fail("debería rechazar: $mensaje");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        // Editar no choca consigo misma; una fila de un médico no se encuentra.
        $accion->ejecutar('diagnosticos', ['descripcion' => 'Asma bronquial'], $id);
        $propia = $this->propia('diagnosticos', 'consultorio-x', ['codediagnostico' => '0009', 'descripcion' => 'AJENA']);
        $this->expectException(\InvalidArgumentException::class);
        $accion->ejecutar('diagnosticos', ['descripcion' => 'Cambiada'], $propia);
    }

    public function test_la_accion_valida_referencias_numeros_y_marcas(): void
    {
        $accion = app(GuardarFilaDeCatalogoInicial::class);
        $tipo = $this->ini('tipos_examenes', ['codetipo' => '001', 'tipo' => 'LABORATORIO']);

        $accion->ejecutar('examenes', ['examen' => 'hemograma', 'codetipo' => '001']);
        $this->assertSame('001', DB::table('examenes')->whereNull('reg_medico')->value('codeexamen'));

        foreach ([[['examen' => 'X', 'codetipo' => '999'], 'no está en el catálogo inicial'], [['examen' => 'X', 'codetipo' => ''], 'Elige']] as [$datos, $mensaje]) {
            try {
                $accion->ejecutar('examenes', $datos);
                $this->fail('debería rechazar');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $accion->ejecutar('motivos-cita', ['tipo_atencion' => 'consulta', 'precio' => '30,5', 'admite_varias_citas_pendientes' => true]);
        $fila = DB::table('motivo_cita')->whereNull('reg_medico')->first();
        $this->assertEquals(30.5, $fila->precio);
        $this->assertSame('01', $fila->codigo);
        $this->expectException(\InvalidArgumentException::class);
        $accion->ejecutar('motivos-cita', ['tipo_atencion' => 'x', 'precio' => '-3']);
        $this->assertGreaterThan(0, $tipo);
    }

    public function test_no_se_quita_un_tipo_o_medicamento_que_otra_fila_inicial_usa(): void
    {
        $quitar = app(QuitarFilaDeCatalogoInicial::class);
        $tipo = $this->ini('tipos_examenes', ['codetipo' => '001', 'tipo' => 'LABORATORIO']);
        $this->ini('examenes', ['codeexamen' => '001', 'examen' => 'HEMOGRAMA', 'codetipo' => '001']);
        $med = $this->ini('vademecum', ['codemedicina' => '0001', 'nombrecomercial' => 'AMOXIL']);
        $trat = $this->ini('recipe_grupo', ['codigo' => '0001', 'tratamiento' => 'OTITIS']);
        $this->ini('recipe_grupo_detalle', ['codigo' => '0001', 'codemedicina' => '0001', 'cantidad' => 1, 'orden' => 0]);

        foreach ([['tipos-examen', $tipo, 'lo usan'], ['medicamentos', $med, 'lo llevan']] as [$slug, $id, $mensaje]) {
            try {
                $quitar->ejecutar($slug, $id);
                $this->fail('debería rechazar');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        // Quitar el tratamiento se lleva sus líneas y libera al medicamento.
        $this->assertSame('OTITIS', $quitar->ejecutar('tratamientos', $trat));
        $this->assertSame(0, DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->count());
        $this->assertSame('AMOXIL', $quitar->ejecutar('medicamentos', $med));
    }

    public function test_las_lineas_de_un_tratamiento_inicial(): void
    {
        $accion = app(GuardarLineaDeTratamientoInicial::class);
        $this->ini('vademecum', ['codemedicina' => '0001', 'nombrecomercial' => 'AMOXIL']);
        $this->ini('recipe_grupo', ['codigo' => '0001', 'tratamiento' => 'OTITIS']);

        $id = $accion->ejecutar('0001', ['codemedicina' => '0001', 'cantidad' => '', 'indicaciones' => 'Cada 8 h']);
        $this->assertSame(1, (int) DB::table('recipe_grupo_detalle')->where('id', $id)->value('cantidad'));

        foreach ([
            [['codemedicina' => '0001'], 'ya está en el tratamiento'],
            [['codemedicina' => '0099'], 'Elige un medicamento'],
            [['codemedicina' => '0001', 'cantidad' => '0'], 'cantidad'],
        ] as [$datos, $mensaje]) {
            try {
                $accion->ejecutar('0001', $datos);
                $this->fail("debería rechazar: $mensaje");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $accion->ejecutar('0001', ['codemedicina' => '0001', 'cantidad' => '3'], $id);
        $this->assertSame(3, (int) DB::table('recipe_grupo_detalle')->where('id', $id)->value('cantidad'));
        $accion->quitar($id);
        $this->assertSame(0, DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->count());
    }

    public function test_el_componente_solo_lo_ven_los_administradores_y_opera_sobre_las_filas_iniciales(): void
    {
        [$medicoUser] = $this->medicoConAcceso();
        $this->actingAs($medicoUser)->get(route('admin.catalogo-inicial.catalogo', 'diagnosticos'))->assertForbidden();

        $this->actingAs($this->admin());
        $this->get(route('admin.catalogo-inicial.catalogo', 'diagnosticos'))->assertOk()->assertSee('Catálogo inicial · Diagnósticos')->assertSee('Motivos de cita');
        $this->get(route('admin.catalogo-inicial.catalogo', 'inexistente'))->assertNotFound();
        $this->get(route('admin.catalogo-inicial.catalogo', 'vacunas'))->assertNotFound();

        $propia = $this->propia('diagnosticos', 'consultorio-x', ['codediagnostico' => '0009', 'descripcion' => 'AJENA']);

        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'diagnosticos'])
            ->call('abrir')->set('f.descripcion', '')->call('guardar')->assertHasErrors(['f.formulario'])
            ->set('f.descripcion', 'asma')->call('guardar')->assertHasNoErrors()->assertSet('modal', null)->assertSee('ASMA')->assertDontSee('AJENA')
            ->call('abrir', $propia)->assertStatus(404);

        $id = DB::table('diagnosticos')->whereNull('reg_medico')->value('id');
        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'diagnosticos'])
            ->call('abrir', $id)->assertSet('f.descripcion', 'ASMA')->set('f.descripcion', 'ASMA LEVE')->call('guardar')->assertHasNoErrors()
            ->call('abrirBorrar', $id)->call('borrar')->assertSet('modal', null);

        $this->assertSame(0, DB::table('diagnosticos')->whereNull('reg_medico')->count());
        $this->assertSame(1, DB::table('diagnosticos')->where('id', $propia)->count());
    }

    public function test_el_componente_arma_el_formulario_de_examenes_y_las_lineas_de_tratamientos(): void
    {
        $this->actingAs($this->admin());
        $this->ini('tipos_examenes', ['codetipo' => '001', 'tipo' => 'LABORATORIO']);
        $this->ini('vademecum', ['codemedicina' => '0001', 'nombrecomercial' => 'AMOXIL']);

        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'examenes'])
            ->call('abrir')->assertSee('LABORATORIO')
            ->set('f.examen', 'hemograma')->set('f.codetipo', '001')->call('guardar')->assertHasNoErrors()
            ->assertSee('HEMOGRAMA');

        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'tratamientos'])
            ->call('abrir')->set('f.tratamiento', 'otitis')->call('guardar')->assertHasNoErrors();
        $id = DB::table('recipe_grupo')->whereNull('reg_medico')->value('id');

        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'tratamientos'])
            ->call('abrirLineas', $id)->assertSee('Medicamentos de OTITIS')
            ->set('l.codemedicina', '0001')->set('l.cantidad', '2')->call('guardarLinea')->assertHasNoErrors()
            ->set('l.codemedicina', '0001')->call('guardarLinea')->assertHasErrors(['l.formulario'])
            ->assertSee('AMOXIL');

        $this->assertSame(1, DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->count());
        $this->actingAs($this->admin());
        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'diagnosticos'])->call('abrirLineas', $id)->assertStatus(404);
    }
}
