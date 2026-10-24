<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La landing pública explica **por qué usar Doctorísimo.App** a los dos públicos y lleva a cada uno a
 * su puerta de entrada: el catálogo de reservas para el paciente y el registro de médicos para el doctor.
 *
 * Las capturas de la sección son de la aplicación real con data sintética (`FakeClinicalDataSeeder`),
 * así que el test también comprueba que los archivos publicados existan.
 */
class LandingWebTest extends TestCase
{
    public function test_la_landing_explica_los_beneficios_para_pacientes_y_medicos(): void
    {
        $respuesta = $this->get('/');

        $respuesta->assertOk();
        $respuesta->assertSee('¿Por qué usar', false);
        $respuesta->assertSee('Para pacientes');
        $respuesta->assertSee('Para médicos');
        $respuesta->assertSee('Encuentra a tu especialista y reserva sin llamadas.');
        $respuesta->assertSee('Tu consultorio en orden, en la nube y en el bolsillo.');
        $respuesta->assertSee('La historia de un paciente, en 1 clic');
        $respuesta->assertSee('Paga en línea, sin efectivo');
        $respuesta->assertSee('Lo que sale de Doctorísimo.App');
        $respuesta->assertSee('Curvas de crecimiento');
        $respuesta->assertSee('Récipes listos para imprimir');
        $respuesta->assertSee('Informes de ecografía');
    }

    public function test_la_landing_lleva_a_cada_publico_a_su_puerta_de_entrada(): void
    {
        $respuesta = $this->get('/');

        $respuesta->assertSee(route('reservas.catalogo'), false);
        $respuesta->assertSee(route('medico.registro'), false);
        $respuesta->assertSee(route('paciente.citas'), false);
        $respuesta->assertSee(route('login'), false);
        $respuesta->assertSee('mailto:soporte@ginecoreport.com', false);
    }

    public function test_las_capturas_de_la_seccion_existen_y_tienen_texto_alternativo(): void
    {
        $respuesta = $this->get('/');

        $respuesta->assertSee('img/landing/catalogo-movil.webp', false);
        $respuesta->assertSee('img/landing/agenda-consultorio.webp', false);
        $respuesta->assertSee('img/landing/agenda-movil.webp', false);
        $respuesta->assertSee('img/landing/crecimiento.webp', false);
        $respuesta->assertSee('img/landing/recipe.webp', false);
        $respuesta->assertSee('img/landing/eco.webp', false);
        $respuesta->assertSee('Catálogo público de Doctorísimo.App en el celular', false);
        $respuesta->assertSee('Agenda del consultorio en Doctorísimo.App', false);
        $respuesta->assertSee('La misma agenda en la app móvil de Doctorísimo.App', false);
        $respuesta->assertSee('Percentiles de Fundacredesa', false);
        $respuesta->assertSee('QR de verificación', false);
        $respuesta->assertSee('Informe de ecografía obstétrica del segundo trimestre', false);

        $this->assertFileExists(public_path('img/landing/catalogo-movil.webp'));
        $this->assertFileExists(public_path('img/landing/agenda-consultorio.webp'));
        $this->assertFileExists(public_path('img/landing/agenda-movil.webp'));
        $this->assertFileExists(public_path('img/landing/crecimiento.webp'));
        $this->assertFileExists(public_path('img/landing/recipe.webp'));
        $this->assertFileExists(public_path('img/landing/eco.webp'));
    }
}
