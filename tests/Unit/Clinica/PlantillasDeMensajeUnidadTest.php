<?php

namespace Tests\Unit\Clinica;

use App\Actions\Catalogos\ReglasDeLaPlantillaDeTexto;
use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use App\Clinica\Mensajes\Vistas\PlantillasDeMensajeVista;
use App\Cumpleanos\MensajeDeCumpleanos;
use App\Models\Evolucion;
use App\Notificaciones\AnalisisDeUnaPlantilla;
use App\Notificaciones\LargoDeSms;
use App\Notificaciones\MensajeDeRecordatorio;
use App\Notificaciones\PlantillaDeMensaje;
use App\Notificaciones\PlantillasDeMensaje;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * **Las plantillas de mensaje y de texto, sin base de datos ni HTTP** (WEB-8.4): la cuenta de los SMS, las etiquetas de cada
 * plantilla, el análisis que alimenta la vista previa, el ViewModel del editor y las reglas de las plantillas de texto.
 *
 * Arranca la app (`Tests\TestCase`) porque el ViewModel lee `old()` (trampa 68 de `TRAMPAS.md`).
 */
class PlantillasDeMensajeUnidadTest extends TestCase
{
    private const EJEMPLO = ['doctor' => 'Dra. Ana Prueba', 'centro' => 'Consultorio Norte', 'fecha' => '15/11/2026'];

    // ---------------------------------------------------------------------------------------- SMS

    public function test_un_texto_sin_tildes_cabe_en_160_y_se_parte_en_trozos_de_153(): void
    {
        $uno = LargoDeSms::de(str_repeat('a', 160));
        $this->assertSame(['gsm7', 160, 1, 160], [$uno['codificacion'], $uno['unidades'], $uno['segmentos'], $uno['limite']]);

        $dos = LargoDeSms::de(str_repeat('a', 161));
        $this->assertSame(['gsm7', 2, 153], [$dos['codificacion'], $dos['segmentos'], $dos['limite']]);

        $this->assertSame(3, LargoDeSms::de(str_repeat('a', 307))['segmentos']);
    }

    public function test_una_tilde_pasa_todo_el_mensaje_a_unicode_y_caben_70(): void
    {
        // «é» y «ñ» están en el alfabeto GSM; «á», «í», «ó» y «ú» no.
        $this->assertSame('gsm7', LargoDeSms::de('café y ñu')['codificacion']);
        $this->assertSame('unicode', LargoDeSms::de(str_repeat('a', 69) . 'á')['codificacion']);
        $this->assertSame(1, LargoDeSms::de(str_repeat('a', 69) . 'á')['segmentos']);
        $this->assertSame(2, LargoDeSms::de(str_repeat('a', 70) . 'á')['segmentos']);
        $this->assertSame(67, LargoDeSms::de(str_repeat('a', 70) . 'á')['limite']);
    }

    public function test_un_emoji_ocupa_dos_lugares_y_los_simbolos_gsm_de_extension_tambien(): void
    {
        $this->assertSame(2, LargoDeSms::de('😀')['unidades']);
        $this->assertSame('unicode', LargoDeSms::de('😀')['codificacion']);
        // `{` y `}` ocupan dos lugares en GSM-7.
        $this->assertSame(['gsm7', 5], [LargoDeSms::de('{a}')['codificacion'], LargoDeSms::de('{a}')['unidades']]);
        $this->assertSame(0, LargoDeSms::de('')['segmentos']);
    }

    // ------------------------------------------------------------------------------------ Etiquetas

    public function test_las_etiquetas_son_las_que_el_servidor_sustituye(): void
    {
        $this->assertSame(MensajeDeRecordatorio::ETIQUETAS, PlantillasDeMensaje::de('cita')->etiquetasValidas());
        $this->assertSame(MensajeDeCumpleanos::ETIQUETAS, PlantillasDeMensaje::de('cumple')->etiquetasValidas());
        $this->assertSame('plantilla_cita', PlantillasDeMensaje::de('cita')->columna);
        $this->assertSame('plantilla_cumple', PlantillasDeMensaje::de('cumple')->columna);
        $this->assertSame(500, PlantillasDeMensaje::de('cita')->maximo());
    }

    public function test_una_etiqueta_que_no_existe_se_detecta_y_una_en_mayuscula_trae_su_sugerencia(): void
    {
        $cita = PlantillasDeMensaje::de('cita');

        $this->assertSame(['{algo}', '{Paciente}'], $cita->etiquetasDesconocidas('Hola {paciente} {algo} {Paciente} {algo} {hora}'));
        $this->assertSame('{paciente}', $cita->sugerencia('{Paciente}'));
        $this->assertNull($cita->sugerencia('{algo}'));

        // `{fecha}` existe en la de citas y NO en la de cumpleaños (el servidor no la sustituye ahí).
        $this->assertSame([], $cita->etiquetasDesconocidas('{fecha}'));
        $this->assertSame(['{fecha}'], PlantillasDeMensaje::de('cumple')->etiquetasDesconocidas('{fecha}'));
    }

