<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Impresion\DistribuidorDeRecipe;
use App\Clinica\Impresion\DocumentoDeRecipe;
use App\Clinica\Impresion\ItemImpreso;
use App\Clinica\Impresion\MedidasDeLaMitad;
use App\Clinica\Impresion\MedidorDeTexto;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\OpcionesDeRecipe;
use App\Clinica\Impresion\ProximaCitaDelRecipe;
use App\Clinica\Impresion\Vistas\HojasDeRecipeVista;
use App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista;
use App\Clinica\Impresion\Vistas\DocumentosDeLaCitaVista;
use App\Models\RecipeFormato;
use PHPUnit\Framework\TestCase;

/**
 * **La hoja del récipe** (WEB-5.2) sin base de datos ni navegador: cómo se mide un texto, cuántos medicamentos
 * entran en una hoja, cuándo el récipe sigue en la segunda y qué dice cada mitad.
 *
 * Lo que se fija acá es la **regla** documentada en `DistribuidorDeRecipe`: tres escalones de letra (10, 9 y 8 pt), gana
 * el que necesite menos hojas y, a igualdad, la letra más grande; un medicamento nunca se parte entre dos hojas y cada
 * hoja repite el membrete y la firma. Que el cuerpo real del navegador no se desborde lo comprueba el verificador de
 * Chrome (`.agent-tools/verificar-recipe-impresion-web.js`).
 */
class ImpresionDeRecipeUnidadTest extends TestCase
{
    // ------------------------------------------------------------------------------------------ Ayudas

    private function membrete(array $elementos = [], array $pie = ['Av. Principal, Torre Salud', '0251-5551234', 'doctora@example.com'], ?string $logo = '/storage/logos/x.png', ?string $firma = null, ?string $sello = null): Membrete
    {
        return new Membrete(
            'Dra. Ana Pérez',
            'Ginecología y Obstetricia',
            'J-12345678-9',
            $logo,
            $pie,
            array_replace_recursive(RecipeFormato::elementosPorDefecto(), $elementos),
            'negro',
            $firma,
            $sello,
            'centro',
        );
    }

    private function item(string $codigo = '0001', ?string $comercial = 'KEFORAL', ?string $generico = 'CEFALEXINA', ?string $presentacion = 'Cap. 500 mg', ?string $indicaciones = 'Una cápsula cada 6 horas.', ?int $cantidad = 2, ?string $respaldo = null): ItemImpreso
    {
        return new ItemImpreso($codigo, $cantidad, $indicaciones, $comercial, $generico, $presentacion, $respaldo);
    }

    /** @param array<int,ItemImpreso> $items */
    private function documento(array $items, string $tipo = DocumentoDeRecipe::PACIENTE, array $persona = []): DocumentoDeRecipe
    {
        return new DocumentoDeRecipe(
            $tipo,
            $tipo === DocumentoDeRecipe::PAREJA ? 'Récipe de la pareja' : 'Récipe 1',
            $tipo === DocumentoDeRecipe::PAREJA ? null : 1,
            '2026-10-11',
            array_merge(['nombre' => 'Ana Pérez', 'cedula' => 'V-12345678', 'edad' => 36, 'direccion' => 'Av. Bolívar, Barquisimeto'], $persona),
            $items,
        );
    }

    /** @return array<int,ItemImpreso> */
    private function items(int $cuantos, ?string $indicaciones = 'Una cápsula cada 6 horas.'): array
    {
        $items = [];
        for ($i = 1; $i <= $cuantos; $i++) {
            $items[] = $this->item(sprintf('%04d', $i), 'MEDICAMENTO ' . $i, 'GENERICO ' . $i, 'Tabletas 500 mg', $indicaciones, $i);
        }

        return $items;
    }

