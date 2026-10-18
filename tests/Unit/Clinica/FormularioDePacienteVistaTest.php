<?php

namespace Tests\Unit\Clinica;

use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\Vistas\FichaDePacienteVista;
use App\Clinica\Pacientes\Vistas\FormularioDePacienteVista;
use App\Models\Paciente;
use App\Models\Specialty;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El ViewModel del **formulario de alta y edición de paciente** (WEB-3.2), sin base de datos.
 *
 * Es el ViewModel que más decisiones de datos tiene: qué se precarga del paciente, qué queda en blanco, de
 * dónde sale cada opción y qué pasa con los datos sucios del legado (la cédula `S.C.`, las fechas
 * `0000-00-00`, un seguro que no está en el catálogo). Se prueba con el paciente como objeto y el catálogo
 * como colección: lo que la pantalla imprime sale de acá.
 *
 * Extiende `Tests\TestCase` (y no el de PHPUnit a secas) porque los ViewModels de formulario usan `old()` y
 * `route()`; **no** usa `RefreshDatabase` ni toca la base.
 */
class FormularioDePacienteVistaTest extends TestCase
{
    /** @param array<string,mixed> $campos */
    private function paciente(array $campos = []): Paciente
    {
        $paciente = new Paciente();
        $paciente->forceFill(array_merge([
            'id'          => 7,
            'nombres'     => 'Ana María',
            'apellidos'   => 'Pérez',
            'cedula'      => '12345678',
            'nac'         => 'V',
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-01',
            'codeestado'  => 'C',
            'telefono'    => '04141234567',
            'email'       => 'ana@example.com',
            'codesegemp'  => '01',
        ], $campos));
        $paciente->exists = true;

        return $paciente;
    }

    /** @param array<int,array{codesegemp:string,nombre:string}> $seguros */
    private function catalogo(array $seguros = []): \Illuminate\Support\Collection
    {
        return collect($seguros)->map(fn ($seguro) => (object) $seguro);
    }

    private function vista(?Paciente $paciente = null, ?string $numhistoria = null, array $seguros = [], ?Specialty $especialidad = null): FormularioDePacienteVista
    {
        return new FormularioDePacienteVista($paciente, $numhistoria, $this->catalogo($seguros), $especialidad);
    }

    /**
     * La especialidad del contexto, sin base de datos: `ConfiguracionDeLaEspecialidad` la busca por su
     * `slug` entre los manifiestos de `config/especialidades/*.php`.
     */
    private function especialidad(string $slug): Specialty
    {
        return new Specialty(['slug' => $slug]);
    }

