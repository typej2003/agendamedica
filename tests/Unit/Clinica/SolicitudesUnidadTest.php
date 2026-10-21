<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Consulta\Vistas\ReposoVista;
use App\Clinica\Impresion\DistribuidorDeDocumento;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\Vistas\HojasDeDocumentoVista;
use App\Models\RecipeFormato;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * **Las solicitudes de la consulta** (WEB-5.3a) sin base de datos ni navegador: qué es un documento, la cuenta de las fechas del
 * reposo (con sus bordes), cómo se reparte un documento en mitades de hoja y qué muestran las pestañas.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome
 * (`.agent-tools/verificar-solicitudes-documentos-web.js`).
 */
class SolicitudesUnidadTest extends TestCase
{
    private function membrete(array $pie = ['Av. Principal, Torre Salud', '0251-5551234', 'doctora@example.com']): Membrete
    {
        return new Membrete(
            'Dra. Ana Pérez', 'Ginecología y Obstetricia', 'J-12345678-9', null, $pie,
            RecipeFormato::elementosPorDefecto(), 'negro', null, null, 'centro',
        );
    }

    private function documento(array $parrafos, array $fijos = [], string $nombre = 'Ana Alvarez'): DocumentoImpreso
    {
        return new DocumentoImpreso(
            DocumentoImpreso::INFORME, 'informe', 'INFORME MÉDICO', 'Informe médico', '2026-10-07',
            ['nombre' => $nombre, 'cedula' => 'V-14555666', 'edad' => 36, 'direccion' => null], $fijos, $parrafos,
        );
    }

    private function palabras(int $cuantas, string $prefijo = 'palabra'): string
    {
        return implode(' ', array_map(fn (int $n) => $prefijo . $n, range(1, $cuantas)));
    }

    // ===================================================================== Qué es un documento

    public function test_un_reposo_es_un_documento_con_mas_de_un_dia_o_con_justificacion(): void
    {
        $this->assertFalse(QueEsUnDocumento::reposo(1, null), 'la plantilla del escritorio: 1 día sin nada');
        $this->assertFalse(QueEsUnDocumento::reposo(1, '   '));
        $this->assertFalse(QueEsUnDocumento::reposo(null, null));
        $this->assertFalse(QueEsUnDocumento::reposo(0, ''));
        $this->assertTrue(QueEsUnDocumento::reposo(2, null));
        $this->assertTrue(QueEsUnDocumento::reposo('8', ''));
        $this->assertTrue(QueEsUnDocumento::reposo(1, 'Reposo relativo'));
    }

    public function test_un_informe_es_un_documento_si_su_texto_no_esta_vacio_ni_es_el_punto_de_la_plantilla(): void
    {
        $this->assertFalse(QueEsUnDocumento::informe('.'));
        $this->assertFalse(QueEsUnDocumento::informe(' . '));
        $this->assertFalse(QueEsUnDocumento::informe(''));
        $this->assertFalse(QueEsUnDocumento::informe(null));
        $this->assertTrue(QueEsUnDocumento::informe('Texto'));
        $this->assertTrue(QueEsUnDocumento::informe('..'));
    }

    public function test_una_constancia_es_un_documento_propio_si_trae_algo_escrito_por_el_medico(): void
    {
        $this->assertFalse(QueEsUnDocumento::constancia(null, null, null));
        $this->assertFalse(QueEsUnDocumento::constancia('', '  ', ''));
        $this->assertTrue(QueEsUnDocumento::constancia('Observación', null, null));
        $this->assertTrue(QueEsUnDocumento::constancia(null, 'GASTRITIS.', null));
        $this->assertTrue(QueEsUnDocumento::constancia(null, null, 'Título'));
    }

    // ===================================================================== La cuenta del reposo

