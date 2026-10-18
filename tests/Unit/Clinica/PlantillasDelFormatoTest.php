<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\MapaDelEstudio;
use App\Clinica\Consulta\PlantillasDelFormato;
use PHPUnit\Framework\TestCase;

/**
 * **La regla de las plantillas del formato del eco** (O-17), sin base de datos ni app.
 *
 * Lo que se fija acá es lo que la pantalla hace en el navegador y lo que el servidor guarda:
 *
 *  - al aplicar, **sólo se completan los campos vacíos** y lo escrito se respeta;
 *  - la plantilla guarda los **campos descriptivos** (opciones, textos y áreas) y **no** las medidas ni las
 *    fechas;
 *  - la frase que la pantalla muestra dice esas dos cosas.
 */
class PlantillasDelFormatoTest extends TestCase
{
    public function test_aplicar_completa_solo_los_campos_vacios_y_respeta_lo_escrito(): void
    {
        $actuales = [
            'observacion' => 'Lo que ya escribió el médico.',
            'sugerencias' => '',
            'bordeutero'  => '1',
        ];

        $plantilla = [
            'observacion' => 'Anexos normales.',
            'sugerencias' => 'Útero normal.',
            'bordeutero'  => '2',
            'posicionavf' => '1',
            'cuerpo'      => '',   // una medida vacía en la plantilla no se escribe
        ];

        $resultado = PlantillasDelFormato::aplicar($actuales, $plantilla);

        $this->assertSame(['sugerencias' => 'Útero normal.', 'posicionavf' => '1'], $resultado['escritos']);
        $this->assertSame(['observacion' => 'Anexos normales.', 'bordeutero' => '2'], $resultado['respetados']);
    }

    public function test_nada_de_lo_que_esta_en_la_plantilla_se_pisa(): void
    {
        $resultado = PlantillasDelFormato::aplicar(
            ['observacion' => 'Otro texto.', 'sugerencias' => 'Otra conclusión.'],
            ['observacion' => 'Anexos normales.', 'sugerencias' => 'Útero normal.'],
        );

        $this->assertSame([], $resultado['escritos']);
        $this->assertCount(2, $resultado['respetados']);
    }

    public function test_solo_se_guardan_los_campos_descriptivos_del_estudio(): void
    {
        $valores = PlantillasDelFormato::valoresDescriptivos('ginecologico', [
            'posicionavf' => '1',
            'observacion' => 'Anexos normales.',
            'cuerpo'      => '62',
            'volumen_der' => '4.2',
            'fecha'       => '2026-10-06',
            'fur'         => '2026-06-01',
            'no_existe'   => 'x',
        ]);

        $this->assertSame([
            'posicionavf' => '1',
            'observacion' => 'Anexos normales.',
        ], $valores);

        // Las medidas no están: son de cada paciente.
        $this->assertArrayNotHasKey('cuerpo', $valores);
        $this->assertArrayNotHasKey('fecha', $valores);
        $this->assertArrayNotHasKey('fur', $valores);
    }

    public function test_la_descripcion_del_panel_dice_las_dos_reglas(): void
    {
        $frase = PlantillasDelFormato::queHace();

        $this->assertStringContainsString('Aplica la plantilla a los campos vacíos', $frase);
        $this->assertStringContainsString('No toca las medidas ni las fechas', $frase);
        $this->assertStringContainsString('no pisa lo que ya escribiste', $frase);
    }

    public function test_los_bloques_que_toca_salen_del_mapa_y_solo_los_descriptivos(): void
    {
        $bloques = PlantillasDelFormato::bloquesQueToca('ginecologico');

        $this->assertContains('Útero', $bloques);
        $this->assertContains('Anexos', $bloques);
        $this->assertContains('Hallazgos, observaciones y conclusiones', $bloques);
    }

    /**
     * **Ningún estudio guarda como plantilla un campo numérico o una fecha**: es la regla de O-17 recorriendo
     * el mapa de los ocho tipos, no una lista escrita a mano.
     */
    public function test_en_todos_los_estudios_las_medidas_y_las_fechas_quedan_afuera(): void
    {
        foreach (['ginecologico', 'obstetrico', 'segundo', 'tercero', 'doppler', 'mamario', 'abdominal', 'fetal'] as $tipo) {
            $descriptivas = MapaDelEstudio::columnasDescriptivas($tipo);

            foreach (MapaDelEstudio::campos($tipo) as $campo) {
                if (in_array($campo['tipo'] ?? 'texto', ['numero', 'fecha', 'fecha-tecleada'], true)) {
                    $this->assertNotContains(
                        $campo['columna'],
                        $descriptivas,
                        'El campo ' . $campo['columna'] . ' (' . ($campo['tipo'] ?? 'texto') . ') de ' . $tipo . ' no puede entrar en la plantilla.',
                    );
                }
            }
        }
    }
}