    private function vista(DocumentoDeRecipe $documento, ?OpcionesDeRecipe $opciones = null, ?array $proximaCita = null, ?Membrete $membrete = null): HojasDeRecipeVista
    {
        $opciones ??= OpcionesDeRecipe::porDefecto();

        return new HojasDeRecipeVista(
            $membrete ?? $this->membrete(),
            $opciones,
            [$documento],
            $proximaCita,
            ['volver' => '/clinica/agenda', 'volverTexto' => 'Volver a la agenda'],
            new OpcionesDeImpresionVista($opciones, '/clinica/recipes/opciones-de-impresion', '/clinica/consulta/1/recipes/1/imprimir'),
        );
    }

    // -------------------------------------------------------------------------------------- Medidor

    public function test_un_texto_vacio_no_ocupa_lineas_y_uno_corto_ocupa_una(): void
    {
        $medidor = new MedidorDeTexto();

        $this->assertSame(0, $medidor->lineas('', 100, 10));
        $this->assertSame(0, $medidor->lineas("  \n ", 100, 10));
        $this->assertSame(1, $medidor->lineas('Una tableta al día.', 125, 10));
    }

    public function test_corta_por_palabra_y_cuenta_los_saltos_de_linea_como_parrafos(): void
    {
        $medidor = new MedidorDeTexto();
        $largo = str_repeat('Tomar una tableta cada ocho horas con las comidas. ', 6);

        $this->assertGreaterThan(1, $medidor->lineas($largo, 125, 10));
        // Cada salto de línea empieza un párrafo, aunque cada uno sea corto.
        $this->assertSame(3, $medidor->lineas("Primera.\nSegunda.\nTercera.", 125, 10));
        // Una línea en blanco en el medio ocupa su lugar.
        $this->assertSame(3, $medidor->lineas("Primera.\n\nTercera.", 125, 10));
    }

    public function test_la_negrita_es_mas_ancha_y_la_letra_mas_grande_ocupa_mas(): void
    {
        $medidor = new MedidorDeTexto();

        $this->assertGreaterThan($medidor->ancho('Medicamento número uno', 10), $medidor->ancho('Medicamento número uno', 10, true));
        $this->assertGreaterThan($medidor->ancho('Medicamento número uno', 8), $medidor->ancho('Medicamento número uno', 10));
        // Las tildes miden lo mismo que su letra base.
        $this->assertSame($medidor->ancho('accion', 10), $medidor->ancho('acción', 10));
    }

    public function test_una_palabra_mas_larga_que_la_linea_se_parte_en_vez_de_desbordar(): void
    {
        $medidor = new MedidorDeTexto();

        $this->assertGreaterThan(1, $medidor->lineas(str_repeat('W', 120), 100, 10));
    }

    // ----------------------------------------------------------------------------------- Medidas

    public function test_el_cuerpo_pierde_alto_con_un_logo_grande_y_centrado_y_lo_gana_sin_logo(): void
    {
        $medidas = new MedidasDeLaMitad();

        $base = $medidas->altoDelCuerpo($this->membrete(), 'Ana Pérez', null);
        $grandeCentrado = $medidas->altoDelCuerpo($this->membrete(['logo' => ['tamano' => 'grande', 'alineacion' => 'centro']]), 'Ana Pérez', null);
        $sinLogo = $medidas->altoDelCuerpo($this->membrete(['logo' => ['visible' => false]]), 'Ana Pérez', null);

        $this->assertLessThan($base, $grandeCentrado);
        $this->assertGreaterThan($base, $sinLogo);
        // El típico (logo mediano a la izquierda, pie de tres líneas) deja unos 100 mm de cuerpo.
        $this->assertGreaterThan(90.0, $base);
        $this->assertLessThan(115.0, $base);
    }

    public function test_el_pie_oculto_o_vacio_no_resta_y_cada_linea_resta(): void
    {
        $medidas = new MedidasDeLaMitad();

        $conTres = $medidas->altoDelCuerpo($this->membrete(), 'Ana', null);
        $conUna = $medidas->altoDelCuerpo($this->membrete(pie: ['Una sola línea']), 'Ana', null);
        $oculto = $medidas->altoDelCuerpo($this->membrete(['pie' => ['visible' => false]]), 'Ana', null);
        $sinPie = $medidas->altoDelCuerpo($this->membrete(pie: []), 'Ana', null);

        $this->assertGreaterThan($conTres, $conUna);
        $this->assertGreaterThan($conUna, $sinPie);
        $this->assertEqualsWithDelta($sinPie, $oculto, 0.001);
    }