    public function test_el_dia_de_inicio_cuenta_como_el_primero_el_ultimo_dia_es_inicio_mas_dias_menos_uno(): void
    {
        $inicio = Carbon::parse('2026-10-10');

        $this->assertSame('2026-10-10', ReposoDeLaConsulta::hasta($inicio, 1)->toDateString(), '1 día: termina el mismo día');
        $this->assertSame('2026-10-11', ReposoDeLaConsulta::reintegro($inicio, 1)->toDateString());
        $this->assertSame('2026-10-17', ReposoDeLaConsulta::hasta($inicio, 8)->toDateString());
        $this->assertSame('2026-10-18', ReposoDeLaConsulta::reintegro($inicio, 8)->toDateString());
    }

    public function test_la_cuenta_cruza_meses_anios_y_el_29_de_febrero(): void
    {
        $this->assertSame('2027-01-03', ReposoDeLaConsulta::hasta(Carbon::parse('2026-12-30'), 5)->toDateString());
        $this->assertSame('2027-01-04', ReposoDeLaConsulta::reintegro(Carbon::parse('2026-12-30'), 5)->toDateString());
        $this->assertSame('2028-02-29', ReposoDeLaConsulta::hasta(Carbon::parse('2028-02-27'), 3)->toDateString());
        $this->assertSame('2028-03-01', ReposoDeLaConsulta::reintegro(Carbon::parse('2028-02-27'), 3)->toDateString());
        $this->assertSame('2027-03-01', ReposoDeLaConsulta::reintegro(Carbon::parse('2027-02-27'), 2)->toDateString(), 'febrero de un año no bisiesto');
        // Un reposo de un año.
        $this->assertSame('2027-10-09', ReposoDeLaConsulta::hasta(Carbon::parse('2026-10-10'), 365)->toDateString());
    }

    public function test_la_cuenta_ignora_la_hora_del_dia_de_inicio(): void
    {
        $this->assertSame('2026-10-12', ReposoDeLaConsulta::hasta(Carbon::parse('2026-10-10 23:59:59'), 3)->toDateString());
    }

    public function test_el_inicio_de_una_fila_sin_fecha_o_con_basura_cae_en_el_dia_de_la_consulta_y_una_fecha_futura_se_respeta(): void
    {
        $this->assertSame('2026-10-07', ReposoDeLaConsulta::fechaDeInicio(null, '2026-10-07'));
        $this->assertSame('2026-10-07', ReposoDeLaConsulta::fechaDeInicio('', '2026-10-07'));
        $this->assertSame('2026-10-07', ReposoDeLaConsulta::fechaDeInicio('0000-00-00', '2026-10-07'));
        $this->assertSame('2026-10-07', ReposoDeLaConsulta::fechaDeInicio('0000-00-01', '2026-10-07'));
        $this->assertSame('2026-10-07', ReposoDeLaConsulta::fechaDeInicio('no es una fecha', '2026-10-07'));
        $this->assertSame('2030-01-15', ReposoDeLaConsulta::fechaDeInicio('2030-01-15 00:00:00', '2026-10-07'), 'un reposo puede empezar en el futuro');
    }

    public function test_la_vista_del_reposo_muestra_el_ultimo_dia_y_el_reintegro_y_usa_los_valores_del_escritorio_por_defecto(): void
    {
        $nuevo = new ReposoVista(null, null, null, false, '2026-10-07', ['guardar' => '/g', 'eliminar' => '/e', 'imprimir' => '/i']);

        $this->assertSame('1', $nuevo->dias(), 'el escritorio crea el reposo de 1 día');
        $this->assertSame('2026-10-07', $nuevo->desde(), 'desde el día de la consulta');
        $this->assertSame('07/10/2026', $nuevo->hasta());
        $this->assertSame('08/10/2026', $nuevo->reintegro());
        $this->assertFalse($nuevo->puedeEliminar());

        $real = new ReposoVista(8, '2026-10-10', 'Motivo', true, '2026-10-07', ['guardar' => '/g', 'eliminar' => '/e', 'imprimir' => '/i']);
        $this->assertSame('17/10/2026', $real->hasta());
        $this->assertSame('18/10/2026', $real->reintegro());
        $this->assertTrue($real->puedeEliminar());

        // Tras un error de validación se muestra lo escrito, no lo guardado, y la cuenta sigue lo escrito.
        $conError = new ReposoVista(8, '2026-10-10', 'Motivo', true, '2026-10-07', ['guardar' => '/g', 'eliminar' => '/e', 'imprimir' => '/i'], ['dias' => '3', 'desde' => '2026-11-01']);
        $this->assertSame('03/11/2026', $conError->hasta());

        $sinDatos = new ReposoVista(null, null, null, false, '2026-10-07', ['guardar' => '/g', 'eliminar' => '/e', 'imprimir' => '/i'], ['dias' => '0']);
        $this->assertSame('—', $sinDatos->hasta());
    }

