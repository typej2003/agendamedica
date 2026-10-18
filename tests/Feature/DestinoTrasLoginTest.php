<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A dónde entra cada rol al iniciar sesión en la web (decisión del 2026-10-08).
 *
 * Desde el login unificado (2026-10-19) la pantalla no pregunta el tipo de cuenta: el rol lo resuelve
 * `AutenticacionPorCorreo`. El panel `/dashboard` quedó para administración: el médico y la secretaría
 * trabajan en `/clinica`, y si llegan al panel se los devuelve a su consultorio. El paciente sigue
 * entrando al panel mientras no exista `/consultorio` (ver el TODO en `User::rutaDeInicio`). Quien tiene
 * dos áreas no entra directo: elige en `/elegir-panel` (`User::rutaDeEntrada`).
 *
 * `DatabaseTransactions` por la razón de siempre (ver MedicAPI/AGENTS.md).
 */
class DestinoTrasLoginTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol, string $clave = 'secreto123'): User
    {
        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'destino-' . uniqid() . '@example.com',
            'password'             => Hash::make($clave),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function medico(string $clave = 'secreto123'): Medico
    {
        $sufijo = uniqid();

        return Medico::create([
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Destino',
            'email'      => "destino-medico-{$sufijo}@example.com",
            'password'   => Hash::make($clave),
            'reg_medico' => "test-destino-{$sufijo}",
        ]);
    }

    public function test_la_ruta_de_inicio_de_cada_rol(): void
    {
        $this->assertSame('dashboard', $this->usuario('Root')->rutaDeInicio());
        $this->assertSame('dashboard', $this->usuario('Administrador')->rutaDeInicio());
        $this->assertSame('dashboard', $this->usuario('Paciente')->rutaDeInicio());

        $this->assertSame('clinica.inicio', $this->usuario('Medico')->rutaDeInicio());
        $this->assertSame('clinica.inicio', $this->usuario('Secretaria')->rutaDeInicio());

        // Una cuenta con los dos roles tiene `rutaDeInicio` en el panel, pero el login ya no la manda
        // directo: le pregunta dónde entrar (`rutaDeEntrada`, ver LoginUnificadoTest). El rol de
        // administración manda en la ruta por defecto.
        $ambos = $this->usuario('Medico');
        $ambos->assignRole('Administrador');
        $this->assertSame('dashboard', $ambos->fresh()->rutaDeInicio());
        $this->assertSame('elegir-panel', $ambos->fresh()->rutaDeEntrada());
    }

    public function test_el_medico_entra_al_consultorio_y_el_panel_lo_devuelve_ahi(): void
    {
        $medico = $this->medico();

        $this->post('/login', [
            'email' => $medico->email, 'password' => 'secreto123',
        ])->assertRedirect(route('clinica.inicio'));

        $this->assertAuthenticated();

        // El panel ya no es su puerta: entra y sale redirigido a su consultorio.
        $this->get('/dashboard')->assertRedirect(route('clinica.inicio'));
    }

    public function test_el_administrador_entra_al_panel(): void
    {
        $root = $this->usuario('Root');

        $this->post('/login', [
            'email' => $root->email, 'password' => 'secreto123',
        ])->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertOk();
    }

    public function test_la_secretaria_trabaja_en_el_consultorio(): void
    {
        // La secretaría es una cuenta de `users` con su rol: el login la reconoce sin preguntarle el
        // tipo (ver LoginUnificadoTest). Lo que se fija acá es el destino, no el formulario.
        $secretaria = $this->usuario('Secretaria');

        $this->post('/login', [
            'email' => $secretaria->email, 'password' => 'secreto123',
        ])->assertRedirect(route('clinica.inicio'));

        $this->get('/dashboard')->assertRedirect(route('clinica.inicio'));
    }

    public function test_el_login_respeta_la_pagina_que_se_habia_pedido(): void
    {
        $medico = $this->medico();

        // Pedir una página protegida sin sesión guarda el destino y manda al login…
        $this->get('/clinica/pacientes')->assertRedirect(route('login'));

        // …y al entrar se sigue por ahí, no por la ruta por defecto del rol.
        $this->post('/login', [
            'email' => $medico->email, 'password' => 'secreto123',
        ])->assertRedirect('/clinica/pacientes');
    }
}
