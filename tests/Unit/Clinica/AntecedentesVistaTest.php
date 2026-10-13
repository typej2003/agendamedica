<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesCatalogoVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesVista;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * **Los antecedentes del paciente en la consulta** (WEB-4.4), sin base de datos: el agrupado por tipo, el
 * estado vacío, la marca de alerta, los "vacíos" del legado y el `old()` de un POST que falló.
 *
 * Todo lo que los ViewModels necesitan llega por el constructor (mismo criterio que los otros ViewModels
 * de la consulta), así que acá no hace falta ni Laravel.
 */
class AntecedentesVistaTest extends TestCase
{
    /**
     * Una fila como la devuelve `AntecedentesDelPaciente::delPaciente()`.
     *
     * `tipo2` es el valor **crudo** de la columna (`A` alerta, `B` la apagó un escritorio, `null` sin
     * marcar) y `alerta` el booleano ya resuelto.
     */
    private function antecedente(
        int $id,
        string $codigo,
        string $nombre,
        ?string $detalle = null,
        ?string $tipoCodigo = '01',
        ?string $tipoNombre = 'PERSONAL',
        bool $alerta = false,
        ?string $tipo2 = null,
        bool $enCatalogo = true,
    ): object {
        return (object) [
            'id' => $id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'detalle' => $detalle,
            'alerta' => $alerta,
            'tipo2' => $tipo2,
            'tipoCodigo' => $tipoCodigo,
            'tipoNombre' => $tipoNombre,
            'descripcionCruda' => $nombre,
            'enCatalogo' => $enCatalogo,
        ];
    }

    private function vista(array $antecedentes = [], array $old = []): SeccionDeAntecedentesVista
    {
        return new SeccionDeAntecedentesVista(
            new Collection($antecedentes),
            '/clinica/consulta/42/antecedentes',
            '/clinica/consulta/42?seccion=antecedentes',
            $old,
        );
    }

    // ------------------------------------------------------------------------------ El agrupado