    // ============================================================================ Los párrafos

    public function test_cada_renglon_del_texto_es_un_parrafo_y_el_rotulo_va_solo_en_el_primero(): void
    {
        $parrafos = DocumentoImpreso::parrafosDe("  Primero  con   espacios \r\n\r\n  Segundo\nTercero ", 'IDX:', true);

        $this->assertSame([
            ['texto' => 'Primero con espacios', 'etiqueta' => 'IDX:', 'aparte' => true],
            ['texto' => 'Segundo', 'etiqueta' => null, 'aparte' => false],
            ['texto' => 'Tercero', 'etiqueta' => null, 'aparte' => false],
        ], $parrafos);
        $this->assertSame([], DocumentoImpreso::parrafosDe(null));
        $this->assertSame([], DocumentoImpreso::parrafosDe("  \n \n"));
    }

    // ================================================================================ El reparto

    public function test_un_texto_corto_sale_a_10_pt_en_una_sola_mitad_con_la_firma(): void
    {
        $reparto = (new DistribuidorDeDocumento())->distribuir($this->membrete(), $this->documento(DocumentoImpreso::parrafosDe('Paciente en control.')));

        $this->assertSame(10, $reparto['puntos']);
        $this->assertCount(1, $reparto['mitades']);
        $this->assertTrue($reparto['mitades'][0]['primera']);
        $this->assertTrue($reparto['mitades'][0]['firma']);
        $this->assertFalse($reparto['mitades'][0]['sigue']);
    }

    public function test_un_texto_que_no_entra_sigue_en_la_otra_mitad_con_la_firma_solo_en_la_ultima(): void
    {
        $reparto = (new DistribuidorDeDocumento())->distribuir($this->membrete(), $this->documento(DocumentoImpreso::parrafosDe($this->palabras(330))));

        $this->assertCount(2, $reparto['mitades']);
        [$primera, $segunda] = $reparto['mitades'];
        $this->assertTrue($primera['primera']);
        $this->assertTrue($primera['sigue']);
        $this->assertFalse($primera['firma']);
        $this->assertFalse($segunda['primera']);
        $this->assertFalse($segunda['sigue']);
        $this->assertTrue($segunda['firma']);
    }

    public function test_no_se_pierde_ni_se_reordena_ninguna_palabra_al_partir(): void
    {
        $parrafos = array_merge(
            DocumentoImpreso::parrafosDe($this->palabras(150, 'a')),
            DocumentoImpreso::parrafosDe($this->palabras(150, 'b')),
            DocumentoImpreso::parrafosDe($this->palabras(150, 'c')),
        );

        foreach ([[], [['etiqueta' => 'PARA:', 'valor' => 'A QUIEN CORRESPONDA']]] as $fijos) {
            $reparto = (new DistribuidorDeDocumento())->distribuir($this->membrete(), $this->documento($parrafos, $fijos));

            $enOrden = [];
            foreach ($reparto['mitades'] as $mitad) {
                foreach ($mitad['parrafos'] as $parrafo) {
                    $enOrden = array_merge($enOrden, preg_split('/\s+/', $parrafo['texto']));
                }
            }

            $esperado = [];
            foreach ($parrafos as $parrafo) {
                $esperado = array_merge($esperado, preg_split('/\s+/', $parrafo['texto']));
            }

            $this->assertSame($esperado, $enOrden);
            // Los datos fijos no se parten: sólo están en la primera mitad.
            $this->assertSame($fijos, $reparto['mitades'][0]['fijos']);
            foreach (array_slice($reparto['mitades'], 1) as $mitad) {
                $this->assertSame([], $mitad['fijos']);
            }
        }
    }

