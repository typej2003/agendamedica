<?php

namespace App\Clinica\Usuarios\Vistas;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * La pantalla **Usuarios** del consultorio (`/clinica/usuarios`, WEB-8.5): las secretarias del médico, con su estado y las cosas
 * que el médico puede hacer con cada una (restablecer su clave, bloquear, desbloquear y quitar su acceso) más el alta.
 *
 * Como todo ViewModel de la web clínica no consulta la base: recibe las cuentas ya leídas (con `vinculos` = los `reg_medico` de
 * cada una) y las URLs que resolvió el controlador. La regla de qué se puede hacer con una cuenta **compartida** (que también
 * trabaja con otro consultorio) no se decide acá: la dicta `CuentaService`; el ViewModel sólo ofrece menos acciones para que la
 * pantalla no proponga lo que el servidor va a rechazar.
 */
final class UsuariosVista
{
    /**
     * @param  Collection<int,User>  $secretarias  con el atributo `vinculos`
     * @param  array<int,string>  $registros  los `reg_medico` del médico
     * @param  array{nombre:string, correo:string, clave:string, motivo:string}|null  $claveNueva  la clave que se muestra una sola vez
     * @param  array<string,mixed>  $urls  `crear` y, como funciones de id, `restablecer`, `bloquear`, `desbloquear` y `quitar`
     */
    public function __construct(
        private Collection $secretarias,
        private array $registros,
        private ?array $claveNueva,
        private string $nombreDelMedico,
        private array $urls,
        private bool $abrirNueva = false,
    ) {
    }

    public function subtitulo(): string
    {
        $total = $this->secretarias->count();

        return $total === 0
            ? 'Las personas que agendan y cobran por ti. Entran con su propia cuenta y no ven las consultas.'
            : $total . ($total === 1 ? ' persona' : ' personas') . ' con acceso a la agenda de ' . $this->nombreDelMedico . '. No ven las consultas ni los catálogos.';
    }

    public function urlCrear(): string
    {
        return $this->urls['crear'];
    }

    public function abrirAlCargar(): bool
    {
        return $this->abrirNueva;
    }

    public function estaVacio(): bool
    {
        return $this->secretarias->isEmpty();
    }

    /** @return array{nombre:string, correo:string, clave:string, motivo:string}|null */
    public function claveNueva(): ?array
    {
        return $this->claveNueva;
    }

    /** El título de la tarjeta de la clave: «Clave temporal de María» (creada o restablecida). */
    public function tituloDeLaClave(): string
    {
        return 'Clave temporal de ' . ($this->claveNueva['nombre'] ?? '');
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->secretarias->map(fn (User $cuenta) => $this->fila($cuenta))->all();
    }

    /** @return array<string,mixed> */
    private function fila(User $cuenta): array
    {
        $compartida = collect($cuenta->getAttribute('vinculos') ?? [])->diff($this->registros)->isNotEmpty();
        $activa = $cuenta->is_active !== false;
        $id = (int) $cuenta->id;

        if (! $activa) {
            $estado = ['tono' => 'peligro', 'icono' => 'bi-slash-circle', 'texto' => 'Bloqueada'];
        } elseif ($cuenta->must_change_password) {
            $estado = ['tono' => 'aviso', 'icono' => 'bi-key', 'texto' => 'Clave temporal sin cambiar'];
        } else {
            $estado = ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Activa'];
        }

        return [
            'id' => $id,
            'nombre' => $cuenta->name,
            'correo' => $cuenta->email,
            'estado' => $estado,
            'motivo' => ! $activa && $cuenta->blocked_reason ? (string) $cuenta->blocked_reason : null,
            'compartida' => $compartida,
            'activa' => $activa,
            'puedeAdministrar' => ! $compartida,
            'urlRestablecer' => ($this->urls['restablecer'])($id),
            'urlBloquear' => ($this->urls['bloquear'])($id),
            'urlDesbloquear' => ($this->urls['desbloquear'])($id),
            'urlQuitar' => ($this->urls['quitar'])($id),
            'avisoRestablecer' => '¿Restablecer la clave de ' . $cuenta->name . '? Se le dará una clave temporal nueva y la actual deja de servir.',
            'avisoQuitar' => '¿Quitarle a ' . $cuenta->name . ' el acceso a tu consultorio? No se borra nada: deja de ver tu agenda.',
        ];
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        return [
            'icono' => 'bi-people',
            'titulo' => 'Todavía no tienes secretaria',
            'texto' => 'Crea su cuenta: agenda, confirma y cobra las citas de tu consultorio, sin ver las consultas.',
        ];
    }
}
