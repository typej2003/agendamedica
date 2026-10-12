<?php

namespace Tests\Unit\Clinica;

use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Catalogos\DefinicionDeCatalogoSimple;
use App\Clinica\Catalogos\ExamenesDelCatalogo;
use App\Clinica\Catalogos\Vistas\CatalogoSimpleVista;
use App\Clinica\Catalogos\Vistas\DoctoresVista;
use App\Clinica\Catalogos\Vistas\ExamenesVista;
use App\Models\Diagnostico;
use App\Models\Doctor;
use App\Models\Examen;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Los ViewModels y las reglas puras de los **catálogos clínicos** (WEB-8.1b), sin base de datos: lo ya leído → textos, estados,
 * filas y URLs. Extiende `Tests\TestCase` porque los formularios usan `old()` y `route()`.
 */
class CatalogosClinicosVistaTest extends TestCase
{
    private function pagina(array $filas, int $total = null): LengthAwarePaginator
    {
        return new LengthAwarePaginator($filas, $total ?? count($filas), 25, 1, ['path' => 'http://localhost/x']);
    }

    private function urls(): array
    {
        return ['guardar' => 'http://localhost/guardar', 'quitar' => fn (int $id) => 'http://localhost/quitar/' . $id];
    }

    public function test_el_siguiente_codigo_respeta_el_ancho_y_el_tope(): void
    {
        $this->assertSame('0043', ReglasDelCatalogo::siguienteCodigo(['0042', 'x'], 'motivos'));
        $this->assertSame('242', ReglasDelCatalogo::siguienteCodigo(['241', '003'], 'exámenes', 3));
        $this->assertSame('001', ReglasDelCatalogo::siguienteCodigo([], 'tipos', 3));

        $this->expectException(InvalidArgumentException::class);
        ReglasDelCatalogo::siguienteCodigo(['999'], 'exámenes', 3);
    }

    public function test_la_vista_simple_arma_filas_con_el_aviso_de_repetido_y_el_estado_vacio(): void
    {
        $fila = new Diagnostico();
        $fila->forceFill(['id' => 7, 'codediagnostico' => '0007', 'descripcion' => 'MIOMA', 'repetidos' => 2]);
        $fila->exists = true;

        $vm = new CatalogoSimpleVista(DefinicionDeCatalogoSimple::diagnosticos(), $this->pagina([$fila]), '', 1, null, 'http://localhost/base', $this->urls());

        $filas = $vm->filas();
        $this->assertSame('MIOMA', $filas[0]['nombre']);
        $this->assertSame('Hay 2 con este nombre', $filas[0]['repetido']);
        $this->assertSame('http://localhost/quitar/7', $filas[0]['urlQuitar']);
        $this->assertSame('http://localhost/base?editar=7', $filas[0]['urlEditar']);
        $this->assertStringContainsString('1 diagnóstico', $vm->subtitulo());
        $this->assertSame('Nuevo diagnóstico', $vm->tituloDelFormulario());

        $vacio = new CatalogoSimpleVista(DefinicionDeCatalogoSimple::motivosDeConsulta(), $this->pagina([]), 'zzz', 0, null, 'http://localhost/base', $this->urls());
        $this->assertTrue($vacio->estaVacio());
        $this->assertStringContainsString('Ningún motivo de consulta coincide con «zzz»', $vacio->vacio()['titulo']);

        $edicion = new CatalogoSimpleVista(DefinicionDeCatalogoSimple::diagnosticos(), $this->pagina([$fila]), '', 1, $fila, 'http://localhost/base', $this->urls());
        $this->assertSame('0007', $edicion->codigoEditando());
        $this->assertSame('MIOMA', $edicion->texto());
        $this->assertTrue($edicion->abrirAlCargar());
    }

    public function test_el_examen_con_un_tipo_fuera_del_catalogo_lo_conserva_como_opcion_y_el_filtro_ofrece_sin_tipo(): void
    {
        $examen = new Examen();
        $examen->forceFill(['id' => 3, 'codeexamen' => '003', 'examen' => 'UREA', 'codetipo' => '077', 'repetidos' => 1]);
        $examen->exists = true;

        $vm = new ExamenesVista($this->pagina([$examen]), '', '', ['001' => 'LABORATORIO'], 1, $examen, 'http://localhost/e', $this->urls());

        $this->assertSame('Tipo 077 (no está en el catálogo)', $vm->filas()[0]['tipo']);
        $valores = array_column($vm->opcionesDeTipo(), 'valor');
        $this->assertSame(['', '001', '077'], $valores);
        $this->assertSame('077', $vm->tipoSeleccionado());
        $this->assertContains(ExamenesDelCatalogo::SIN_TIPO, array_column($vm->opcionesDelFiltro(), 'valor'));
    }

    public function test_el_medico_referido_muestra_el_codigo_y_conserva_una_especialidad_desconocida(): void
    {
        $doctor = new Doctor();
        $doctor->forceFill(['id' => 4, 'cedula' => 8, 'apellidos' => 'PEREZ', 'nombres' => 'LUIS', 'codeespecial' => '055', 'repetidos' => 1]);
        $doctor->exists = true;

        $vm = new DoctoresVista($this->pagina([$doctor]), '', ['002' => 'CARDIOLOGIA'], 1, $doctor, 'http://localhost/d', $this->urls());

        $fila = $vm->filas()[0];
        $this->assertSame('LUIS PEREZ', $fila['nombre']);
        $this->assertSame('8', $fila['codigo']);
        $this->assertSame('Especialidad 055', $fila['especialidad']);
        $this->assertSame('8', $vm->codigoEditando());
        $this->assertSame(['002', '055'], array_column($vm->opcionesDeEspecialidad(), 'valor'));
    }
}