    public function test_si_ni_a_8_pt_entra_en_una_hoja_sigue_en_otra_y_el_documento_nunca_se_corta(): void
    {
        $reparto = (new DistribuidorDeDocumento())->distribuir($this->membrete(), $this->documento(DocumentoImpreso::parrafosDe($this->palabras(1800))));

        $this->assertContains($reparto['puntos'], DistribuidorDeDocumento::ESCALONES);
        $this->assertGreaterThan(2, count($reparto['mitades']), 'sigue en una hoja más');
        $this->assertSame(1, count(array_filter($reparto['mitades'], fn (array $m) => $m['firma'])), 'la firma sólo en la última');
        $this->assertTrue(end($reparto['mitades'])['firma']);
        $total = array_sum(array_map(fn (array $m) => array_sum(array_map(fn (array $p) => count(preg_split('/\s+/', $p['texto'])), $m['parrafos'])), $reparto['mitades']));
        $this->assertSame(1800, $total);
    }

    public function test_gana_el_escalon_con_menos_hojas_y_a_igualdad_la_letra_mas_grande(): void
    {
        $distribuidor = new DistribuidorDeDocumento();
        $puntos = [];

        foreach ([20, 150, 280, 330, 380, 450, 560] as $palabras) {
            $reparto = $distribuidor->distribuir($this->membrete(), $this->documento(DocumentoImpreso::parrafosDe($this->palabras($palabras))));
            $puntos[$palabras] = [$reparto['puntos'], (int) ceil(count($reparto['mitades']) / 2)];
        }

        // Cuanto más texto, las hojas no bajan. La letra **no** es monótona a propósito: gana el escalón con menos hojas y, a
        // igualdad, el más grande; así un texto que ocupa dos hojas a 10 pt sigue a 10 aunque a 8 también fueran dos.
        $anterior = null;
        foreach ($puntos as $palabras => [$pt, $hojas]) {
            $this->assertContains($pt, DistribuidorDeDocumento::ESCALONES);
            if ($anterior !== null) {
                $this->assertGreaterThanOrEqual($anterior, $hojas, $palabras . ' palabras');
            }
            $anterior = $hojas;
        }
        $this->assertSame(10, $puntos[20][0]);
        $this->assertSame(1, $puntos[330][1], 'las dos mitades de una hoja bastan para 330 palabras');
    }

    public function test_una_firma_no_queda_sola_en_la_mitad_de_continuacion(): void
    {
        // Se busca un largo para el que todo el texto entra en la primera mitad sin firma pero no con ella.
        $distribuidor = new DistribuidorDeDocumento();
        $encontrado = false;

        for ($palabras = 100; $palabras <= 260 && ! $encontrado; $palabras += 2) {
            $reparto = $distribuidor->distribuir($this->membrete(), $this->documento(DocumentoImpreso::parrafosDe($this->palabras($palabras))));

            foreach ($reparto['mitades'] as $mitad) {
                $this->assertTrue($mitad['parrafos'] !== [] || count($reparto['mitades']) === 1, 'ninguna mitad queda sin texto (' . $palabras . ' palabras)');
            }

            $encontrado = count($reparto['mitades']) === 2;
        }

        $this->assertTrue($encontrado, 'hay un largo que obliga a pasar a la segunda mitad');
    }

