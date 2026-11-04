<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Validation\ValidationException;

/**
 * **Secretarías del consultorio**, administradas desde el detalle del médico: alta con clave temporal,
 * restablecer la clave, bloquear, desbloquear y quitar el acceso.
 *
 * La lógica es la de `CuentaService` (la misma que `/clinica/usuarios` y la que usa la pantalla del médico),
 * incluida la regla de la cuenta compartida: una secretaría que también trabaja con otro consultorio no se
 * toca desde acá, sólo se le quita el acceso a éste.
 */
trait GestionaSecretarias
{
    /** Formulario abierto: `nueva` | `clave` | `bloquear` | null */
    public $secretariaAccion = null;

    public $secretariaNombre = '';
    public $secretariaEmail = '';
    public $secretariaId = null;
    public $secretariaMotivo = '';

    /** Clave temporal recién creada o restablecida: ['nombre','correo','clave','motivo']. */
    public $claveSecretaria = null;

    public function abrirNuevaSecretaria(): void
    {
        $this->resetErrorBag();
        $this->secretariaNombre = '';
        $this->secretariaEmail = '';
        $this->secretariaAccion = 'nueva';
    }

    public function cerrarSecretaria(): void
    {
        $this->secretariaAccion = null;
        $this->secretariaId = null;
        $this->secretariaNombre = '';
        $this->secretariaEmail = '';
        $this->secretariaMotivo = '';
        $this->resetErrorBag();
    }

    public function crearSecretaria(CuentaService $cuentas): void
    {
        $this->validate([
            'secretariaNombre' => 'required|string|max:100',
            'secretariaEmail' => 'required|email|max:150',
        ], [], ['secretariaNombre' => 'nombre', 'secretariaEmail' => 'correo']);

        $regMedico = (string) $this->medico->reg_medico;
        if ($regMedico === '') {
            $this->addError('secretariaEmail', 'Este médico no tiene reg_medico: no se le puede asociar una secretaría.');

            return;
        }

        try {
            $r = $cuentas->crearSecretaria($this->secretariaNombre, $this->secretariaEmail, $regMedico);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo === 'name' ? 'secretariaNombre' : ($campo === 'email' ? 'secretariaEmail' : $campo), $mensajes[0]);
            }

            return;
        }

        $this->claveSecretaria = [
            'nombre' => $r['user']->name,
            'correo' => $r['user']->email,
            'clave' => $r['clave'],
            'motivo' => 'creada',
        ];
        $this->cerrarSecretaria();
        session()->flash('message', 'Creaste la cuenta de ' . $r['user']->name . '. Entrégale la clave temporal: la tiene que cambiar al entrar.');
    }

    public function restablecerClaveSecretaria(int $id, CuentaService $cuentas): void
    {
        $cuenta = $this->secretariaDelMedico($id);

        try {
            $clave = $cuentas->restablecerClaveDeSecretaria($cuenta, $this->registrosDelMedico());
        } catch (ValidationException $e) {
            $this->addError('secretaria', $e->errors()['cuenta'][0] ?? $e->getMessage());

            return;
        }

        $this->claveSecretaria = [
            'nombre' => $cuenta->name,
            'correo' => $cuenta->email,
            'clave' => $clave,
            'motivo' => 'restablecida',
        ];
        session()->flash('message', 'Restableciste la clave de ' . $cuenta->name . '. Entrégale la clave temporal nueva.');
    }

    public function abrirBloquearSecretaria(int $id): void
    {
        $this->secretariaDelMedico($id);
        $this->resetErrorBag();
        $this->secretariaId = $id;
        $this->secretariaMotivo = '';
        $this->secretariaAccion = 'bloquear';
    }

    public function bloquearSecretaria(CuentaService $cuentas): void
    {
        $this->validate(['secretariaMotivo' => 'nullable|string|max:200'], [], ['secretariaMotivo' => 'motivo']);

        $cuenta = $this->secretariaDelMedico((int) $this->secretariaId);

        try {
            $cuentas->bloquearSecretaria($cuenta, $this->registrosDelMedico(), (string) $this->secretariaMotivo, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('secretaria', $e->errors()['cuenta'][0] ?? $e->getMessage());

            return;
        }

        $this->cerrarSecretaria();
        session()->flash('message', 'Bloqueaste a ' . $cuenta->name . ': ya no puede entrar. Puedes desbloquearla cuando quieras.');
    }

    public function desbloquearSecretaria(int $id, CuentaService $cuentas): void
    {
        $cuenta = $this->secretariaDelMedico($id);

        try {
            $cuentas->desbloquearSecretaria($cuenta, $this->registrosDelMedico());
        } catch (ValidationException $e) {
            $this->addError('secretaria', $e->errors()['cuenta'][0] ?? $e->getMessage());

            return;
        }

        session()->flash('message', 'Desbloqueaste a ' . $cuenta->name . ': ya puede entrar de nuevo.');
    }

    public function quitarSecretaria(int $id, CuentaService $cuentas): void
    {
        $cuenta = $this->secretariaDelMedico($id);
        $resultado = $cuentas->quitarAccesoDeSecretaria($cuenta, $this->registrosDelMedico());

        session()->flash('message', $resultado['activa']
            ? 'Le quitaste a ' . $cuenta->name . ' el acceso a este consultorio. Su cuenta sigue activa para el otro consultorio donde trabaja.'
            : 'Le quitaste a ' . $cuenta->name . ' el acceso a este consultorio. No se borró nada: su cuenta quedó sin acceso.');
    }

    public function cerrarClaveSecretaria(): void
    {
        $this->claveSecretaria = null;
    }

    /** La cuenta, o 404: una cuenta que no es secretaría de este médico ni se confirma que existe. */
    private function secretariaDelMedico(int $id): User
    {
        $cuenta = User::find($id);

        abort_unless($cuenta && app(CuentaService::class)->esSecretariaDe($cuenta, $this->registrosDelMedico()), 404);

        return $cuenta;
    }
}