    public function test_la_vista_previa_la_arma_la_misma_clase_que_arma_el_mensaje_real(): void
    {
        $cita = PlantillasDeMensaje::de('cita');
        $datos = $cita->datosDeEjemplo(self::EJEMPLO);
        $texto = 'Hola {paciente}, el {fecha} a las {hora} con {doctor} en {centro}.';

        $this->assertSame((new MensajeDeRecordatorio())->armar($texto, $datos), $cita->armar($texto, $datos));
        $this->assertSame('Hola Ana Ejemplo, el 15/11/2026 a las 9:30 AM con Dra. Ana Prueba en Consultorio Norte.', $cita->armar($texto, $datos));

        $cumple = PlantillasDeMensaje::de('cumple');
        $this->assertSame(
            (new MensajeDeCumpleanos())->armar('¡Feliz cumpleaños {paciente}! {doctor}', $cumple->datosDeEjemplo(self::EJEMPLO)),
            $cumple->armar('¡Feliz cumpleaños {paciente}! {doctor}', $cumple->datosDeEjemplo(self::EJEMPLO)),
        );
        // Una plantilla vacía vale la de por defecto, en las dos.
        $this->assertSame('Recordatorio de cita para Ana Ejemplo: 15/11/2026 a las 9:30 AM.', $cita->armar(null, $datos));
        $this->assertSame('¡Feliz cumpleaños Ana Ejemplo! Le deseamos un excelente día. Saludos.', $cumple->armar('  ', $cumple->datosDeEjemplo(self::EJEMPLO)));
    }

    // ------------------------------------------------------------------------------------ Análisis

    public function test_el_analisis_avisa_de_lo_que_hay_que_revisar(): void
    {
        $cita = PlantillasDeMensaje::de('cita');
        $ejemplo = $cita->datosDeEjemplo(self::EJEMPLO);

        $vacia = AnalisisDeUnaPlantilla::de($cita, '', $ejemplo);
        $this->assertTrue($vacia['vacia']);
        $this->assertSame('info', $vacia['avisos'][0]['tono']);
        $this->assertStringContainsString('se usa el mensaje por defecto', $vacia['avisos'][0]['texto']);

        $mala = AnalisisDeUnaPlantilla::de($cita, 'Cita el {fecha} {algo}', $ejemplo);
        $textos = implode(' | ', array_column($mala['avisos'], 'texto'));
        $this->assertStringContainsString('{algo} no es una etiqueta', $textos);
        $this->assertStringContainsString('Falta {paciente}', $textos);
        $this->assertStringContainsString('no usa {hora}', $textos);

        $buena = AnalisisDeUnaPlantilla::de($cita, 'Hola {paciente}, {fecha} {hora}.', $ejemplo);
        $this->assertSame([], $buena['avisos']);
        $this->assertSame(1, $buena['sms']['segmentos']);

        $larga = AnalisisDeUnaPlantilla::de($cita, str_repeat('x', 501), $ejemplo);
        $this->assertTrue($larga['excede']);
    }

    // ------------------------------------------------------------------------------ El ViewModel

    private function vista(?Evolucion $evolucion, ?string $registroDelContexto = 'REG-1'): PlantillasDeMensajeVista
    {
        $urls = [];
        foreach (['cita', 'cumple'] as $slug) {
            $urls[$slug] = ['guardar' => "/g/{$slug}", 'restablecer' => "/r/{$slug}", 'vistaPrevia' => "/v/{$slug}"];
        }

        return new PlantillasDeMensajeVista('REG-1', $evolucion, $registroDelContexto, self::EJEMPLO, $urls, '/clinica/cumpleanos');
    }

    public function test_sin_plantilla_guardada_el_editor_dice_que_usa_la_de_por_defecto(): void
    {
        [$cita, $cumple] = $this->vista(null)->editores();

        $this->assertSame('', $cita['texto']);
        $this->assertFalse($cita['personalizada']);
        $this->assertSame('Usa el mensaje por defecto', $cita['estado']['texto']);
        $this->assertSame('Recordatorio de cita para Ana Ejemplo: 15/11/2026 a las 9:30 AM.', $cita['analisis']['vista_previa']);
        $this->assertSame('plantilla-cita', $cita['ancla']);
        $this->assertSame(['{paciente}', '{fecha}', '{hora}', '{doctor}', '{centro}'], array_column($cita['etiquetas'], 'etiqueta'));
        $this->assertSame(['{paciente}', '{doctor}'], array_column($cumple['etiquetas'], 'etiqueta'));
        $this->assertSame(500, $cita['maximo']);
    }

    public function test_con_plantilla_guardada_el_editor_la_muestra_y_la_vista_previa_la_sustituye(): void
    {
        $evolucion = new Evolucion(['plantilla_cita' => 'Hola {paciente}, {fecha}.', 'plantilla_cumple' => 'Feliz {paciente}, {doctor}']);
        [$cita, $cumple] = $this->vista($evolucion)->editores();

        $this->assertTrue($cita['personalizada']);
        $this->assertSame('Con tu texto', $cita['estado']['texto']);
        $this->assertSame('Hola {paciente}, {fecha}.', $cita['texto']);
        $this->assertSame('Hola Ana Ejemplo, 15/11/2026.', $cita['analisis']['vista_previa']);
        $this->assertSame('Feliz Ana Ejemplo, Dra. Ana Prueba', $cumple['analisis']['vista_previa']);
    }