    public function test_la_hoja_de_un_documento_trae_el_titulo_de_continuacion_y_la_mitad_derecha_en_blanco_si_es_una_sola(): void
    {
        $corta = new HojasDeDocumentoVista($this->membrete(), [$this->documento(DocumentoImpreso::parrafosDe('Breve.'))]);
        $hojas = $corta->hojas();
        $this->assertCount(1, $hojas);
        $this->assertCount(1, $hojas[0]['mitades'], 'la derecha la pinta la vista como mitad vacía');
        $this->assertSame('documento', $hojas[0]['tipo']);
        $this->assertSame('INFORME MÉDICO', $hojas[0]['mitades'][0]['titulo']);
        $this->assertSame('36 años', $hojas[0]['mitades'][0]['edad']);
        $this->assertSame('07/10/2026', $hojas[0]['mitades'][0]['fecha']);

        // En la página pequeña cada trozo va en su propia página.
        $larga = new HojasDeDocumentoVista($this->membrete(), [$this->documento(DocumentoImpreso::parrafosDe($this->palabras(330)))->conPagina('pequena')]);
        $mitades = array_merge(...array_column($larga->hojas(), 'mitades'));
        $this->assertSame('INFORME MÉDICO (continuación)', $mitades[1]['titulo']);
        $this->assertTrue($mitades[0]['sigue']);
        $this->assertTrue($mitades[1]['firma']);
    }

    // ==================================================================================== Pestañas

    public function test_las_pestanas_son_las_nueve_de_hoy_en_el_orden_del_escritorio_y_una_clave_desconocida_abre_la_constancia(): void
    {
        $pestanas = new PestanasDeSolicitudes(fn (string $clave) => '/c?solicitud=' . $clave);
        $opciones = $pestanas->opciones('reposo');

        // WEB-5.4: el escritorio tiene primero las pestañas de laboratorio y de estudios. La historia va después del
        // informe, como en el escritorio (2026-10-19).
        $this->assertSame(['Laboratorio', 'Estudios', 'Constancia', 'Reposo', 'Referencia', 'Hospitalización', 'Informe médico', 'Historia clínica', 'Dieta / Recomendaciones'], array_column($opciones, 'texto'));
        $this->assertSame([false, false, false, true, false, false, false, false, false], array_column($opciones, 'activa'));
        $this->assertSame('/c?solicitud=laboratorio', $opciones[0]['url']);
        $this->assertSame('/c?solicitud=estudios', $opciones[1]['url']);
        $this->assertSame('/c?solicitud=hospitalizacion', $opciones[5]['url']);
        $this->assertSame('/c?solicitud=informe', $opciones[6]['url']);
        $this->assertSame('/c?solicitud=historia', $opciones[7]['url']);
        $this->assertSame('/c?solicitud=dieta', $opciones[8]['url']);

        $this->assertSame('constancia', PestanasDeSolicitudes::normalizar(null));
        $this->assertSame('constancia', PestanasDeSolicitudes::normalizar('dietas'));
        $this->assertSame('referencias', PestanasDeSolicitudes::normalizar('referencias'));
        $this->assertSame('dieta', PestanasDeSolicitudes::normalizar('dieta'));
        $this->assertSame('historia', PestanasDeSolicitudes::normalizar('historia'));
        $this->assertSame('laboratorio', PestanasDeSolicitudes::normalizar('laboratorio'));
        $this->assertSame('estudios', PestanasDeSolicitudes::normalizar('estudios'));
        $this->assertSame(['laboratorio', 'estudios', 'constancia', 'reposo', 'referencias', 'hospitalizacion', 'informe', 'historia', 'dieta'], PestanasDeSolicitudes::claves());
    }

    /** Las pestañas que la especialidad no ofrece: en pediatría, la dieta (es su ítem *Alimentación*). */
    public function test_una_pestana_oculta_no_se_pinta_y_su_url_cae_en_la_de_siempre(): void
    {
        $pestanas = new PestanasDeSolicitudes(fn (string $clave) => '/c?solicitud=' . $clave, ['dieta'], ['historia' => 'Historia pediátrica']);

        $textos = array_column($pestanas->opciones('constancia'), 'texto');
        $this->assertNotContains('Dieta / Recomendaciones', $textos);
        $this->assertContains('Historia pediátrica', $textos);
        $this->assertNotContains('Historia clínica', $textos);

        $this->assertSame('constancia', PestanasDeSolicitudes::normalizar('dieta', ['dieta']));
        $this->assertNotContains('dieta', PestanasDeSolicitudes::claves(['dieta']));
        $this->assertCount(8, PestanasDeSolicitudes::claves(['dieta']));
    }
}
