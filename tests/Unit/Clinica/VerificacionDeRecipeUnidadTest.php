<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Impresion\QrDelRecipe;
use App\Clinica\Verificacion\CredencialesDeVerificacion;
use App\Clinica\Verificacion\EstadoDeLaVerificacion;
use App\Clinica\Verificacion\GeneradorDeQr;
use App\Clinica\Verificacion\RecetaVerificada;
use App\Clinica\Verificacion\UrlDeVerificacion;
use App\Clinica\Verificacion\Vistas\VerificacionPublicaVista;
use Tests\TestCase;

/**
 * **La verificación pública del récipe sin base de datos** (WEB-5.6): las credenciales del QR, su dibujo, las direcciones y
 * lo que la página pública pinta en cada estado. (`Tests\TestCase` y no el de PHPUnit: `config()` necesita la aplicación,
 * trampa 68 de `TRAMPAS.md`.)
 */
class VerificacionDeRecipeUnidadTest extends TestCase
{
    // ------------------------------------------------------------------------------------ Credenciales

    public function test_el_token_son_43_caracteres_base64url_y_no_se_repite(): void
    {
        $tokens = [];
        for ($i = 0; $i < 200; $i++) {
            $token = CredencialesDeVerificacion::nuevoToken();
            $this->assertSame(43, strlen($token));
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
            $this->assertTrue(CredencialesDeVerificacion::tieneFormaDeToken($token));
            $tokens[$token] = true;
        }

        $this->assertCount(200, $tokens);
    }