    public function test_el_aviso_de_registro_solo_aparece_si_el_consultorio_es_de_otro_registro(): void
    {
        $this->assertNull($this->vista(null, 'REG-1')->avisoDeRegistro());
        $this->assertNull($this->vista(null, null)->avisoDeRegistro());
        $this->assertStringContainsString('REG-2', (string) $this->vista(null, 'REG-2')->avisoDeRegistro());
    }

    public function test_la_frase_del_sms_dice_cuantos_son(): void
    {
        $this->assertSame('Sin texto.', PlantillasDeMensajeVista::fraseDeSms(LargoDeSms::de('')));
        $this->assertStringStartsWith('1 SMS (5 de 160)', PlantillasDeMensajeVista::fraseDeSms(LargoDeSms::de('hola!')));
        $this->assertStringStartsWith('2 SMS (', PlantillasDeMensajeVista::fraseDeSms(LargoDeSms::de(str_repeat('á', 71))));
    }

    // ----------------------------------------------------------- Las reglas de las plantillas de texto

    public function test_las_reglas_de_una_plantilla_de_texto(): void
    {
        $this->assertSame('CONTROL PRENATAL', ReglasDeLaPlantillaDeTexto::titulo("  control   prenatal "));
        $this->assertSame('EV', ReglasDeLaPlantillaDeTexto::tipo('ev'));
        $this->assertSame('0007', ReglasDeLaPlantillaDeTexto::siguienteCodigo(['0006', 'W5301', null, 'x']));
        $this->assertSame('0001', ReglasDeLaPlantillaDeTexto::siguienteCodigo([]));
        $this->assertSame(ReglasDeLaPlantillaDeTexto::clave('Dieta Diabética'), ReglasDeLaPlantillaDeTexto::clave('  DIETA   diabetica'));
        $this->assertSame(ReglasDeLaPlantillaDeTexto::textoComparable("a\r\nb "), ReglasDeLaPlantillaDeTexto::textoComparable("a\nb"));
    }

    public function test_los_tipos_desconocidos_se_rechazan_en_el_alta_pero_se_conservan_al_editar(): void
    {
        $this->assertSame('ZZ', ReglasDeLaPlantillaDeTexto::tipo('zz', 'ZZ'));

        foreach ([null, '', 'ZZ'] as $malo) {
            try {
                ReglasDeLaPlantillaDeTexto::tipo($malo);
                $this->fail('Debió rechazar el tipo.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('tipo de plantilla', $e->getMessage());
            }
        }
    }

    public function test_titulo_y_texto_son_obligatorios_y_tienen_tope(): void
    {
        foreach ([fn () => ReglasDeLaPlantillaDeTexto::titulo('  '), fn () => ReglasDeLaPlantillaDeTexto::texto(null),
            fn () => ReglasDeLaPlantillaDeTexto::titulo(str_repeat('a', 201)), fn () => ReglasDeLaPlantillaDeTexto::texto(str_repeat('a', 20001))] as $caso) {
            try {
                $caso();
                $this->fail('Debió rechazar el valor.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_los_tipos_de_plantilla_se_nombran_y_los_desconocidos_se_muestran_tal_cual(): void
    {
        $this->assertSame('Evolución', TiposDePlantillaDeTexto::nombre('EV'));
        $this->assertSame('Informe médico', TiposDePlantillaDeTexto::nombre('IM'));
        $this->assertSame('Dietas / Recomendaciones', TiposDePlantillaDeTexto::nombre('DI'));
        $this->assertSame('Tipo ZZ', TiposDePlantillaDeTexto::nombre('ZZ'));
        $this->assertSame('Sin tipo', TiposDePlantillaDeTexto::nombre(null));
        $this->assertTrue(TiposDePlantillaDeTexto::seUsaEnLaWeb('EV'));
        // Los nueve tipos de eco ya no son «huérfanos»: A-14 (2026-10-17) los ofreció en su estudio, a pedido de una
        // médica. `EM` (eco de cuello) y `ER` (eco renal) siguen sin pantalla en la web.
        $this->assertTrue(TiposDePlantillaDeTexto::seUsaEnLaWeb('UG'));
        $this->assertFalse(TiposDePlantillaDeTexto::seUsaEnLaWeb('EM'));
        // 23 desde el 2026-10-19: los tres tipos de *Exámenes/Estudios* de la consulta pediátrica (`EX`,
        // `IG` y `EE`, commit `355915e`). El conteo se quedó en 20 y la prueba se volvió roja sin que nadie
        // tocara el catálogo (el dato estaba bien: el assert estaba viejo).
        $this->assertCount(23, TiposDePlantillaDeTexto::nombres());
    }
}