    public function test_la_direccion_larga_y_la_fuente_ancha_restan_alto(): void
    {
        $medidas = new MedidasDeLaMitad();
        $direccion = str_repeat('Avenida Bolívar con calle doce, edificio Sol. ', 3);

        $this->assertLessThan(
            $medidas->altoDelCuerpo($this->membrete(), 'Ana', null),
            $medidas->altoDelCuerpo($this->membrete(), 'Ana', $direccion),
        );

        $especialidad = new Membrete(
            'Dr. Carlos Alberto Mendoza Rodríguez de la Torre',
            'Ginecología, obstetricia y medicina materno fetal',
            'J-12345678-9',
            '/storage/logos/x.png',
            [],
            array_replace_recursive(RecipeFormato::elementosPorDefecto(), [
                'medico' => ['fuente' => 'dejavu_sans', 'negrita' => true],
                'especialidad' => ['fuente' => 'dejavu_sans'],
            ]),
            'negro',
            null,
            null,
            'centro',
        );
        $arimo = new Membrete($especialidad->medico, $especialidad->especialidad, $especialidad->rif, '/storage/logos/x.png', [],
            RecipeFormato::elementosPorDefecto(), 'negro', null, null, 'centro');

        $this->assertGreaterThanOrEqual($medidas->altoDelMembrete($arimo), $medidas->altoDelMembrete($especialidad));
    }

    public function test_un_membrete_absurdo_deja_un_cuerpo_minimo_y_no_negativo(): void
    {
        $medidas = new MedidasDeLaMitad();
        $enorme = $this->membrete(['logo' => ['tamano' => 'grande', 'alineacion' => 'centro']], array_fill(0, 3, str_repeat('Línea de pie larguísima ', 30)));

        $this->assertGreaterThanOrEqual(30.0, $medidas->altoDelCuerpo($enorme, str_repeat('Nombre ', 30), str_repeat('Dirección ', 40)));
    }

    // ------------------------------------------------------------------------------- Distribuidor

    public function test_un_recipe_normal_entra_en_una_hoja_con_la_letra_mas_grande(): void
    {
        $reparto = (new DistribuidorDeRecipe())->distribuir($this->membrete(), $this->documento($this->items(3)), OpcionesDeRecipe::porDefecto());

        $this->assertSame(10, $reparto['puntos']);
        $this->assertSame([[0, 1, 2]], $reparto['hojas']);
    }

    public function test_si_con_una_letra_mas_chica_entra_en_una_hoja_baja_el_escalon(): void
    {
        $distribuidor = new DistribuidorDeRecipe();
        $membrete = $this->membrete();

        // Se busca el primer tamaño en que a 10 pt ya no entra en una hoja pero a 9 u 8 pt sí.
        $escalones = [];
        for ($cuantos = 1; $cuantos <= 14; $cuantos++) {
            $reparto = $distribuidor->distribuir($membrete, $this->documento($this->items($cuantos)), OpcionesDeRecipe::porDefecto());
            $escalones[$cuantos] = [$reparto['puntos'], count($reparto['hojas'])];
        }

        $conUnaHoja = array_filter($escalones, fn (array $e) => $e[1] === 1);
        $this->assertNotEmpty($conUnaHoja);
        // En cuanto se agregan medicamentos la letra baja o aparece la segunda hoja: nunca sube.
        $anterior = 10;
        foreach ($escalones as [$puntos, $hojas]) {
            if ($hojas === 1) {
                $this->assertLessThanOrEqual($anterior, $puntos);
                $anterior = $puntos;
            }
        }
        $this->assertContains(8, array_map(fn (array $e) => $e[0], $conUnaHoja), 'Algún récipe entra en una hoja sólo con la letra más chica');
        // Y más de una hoja a 10 pt no se usa cuando a 8 pt hacen falta las mismas.
        foreach ($escalones as [$puntos, $hojas]) {
            $this->assertContains($puntos, DistribuidorDeRecipe::ESCALONES);
        }
    }

