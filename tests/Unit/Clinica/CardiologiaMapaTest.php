<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Cardiologia\MapaDelCardiologo;
use App\Clinica\Consulta\Vistas\CardiologoVista;
use PHPUnit\Framework\TestCase;

/**
 * **El mapa del dato de los estudios cardiológicos** (CAR-2, 2026-11-04): que la sección tenga una pestaña, que sus campos
 * apunten a las columnas del esquema de CardioReport y que el ViewModel los pinte con su valor guardado.
 */
class CardiologiaMapaTest extends TestCase
{
    public function test_la_seccion_tiene_el_eco_y_sus_pestanas_de_estres_y_ete(): void
    {
        $this->assertSame([
            'eco' => 'Ecocardiograma',
            'estres' => 'Eco de estrés',
            'ete' => 'Eco transesofágico',
            'doppler-ms' => 'Doppler de miembros superiores',
            'carotidas' => 'Doppler carotídeo',
            'doppler-venoso' => 'Doppler venoso de miembros inferiores',
            'esfuerzo' => 'Prueba de esfuerzo',
            'oscilometria' => 'Oscilometría',
            'marcapasos' => 'Marcapasos',
            'doppler-arterial-mi' => 'Doppler arterial de miembros inferiores',
            'ekg' => 'E.K.G.',
            'archivos' => 'Archivos PDF',
        ], MapaDelCardiologo::PESTANAS[MapaDelCardiologo::SECCION]);
        $this->assertTrue(MapaDelCardiologo::esSeccion('cardiologia'));
        $this->assertFalse(MapaDelCardiologo::esSeccion('estudio'));
        $this->assertSame([
            'ecocardiografia', 'ecocardiografico_in', 'ecocardiograma_ete',
            'dd_arterial_venoso_ms', 'dd_arterias_carotidas_dos', 'ddflujo_venoso',
            'prueba_esfuerzo', 'oscilometria', 'implantacion_marcapasos', 'dd_arterial_mi', 'ekg',
        ], array_keys(MapaDelCardiologo::TABLAS));
    }

    public function test_los_campos_del_eco_son_las_columnas_del_esquema(): void
    {
        $columnas = array_column(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_ECO), 'columna');

