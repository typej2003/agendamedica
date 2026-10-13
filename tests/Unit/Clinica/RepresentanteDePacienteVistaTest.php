<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Pacientes\CatalogoDeParentescos;
use App\Clinica\Pacientes\Vistas\RepresentanteDePacienteVista;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel del **representante del paciente** (WEB-3.4) se prueba sin base de datos ni HTTP: datos de
 * entrada → textos de salida (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá es, sobre todo, **el parentesco**: el catálogo es nuevo de AppDDR y los códigos del
 * legado son desconocidos, así que un código que no esté en él se muestra **tal cual** —nunca traducido a
 * un nombre que nadie verificó— y se sigue ofreciendo en el `<select>` para no perderlo. Los datos basura
 * del legado (vacíos y espacios) los limpia `CamposDePaciente`, igual que en el resto de la ficha.
 */
class RepresentanteDePacienteVistaTest extends TestCase
{
    private function representante(array $campos = []): object
    {
        return (object) array_merge([
            'id'             => 3,
            'nombre'         => 'Rosa Pérez',
            'codeparentesco' => 'M',
            'cedula'         => '87654321',
            'telefono'       => '04149876543',
            'email'          => 'rosa@example.com',
            'direccion'      => 'Calle 1',
        ], $campos);
    }

    /** El catálogo del seeder, sin base de datos: es lo que el controlador le pasa ya leído. */
    private function catalogo(): \Illuminate\Support\Collection
    {
        $nombres = ['M' => 'Madre', 'P' => 'Padre', 'L' => 'Representante legal', 'A' => 'Abuelo/a'];

        return collect($nombres)->map(fn ($nombre, $codigo) => (object) ['codigo' => $codigo, 'nombre' => $nombre])->values();
    }

    /**
     * El ViewModel tal como lo arma el controlador: con el **nombre del parentesco ya resuelto** contra el
     * catálogo (`CatalogoDeParentescos::nombreDe`, que es lo que hace `RepresentanteController::bloque`) y
     * el catálogo ya leído para el `<select>`. Un código que no está en el catálogo llega como `null` y el
     * bloque muestra el código crudo.
     */
    private function vista(?object $representante = null, bool $tieneHistoria = true, ?string $url = '/clinica/pacientes/51/representante'): RepresentanteDePacienteVista
    {
        $catalogo = $this->catalogo();
        $codigo = $representante->codeparentesco ?? null;
        $parentesco = (new CatalogoDeParentescos())->nombreDe($catalogo, is_string($codigo) ? $codigo : null);

        return new RepresentanteDePacienteVista($tieneHistoria, $representante, $parentesco, $catalogo, $url);
    }

    public function test_con_representante_muestra_sus_datos_y_traduce_el_parentesco(): void
    {
        $vista = $this->vista($this->representante());

        $this->assertTrue($vista->cargado());
        $this->assertTrue($vista->puedeEditar());
        $this->assertSame('Rosa Pérez', $vista->nombre());
        $this->assertSame('Madre', $vista->parentesco());
        $this->assertSame('M', $vista->codigoParentesco());
        $this->assertSame('87654321', $vista->cedula());
        $this->assertSame('04149876543', $vista->telefono());
        $this->assertSame('rosa@example.com', $vista->correo());
        $this->assertSame('Calle 1', $vista->direccion());
        $this->assertSame('Editar el representante', $vista->botonEditar());
        $this->assertSame('/clinica/pacientes/51/representante', $vista->urlGuardar());
    }

    public function test_sin_representante_lo_dice_y_ofrece_cargarlo(): void
    {
        $vista = $this->vista(null, true);

        $this->assertFalse($vista->cargado());
        $this->assertTrue($vista->puedeEditar());
        $this->assertNull($vista->nombre());
        $this->assertNull($vista->parentesco());
        $this->assertSame('Sin representante cargado', $vista->vacio()['titulo']);
        $this->assertStringContainsString('recordatorio', $vista->vacio()['texto']);
        $this->assertSame('Cargar el representante', $vista->botonEditar());
        $this->assertSame('Cargar el representante', $vista->tituloFormulario());
    }

    /**
     * **Sin historia no hay formulario**: `representante` se guarda con el `numhistoria` (su clave real en
     * el legado), así que el bloque explica el motivo y manda a asignarla (WEB-3.3). El caso del POST
     * armado a mano lo cubre el test HTTP; acá se fija lo que ve la pantalla.
     */
    public function test_sin_historia_no_hay_formulario_y_el_motivo_es_el_numero_que_falta(): void
    {
        $vista = $this->vista(null, false, null);

        $this->assertFalse($vista->cargado());
        $this->assertFalse($vista->puedeEditar(), 'sin historia no hay formulario que ofrecer');
        $this->assertNull($vista->urlGuardar());
        $this->assertSame('Todavía no se puede cargar el representante', $vista->vacio()['titulo']);
        $this->assertStringContainsString('Asígnale el número', $vista->vacio()['texto']);
    }

    public function test_la_url_del_controlador_es_lo_unico_que_habilita_el_formulario(): void
    {
        // Aunque el paciente tenga historia, sin URL no hay acción que ofrecer (el ViewModel se prueba sin
        // contenedor de Laravel y no arma rutas).
        $this->assertFalse($this->vista($this->representante(), true, null)->puedeEditar());
        $this->assertFalse($this->vista(null, false, '/x')->puedeEditar());
    }

    /**
     * El código del escritorio que **no** está en el catálogo se muestra crudo: el catálogo es de AppDDR y
     * los códigos del legado son desconocidos, así que traducirlo sería afirmar una equivalencia que nadie
     * verificó (misma decisión que el `codesegemp` que no está en `seg_emp`).
     */
    public function test_un_codigo_de_parentesco_que_no_esta_en_el_catalogo_se_muestra_tal_cual(): void
    {
        $vista = $this->vista($this->representante(['codeparentesco' => 'X']));

        $this->assertSame('X', $vista->parentesco());
        $this->assertSame('X', $vista->codigoParentesco());

        // Y se ofrece en el `<select>` —marcado y distinguido— para que guardar otro dato no lo pierda.
        $opciones = collect($vista->parentescos());
        $this->assertSame('X (código del escritorio)', $opciones->firstWhere('valor', 'X')['texto']);
        $this->assertTrue($opciones->firstWhere('valor', 'X')['elegido']);
        $this->assertFalse($opciones->firstWhere('valor', null)['elegido']);
    }

    public function test_el_select_tiene_el_catalogo_y_la_opcion_sin_dato(): void
    {
        $vista = $this->vista($this->representante(['codeparentesco' => 'M']));
        $opciones = $vista->parentescos();

        $this->assertSame('— Sin dato —', $opciones[0]['texto']);
        $this->assertNull($opciones[0]['valor']);
        $this->assertFalse($opciones[0]['elegido']);
        $this->assertSame('Madre', $opciones[1]['texto']);
        $this->assertTrue($opciones[1]['elegido']);
        $this->assertSame('Padre', $opciones[2]['texto']);
        $this->assertFalse($opciones[2]['elegido']);
        // Un representante sin parentesco deja elegido "Sin dato" y no agrega ningún código inventado.
        $this->assertCount(5, $opciones);

        $sinDato = $this->vista($this->representante(['codeparentesco' => null]));
        $this->assertTrue($sinDato->parentescos()[0]['elegido']);
        $this->assertNull($sinDato->parentesco());
        $this->assertCount(5, $sinDato->parentescos());
    }

    public function test_los_campos_vacios_o_con_espacios_del_legado_son_null(): void
    {
        $vista = $this->vista($this->representante([
            'nombre' => '   ', 'cedula' => '', 'telefono' => '  ', 'email' => null, 'direccion' => ' ',
            'codeparentesco' => '  ',
        ]));

        $this->assertTrue($vista->cargado(), 'la fila existe: el bloque muestra el estado con datos');
        $this->assertNull($vista->nombre());
        $this->assertNull($vista->cedula());
        $this->assertNull($vista->telefono());
        $this->assertNull($vista->correo());
        $this->assertNull($vista->direccion());
        $this->assertNull($vista->parentesco());
        $this->assertNull($vista->codigoParentesco());
    }

    /** `S.C.` es el marcador de "sin cédula" del legado: no es un dato, y la ficha muestra `—`. */
    public function test_una_cedula_sc_del_legado_no_se_muestra_como_cedula(): void
    {
        $this->assertNull($this->vista($this->representante(['cedula' => 'S.C.']))->cedula());
    }

    public function test_el_catalogo_resuelve_el_nombre_y_deja_pasar_lo_que_no_conoce(): void
    {
        $catalogo = new CatalogoDeParentescos();
        $leido = $this->catalogo();

        $this->assertSame('Madre', $catalogo->nombreDe($leido, 'M'));
        $this->assertSame('Madre', $catalogo->nombreDe($leido, ' M '));
        // Lo que no está en el catálogo **no** se traduce: el ViewModel muestra el código.
        $this->assertNull($catalogo->nombreDe($leido, 'X'));
        $this->assertNull($catalogo->nombreDe($leido, ''));
        $this->assertNull($catalogo->nombreDe($leido, null));
    }
}