    public function test_doce_medicamentos_siguen_en_una_segunda_hoja_sin_partir_ninguno(): void
    {
        $reparto = (new DistribuidorDeRecipe())->distribuir($this->membrete(), $this->documento($this->items(12)), OpcionesDeRecipe::porDefecto());

        $this->assertCount(2, $reparto['hojas']);

        // Todos están, cada uno una sola vez y en orden.
        $this->assertSame(range(0, 11), array_merge(...$reparto['hojas']));
        $this->assertNotEmpty($reparto['hojas'][1]);
    }

    public function test_con_muchisimos_medicamentos_hacen_falta_mas_hojas_y_ninguna_queda_vacia(): void
    {
        $reparto = (new DistribuidorDeRecipe())->distribuir($this->membrete(), $this->documento($this->items(40)), OpcionesDeRecipe::porDefecto());

        $this->assertGreaterThanOrEqual(3, count($reparto['hojas']));
        foreach ($reparto['hojas'] as $hoja) {
            $this->assertNotEmpty($hoja);
        }
        $this->assertSame(range(0, 39), array_merge(...$reparto['hojas']));
    }

    public function test_un_medicamento_que_no_cabe_ni_solo_se_queda_solo_en_su_hoja(): void
    {
        $enorme = $this->item('9999', 'ENORME', null, null, str_repeat("Una indicación kilométrica del legado. ", 400), 1);
        $reparto = (new DistribuidorDeRecipe())->distribuir(
            $this->membrete(),
            $this->documento([$this->item('0001'), $enorme, $this->item('0002')]),
            OpcionesDeRecipe::porDefecto(),
        );

        // El enorme va en su propia hoja: no se parte y no arrastra a los otros.
        $hojaDelEnorme = array_values(array_filter($reparto['hojas'], fn (array $hoja) => in_array(1, $hoja, true)))[0];
        $this->assertSame([1], $hojaDelEnorme);
        $this->assertSame([0, 1, 2], array_merge(...$reparto['hojas']));
    }

    public function test_en_solo_indicaciones_hay_mas_cuerpo_porque_no_hay_que_acomodar_la_mitad_rp(): void
    {
        $distribuidor = new DistribuidorDeRecipe();
        $documento = $this->documento($this->items(9, null), persona: ['direccion' => str_repeat('Avenida Bolívar y calle doce, edificio Sol, piso 4. ', 3)]);

        $conRp = $distribuidor->distribuir($this->membrete(), $documento, OpcionesDeRecipe::porDefecto());
        $soloIndicaciones = $distribuidor->distribuir($this->membrete(), $documento, new OpcionesDeRecipe(RecipeFormato::MODO_SOLO_INDICACIONES));

        $this->assertLessThanOrEqual(count($conRp['hojas']), count($soloIndicaciones['hojas']));
        $this->assertGreaterThanOrEqual($conRp['puntos'], $soloIndicaciones['puntos']);
    }

    public function test_un_recipe_sin_medicamentos_da_una_hoja_vacia_y_no_revienta(): void
    {
        $reparto = (new DistribuidorDeRecipe())->distribuir($this->membrete(), $this->documento([]), OpcionesDeRecipe::porDefecto());

        $this->assertSame([[]], $reparto['hojas']);
    }

    // ----------------------------------------------------------------------------------- El nombre

    public function test_el_nombre_sale_en_mayusculas_con_la_presentacion_y_comercial_por_defecto(): void
    {
        $item = $this->item(comercial: 'Keforal', generico: 'Cefalexina', presentacion: 'Cap. 500 mg');

        $this->assertSame('KEFORAL / CAP. 500 MG', $item->nombre());
        $this->assertSame('CEFALEXINA / CAP. 500 MG', $item->nombre(true));
    }