    public function test_los_antecedentes_se_agrupan_por_el_nombre_de_su_tipo(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0002', 'DIABETES', 'desde 2019', '02', 'FAMILIAR'),
            $this->antecedente(2, '0285', 'HIPERTENSION', null, '01', 'PERSONAL'),
            $this->antecedente(3, '0001', 'SARAMPION', null, '01', 'PERSONAL'),
        ]);

        $grupos = $vista->grupos();

        $this->assertSame(['FAMILIAR', 'PERSONAL'], array_column($grupos, 'titulo'));
        $this->assertSame(['DIABETES'], array_column($grupos[0]['filas'], 'nombre'));
        // Dentro del grupo se conserva el orden de la lectura (por código en la base).
        $this->assertSame(['HIPERTENSION', 'SARAMPION'], array_column($grupos[1]['filas'], 'nombre'));
        $this->assertSame(3, $vista->total());
    }

    public function test_lo_que_no_tiene_tipo_va_a_un_grupo_sin_clasificar_al_final(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0001', 'SIN TIPO', null, null, null),
            $this->antecedente(2, '0002', 'CON TIPO', null, '02', 'FAMILIAR'),
        ]);

        $grupos = $vista->grupos();

        // "Sin clasificar" es una etiqueta de la pantalla, no un tipo inventado, y va última.
        $this->assertSame(['FAMILIAR', 'Sin clasificar'], array_column($grupos, 'titulo'));
        $this->assertSame(['SIN TIPO'], array_column($grupos[1]['filas'], 'nombre'));
    }

    // ------------------------------------------------------------------------------- El estado

    public function test_sin_antecedentes_la_seccion_esta_vacia(): void
    {
        $vista = $this->vista();

        $this->assertTrue($vista->estaVacio());
        $this->assertSame(0, $vista->total());
        $this->assertSame([], $vista->grupos());
        $this->assertSame('Sin cargar', $vista->estado()['texto']);
        $this->assertSame('neutro', $vista->estado()['tono']);
    }

    public function test_con_antecedentes_el_estado_los_cuenta_y_avisa_si_hay_alerta(): void
    {
        $sinAlerta = $this->vista([$this->antecedente(1, '0001', 'ASMA')]);
        $this->assertSame('Cargados', $sinAlerta->estado()['texto']);
        $this->assertSame('exito', $sinAlerta->estado()['tono']);

        $conAlerta = $this->vista([
            $this->antecedente(1, '0001', 'ASMA', null, '01', 'PERSONAL', true, 'A'),
            $this->antecedente(2, '0002', 'DIABETES'),
        ]);
        $this->assertSame(1, $conAlerta->alertas());
        $this->assertSame('Con alerta', $conAlerta->estado()['texto']);
        $this->assertStringContainsString('1 antecedente marcado', $conAlerta->estado()['titulo']);
    }

    /**
     * Sólo el `'A'` de la fuente es alerta: la `B` que dejó la casilla apagada de un escritorio y el `null`
     * de las filas que inserta el botón *Pasar* se muestran **sin marca** (no se interpretan).
     */
    public function test_solo_la_a_de_la_fuente_es_alerta(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0001', 'ALERTA', null, '01', 'PERSONAL', true, 'A'),
            $this->antecedente(2, '0002', 'APAGADA', null, '01', 'PERSONAL', false, 'B'),
            $this->antecedente(3, '0003', 'SIN MARCAR', null, '01', 'PERSONAL', false, null),
        ]);

        $filas = $vista->grupos()[0]['filas'];

        $this->assertTrue($filas[0]['alerta']);
        $this->assertFalse($filas[1]['alerta']);
        $this->assertFalse($filas[2]['alerta']);
        $this->assertSame(1, $vista->alertas());
    }

    // --------------------------------------------------------------------- Los vacíos del legado

    public function test_el_detalle_vacio_se_muestra_como_raya_y_el_largo_se_recorta(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0001', 'SIN DETALLE', null),
            $this->antecedente(2, '0002', 'CON DETALLE', "  II CESAREAS\nLA ULTIMA HACE 5 AÑOS  "),
            $this->antecedente(3, '0003', 'LARGO', str_repeat('x', 200)),
        ]);

        $filas = $vista->grupos()[0]['filas'];

        // Sin detalle: `null` (la vista pinta la raya del dato ausente).
        $this->assertSame('', $filas[0]['detalle']);
        $this->assertNull($filas[0]['detalleCorto']);
        // El detalle se colapsa para el listado, y el `<input>` lleva el texto tal cual (la vista lo pinta
        // sin recortar: es el dato que se edita).
        $this->assertSame('II CESAREAS LA ULTIMA HACE 5 AÑOS', $filas[1]['detalleCorto']);
        $this->assertSame("  II CESAREAS\nLA ULTIMA HACE 5 AÑOS  ", $filas[1]['detalle']);
        $this->assertSame(158, mb_strlen($filas[2]['detalleCorto']));
        $this->assertStringEndsWith('…', $filas[2]['detalleCorto']);
        $this->assertSame(200, mb_strlen($filas[2]['detalle']));
    }

    public function test_un_antecedente_que_ya_no_esta_en_el_catalogo_lo_avisa(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '9999', 'VIEJO', null, '01', 'PERSONAL', false, null, false),
        ]);

        $this->assertSame('ya no está en tu catálogo', $vista->grupos()[0]['filas'][0]['avisoCatalogo']);
    }

    // ------------------------------------------------------------------------------ El `old()`

    public function test_el_old_del_post_que_fallo_no_borra_lo_escrito(): void
    {
        $vista = $this->vista(
            [$this->antecedente(7, '0001', 'ASMA', 'lo guardado', '01', 'PERSONAL', false, null)],
            ['filas' => [7 => ['detalle' => 'lo que se escribió', 'alerta' => 'A']]],
        );

        $fila = $vista->grupos()[0]['filas'][0];

        $this->assertSame('lo que se escribió', $fila['detalle']);
        $this->assertTrue($fila['alerta']);
    }

    public function test_el_old_no_pisa_las_filas_que_no_vinieron(): void
    {
        $vista = $this->vista(
            [
                $this->antecedente(7, '0001', 'ASMA', 'lo guardado', '01', 'PERSONAL', true, 'A'),
                $this->antecedente(8, '0002', 'DIABETES', 'otra cosa', '01', 'PERSONAL', false, null),
            ],
            ['filas' => [7 => ['detalle' => 'nuevo', 'alerta' => 'B']]],
        );

        $filas = $vista->grupos()[0]['filas'];

        $this->assertSame('nuevo', $filas[0]['detalle']);
        $this->assertFalse($filas[0]['alerta']);
        // La fila 8 no vino en el `old()`: se muestra como está guardada.
        $this->assertSame('otra cosa', $filas[1]['detalle']);
        $this->assertFalse($filas[1]['alerta']);
    }

    // --------------------------------------------------------------------------- El buscador

    public function test_el_buscador_marca_lo_que_el_paciente_ya_tiene_y_lo_pendiente(): void
    {
        $catalogo = new SeccionDeAntecedentesCatalogoVista(
            new Collection([
                (object) ['codigo' => '0001', 'nombre' => 'ASMA', 'tipoCodigo' => '01', 'tipoNombre' => 'PERSONAL'],
                (object) ['codigo' => '0002', 'nombre' => 'DIABETES', 'tipoCodigo' => '02', 'tipoNombre' => 'FAMILIAR'],
                (object) ['codigo' => '0003', 'nombre' => 'TABACO', 'tipoCodigo' => '03', 'tipoNombre' => 'PSICOBIOLOGICOS'],
            ]),
            ['0001'],
            [['codigo' => '01', 'nombre' => 'PERSONAL']],
            '/clinica/consulta/42',
            '/clinica/consulta/42/antecedentes/crear',
            '',
            '',
            ['agregar' => '0003'],
        );

        $resultados = $catalogo->resultados();

        $this->assertTrue($resultados[0]['yaEsta'], 'ASMA ya está en el paciente');
        $this->assertFalse($resultados[0]['pendiente']);
        $this->assertFalse($resultados[1]['yaEsta']);
        $this->assertTrue($resultados[2]['pendiente'], 'TABACO quedó tildado en el buscador');
        $this->assertSame('FAMILIAR', $resultados[1]['tipo']);
        $this->assertSame(['0003'], $catalogo->pendientesDelBuscador());
        $this->assertStringContainsString('Tildaste un antecedente', $catalogo->avisoDePendientes());
        // El "Agregar" de una fila conserva lo que ya estaba tildado y suma el suyo.
        $urlAgregar = $catalogo->urlAgregar('0002');
        $this->assertStringContainsString('/clinica/consulta/42?seccion=antecedentes', $urlAgregar);
        $this->assertStringContainsString('agregar[]=0003', $urlAgregar);
        $this->assertStringContainsString('agregar[]=0002', $urlAgregar);
        // Y la URL del guardado lleva los dos: la sección y los pendientes.
        $this->assertStringContainsString('seccion=antecedentes', $catalogo->urlGuardar());
        $this->assertStringContainsString('agregar[]=0003', $catalogo->urlGuardar());
    }

    public function test_el_buscador_sin_pendientes_no_avisa_nada(): void
    {
        $catalogo = new SeccionDeAntecedentesCatalogoVista(new Collection(), [], [], '/x', '/y');

        $this->assertSame([], $catalogo->pendientesDelBuscador());
        $this->assertSame([], $catalogo->resultados());
        $this->assertSame('Tu catálogo no tiene antecedentes que coincidan', $catalogo->sinResultados()['titulo']);
    }
}
