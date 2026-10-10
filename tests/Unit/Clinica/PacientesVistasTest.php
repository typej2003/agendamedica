<?php

namespace Tests\Unit\Clinica;

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

    public function test_el_listado_distingue_vacio_de_busqueda_sin_resultados(): void
    {
        $vacio = new LengthAwarePaginator([], 0, 25);

        $sinPacientes = new ListadoDePacientesVista($vacio, '');
        $this->assertTrue($sinPacientes->estaVacio());
        $this->assertSame('bi-people', $sinPacientes->vacio()['icono']);

        $sinResultados = new ListadoDePacientesVista($vacio, 'Zzz');
        $this->assertSame('bi-search', $sinResultados->vacio()['icono']);
        $this->assertSame('Sin resultados para «Zzz».', $sinResultados->subtitulo());
    }

    public function test_el_subtitulo_y_el_rango_cuentan_los_pacientes(): void
    {
        $uno = new ListadoDePacientesVista(new LengthAwarePaginator([$this->paciente()], 1, 25), '');
        $this->assertSame('1 paciente en el registro con el que estás trabajando.', $uno->subtitulo());
        $this->assertSame('Mostrando 1–1 de 1', $uno->rango());

        $varios = new ListadoDePacientesVista(new LengthAwarePaginator([$this->paciente()], 40, 25), 'Pérez');
        $this->assertSame('40 resultados para «Pérez».', $varios->subtitulo());
    }

    public function test_las_iniciales_aguantan_los_dos_formatos_de_nombre(): void
    {
        $this->assertSame('AP', Iniciales::de('Pérez, Ana María'));
        $this->assertSame('AP', Iniciales::de('Ana Pérez'));
        $this->assertSame('M', Iniciales::de('Madonna'));
        $this->assertSame('?', Iniciales::de('  '));
        $this->assertSame(Iniciales::tono('Pérez, Ana'), Iniciales::tono('  pérez, ana '));
    }
}