    public function test_si_falta_el_nombre_pedido_sale_el_otro_y_sin_ninguno_la_descripcion_o_el_codigo(): void
    {
        $this->assertSame('KEFORAL / CAP', $this->item(comercial: 'Keforal', generico: null, presentacion: 'Cap')->nombre(true));
        $this->assertSame('CEFALEXINA / CAP', $this->item(comercial: null, generico: 'Cefalexina', presentacion: 'Cap')->nombre(false));
        $this->assertSame('CEFALEXINA', $this->item(comercial: null, generico: 'Cefalexina', presentacion: null)->nombre());
        $this->assertSame('VIEJO / TABLETAS', $this->item(codigo: '0099', comercial: null, generico: null, presentacion: null, respaldo: 'Viejo / tabletas')->nombre());
        $this->assertSame('MEDICAMENTO 0099', $this->item(codigo: '0099', comercial: null, generico: null, presentacion: null)->nombre());
    }

    // ----------------------------------------------------------------------------------- Opciones

    public function test_las_opciones_sin_nada_guardado_son_rp_e_indicaciones_y_comercial(): void
    {
        $opciones = OpcionesDeRecipe::desde(null);

        $this->assertTrue($opciones->conRpEIndicaciones());
        $this->assertFalse($opciones->esGenerico());
        $this->assertSame('rp_indicaciones', $opciones->modo);
        $this->assertSame('comercial', $opciones->nombre);
    }

    public function test_las_opciones_guardadas_se_leen_y_un_valor_desconocido_vuelve_al_default(): void
    {
        $formato = new RecipeFormato();
        $formato->modo_impresion = 'solo_indicaciones';
        $formato->nombre_medicamento = 'generico';

        $opciones = OpcionesDeRecipe::desde($formato);
        $this->assertFalse($opciones->conRpEIndicaciones());
        $this->assertTrue($opciones->esGenerico());

        $roto = OpcionesDeRecipe::de('apaisado', 'raro');
        $this->assertTrue($roto->conRpEIndicaciones());
        $this->assertFalse($roto->esGenerico());
    }

    public function test_el_dialogo_marca_lo_guardado_y_resume_las_opciones(): void
    {
        $dialogo = new OpcionesDeImpresionVista(new OpcionesDeRecipe('solo_indicaciones', 'generico'), '/guardar', '/siguiente');

        $this->assertSame('/guardar', $dialogo->urlGuardar());
        $this->assertSame('/siguiente', $dialogo->siguiente());
        $this->assertSame(['solo_indicaciones'], array_column(array_filter($dialogo->modos(), fn (array $o) => $o['marcada']), 'valor'));
        $this->assertSame(['generico'], array_column(array_filter($dialogo->nombres(), fn (array $o) => $o['marcada']), 'valor'));
        $this->assertSame('Solo indicaciones · nombre genérico', $dialogo->resumen());
    }

    // ----------------------------------------------------------------------------- La vista previa

    public function test_en_rp_e_indicaciones_la_hoja_lleva_dos_mitades_y_la_direccion_solo_en_rp(): void
    {
        $hojas = $this->vista($this->documento($this->items(2)))->hojas();

        $this->assertCount(1, $hojas);
        $this->assertCount(2, $hojas[0]['mitades']);
        [$rp, $indicaciones] = $hojas[0]['mitades'];

        $this->assertSame('rp', $rp['clase']);
        $this->assertSame('Rp.', $rp['titulo']);
        $this->assertSame('Av. Bolívar, Barquisimeto', $rp['direccion']);
        $this->assertSame('indicaciones', $indicaciones['clase']);
        $this->assertSame('Indicaciones:', $indicaciones['titulo']);
        $this->assertNull($indicaciones['direccion']);

        // Rp.: cantidad y nombre; Indicaciones: nombre e indicación.
        $this->assertSame(['cantidad' => '1', 'nombre' => 'MEDICAMENTO 1 / TABLETAS 500 MG'], $rp['filas'][0]);
        $this->assertSame('Una cápsula cada 6 horas.', $indicaciones['filas'][0]['indicaciones']);
    }

    public function test_en_solo_indicaciones_la_hoja_lleva_una_sola_mitad(): void
    {
        $hojas = $this->vista($this->documento($this->items(2)), new OpcionesDeRecipe(RecipeFormato::MODO_SOLO_INDICACIONES))->hojas();

        $this->assertCount(1, $hojas[0]['mitades']);
        $this->assertSame('indicaciones', $hojas[0]['mitades'][0]['clase']);
    }