    /** El valor que el `<select>` deja elegido (o `null` si ninguno lo está). */
    private function elegido(array $opciones): ?string
    {
        foreach ($opciones as $opcion) {
            if ($opcion['elegido']) {
                return $opcion['valor'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------------------------------ Alta

    public function test_el_alta_es_una_pantalla_vacia_que_guarda_contra_el_alta_de_pacientes(): void
    {
        $vista = $this->vista();

        $this->assertFalse($vista->editando());
        $this->assertSame('Nuevo paciente', $vista->titulo());
        $this->assertSame('Guardar el paciente', $vista->botonGuardar());
        $this->assertSame(route('clinica.pacientes.guardar'), $vista->urlGuardar());
        $this->assertSame(route('clinica.pacientes'), $vista->urlVolver());
        // En el alta el campo está vacío pero el tilde **no** viene puesto: la cédula es obligatoria y el
        // formulario tiene que pedirla (marcar "sin cédula" es una decisión del usuario).
        $this->assertFalse($vista->sinCedula());
        $this->assertSame('', $vista->cedula());
    }

    public function test_el_alta_arranca_con_el_seguro_personal_del_escritorio_y_sin_estado_civil(): void
    {
        // El escritorio, al insertar una cédula nueva, deja `codesegemp = '00'` y `codeestado = 'S'`
        // (`w_agregar_pacientes.srw:378-381`). La web copia solo el seguro (PERSONAL): el estado civil y
        // el sexo son datos de la persona y la pantalla no los inventa.
        $vista = $this->vista(null, null, [['codesegemp' => '00', 'nombre' => 'PERSONAL']]);

        $seguros = $vista->seguros();
        $this->assertCount(1, $seguros);
        $this->assertSame('00', $seguros[0]['valor']);
        $this->assertSame('PERSONAL', $seguros[0]['texto']);

        $this->assertSame('V', $vista->nacionalidades()[0]['valor']);
        $this->assertTrue($vista->nacionalidades()[0]['elegido']);
        $this->assertFalse($vista->sexos()[0]['elegido']);
        $this->assertFalse($vista->estadosCiviles()[0]['elegido']);
    }

    public function test_un_consultorio_sin_catalogo_de_seguros_lo_dice_y_no_rompe_el_select(): void
    {
        // Sin catálogo, la pantalla no inventa el seguro "PERSONAL": el `<select>` queda vacío y el
        // formulario avisa (la vista usa `sinSeguros()`).
        $vista = $this->vista();

        $this->assertTrue($vista->sinSeguros());
        $this->assertSame([], $vista->seguros());
    }

    // --------------------------------------------------------------------------------------- Edición

    public function test_la_edicion_precarga_lo_que_tiene_el_paciente_y_ofrece_volver_a_su_ficha(): void
    {
        $vista = $this->vista($this->paciente(), '01001');

        $this->assertTrue($vista->editando());
        $this->assertSame('Editar la ficha', $vista->titulo());
        $this->assertSame('Guardar cambios', $vista->botonGuardar());
        $this->assertSame(route('clinica.pacientes.actualizar', 7), $vista->urlGuardar());
        $this->assertSame(route('clinica.pacientes.ver', 7), $vista->urlVolver());
        $this->assertSame('Ficha del paciente', $vista->textoVolver());

        $this->assertSame('Pérez, Ana María', $vista->valor('apellidos') . ', ' . $vista->valor('nombres'));
        $this->assertSame('12345678', $vista->cedula());
        $this->assertSame('F', $vista->valor('sexo'));
        $this->assertSame('1990-05-01', $vista->fecha('fnacimiento'));
        $this->assertStringContainsString('Pérez, Ana María', $vista->subtitulo());
        $this->assertStringContainsString('historia 01001', $vista->subtitulo());
    }

    public function test_la_edicion_no_precarga_ningun_seguro_cuando_el_paciente_no_tiene(): void
    {
        $vista = $this->vista($this->paciente(['codesegemp' => null]), null, [['codesegemp' => '00', 'nombre' => 'PERSONAL']]);

        $this->assertSame('', (string) $vista->valor('codesegemp', null));
    }

    public function test_un_seguro_que_no_esta_en_el_catalogo_se_ofrece_igual_para_no_perderlo(): void
    {
        // Un código de otra instalación (o de un catálogo que todavía no bajó) no puede desaparecer del
        // <select> al guardar: se agrega como opción con su código.
        $vista = $this->vista($this->paciente(['codesegemp' => '07']), null, [['codesegemp' => '00', 'nombre' => 'PERSONAL']]);

        $seguros = $vista->seguros();
        $this->assertCount(2, $seguros);
        $this->assertSame('07 (no está en el catálogo)', $seguros[1]['texto']);
    }

    // ------------------------------------------------------------------------------ Cédula y "S.C."

    public function test_sin_cedula_marca_la_opcion_del_legado_y_no_muestra_el_marcador_como_documento(): void
    {
        // La base puede tener el marcador escrito (`S.C.`): la pantalla no lo muestra como una cédula, sino
        // con el tilde de "sin cédula" puesto.
        $vista = $this->vista($this->paciente(['cedula' => 'S.C.']));

        $this->assertTrue($vista->sinCedula());
        $this->assertSame('', $vista->cedula());
    }

    public function test_una_cedula_normal_no_marca_la_opcion(): void
    {
        $vista = $this->vista($this->paciente());

        $this->assertFalse($vista->sinCedula());
        $this->assertSame('12345678', $vista->cedula());
    }

    public function test_un_paciente_sin_cedula_abre_la_edicion_con_el_campo_vacio(): void
    {
        // Es como la web guarda el "sin cédula": la columna queda nula. La edición abre con el campo vacío
        // (y el JS de la pantalla marca el tilde), así que se puede guardar cualquier otro cambio sin que
        // el formulario exija una cédula que el paciente no tiene.
        foreach ([null, ''] as $sinDocumento) {
            $vista = $this->vista($this->paciente(['cedula' => $sinDocumento]));

            $this->assertSame('', $vista->cedula());
            $this->assertTrue($vista->editando());
        }

        // El marcador escrito del legado sí se muestra como "sin cédula".
        $this->assertTrue($this->vista($this->paciente(['cedula' => 'S.C.']))->sinCedula());
    }

    // ------------------------------------------------------------------------- Fechas sucias del legado

    public function test_una_fecha_imposible_del_legado_no_se_le_pasa_al_input_de_fecha(): void
    {
        // `0000-00-00` y la familia `0000-00-*` no son un valor válido de `<input type="date">`: la pantalla
        // los deja vacíos (y guardarlos otra vez los deja nulos) en vez de mostrar una fecha inventada.
        foreach (['0000-00-00', '0000-00-01', null] as $basura) {
            $vista = $this->vista($this->paciente(['fnacimiento' => $basura]));

            $this->assertSame('', $vista->fecha('fnacimiento'));
        }
    }

    // ------------------------------------------------- Valores por defecto del alta (O-01 y O-02, 2026-10-17)

    public function test_el_alta_de_ginecologia_propone_femenino(): void
    {
        // La especialidad lo declara en su manifiesto (`paciente.sexo_por_defecto = 'F'`, O-01): el alta
        // de una paciente de ginecología arranca en Femenino, con el código del legado.
        $vista = $this->vista(null, null, [], $this->especialidad('ginecologia-y-obstetricia'));

        $this->assertSame('F', $this->elegido($vista->sexos()));
        // «— Sin dato —» sigue disponible (la opción vacía la pone la vista) y los dos sexos se ofrecen.
        $this->assertSame(['M', 'F'], array_column($vista->sexos(), 'valor'));
    }

    public function test_una_especialidad_que_no_declara_el_sexo_sigue_abriendo_en_sin_dato(): void
    {
        // Pediatría tiene manifiesto pero no declara la clave; una especialidad sin manifiesto tampoco la
        // tiene. En las dos el alta queda en «— Sin dato —»: el default no es del producto, es de la
        // especialidad que lo pide.
        foreach (['pediatria', 'medicina-general'] as $slug) {
            $this->assertNull($this->elegido($this->vista(null, null, [], $this->especialidad($slug))->sexos()), $slug);
        }

        // Y sin especialidad en el contexto, tampoco.
        $this->assertNull($this->elegido($this->vista()->sexos()));
    }

    public function test_la_edicion_no_se_pisa_con_el_sexo_por_defecto(): void
    {
        // Al editar manda lo guardado, sea cual sea.
        $this->assertSame(
            'M',
            $this->elegido($this->vista($this->paciente(['sexo' => 'M']), null, [], $this->especialidad('ginecologia-y-obstetricia'))->sexos()),
        );

        // Un paciente de ginecología **sin** sexo guardado no se completa solo en la edición: el default
        // es del alta.
        $this->assertNull(
            $this->elegido($this->vista($this->paciente(['sexo' => null]), null, [], $this->especialidad('ginecologia-y-obstetricia'))->sexos()),
        );
    }

    public function test_la_fecha_de_ingreso_del_alta_propone_hoy(): void
    {
        $vista = $this->vista();

        $this->assertSame(FechaDelConsultorio::hoy()->toDateString(), $vista->fecha('fingreso'));
        // La de nacimiento no tiene default: la pantalla no inventa una edad.
        $this->assertSame('', $vista->fecha('fnacimiento'));
    }

    public function test_la_fecha_de_ingreso_es_la_del_consultorio_y_no_la_del_servidor(): void
    {
        // Las 20:30 del sábado 10/10/2026 en Caracas son las 00:30 del domingo 11 en UTC, que es el reloj
        // del servidor (trampa 59). El alta tiene que proponer el **10**, el día que se está viviendo acá.
        Carbon::setTestNow(Carbon::parse('2026-10-11 00:30:00', 'UTC'));

        try {
            $this->assertSame('2026-10-10', $this->vista()->fecha('fingreso'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_edicion_manda_la_fecha_de_ingreso_guardada(): void
    {
        $this->assertSame('2020-03-15', $this->vista($this->paciente(['fingreso' => '2020-03-15']))->fecha('fingreso'));
    }

    // ------------------------------------------------------------------- La ficha muestra el seguro

    public function test_la_ficha_muestra_el_nombre_del_seguro_y_no_un_codigo(): void
    {
        $ficha = new FichaDePacienteVista((object) ['id' => 7, 'codesegemp' => '01'], collect());
        $ficha = $ficha->conSeguro('SEGURO HORIZONTE');

        $this->assertSame('SEGURO HORIZONTE', $ficha->seguro());
    }

    public function test_la_ficha_no_muestra_seguro_cuando_el_codigo_es_el_personal_del_legado(): void
    {
        // `00` es "PERSONAL" (el default del alta del escritorio): es el paciente que se atiende por su
        // cuenta, no una empresa.
        $ficha = (new FichaDePacienteVista((object) ['id' => 7, 'codesegemp' => '00'], collect()))
            ->conSeguro('PERSONAL');

        $this->assertNull($ficha->seguro());
    }

    public function test_la_ficha_muestra_el_codigo_cuando_el_seguro_no_esta_en_el_catalogo(): void
    {
        $ficha = (new FichaDePacienteVista((object) ['id' => 7, 'codesegemp' => '07'], collect()))
            ->conSeguro(null);

        $this->assertSame('07', $ficha->seguro());
    }
}
