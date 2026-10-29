<?php

namespace Tests\Feature;

use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Specialty;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **El SEO de las páginas públicas** (auditoría de Plesk, 2026-10-29): toda página que un buscador puede ver trae su
 * `<meta name="description">` y su `<h1>`; lo que no debe indexarse (búsquedas, restablecer contraseña, registro del
 * paciente) lo dice con `noindex`; y `sitemap.xml` y `robots.txt` salen del servidor con las direcciones de su dominio.
 *
 * `DatabaseTransactions` por la razón de siempre (ver MedicAPI/AGENTS.md).
 */
class SeoPublicoTest extends TestCase
{
    use DatabaseTransactions;

    private Medico $medico;

    private Specialty $especialidad;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 18, 7, 0, 0));

        $this->especialidad = Specialty::create(['name' => 'Seo ' . substr(uniqid(), -6), 'activo' => true]);

        $this->medico = Medico::create([
            'name' => 'Sara',
            'lastname' => 'Buscadores',
            'email' => 'seo-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'test-seo' . substr(uniqid(), -6),
        ]);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $this->medico->reg_medico]);
        $this->medico->specialties()->attach($this->especialidad);

        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede SEO',
            'address' => 'Calle de prueba',
        ]);
        $oficina = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $this->medico->reg_medico,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);
        OfficeSchedule::create([
            'office_id' => $oficina->id,
            'reg_medico' => $this->medico->reg_medico,
            'dia_semana' => 1,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function descripcion(string $html): ?string
    {
        return preg_match('/<meta name="description" content="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : null;
    }

    /** @return list<string> */
    private function urlsPublicas(): array
    {
        return ['/login', '/registro', '/registro/medico', '/reservar', '/reservar/' . $this->medico->id, '/password/reset', '/verificar'];
    }

    public function test_las_paginas_publicas_traen_descripcion_y_un_solo_h1(): void
    {
        foreach ($this->urlsPublicas() as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $descripcion = $this->descripcion($html);
            $this->assertNotNull($descripcion, "$url no trae la meta description");
            $this->assertGreaterThanOrEqual(60, mb_strlen($descripcion), "$url: descripción demasiado corta");
            $this->assertLessThanOrEqual(200, mb_strlen($descripcion), "$url: descripción demasiado larga");
            $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html), "$url debe tener exactamente un h1");
        }
    }

    public function test_la_descripcion_de_cada_pagina_es_distinta(): void
    {
        $descripciones = array_map(fn ($url) => $this->descripcion($this->get($url)->getContent()), $this->urlsPublicas());

        $this->assertCount(count($descripciones), array_unique($descripciones));
    }

    public function test_el_restablecer_contrasena_esta_en_espanol_y_no_se_indexa(): void
    {
        $html = $this->get('/password/reset')->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="h5 mb-0">Restablecer contraseña</h1>', $html);
        $this->assertStringContainsString('Enviar enlace de restablecimiento', $html);
        $this->assertStringNotContainsString('Reset Password', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);

        $reset = $this->get('/password/reset/token-de-prueba')->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="h5 mb-0">Elegir contraseña nueva</h1>', $reset);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $reset);
        $this->assertNotNull($this->descripcion($reset));
    }

    public function test_el_registro_del_paciente_no_se_indexa_pero_el_del_medico_si(): void
    {
        $this->get('/registro')->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false);
        $this->get('/registro/medico')->assertOk()->assertDontSee('name="robots"', false);
    }

    public function test_el_catalogo_de_una_especialidad_tiene_su_propio_titulo_descripcion_y_canonica(): void
    {
        $general = $this->get('/reservar')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Reservar cita · Doctorísimo</title>', $general);
        $this->assertStringContainsString('<link rel="canonical" href="' . route('reservas.catalogo') . '">', $general);
        $this->assertStringNotContainsString('name="robots"', $general);

        $canonica = route('reservas.catalogo', ['especialidad' => $this->especialidad->slug]);
        $url = '/reservar?especialidad=' . $this->especialidad->slug;
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('<title>Reservar cita de ' . $this->especialidad->name . ' · Doctorísimo</title>', $html);
        $this->assertStringContainsString($this->especialidad->name, $this->descripcion($html));
        $this->assertNotSame($this->descripcion($general), $this->descripcion($html));
        $this->assertStringContainsString('<link rel="canonical" href="' . e($canonica) . '">', $html);

        // Una búsqueda por texto es una página de variantes sin fin: no se indexa y apunta a la canónica.
        $buscada = $this->get($url . '&buscar=Sara')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $buscada);
        $this->assertStringContainsString('<link rel="canonical" href="' . e($canonica) . '">', $buscada);
    }

    private function h1(string $html): string
    {
        $this->assertSame(1, preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m));

        return trim(html_entity_decode(strip_tags($m[1])));
    }

    /** El texto visible de la página, sin cabecera, scripts ni estilos y con los espacios colapsados. */
    private function textoVisible(string $html): string
    {
        $html = preg_replace('#<(script|style|head).*?</>#is', ' ', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    public function test_cada_variante_del_catalogo_tiene_su_h1_y_su_texto(): void
    {
        $this->especialidad->update(['description' => 'Atención de prueba para los buscadores.']);

        $general = $this->get('/reservar')->assertOk()->getContent();
        $variante = $this->get('/reservar?especialidad=' . $this->especialidad->slug)->assertOk()->getContent();

        $this->assertSame('Reserva tu cita', $this->h1($general));
        $this->assertSame('Reserva tu cita de ' . $this->especialidad->name, $this->h1($variante));

        // La bajada y el encabezado de la lista nombran la especialidad y cuentan los médicos.
        $texto = $this->textoVisible($variante);
        $this->assertStringContainsString('Atención de prueba para los buscadores.', $texto);
        $this->assertStringContainsString('Hay 1 médico de ' . $this->especialidad->name . ' con agenda publicada', $texto);
        $this->assertStringContainsString('Médicos de ' . $this->especialidad->name, $texto);

        // Y nada de eso está en `/reservar`, que no puede ser copia de la variante.
        $textoGeneral = $this->textoVisible($general);
        $this->assertStringNotContainsString('Atención de prueba para los buscadores.', $textoGeneral);
        $this->assertStringNotContainsString('con agenda publicada: elige al tuyo', $textoGeneral);
    }

    public function test_dos_especialidades_no_comparten_h1_ni_bajada(): void
    {
        $otra = Specialty::create(['name' => 'Otra ' . substr(uniqid(), -6), 'activo' => true, 'description' => 'Descripción de la otra.']);
        $this->medico->specialties()->attach($otra);

        $a = $this->get('/reservar?especialidad=' . $this->especialidad->slug)->assertOk()->getContent();
        $b = $this->get('/reservar?especialidad=' . $otra->slug)->assertOk()->getContent();

        $this->assertNotSame($this->h1($a), $this->h1($b));
        $this->assertNotSame($this->descripcion($a), $this->descripcion($b));
        $this->assertStringContainsString('Descripción de la otra.', $this->textoVisible($b));
        $this->assertStringNotContainsString('Descripción de la otra.', $this->textoVisible($a));
    }

    public function test_la_busqueda_dentro_de_una_especialidad_no_promete_una_cuenta_de_medicos(): void
    {
        $html = $this->get('/reservar?especialidad=' . $this->especialidad->slug . '&buscar=Sara')->assertOk()->getContent();
        $texto = $this->textoVisible($html);

        $this->assertStringContainsString('que coinciden con «Sara»', $texto);
        $this->assertStringNotContainsString('Hay 1 médico', $texto);
        $this->assertSame('Reserva tu cita de ' . $this->especialidad->name, $this->h1($html));
    }

    public function test_la_ficha_del_medico_nombra_al_medico_y_su_especialidad(): void
    {
        $html = $this->get('/reservar/' . $this->medico->id)->assertOk()->getContent();
        $descripcion = $this->descripcion($html);

        $this->assertStringContainsString($this->medico->nombre_mostrar, $descripcion);
        $this->assertStringContainsString($this->especialidad->name, $descripcion);
    }

    public function test_la_busqueda_heredada_no_se_indexa(): void
    {
        foreach (['/medicos/buscar', '/register/doctor', '/register/patient'] as $url) {
            $this->get($url)->assertHeader('X-Robots-Tag', 'noindex, follow');
        }
    }

    public function test_el_sitemap_lista_las_paginas_publicas_y_las_fichas_con_agenda(): void
    {
        $respuesta = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('application/xml', $respuesta->headers->get('Content-Type'));

        $xml = simplexml_load_string($respuesta->getContent());
        $this->assertNotFalse($xml);
        $direcciones = [];
        foreach ($xml->url as $url) {
            $direcciones[] = (string) $url->loc;
        }

        $this->assertContains(url('/'), $direcciones);
        $this->assertContains(route('reservas.catalogo'), $direcciones);
        $this->assertContains(route('medico.registro'), $direcciones);
        $this->assertContains(route('reservas.medico', ['medico' => $this->medico->id]), $direcciones);
        foreach ($direcciones as $direccion) {
            $this->assertStringNotContainsString('/clinica', $direccion);
            $this->assertStringNotContainsString('/admin', $direccion);
            $this->assertStringNotContainsString('/verificar', $direccion);
        }
    }

    public function test_robots_apunta_al_sitemap_y_cierra_solo_lo_privado(): void
    {
        $respuesta = $this->get('/robots.txt')->assertOk();
        $this->assertStringStartsWith('text/plain', $respuesta->headers->get('Content-Type'));
        $texto = $respuesta->getContent();

        $this->assertStringContainsString('Sitemap: ' . url('/sitemap.xml'), $texto);
        foreach (['/admin', '/clinica', '/dashboard', '/api'] as $prefijo) {
            $this->assertStringContainsString('Disallow: ' . $prefijo . "\n", $texto);
        }
        // Lo público, y las pantallas con `noindex` que el buscador tiene que poder leer, no se cierran.
        foreach (['/reservar', '/registro', '/login', '/password', '/'] as $publico) {
            $this->assertStringNotContainsString('Disallow: ' . $publico . "\n", $texto);
        }
    }
}