    public function test_el_nombre_generico_cambia_los_medicamentos_de_las_dos_mitades(): void
    {
        $hojas = $this->vista($this->documento($this->items(1)), new OpcionesDeRecipe(RecipeFormato::MODO_RP_E_INDICACIONES, RecipeFormato::NOMBRE_GENERICO))->hojas();

        $this->assertSame('GENERICO 1 / TABLETAS 500 MG', $hojas[0]['mitades'][0]['filas'][0]['nombre']);
        $this->assertSame('GENERICO 1 / TABLETAS 500 MG', $hojas[0]['mitades'][1]['filas'][0]['nombre']);
    }

    public function test_los_datos_del_paciente_salen_formateados(): void
    {
        $mitad = $this->vista($this->documento($this->items(1)))->hojas()[0]['mitades'][0];

        $this->assertSame('Ana Pérez', $mitad['paciente']);
        $this->assertSame('V-12345678', $mitad['cedula']);
        $this->assertSame('36 años', $mitad['edad']);
        $this->assertSame('11/10/2026', $mitad['fecha']);

        $unAnio = $this->vista($this->documento($this->items(1), persona: ['edad' => 1]))->hojas()[0]['mitades'][0];
        $this->assertSame('1 año', $unAnio['edad']);

        $sinEdad = $this->vista($this->documento($this->items(1), persona: ['edad' => null, 'cedula' => null]))->hojas()[0]['mitades'][0];
        $this->assertSame('', $sinEdad['edad']);
        $this->assertSame('', $sinEdad['cedula']);
    }

    public function test_el_recipe_de_la_pareja_no_lleva_edad_ni_direccion(): void
    {
        $documento = $this->documento(
            $this->items(1),
            DocumentoDeRecipe::PAREJA,
            ['nombre' => 'Mariana Rojas', 'cedula' => '9876543', 'edad' => 40, 'direccion' => 'No debe salir'],
        );

        $hojas = $this->vista($documento)->hojas();

        foreach ($hojas[0]['mitades'] as $mitad) {
            $this->assertSame('Mariana Rojas', $mitad['paciente']);
            $this->assertSame('9876543', $mitad['cedula']);
            $this->assertNull($mitad['edad']);
            $this->assertNull($mitad['direccion']);
        }
    }

    public function test_cuando_el_recipe_sigue_en_otra_hoja_cada_mitad_lo_dice_y_repite_los_datos(): void
    {
        $hojas = $this->vista($this->documento($this->items(12)))->hojas();

        $this->assertCount(2, $hojas);
        $this->assertSame('Hoja 1 de 2', $hojas[0]['etiqueta']);
        $this->assertSame('Hoja 2 de 2', $hojas[1]['etiqueta']);

        foreach ($hojas as $hoja) {
            foreach ($hoja['mitades'] as $mitad) {
                $this->assertSame($hoja['etiqueta'], $mitad['etiqueta']);
                $this->assertSame('Ana Pérez', $mitad['paciente']);
            }
        }

        // Los mismos medicamentos de la misma hoja en las dos mitades.
        $this->assertCount(count($hojas[1]['mitades'][0]['filas']), $hojas[1]['mitades'][1]['filas']);
        $this->assertSame('12 medicamentos', explode(' · ', $this->vista($this->documento($this->items(12)))->resumenDeLaImpresion())[1]);
    }

    public function test_un_recipe_de_una_hoja_no_lleva_etiqueta(): void
    {
        $this->assertNull($this->vista($this->documento($this->items(2)))->hojas()[0]['etiqueta']);
    }

