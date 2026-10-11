<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Lo que ve **cualquiera, antes de entrar** (revisión general de la web, 2026-10-11): la pantalla de
 * login y las páginas de error. Son lo primero que ve un médico nuevo, y estaban sin terminar:
 *
 *  - el login titulaba la pestaña con "Laravel" (`config('app.name')` vale `Laravel` si el `.env` no lo
 *    cambia) y pedía `/vendor/fontawesome-free/…`, un archivo que **no está en el repo** → 404 en cada
 *    carga y los iconos del correo y la contraseña en blanco;
 *  - un 404 o un 403 salía con la página por defecto de Laravel: en inglés, sin marca y sin salida.
 *
 * No necesita base de datos: son páginas sin sesión.
 */
class PantallasPublicasWebTest extends TestCase
{
    // --------------------------------------------------------------------------------------- Login

    public function test_el_login_titula_la_pestana_con_la_marca_y_no_con_laravel(): void
    {
        // Se fuerza el valor por defecto de Laravel: es lo que pasa en cualquier entorno cuyo `.env` no
        // fije `APP_NAME` (el de desarrollo no lo fija).
        config(['app.name' => 'Laravel']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('<title>Iniciar sesión · Doctorísimo</title>', false);
    }

    public function test_el_login_no_pide_archivos_locales_que_no_existen(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        // Cada `asset('…')` que el login imprime tiene que ser un archivo de `public/`: uno que falte es un
        // 404 en cada carga (y, en un CSS de iconos, iconos en blanco).
        // Sólo archivos estáticos (`<link>`, `<script>`, `<img>`): un `<a href>` es una ruta, no un archivo.
        preg_match_all(
            '#<(?:link|script|img)\b[^>]*?\b(?:href|src)="' . preg_quote(url('/'), '#') . '/([^"?\#]+)#',
            $html,
            $locales,
        );

        $this->assertNotEmpty($locales[1], 'El login debería cargar al menos su propio CSS.');

        foreach (array_unique($locales[1]) as $ruta) {
            $this->assertFileExists(public_path($ruta), "El login pide /{$ruta} y no existe en public/.");
        }
    }

    public function test_los_iconos_del_login_no_dependen_de_font_awesome(): void
    {
        // Los iconos del correo y de la contraseña se pintaban con `fas fa-…`: sin esa hoja de estilos,
        // quedaban vacíos. La página ya carga Bootstrap Icons (el mismo set que la web clínica).
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('fa-envelope', false)
            ->assertDontSee('fa-lock', false)
            ->assertSee('bi-envelope', false)
            ->assertSee('bi-lock', false);
    }

    // ------------------------------------------------------------------------------ Páginas de error

    public function test_un_404_sale_en_espanol_con_la_marca_y_una_salida(): void
    {
        $this->get('/clinica/ruta-que-no-existe')
            ->assertNotFound()
            ->assertSee('lang="es"', false)
            ->assertSee('No encontramos esa página')
            ->assertSee('Volver al inicio')
            ->assertSee('href="' . url('/') . '"', false)
            ->assertSee('Doctorísimo')
            ->assertDontSee('Not Found');
    }

    public function test_un_403_sale_en_espanol_y_dice_que_no_tiene_permiso(): void
    {
        Route::get('/_prueba-403', fn () => abort(403));

        $this->get('/_prueba-403')
            ->assertForbidden()
            ->assertSee('lang="es"', false)
            ->assertSee('No tienes permiso')
            ->assertSee('Volver al inicio')
            ->assertDontSee('Forbidden');
    }

    public function test_la_pagina_de_error_no_pide_nada_a_otro_servidor_para_dibujarse(): void
    {
        // La de Laravel carga una tipografía de Google: sin internet (un consultorio con la red caída es
        // justo cuando más se ve una página de error) queda a medias. Se dibuja con lo que ya tiene.
        $this->get('/clinica/ruta-que-no-existe')
            ->assertNotFound()
            ->assertDontSee('fonts.googleapis.com')
            ->assertDontSee('fonts.gstatic.com');
    }
}
