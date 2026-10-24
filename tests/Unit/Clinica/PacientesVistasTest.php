<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Pacientes\BusquedaCorregida;
use App\Clinica\Pacientes\FiltroDePacientes;
use App\Clinica\Pacientes\Vistas\FichaDePacienteVista;
use App\Clinica\Pacientes\Vistas\ListadoDePacientesVista;
use App\Support\Iniciales;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;

/**
 * Los ViewModels de Pacientes se prueban sin base de datos ni HTTP: datos de entrada → textos de salida
 * (Docs/Web/guia-arquitectura-web.md). Las rutas se evitan usando solo lo que no las necesita.
 */
class PacientesVistasTest extends TestCase
{
    private function paciente(array $campos = []): object
    {
        return (object) array_merge([
            'id'          => 7,
            'nombres'     => 'Ana María',
            'apellidos'   => 'Pérez',
            'cedula'      => '12345678',
            'fnacimiento' => '1990-05-01',
            'sexo'        => 'F',
            'telefono'    => '04141234567',
            'email'       => 'ana@example.com',
            'numhistoria' => 1001,
        ], $campos);
    }

    /** Con la página y la ruta explícitas el paginador no consulta el contenedor de Laravel. */
    private function paginador(array $items, int $total): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, $total, 25, 1, ['path' => '/clinica/pacientes']);
    }

    /**
     * Un listado con la **base** puesta (el controlador pasa `route('clinica.pacientes')`): el ViewModel
     * no llama a `route()` para que estos tests sigan corriendo sin contenedor de Laravel.
     *
     * @param  array{todos?:int, sinHistoria?:int, conHistoria?:int}  $conteos
     */
    private function listado(
        array $items,
        int $total,
        string $buscar = '',
        string $filtro = FiltroDePacientes::TODOS,
        array $conteos = ['todos' => 0, 'sinHistoria' => 0, 'conHistoria' => 0],
    ): ListadoDePacientesVista {
        return new ListadoDePacientesVista($this->paginador($items, $total), $buscar, $filtro, $conteos, '/clinica/pacientes');
    }

    public function test_la_ficha_arma_el_nombre_y_los_estados(): void
    {
        $vista = new FichaDePacienteVista($this->paciente(), collect());

        $this->assertSame('Pérez, Ana María', $vista->nombre());
        $this->assertSame('Historia N.º 1001', $vista->subtitulo());
        $this->assertSame('Femenino', $vista->sexo());
        $this->assertSame('1001', $vista->numeroDeHistoria());

        $estados = $vista->estados();
        $this->assertSame('marca', $estados[0]['tono']);
        $this->assertSame('Historia 1001', $estados[0]['texto']);
        $this->assertCount(3, $estados); // historia, edad y sexo
    }

    public function test_sin_historia_el_estado_es_de_aviso_y_las_consultas_explican_por_que(): void
    {
        $vista = new FichaDePacienteVista($this->paciente(['numhistoria' => null]), collect());

        $this->assertFalse($vista->tieneHistoria());
        $this->assertNull($vista->numeroDeHistoria());
        $this->assertSame('aviso', $vista->estados()[0]['tono']);
        $this->assertSame('Sin historia', $vista->estados()[0]['texto']);
        $this->assertStringContainsString('todavía no tiene historia', $vista->sinConsultas()['titulo']);
    }

    public function test_los_datos_ausentes_son_null_y_no_texto_vacio(): void
    {
        $vista = new FichaDePacienteVista($this->paciente([
            'cedula' => '  ', 'telefono' => null, 'email' => '', 'sexo' => '', 'fnacimiento' => '0000-00-00',
        ]), collect());

        $this->assertNull($vista->cedula());
        $this->assertNull($vista->telefono());
        $this->assertNull($vista->correo());
        $this->assertNull($vista->sexo());
        $this->assertNull($vista->nacimiento());
        $this->assertNull($vista->edad());
    }

    public function test_la_ficha_tolera_los_marcadores_y_el_texto_del_legado(): void
    {
        // `S.C.` es la opción "sin cédula" del alta del legado y el número de historia es texto: puede
        // venir vacío (el `!== null` de antes lo daba por bueno) o con espacios y ceros a la izquierda.
        $vista = new FichaDePacienteVista($this->paciente([
            'cedula' => 'S.C.', 'numhistoria' => ' 01001 ', 'telefono' => '   ', 'email' => '  ', 'sexo' => 'f',
        ]), collect());

        $this->assertNull($vista->cedula());
        $this->assertNull($vista->telefono());
        $this->assertNull($vista->correo());
        $this->assertSame('Femenino', $vista->sexo());
        $this->assertSame('01001', $vista->numeroDeHistoria());
        $this->assertSame('Historia N.º 01001', $vista->subtitulo());
        $this->assertSame('Historia 01001', $vista->estados()[0]['texto']);

        $sinNumero = new FichaDePacienteVista($this->paciente(['numhistoria' => '']), collect());
        $this->assertFalse($sinNumero->tieneHistoria());
        $this->assertNull($sinNumero->numeroDeHistoria());
        $this->assertSame('Sin historia', $sinNumero->estados()[0]['texto']);
        $this->assertStringContainsString('todavía no tiene historia', $sinNumero->sinConsultas()['titulo']);
    }

    public function test_la_ficha_no_muestra_un_titulo_en_blanco(): void
    {
        // Hay fichas del legado sin nombres ni apellidos (una está en la base de desarrollo).
        $vista = new FichaDePacienteVista($this->paciente(['nombres' => null, 'apellidos' => '   ']), collect());

        $this->assertSame('Paciente sin nombre', $vista->nombre());
    }

    public function test_la_edad_aguanta_una_fecha_de_nacimiento_basura(): void
    {
        // `0000-00-00` es la fecha nula del legado y `0000-00-01` es de la misma familia: Carbon lo
        // interpreta como el **año -1** y la ficha mostraba "2026 años". Para la pantalla, sin fecha.
        foreach (['0000-00-00', '0000-00-00 00:00:00', '0000-00-01', '', null] as $nula) {
            $vista = new FichaDePacienteVista($this->paciente(['fnacimiento' => $nula]), collect());

            $this->assertNull($vista->edad(), 'la edad de ' . var_export($nula, true) . ' tiene que ser null');
            $this->assertNull($vista->nacimiento(), 'la fecha de ' . var_export($nula, true) . ' tiene que ser null');
        }

        // Lo que no se puede interpretar tampoco da edad; la fecha se muestra tal cual, que es la
        // decisión de `FechaClinica`: avisar el dato roto en vez de esconderlo. El año imposible y la
        // fecha futura también quedan sin edad (`diffInYears` es absoluto).
        foreach (['no es una fecha', '32/13/1990', '0001-01-01', '2099-01-01'] as $rota) {
            $vista = new FichaDePacienteVista($this->paciente(['fnacimiento' => $rota]), collect());

            $this->assertNull($vista->edad(), 'la edad de ' . var_export($rota, true) . ' tiene que ser null');
            $this->assertNotNull($vista->nacimiento(), 'la fecha de ' . var_export($rota, true) . ' se muestra tal cual');
        }

        // El estado de la edad no se agrega cuando no hay edad: sin dato no hay pastilla.
        $this->assertCount(2, (new FichaDePacienteVista($this->paciente(['fnacimiento' => '0000-00-00']), collect()))->estados());

        $buena = new FichaDePacienteVista($this->paciente(['fnacimiento' => '1990-05-01']), collect());
        $this->assertSame('01/05/1990', $buena->nacimiento());
        $this->assertMatchesRegularExpression('/^\d+ años$/', $buena->edad());
    }

    public function test_las_consultas_salen_con_fecha_legible_y_resumen_recortado(): void
    {
        $consultas = collect([
            (object) ['fecha' => '2026-09-30', 'nroconsulta' => 3, 'enfermedadactual' => ' Control de rutina '],
            (object) ['fecha' => '2026-08-01', 'nroconsulta' => 2, 'enfermedadactual' => '   '],
        ]);

        $filas = (new FichaDePacienteVista($this->paciente(), $consultas))->consultas();

        $this->assertSame('30/09/2026', $filas[0]['fecha']);
        $this->assertSame('Consulta 3', $filas[0]['numero']);
        $this->assertSame('Control de rutina', $filas[0]['resumen']);
        $this->assertNull($filas[1]['resumen']);
    }

    public function test_las_consultas_llevan_la_url_de_su_pantalla_cuando_el_controlador_la_da(): void
    {
        $consultas = collect([
            (object) ['id' => 41, 'fecha' => '2026-09-30', 'nroconsulta' => 3, 'enfermedadactual' => 'Control'],
            // Sin id no hay a dónde ir: la fila no es un enlace.
            (object) ['fecha' => '2026-08-01', 'nroconsulta' => 2, 'enfermedadactual' => null],
        ]);

        // Sin URL —la secretaría, que no entra a la consulta— la fila queda como texto.
        $sin = (new FichaDePacienteVista($this->paciente(), $consultas))->consultas();
        $this->assertNull($sin[0]['url']);

        $con = (new FichaDePacienteVista($this->paciente(), $consultas))
            ->conUrlConsulta(fn (int $id) => '/clinica/consulta/' . $id)
            ->consultas();

        $this->assertSame('/clinica/consulta/41', $con[0]['url']);
        $this->assertNull($con[1]['url']);
    }

    public function test_el_listado_distingue_vacio_de_busqueda_sin_resultados(): void
    {
        $vacio = $this->paginador([], 0);

        $sinPacientes = new ListadoDePacientesVista($vacio, '');
        $this->assertTrue($sinPacientes->estaVacio());
        $this->assertSame('bi-people', $sinPacientes->vacio()['icono']);

        $sinResultados = new ListadoDePacientesVista($vacio, 'Zzz');
        $this->assertSame('bi-search', $sinResultados->vacio()['icono']);
        $this->assertSame('Sin resultados para «Zzz».', $sinResultados->subtitulo());
    }

    public function test_el_subtitulo_y_el_rango_cuentan_los_pacientes(): void
    {
        $uno = new ListadoDePacientesVista($this->paginador([$this->paciente()], 1), '');
        $this->assertSame('1 paciente.', $uno->subtitulo());
        $this->assertSame('Mostrando 1–1 de 1', $uno->rango());

        $varios = new ListadoDePacientesVista($this->paginador([$this->paciente()], 40), 'Pérez');
        $this->assertSame('40 resultados para «Pérez».', $varios->subtitulo());
    }

    // ------------------------------------------------------ Asignar la historia desde la ficha (WEB-3.3)

    public function test_la_ficha_sin_historia_ofrece_la_accion_y_la_del_que_ya_tiene_no(): void
    {
        // La URL la resuelve el controlador: acá se prueba el ViewModel con una URL fija, sin contenedor.
        $sin = (new FichaDePacienteVista($this->paciente(['numhistoria' => null]), collect()))
            ->conUrlAsignarHistoria('/clinica/pacientes/51/historia');

        $this->assertTrue($sin->puedeAsignarHistoria());
        $this->assertSame('/clinica/pacientes/51/historia', $sin->urlAsignarHistoria());
        $this->assertSame('Falta el número de historia', $sin->sinHistoria()['titulo']);
        $this->assertStringContainsString('correlativo', $sin->sinHistoria()['texto']);
        $this->assertStringContainsString('Pérez, Ana María', $sin->confirmarAsignarHistoria());
        $this->assertStringContainsString('no se puede deshacer', $sin->confirmarAsignarHistoria());
        $this->assertSame('Asignar el número de historia', $sin->botonAsignarHistoria());

        // El que ya tiene historia **no** ofrece nada: sólo la muestra.
        $con = (new FichaDePacienteVista($this->paciente(), collect()))
            ->conUrlAsignarHistoria('/clinica/pacientes/51/historia');

        $this->assertFalse($con->puedeAsignarHistoria());
        $this->assertSame('1001', $con->numeroDeHistoria());
    }

    public function test_la_ficha_no_ofrece_asignar_la_historia_sin_la_url_del_controlador(): void
    {
        // El ViewModel no arma rutas (se prueba sin Laravel): sin URL no hay acción que ofrecer, ni
        // siquiera para un paciente sin historia.
        $vista = new FichaDePacienteVista($this->paciente(['numhistoria' => null]), collect());

        $this->assertFalse($vista->puedeAsignarHistoria());
        $this->assertNull($vista->urlAsignarHistoria());
    }

    public function test_la_ficha_con_el_numero_sin_la_historia_del_consultorio_ofrece_registrarla(): void
    {
        // Trampa 143: el vínculo dice «7001» pero la fila de `historias` no existe. La ficha muestra el
        // número —es el que el paciente ya tiene— y ofrece **registrar esa historia**, no asignar otra.
        $vista = (new FichaDePacienteVista($this->paciente(['numhistoria' => '7001']), collect()))
            ->conHistoriaRegistrada(false)
            ->conUrlAsignarHistoria('/clinica/pacientes/51/historia');

        $this->assertTrue($vista->puedeAsignarHistoria());
        $this->assertFalse($vista->historiaRegistrada());
        $this->assertSame('7001', $vista->numeroDeHistoria());
        $this->assertSame('Historia N.º 7001', $vista->subtitulo());
        // El estado de arriba no deja creer que ya se lo puede atender.
        $this->assertSame('aviso', $vista->estados()[0]['tono']);
        $this->assertSame('Historia 7001 sin registrar', $vista->estados()[0]['texto']);

        $this->assertSame('Falta registrar la historia del consultorio', $vista->sinHistoria()['titulo']);
        $this->assertStringContainsString('7001', $vista->sinHistoria()['texto']);
        $this->assertStringContainsString('mismo número', $vista->sinHistoria()['texto']);
        $this->assertSame('Registrar la historia 7001', $vista->botonAsignarHistoria());
        $this->assertStringContainsString('no se le asigna otro', $vista->confirmarAsignarHistoria());

        // Con la historia registrada (o con el paciente sin número) los textos son los de siempre.
        $registrada = (new FichaDePacienteVista($this->paciente(), collect()))->conUrlAsignarHistoria('/x');
        $this->assertFalse($registrada->puedeAsignarHistoria());
        $this->assertSame('Asignar el número de historia', (new FichaDePacienteVista($this->paciente(['numhistoria' => null]), collect()))->botonAsignarHistoria());

        // La basura que el legado guarda como número (`S/H`) **no es** una historia: la ficha ofrece asignar
        // una nueva, no «registrar» un número que no existe.
        $basura = (new FichaDePacienteVista($this->paciente(['numhistoria' => 'S/H']), collect()))
            ->conHistoriaRegistrada(false)
            ->conUrlAsignarHistoria('/x');

        $this->assertTrue($basura->puedeAsignarHistoria());
        $this->assertSame('Sin historia', $basura->estados()[0]['texto']);
        $this->assertSame('Asignar el número de historia', $basura->botonAsignarHistoria());
        $this->assertSame('Falta el número de historia', $basura->sinHistoria()['titulo']);
    }

    public function test_el_filtro_sin_historia_arma_sus_opciones_con_el_conteo(): void
    {
        $vista = $this->listado(
            [$this->paciente(['numhistoria' => null])],
            1,
            '',
            FiltroDePacientes::SIN_HISTORIA,
            ['todos' => 9, 'sinHistoria' => 1, 'conHistoria' => 8],
        );

        $this->assertTrue($vista->viendoSinHistoria());
        $this->assertTrue($vista->hayFiltro());
        $this->assertSame(FiltroDePacientes::SIN_HISTORIA, $vista->filtro());
        $this->assertSame('1 paciente sin número de historia: hay que asignarles el número antes de atenderlos.', $vista->subtitulo());

        $filtros = $vista->filtros();
        $this->assertSame('Todos (9)', $filtros[0]['texto']);
        $this->assertSame('Sin historia (1)', $filtros[1]['texto']);
        $this->assertSame('Con historia (8)', $filtros[2]['texto']);
        $this->assertFalse($filtros[0]['activa']);
        $this->assertTrue($filtros[1]['activa']);
        $this->assertFalse($filtros[2]['activa']);

        // Cada opción lleva a su filtro y "Todos" no manda el parámetro (vuelve al listado pelado).
        $this->assertSame('/clinica/pacientes', $filtros[0]['url']);
        $this->assertSame('/clinica/pacientes?historia=sin-historia', $filtros[1]['url']);
        $this->assertSame('/clinica/pacientes?historia=con-historia', $filtros[2]['url']);
    }

    public function test_el_filtro_se_conserva_al_buscar_y_se_quita_al_limpiar(): void
    {
        $vista = $this->listado([], 0, 'Pérez', FiltroDePacientes::SIN_HISTORIA);

        // La búsqueda viaja en las opciones del segmentado: cambiar de filtro no la pierde.
        $this->assertStringContainsString('buscar=P', $vista->filtros()[1]['url']);
        $this->assertStringContainsString('historia=sin-historia', $vista->filtros()[1]['url']);

        // "Limpiar" saca la búsqueda y deja el filtro; "Ver todos" saca las dos cosas.
        $this->assertSame('/clinica/pacientes?historia=sin-historia', $vista->urlLimpiarBusqueda());
        $this->assertSame('/clinica/pacientes', $vista->urlTodos());
    }

    public function test_el_estado_vacio_distingue_el_filtro_sin_historia_de_la_busqueda(): void
    {
        // Con el filtro puesto y sin búsqueda, el vacío es una buena noticia, no un "no hay nada".
        $sinHistoria = $this->listado([], 0, '', FiltroDePacientes::SIN_HISTORIA);
        $this->assertSame('No hay pacientes sin historia', $sinHistoria->vacio()['titulo']);
        $this->assertStringContainsString('ya tienen su número', $sinHistoria->vacio()['texto']);

        // La búsqueda manda: buscar dentro del filtro y no encontrar es otra cosa.
        $conBusqueda = $this->listado([], 0, 'Zzz', FiltroDePacientes::SIN_HISTORIA);
        $this->assertSame('Ningún paciente coincide con la búsqueda', $conBusqueda->vacio()['titulo']);

        // Y sin filtro queda el vacío del consultorio entero, como antes.
        $this->assertSame('Este consultorio todavía no tiene pacientes', $this->listado([], 0)->vacio()['titulo']);
    }

    public function test_un_filtro_desconocido_se_normaliza_a_todos(): void
    {
        // El controlador pasa por acá antes de consultar: `?historia=cualquiera` no devuelve una lista
        // mezclada ni rompe; se comporta como "Todos".
        $this->assertSame(FiltroDePacientes::TODOS, FiltroDePacientes::normalizar('cualquiera'));
        $this->assertSame(FiltroDePacientes::TODOS, FiltroDePacientes::normalizar(''));
        $this->assertSame(FiltroDePacientes::TODOS, FiltroDePacientes::normalizar(null));
        $this->assertSame(FiltroDePacientes::SIN_HISTORIA, FiltroDePacientes::normalizar('sin-historia'));
        $this->assertSame(FiltroDePacientes::CON_HISTORIA, FiltroDePacientes::normalizar('con-historia'));
    }

    public function test_las_iniciales_aguantan_los_dos_formatos_de_nombre(): void
    {
        $this->assertSame('AP', Iniciales::de('Pérez, Ana María'));
        $this->assertSame('AP', Iniciales::de('Ana Pérez'));
        $this->assertSame('M', Iniciales::de('Madonna'));
        $this->assertSame('?', Iniciales::de('  '));
        $this->assertSame(Iniciales::tono('Pérez, Ana'), Iniciales::tono('  pérez, ana '));
    }

    public function test_el_listado_avisa_cuando_busco_un_termino_corregido(): void
    {
        $vista = new ListadoDePacientesVista(
            $this->paginador([$this->paciente()], 1),
            new BusquedaCorregida('Gonzlez', 'Gonzalez'),
            FiltroDePacientes::SIN_HISTORIA,
            ['todos' => 1, 'sinHistoria' => 1, 'conHistoria' => 0],
            '/clinica/pacientes',
        );

        // Lo escrito se conserva (el campo del buscador y el subtítulo); lo corregido es lo que se avisa.
        $this->assertSame('Gonzlez', $vista->buscar());
        $this->assertSame('Gonzalez', $vista->terminoBuscado());
        $this->assertTrue($vista->hayCorreccion());
        $this->assertSame(
            '1 resultado para «Gonzlez»: hay que asignarles el número antes de atenderlos.',
            $vista->subtitulo(),
        );

        // La salida busca lo escrito sin perder el segmento de historia.
        $this->assertSame(
            '/clinica/pacientes?buscar=Gonzlez&historia=sin-historia&literal=1',
            $vista->urlBuscarLoEscrito(),
        );
    }

    public function test_sin_correccion_no_hay_aviso(): void
    {
        $this->assertFalse($this->listado([], 0, 'Gonzalez')->hayCorreccion());
        $this->assertFalse($this->listado([], 0)->hayCorreccion());
        $this->assertSame('', $this->listado([], 0)->terminoBuscado());
    }
}