    public function test_solo_tiene_forma_de_token_lo_que_mide_43_y_es_base64url(): void
    {
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeToken(''));
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeToken(str_repeat('a', 42)));
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeToken(str_repeat('a', 44)));
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeToken(str_repeat('a', 42) . '+'));
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeToken(str_repeat('a', 42) . "\n"));
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeToken('ejemplo'));
    }

    public function test_el_codigo_usa_un_alfabeto_sin_caracteres_ambiguos(): void
    {
        $vistos = [];
        for ($i = 0; $i < 500; $i++) {
            $codigo = CredencialesDeVerificacion::nuevoCodigo();
            $this->assertSame(8, strlen($codigo));
            $this->assertMatchesRegularExpression('/\A[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}\z/', $codigo);
            $this->assertTrue(CredencialesDeVerificacion::tieneFormaDeCodigo($codigo));
            $vistos[$codigo] = true;
        }

        foreach (str_split('0O1IL') as $ambiguo) {
            foreach (array_keys($vistos) as $codigo) {
                $this->assertStringNotContainsString($ambiguo, $codigo);
            }
        }
        $this->assertGreaterThan(495, count($vistos), '8 símbolos de 31 no se repiten en 500 intentos.');
    }

    public function test_el_codigo_tipeado_se_normaliza_y_se_imprime_con_guion(): void
    {
        $this->assertSame('ABCD2345', CredencialesDeVerificacion::normalizarCodigo(' abcd-2345 '));
        $this->assertSame('ABCD2345', CredencialesDeVerificacion::normalizarCodigo('abcd 2345'));
        $this->assertSame('ABCD-2345', CredencialesDeVerificacion::formatearCodigo('ABCD2345'));
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeCodigo('ABCD0345'), 'El 0 no existe en el alfabeto.');
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeCodigo('ABCD234'));
    }

    // ---------------------------------------------------------------------------------------- Direcciones

    public function test_la_direccion_sale_de_la_configuracion_y_no_de_la_peticion(): void
    {
        config(['app.url' => 'http://localhost', 'clinica.verificacion.url' => null]);
        $this->assertSame('http://localhost/verificar', UrlDeVerificacion::pagina());

        config(['clinica.verificacion.url' => 'https://doctorisimo.app/']);
        $this->assertSame('https://doctorisimo.app/verificar', UrlDeVerificacion::pagina());
        $this->assertSame('https://doctorisimo.app/verificar/abc', UrlDeVerificacion::deToken('abc'));
        $this->assertSame('doctorisimo.app/verificar', UrlDeVerificacion::visible());
    }

    // ------------------------------------------------------------------------------------- El dibujo

    public function test_el_qr_es_un_svg_determinista_sin_medidas_que_el_css_dimensiona(): void
    {
        config(['clinica.verificacion.url' => 'https://doctorisimo.app']);
        $url = UrlDeVerificacion::deToken(str_repeat('A', 43));
        $generador = new GeneradorDeQr();

        $svg = $generador->svg($url);

        $this->assertSame($svg, $generador->svg($url), 'La misma dirección dibuja siempre el mismo QR.');
        $this->assertNotSame($svg, $generador->svg(UrlDeVerificacion::deToken(str_repeat('B', 43))));
        $this->assertStringStartsWith('<svg ', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
        $this->assertStringContainsString('viewBox="0 0 200 200"', $svg);
        $this->assertDoesNotMatchRegularExpression('/\s(width|height)="/', substr($svg, 0, strpos($svg, '>')), 'La etiqueta <svg> no fija medidas.');
        $this->assertStringContainsString('role="img"', $svg);
    }

    public function test_una_direccion_de_verificacion_cabe_en_la_version_5_del_qr(): void
    {
        config(['clinica.verificacion.url' => 'https://doctorisimo.app']);
        $svg = (new GeneradorDeQr())->svg(UrlDeVerificacion::deToken(CredencialesDeVerificacion::nuevoToken()));

        // 37 módulos (versión 5-M, hasta 84 bytes) + 2 de silencio por lado = 41; el SVG escala 200 / 41.
        $this->assertStringContainsString('scale(' . round(200 / 41, 3), $svg);
        // 16 mm entre 41 módulos: 0,39 mm por módulo, por encima del mínimo legible (≈ 0,25 mm).
        $this->assertGreaterThan(0.25, 16 / 41);
    }

    public function test_el_qr_de_la_hoja_de_prueba_es_un_ejemplo_que_no_apunta_a_un_recipe(): void
    {
        config(['clinica.verificacion.url' => 'https://doctorisimo.app']);

        $qr = QrDelRecipe::deEjemplo();

        $this->assertTrue($qr->ejemplo);
        $this->assertSame('EJEMPLO', $qr->codigo);
        $this->assertSame('doctorisimo.app/verificar', $qr->direccion);
        $this->assertSame((new GeneradorDeQr())->svg('https://doctorisimo.app/verificar/ejemplo'), $qr->svg);
        $this->assertFalse(CredencialesDeVerificacion::tieneFormaDeToken(UrlDeVerificacion::TOKEN_DE_EJEMPLO));
    }

    // ------------------------------------------------------------------------------ La página pública

    private function vista(string $estado, array $extra = []): VerificacionPublicaVista
    {
        $medico = ['nombre' => 'Dra. Ana Imprime', 'especialidad' => 'Ginecología', 'rif' => 'J-1', 'registro' => null, 'lugar' => ['Av. Principal', 'Barquisimeto']];

        return new VerificacionPublicaVista(new RecetaVerificada(...array_merge([
            'estado' => $estado, 'esDeLaPareja' => false, 'numero' => 2, 'emision' => '2026-10-05', 'vence' => '2026-11-04',
            'vigenciaEnDias' => 30, 'medico' => $medico, 'medicamentos' => [['cantidad' => '2', 'nombre' => 'KEFORAL / CAP. 500 MG']],
        ], $extra)), '/verificar');
    }

    public function test_cada_estado_tiene_su_color_su_icono_y_su_texto(): void
    {
        $esperado = [
            EstadoDeLaVerificacion::VIGENTE => ['exito', 'ok', 'Récipe auténtico y vigente'],
            EstadoDeLaVerificacion::VENCIDO => ['aviso', 'reloj', 'Récipe auténtico pero vencido'],
            EstadoDeLaVerificacion::ANULADO => ['peligro', 'anulado', 'Anulado por el médico'],
            EstadoDeLaVerificacion::ELIMINADO => ['peligro', 'eliminado', 'Este récipe ya no existe'],
            EstadoDeLaVerificacion::NO_ENCONTRADO => ['peligro', 'pregunta', 'No se encontró este récipe'],
            EstadoDeLaVerificacion::EJEMPLO => ['info', 'info', 'Código de ejemplo'],
        ];

        foreach ($esperado as $estado => [$tono, $icono, $titulo]) {
            $resultado = $this->vista($estado)->resultado();
            $this->assertSame($tono, $resultado['tono'], $estado);
            $this->assertSame($icono, $resultado['icono'], $estado);
            $this->assertSame($titulo, $resultado['titulo'], $estado);
            $this->assertNotSame('', $resultado['explicacion'], $estado);
        }
    }

    public function test_solo_un_recipe_autentico_muestra_su_contenido(): void
    {
        foreach ([EstadoDeLaVerificacion::VIGENTE, EstadoDeLaVerificacion::VENCIDO] as $estado) {
            $vista = $this->vista($estado);
            $this->assertTrue($vista->conContenido());
            $this->assertCount(1, $vista->medicamentos());
            $this->assertSame(['Récipe', 'Fecha de emisión', 'Vigencia'], array_column($vista->datos(), 'etiqueta'));
            $this->assertSame('Récipe 2', $vista->datos()[0]['valor']);
            $this->assertSame('05/10/2026', $vista->datos()[1]['valor']);
            $this->assertSame('30 días · hasta el 04/11/2026', $vista->datos()[2]['valor']);
        }

        foreach ([EstadoDeLaVerificacion::ANULADO, EstadoDeLaVerificacion::ELIMINADO, EstadoDeLaVerificacion::NO_ENCONTRADO, EstadoDeLaVerificacion::EJEMPLO] as $estado) {
            $vista = $this->vista($estado);
            $this->assertFalse($vista->conContenido(), $estado);
            $this->assertSame([], $vista->medicamentos(), $estado);
            $this->assertSame([], $vista->datos(), $estado);
        }
    }

    public function test_el_medico_se_muestra_si_hay_recipe_detras_y_nunca_en_no_encontrado(): void
    {
        $this->assertSame('Dra. Ana Imprime', $this->vista(EstadoDeLaVerificacion::ANULADO)->medico()['nombre']);
        $this->assertSame('Dra. Ana Imprime', $this->vista(EstadoDeLaVerificacion::ELIMINADO)->medico()['nombre']);
        $this->assertNull($this->vista(EstadoDeLaVerificacion::NO_ENCONTRADO)->medico());
        $this->assertNull($this->vista(EstadoDeLaVerificacion::EJEMPLO)->medico());

        $detalles = $this->vista(EstadoDeLaVerificacion::VIGENTE)->medico()['detalles'];
        $this->assertSame(['Especialidad', 'RIF', 'Consultorio'], array_column($detalles, 'etiqueta'), 'El registro vacío no se pinta.');
        $this->assertSame('Av. Principal · Barquisimeto', $detalles[2]['valor']);
    }

    public function test_el_recipe_de_la_pareja_se_rotula_sin_numero(): void
    {
        $vista = $this->vista(EstadoDeLaVerificacion::VIGENTE, ['esDeLaPareja' => true, 'numero' => null]);

        $this->assertSame('Récipe de la pareja', $vista->datos()[0]['valor']);
    }

    public function test_el_anulado_dice_cuando_se_anulo(): void
    {
        $resultado = $this->vista(EstadoDeLaVerificacion::ANULADO, ['anuladoEn' => '2026-10-09'])->resultado();

        $this->assertStringContainsString('09/10/2026', $resultado['explicacion']);
    }
}