    public function test_la_proxima_cita_sale_en_cada_mitad_con_la_hora_solo_si_la_hay(): void
    {
        $conHora = $this->vista($this->documento($this->items(1)), proximaCita: ['fecha' => '2026-10-18', 'hora' => '10:30']);
        $sinHora = $this->vista($this->documento($this->items(1)), proximaCita: ['fecha' => '2026-10-18', 'hora' => null]);
        $ninguna = $this->vista($this->documento($this->items(1)));

        foreach ($conHora->hojas()[0]['mitades'] as $mitad) {
            $this->assertSame('Próxima cita: 18/10/2026  10:30', $mitad['proximaCita']);
        }
        $this->assertSame('Próxima cita: 18/10/2026', $sinHora->hojas()[0]['mitades'][0]['proximaCita']);
        $this->assertNull($ninguna->hojas()[0]['mitades'][0]['proximaCita']);
        $this->assertSame('Próxima cita: 18/10/2026  10:30', ProximaCitaDelRecipe::texto(['fecha' => '2026-10-18', 'hora' => '10:30']));
    }

    public function test_el_titulo_y_el_resumen_describen_lo_que_se_imprime(): void
    {
        $vista = $this->vista($this->documento($this->items(12)));

        $this->assertSame('Récipe 1 · Ana Pérez', $vista->titulo());
        $this->assertSame('Un récipe · 12 medicamentos · 2 hojas', $vista->resumenDeLaImpresion());
        $this->assertSame('Rp. + Indicaciones · nombre comercial', $vista->resumenDeLasOpciones());
        $this->assertSame('/clinica/agenda', $vista->urlVolver());
        $this->assertSame('Volver a la agenda', $vista->textoVolver());
    }

    // ------------------------------------------------------------------------------------ Membrete

    public function test_el_membrete_resuelve_las_clases_de_cada_bloque(): void
    {
        $membrete = $this->membrete([
            'logo' => ['alineacion' => 'derecha', 'tamano' => 'grande', 'borde' => true],
            'medico' => ['alineacion' => 'izquierda', 'fuente' => 'tinos', 'negrita' => true, 'italica' => true, 'subrayado' => true, 'borde' => true],
        ]);

        $this->assertSame('logo-derecha', $membrete->distribucion());
        $this->assertSame('logo-grande con-borde', $membrete->clasesDelLogo());
        $this->assertSame('alin-izquierda fuente-tinos negrita italica subrayado con-borde', $membrete->clasesDeTexto('medico'));
        $this->assertSame('alin-centro fuente-arimo negrita', $membrete->clasesDeTexto('especialidad'));
        $this->assertSame(['medico', 'especialidad', 'rif'], array_column($membrete->textos(), 'elemento'));
    }

    public function test_el_membrete_no_pinta_lo_que_el_medico_oculto_ni_lo_que_esta_vacio(): void
    {
        $membrete = new Membrete(
            'Dra. Ana Pérez',
            null,
            '  ',
            null,
            ['Pie uno'],
            array_replace_recursive(RecipeFormato::elementosPorDefecto(), ['medico' => ['visible' => false], 'pie' => ['visible' => false]]),
            'negro',
            null,
            null,
            'centro',
        );

        $this->assertFalse($membrete->conLogo());
        $this->assertSame('sin-logo', $membrete->distribucion());
        $this->assertSame([], $membrete->textos());
        $this->assertSame([], $membrete->lineasDelPie());
    }

    public function test_un_logo_oculto_por_el_medico_no_se_pinta_aunque_el_archivo_exista(): void
    {
        $this->assertFalse($this->membrete(['logo' => ['visible' => false]])->conLogo());
        $this->assertTrue($this->membrete()->conLogo());
    }

    public function test_el_color_de_la_linea_conocido_se_resuelve_y_el_desconocido_vuelve_a_negro(): void
    {
        $azul = new Membrete('Dr.', null, null, null, [], RecipeFormato::elementosPorDefecto(), 'azul', null, null, 'centro');
        $raro = new Membrete('Dr.', null, null, null, [], RecipeFormato::elementosPorDefecto(), 'turquesa', null, null, 'centro');

        $this->assertSame('#1565c0', $azul->colorCss());
        $this->assertSame('#000000', $raro->colorCss());
    }

    // ---------------------------------------------------------------- Los récipes de una cita