        $this->assertSame([
            'ha_ai', 'ha_rao', 'ha_gpavd', 'ha_cavidad_vd', 'ha_septum_iv', 'ha_pared_post',
            'ha_ddvi', 'ha_dsvi', 'ha_ivsvi', 'ha_fa', 'ha_fe', 'conclusiones',
        ], $columnas);
    }

    public function test_el_eco_de_estres_y_el_ete_tienen_sus_columnas_y_opciones(): void
    {
        $estres = collect(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_ESTRES))->keyBy('clave');
        $ete = collect(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_ETE))->keyBy('clave');

        $this->assertSame('ecocardiografico_in', $estres['farmacologico']['tabla']);
        $this->assertSame('si_no', $estres['farmacologico']['tipo']);
        $this->assertSame(['Persantin', 'Persantin/Dubutamina', 'Dubutamina'], array_keys($estres['medicamento']['opciones']));
        $this->assertSame('hallasgos', $estres['hallasgos']['columna']);

        $this->assertSame('ecocardiograma_ete', $ete['indicacion']['tabla']);
        $this->assertSame('conclusiones', $ete['conclusiones_ete']['columna']);
        $this->assertArrayNotHasKey('medico', $ete->all(), 'la cédula del médico no se edita desde la web');
    }

    public function test_los_doppler_tienen_sus_columnas_y_sus_medidas(): void
    {
        $ms = array_column(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_DOPPLER_MS), 'columna');
        $car = collect(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_CAROTIDAS))->keyBy('clave');

        $this->assertSame([
            'indicacion', 'equipo', 'transductor', 'miembro_superior_derecho', 'miembro_superior_izquierdo',
            'texto', 'conclusion',
        ], $ms);

        $this->assertSame('ar_accp_der', $car['accp_der']['columna']);
        $this->assertSame('ar_accd_izq', $car['accd_izq']['columna']);
        $this->assertSame('numero', $car['aci_der']['tipo']);
        $this->assertSame('ar_carotida_interna_der', $car['pico_diastolico_der']['columna']);
        $this->assertSame('av_diametro_izq', $car['vertebral_diametro_izq']['columna']);
        $this->assertSame('relac_intima_media_d', $car['rim_der']['columna']);
        $this->assertSame('relac_intima_media', $car['rim_izq']['columna']);
        $this->assertSame('hallasgos', $car['hallasgos_car']['columna']);
    }

    public function test_el_doppler_venoso_es_la_grilla_de_siete_venas_por_cuatro_fases_y_dos_lados(): void
    {
        $venoso = MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_VENOSO);
        $claves = array_column($venoso, 'clave');

        // 7 venas × 4 fases × 2 lados + indicación, equipo, transductor, texto y conclusiones.
        $this->assertCount(56 + 5, $venoso);
        $this->assertContains('fem_comun_r2', $claves);
        $this->assertSame('fem_comun_r2', collect($venoso)->firstWhere('clave', 'fem_comun_r2')['columna']);
        $this->assertSame('Femoral común', collect($venoso)->firstWhere('clave', 'fem_comun_r2')['grupo']);
        $this->assertSame('tansductor', collect($venoso)->firstWhere('clave', 'transductor_venoso')['columna']);
    }

    public function test_esfuerzo_oscilometria_y_marcapasos_tienen_sus_columnas(): void
    {
        $esfuerzo = collect(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_ESFUERZO))->keyBy('clave');
        $oscilo = collect(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_OSCILOMETRIA))->keyBy('clave');
        $marcapasos = collect(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_MARCAPASOS))->keyBy('clave');

        $this->assertSame('prueba_esfuerzo', $esfuerzo['mets']['tabla']);
        $this->assertSame('conclusiones', $esfuerzo['conclusion_esfuerzo']['columna']);
        $this->assertSame('ccO2/kg/min', $esfuerzo['cons_max_oxigeno']['unidad']);

        $this->assertSame('ind_tobillo_brazo_derecho', $oscilo['itb_tobillo_der']['columna']);
        $this->assertSame('segmentos_t_md_izq', $oscilo['segmento_t_md_izq']['columna']);
        $this->assertSame('si_no', $oscilo['reserva_der']['tipo']);
        $this->assertSame('numero', $oscilo['itb_tobillo_der']['tipo']);

        $this->assertSame('implantacion_marcapasos', $marcapasos['mp_marca']['tabla']);
        $this->assertSame('Generador', $marcapasos['mp_marca']['grupo']);
        $this->assertSame('numero', $marcapasos['mp_umbral_ventriculo']['tipo']);
        $this->assertSame('Mediciones', $marcapasos['mp_resistencia_auricula']['grupo']);
        $this->assertCount(34, $marcapasos, 'las 34 columnas editables del marcapasos');
    }

    public function test_el_doppler_arterial_de_miembros_inferiores_usa_num_consulta_con_guion_bajo(): void
    {
        $arterial = collect(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_ARTERIAL_MI))->keyBy('clave');

        // La tabla ya existía (ginecología) y su clave del legado es numhistoria + num_consulta.
        $this->assertSame('num_consulta', MapaDelCardiologo::TABLAS['dd_arterial_mi'][2]);
        $this->assertSame('iliaca_ext_der', $arterial['iliaca_ext_der']['columna']);
        $this->assertSame('numero', $arterial['iliaca_ext_der']['tipo']);
        $this->assertSame('Ilíaca externa', $arterial['iliaca_ext_der']['grupo']);
        $this->assertSame('iliaca_ext', $arterial['iliaca_ext_morfologia']['columna']);
        $this->assertSame('texto', $arterial['iliaca_ext_morfologia']['tipo']);
        $this->assertSame('conclusion', $arterial['conclusion_arterial_mi']['columna']);
    }

    public function test_el_ekg_reusa_el_mapa_de_medicina_interna(): void
    {
        $ekg = collect(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_EKG))->keyBy('clave');

        $this->assertSame('ekg', $ekg['cardio_ekg_ritmo']['tabla']);
        $this->assertSame('ritmo', $ekg['cardio_ekg_ritmo']['columna']);
        $this->assertSame('si_no', $ekg['cardio_ekg_hbsai']['tipo']);
        $this->assertSame('parrafo', $ekg['cardio_ekg_conclusiones']['tipo']);
        $this->assertSame('conclusiones', $ekg['cardio_ekg_conclusiones']['columna']);
        $this->assertSame('texto', $ekg['cardio_ekg_fc']['tipo']);
    }

    public function test_la_pestana_pedida_se_normaliza_a_la_que_existe(): void
    {
        $this->assertSame('eco', MapaDelCardiologo::pestana(MapaDelCardiologo::SECCION, ''));
        $this->assertSame('eco', MapaDelCardiologo::pestana(MapaDelCardiologo::SECCION, 'inventada'));
    }

    public function test_el_viewmodel_pinta_los_valores_guardados_y_su_unidad(): void
    {
        $vista = new CardiologoVista(
            MapaDelCardiologo::SECCION,
            MapaDelCardiologo::PESTANA_ECO,
            [],
            ['ha_ai' => '3,4', 'ha_fe' => '60', 'conclusiones' => 'Normal.'],
            '/guardar',
            '/volver',
        );

        $campos = collect($vista->campos())->keyBy('clave');

        $this->assertSame('3,4', $campos['ha_ai']['valor']);
        $this->assertSame('cm', $campos['ha_ai']['unidad']);
        $this->assertSame('%', $campos['ha_fe']['unidad']);
        $this->assertSame('parrafo', $campos['conclusiones']['tipo']);
        $this->assertSame('col-12', $campos['conclusiones']['columna']);
        $this->assertSame('Estudios cardiológicos', $vista->titulo());
        $this->assertSame([], $vista->pestanas());
    }
}
