<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `php artisan correo:diagnostico`: el diagnóstico del correo saliente que se corre en el servidor antes de
 * subir (ROADMAP §5).
 *
 * Lo que se fija acá: que un `MAIL_MAILER=log` no pase por bueno, que un servidor SMTP que no resuelve se
 * señale con el mismo error que ve el registro de médicos, y que la opción `--enviar-a` mande de verdad.
 */
class CorreoDiagnosticoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El `.env` de los tests no trae remitente: sin esto el comando avisaría de eso en todas las pruebas.
        config([
            'mail.from.address' => 'no-reply@doctorisimo.app',
            'mail.from.name' => 'Doctorísimo',
        ]);
    }

    public function test_avisa_cuando_el_transporte_no_manda_correos(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('correo:diagnostico')
            ->expectsOutputToContain('no sale ningún correo')
            ->assertFailed();
    }

    public function test_avisa_cuando_el_servidor_smtp_no_resuelve(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.dominio-que-no-existe.test',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.encryption' => 'ssl',
        ]);

        $this->artisan('correo:diagnostico')
            ->expectsOutputToContain('no resuelve')
            ->assertFailed();
    }

    public function test_un_smtp_con_host_valido_pasa_el_control(): void
    {
        config([
            'mail.default' => 'smtp',
            // Una IP no necesita DNS y el comando no se conecta si no se le pide un envío.
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1025,
        ]);

        $this->artisan('correo:diagnostico')
            ->expectsOutputToContain('está completa')
            ->assertSuccessful();
    }

    public function test_la_opcion_enviar_a_manda_el_correo_de_prueba(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('correo:diagnostico', ['--enviar-a' => 'doctor@example.com'])
            ->expectsOutputToContain('Enviado')
            ->assertSuccessful();

        $this->assertCount(1, Mail::getSymfonyTransport()->messages());
    }

    public function test_sin_la_opcion_no_manda_nada(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('correo:diagnostico')->assertSuccessful();

        $this->assertCount(0, Mail::getSymfonyTransport()->messages());
    }
}