    private function listaDeLaCita(array $disponibles, bool $atendida = true): DocumentosDeLaCitaVista
    {
        return new DocumentosDeLaCitaVista(
            'Ana Pérez',
            '2026-10-07',
            $atendida,
            $disponibles,
            '/clinica/agenda?fecha=2026-10-07',
            fn (string $clave) => '/clinica/agenda/5/documentos/imprimir?documento=' . $clave,
            new OpcionesDeImpresionVista(OpcionesDeRecipe::porDefecto(), '/guardar'),
        );
    }

    private function recipeDeLaLista(string $clave, string $titulo, string $resumen, ?string $fecha, bool $pareja = false): array
    {
        return [
            'clave' => $clave, 'tipo' => 'recipe', 'titulo' => $titulo, 'resumen' => $resumen, 'fecha' => $fecha,
            'icono' => $pareja ? 'bi-people' : 'bi-prescription2', 'de_la_pareja' => $pareja,
        ];
    }

    public function test_la_lista_de_la_cita_describe_cada_documento_sin_su_contenido_y_ofrece_imprimir_todos(): void
    {
        $lista = $this->listaDeLaCita([
            $this->recipeDeLaLista('recipe-1', 'Récipe 1', '3 medicamentos', '2026-10-05'),
            $this->recipeDeLaLista('recipe-pareja', 'Récipe de la pareja', '1 medicamento', null, true),
            ['clave' => 'reposo', 'tipo' => 'reposo', 'titulo' => 'Reposo médico', 'resumen' => '8 días', 'fecha' => '2026-10-07', 'icono' => 'bi-bandaid', 'de_la_pareja' => false],
        ]);

        $this->assertFalse($lista->estaVacia());
        $this->assertTrue($lista->puedeImprimirTodos());
        $this->assertTrue($lista->hayRecipes());
        $this->assertSame('Documentos de Ana Pérez', $lista->titulo());
        $this->assertSame('Cita del 07/10/2026', $lista->subtitulo());
        $this->assertSame('/clinica/agenda/5/documentos/imprimir?documento=todos', $lista->urlImprimirTodos());

        $documentos = $lista->documentos();
        $this->assertSame('/clinica/agenda/5/documentos/imprimir?documento=recipe-1', $documentos[0]['urlImprimir']);
        $this->assertSame('05/10/2026', $documentos[0]['fecha']);
        $this->assertSame('bi-prescription2', $documentos[0]['icono']);
        $this->assertNull($documentos[1]['fecha']);
        $this->assertSame('bi-people', $documentos[1]['icono']);
        // Los récipes pasan por las opciones de la hoja; los demás documentos van directo a su vista previa.
        $this->assertTrue($documentos[0]['conOpciones']);
        $this->assertFalse($documentos[2]['conOpciones']);
        // La lista sólo lleva títulos y resúmenes: nada de medicamentos, indicaciones ni textos.
        $this->assertSame(['titulo', 'resumen', 'fecha', 'urlImprimir', 'icono', 'conOpciones'], array_keys($documentos[0]));
    }

    public function test_con_un_solo_documento_no_se_ofrece_imprimir_todos_y_sin_recipes_no_hay_opciones(): void
    {
        $lista = $this->listaDeLaCita([
            ['clave' => 'informe', 'tipo' => 'informe', 'titulo' => 'Informe médico', 'resumen' => 'Informe médico', 'fecha' => null, 'icono' => 'bi-file-earmark-text', 'de_la_pareja' => false],
        ]);

        $this->assertFalse($lista->puedeImprimirTodos());
        $this->assertFalse($lista->hayRecipes());
    }

    public function test_sin_documentos_la_lista_dice_por_que_no_hay_nada(): void
    {
        $atendida = $this->listaDeLaCita([]);
        $sinAtender = $this->listaDeLaCita([], false);

        $this->assertTrue($atendida->estaVacia());
        $this->assertSame('Esta consulta no tiene documentos para imprimir', $atendida->sinDocumentos()['titulo']);
        $this->assertSame('Esta cita todavía no fue atendida', $sinAtender->sinDocumentos()['titulo']);
    }
}
