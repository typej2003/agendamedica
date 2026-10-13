<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Usuarios\Vistas\UsuariosVista;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * La pantalla de Usuarios sin base de datos (WEB-8.5): el estado de cada cuenta y qué acciones se ofrecen.
 */
class UsuariosVistaTest extends TestCase
{
    private function cuenta(int $id, array $atributos = [], array $vinculos = ['reg-1']): User
    {
        $user = new User(['name' => 'Cuenta ' . $id, 'email' => "c{$id}@example.com"] + $atributos);
        $user->id = $id;
        $user->setAttribute('vinculos', $vinculos);

        return $user;
    }

    private function vista(array $cuentas, ?array $clave = null): UsuariosVista
    {
        return new UsuariosVista(collect($cuentas), ['reg-1'], $clave, 'Dra. Ana Pérez', [
            'crear' => 'https://x.test/crear',
            'restablecer' => fn (int $id) => "https://x.test/{$id}/restablecer",
            'bloquear' => fn (int $id) => "https://x.test/{$id}/bloquear",
            'desbloquear' => fn (int $id) => "https://x.test/{$id}/desbloquear",
            'quitar' => fn (int $id) => "https://x.test/{$id}",
        ]);
    }

    public function test_el_estado_de_cada_cuenta(): void
    {
        $filas = $this->vista([
            $this->cuenta(1, ['is_active' => true, 'must_change_password' => false]),
            $this->cuenta(2, ['is_active' => true, 'must_change_password' => true]),
            $this->cuenta(3, ['is_active' => false, 'blocked_reason' => 'Se fue']),
        ])->filas();

        $this->assertSame(['exito', 'Activa'], [$filas[0]['estado']['tono'], $filas[0]['estado']['texto']]);
        $this->assertSame(['aviso', 'Clave temporal sin cambiar'], [$filas[1]['estado']['tono'], $filas[1]['estado']['texto']]);
        $this->assertSame(['peligro', 'Bloqueada'], [$filas[2]['estado']['tono'], $filas[2]['estado']['texto']]);
        $this->assertSame('Se fue', $filas[2]['motivo']);
        $this->assertNull($filas[0]['motivo']);
    }

    public function test_una_cuenta_compartida_ofrece_menos_acciones(): void
    {
        $filas = $this->vista([
            $this->cuenta(1, ['is_active' => true], ['reg-1']),
            $this->cuenta(2, ['is_active' => true], ['reg-1', 'reg-ajeno']),
        ])->filas();

        $this->assertFalse($filas[0]['compartida']);
        $this->assertTrue($filas[0]['puedeAdministrar']);
        $this->assertTrue($filas[1]['compartida']);
        $this->assertFalse($filas[1]['puedeAdministrar']);
        $this->assertSame('https://x.test/2', $filas[1]['urlQuitar']);
    }

    public function test_textos_y_estado_vacio(): void
    {
        $this->assertStringContainsString('no ven las consultas', $this->vista([])->subtitulo());
        $this->assertSame('Todavía no tienes secretaria', $this->vista([])->vacio()['titulo']);
        $this->assertTrue($this->vista([])->estaVacio());

        $subtitulo = $this->vista([$this->cuenta(1)])->subtitulo();
        $this->assertStringContainsString('1 persona con acceso a la agenda de Dra. Ana Pérez', $subtitulo);

        $con = $this->vista([], ['nombre' => 'María', 'correo' => 'm@x.test', 'clave' => 'Abc12345', 'motivo' => 'creada']);
        $this->assertSame('Clave temporal de María', $con->tituloDeLaClave());
        $this->assertSame('Abc12345', $con->claveNueva()['clave']);
    }
}
