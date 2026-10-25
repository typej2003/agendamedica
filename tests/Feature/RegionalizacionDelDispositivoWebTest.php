<?php

namespace Tests\Feature;

use App\Support\Regionalizacion;
use Tests\TestCase;

/**
 * La cookie `ddr_region` (WEB-13.5): la escribe el navegador sin cifrar, el middleware la valida y la página
 * sale con la regionalización en el `<html>`, que es lo que leen los campos de hora y de fecha.
 */
class RegionalizacionDelDispositivoWebTest extends TestCase
{
    public function test_sin_cookie_la_pagina_sale_con_la_del_consultorio(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-region="dmy|/|12"', false)
            ->assertSee('js/regionalizacion.js', false);
    }

    public function test_la_cookie_del_dispositivo_se_aplica(): void
    {
        $this->withUnencryptedCookie(Regionalizacion::COOKIE, 'mdy|/|24')
            ->get('/login')
            ->assertOk()
            ->assertSee('data-fecha-orden="mdy"', false)
            ->assertSee('data-hora-ciclo="24"', false);
    }

    public function test_una_cookie_que_no_es_valida_se_ignora(): void
    {
        $this->withUnencryptedCookie(Regionalizacion::COOKIE, '"><script>|/|24')
            ->get('/login')
            ->assertOk()
            ->assertSee('data-region="dmy|/|12"', false)
            ->assertDontSee('<script>|', false);
    }
}
