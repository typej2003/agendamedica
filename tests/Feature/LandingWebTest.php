<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La **portada pública** (`/`, `PortadaController`): le habla al médico —el registro es la conversión— y deja
 * un bloque al paciente que lleva al portal de reservas.
 *
 * Las capturas son de la aplicación real con data sintética (`FakeClinicalDataSeeder`), así que el test también
 * comprueba que los archivos publicados existan. `DatabaseTransactions` por la razón de siempre (ver
 * MedicAPI/AGENTS.md).
 */
class LandingWebTest extends TestCase
{
    use DatabaseTransactions;

    private const CAPTURAS = [
        'agenda-consultorio' => 'Agenda del consultorio en Doctorísimo.App',
        'agenda-movil' => 'La misma agenda en la app móvil de Doctorísimo.App',
        'crecimiento' => 'Percentiles de Fundacredesa',
        'recipe' => 'QR de verificación',
        'eco' => 'Informe de ecografía obstétrica del segundo trimestre',
        'catalogo-movil' => 'Catálogo público de Doctorísimo.App en el celular',
    ];

    public function test_la_portada_le_habla_al_medico_y_lo_lleva_al_registro(): void
    {
        config(['servicios.prueba_meses' => 1]);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Doctorísimo · Agenda, historia clínica y récipes para tu consultorio</title>', false)
            ->assertSee('<meta name="description"', false)
            ->assertSee('Del papeleo nos encargamos nosotros.')
            ->assertSee('Prueba gratis 1 mes')
            ->assertSee('La historia de un paciente, en 1 clic')
            ->assertSee('Más citas, sin perseguirlas')
            ->assertSee('Lo que sale de Doctorísimo.App')
            ->assertSee('un año de cortesía', false)
            ->assertSee('href="' . route('medico.registro') . '"', false)
            ->assertSee('href="' . route('login') . '"', false)
            // Lo que no existe no se promete.
            ->assertDontSee('Telemedicina')
            ->assertDontSee('Iniciar Consulta')
            ->assertDontSee('24/7');
    }

    public function test_el_paciente_tiene_su_bloque_hacia_la_reserva(): void
    {
        $this->get('/')
            ->assertSee('¿Buscas médico?')
            ->assertSee('Paga en línea, sin efectivo')
            ->assertSee('href="' . route('reservas.catalogo') . '"', false)
            ->assertSee('href="' . route('paciente.citas') . '"', false)
            ->assertSee('mailto:' . config('app.contacto'), false)
            ->assertSee(config('app.contacto'))
            // El correo viejo del producto anterior no se publica.
            ->assertDontSee('ginecoreport.com');
    }

    public function test_las_capturas_existen_y_tienen_texto_alternativo(): void
    {
        $respuesta = $this->get('/');

        foreach (self::CAPTURAS as $archivo => $alternativo) {
            $respuesta->assertSee('img/landing/' . $archivo . '.webp', false);
            $respuesta->assertSee($alternativo, false);
            $this->assertFileExists(public_path('img/landing/' . $archivo . '.webp'));
        }
    }

    public function test_sin_prueba_configurada_no_promete_meses_gratis(): void
    {
        config(['servicios.prueba_meses' => 0]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Prueba gratis')
            ->assertSee('Solicita tu cuenta');
    }

    public function test_muestra_solo_los_planes_activos_y_visibles(): void
    {
        $sufijo = substr(uniqid(), -6);
        $this->plan('portada-visible-' . $sufijo, 'Plan Portada Visible', ['precio_usd' => 25, 'precio_tachado_usd' => 30]);
        $this->plan('portada-oculto-' . $sufijo, 'Plan Portada Oculto', ['visible' => false]);
        $this->plan('portada-inactivo-' . $sufijo, 'Plan Portada Inactivo', ['activo' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="planes"', false)
            ->assertSee('Plan Portada Visible')
            ->assertSee('$25')
            ->assertDontSee('Plan Portada Oculto')
            ->assertDontSee('Plan Portada Inactivo');
    }

    public function test_un_plan_con_precio_anual_muestra_el_mensual_equivalente(): void
    {
        $sufijo = substr(uniqid(), -6);
        $this->plan('portada-anual-' . $sufijo, 'Plan Portada Anual', [
            'precio_usd' => 20,
            'precio_anual_usd' => 200,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Plan Portada Anual')
            ->assertSee('$16.66')
            ->assertDontSee('$200')
            ->assertDontSee('ahorras $40');
    }

    public function test_sin_planes_visibles_no_sale_la_seccion(): void
    {
        Plan::query()->update(['visible' => false]);

        $this->get('/')->assertOk()->assertDontSee('id="planes"', false);
    }

    public function test_el_filtro_viejo_por_especialidad_va_al_portal_de_reservas(): void
    {
        $this->get('/?especialidad=ginecologia')
            ->assertRedirect(route('reservas.catalogo', ['especialidad' => 'ginecologia']));
    }

    private function plan(string $codigo, string $nombre, array $datos = []): Plan
    {
        return Plan::create(array_merge([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'frecuencia' => Plan::MENSUAL,
            'precio_usd' => 20,
            'restricciones' => null,
            'es_default' => false,
            'visible' => true,
            'activo' => true,
            'orden' => 1,
        ], $datos));
    }
}
