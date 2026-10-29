<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Usuarios\Vistas\UsuariosVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CupoDelPlan;
use App\Services\CuentaService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * **Usuarios del consultorio** (WEB-8.5): el médico gestiona a su secretaria desde `/clinica/usuarios`, sin pasar por el panel
 * de administración. Crea su cuenta (clave temporal que se muestra **una sola vez**), le restablece la clave, la bloquea o la
 * desbloquea y le quita el acceso.
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`: la secretaría recibe 403) y sólo toca cuentas **solo-secretaría
 * vinculadas a los registros del médico del contexto**: una cuenta ajena —de otro médico, de administración, o que también es
 * médico— responde 404 como si no existiera. La regla no está acá sino en `CuentaService`, la misma que usa el panel
 * (`/admin/cuentas`): el controlador valida el HTTP, llama y avisa.
 */
class UsuarioController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private CuentaService $cuentas, private CupoDelPlan $cupos)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $medico = $contexto['medico'];
        $registros = $this->registrosDelMedico($medico);
        $regMedico = (string) $contexto['reg_medico'];
        $puedeAgregar = $this->cupos->puedeAgregarSecretaria($regMedico);

        return view('clinica.usuarios.index', ['vm' => new UsuariosVista(
            $this->cuentas->secretariasDe($registros),
            $registros,
            $request->session()->get('clave_temporal'),
            trim(($medico->prefix ? $medico->prefix . ' ' : '') . $medico->name . ' ' . $medico->lastname),
            $this->urls(),
            $request->boolean('nueva'),
            $puedeAgregar,
            $puedeAgregar ? null : $this->motivoDeCupo($regMedico),
            $puedeAgregar ? null : route('clinica.plan'),
        )]);
    }

    public function crear(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|string|email|max:150',
        ], [
            'name.required' => 'Escribe el nombre de la secretaria.',
            'name.max' => 'El nombre admite hasta 100 caracteres.',
            'email.required' => 'Escribe el correo con el que va a entrar.',
            'email.email' => 'El correo no es válido.',
            'email.max' => 'El correo admite hasta 150 caracteres.',
        ]);

        $nueva = $this->cuentas->crearSecretaria($datos['name'], $datos['email'], (string) $contexto['reg_medico']);

        return $this->conClave($nueva['user'], $nueva['clave'], 'creada')
            ->with('estado', 'Creaste la cuenta de ' . $nueva['user']->name . '. Entrégale la clave temporal: la tiene que cambiar al entrar.');
    }

    public function restablecer(Request $request, int $usuario)
    {
        $cuenta = $this->cuentaDelMedico($request, $usuario);
        $clave = $this->cuentas->restablecerClaveDeSecretaria($cuenta, $this->registros($request));

        return $this->conClave($cuenta, $clave, 'restablecida')
            ->with('estado', 'Restableciste la clave de ' . $cuenta->name . '. Entrégale la clave temporal nueva.');
    }

    public function bloquear(Request $request, int $usuario)
    {
        $cuenta = $this->cuentaDelMedico($request, $usuario);
        $datos = $request->validate(['motivo' => 'nullable|string|max:200'], ['motivo.max' => 'El motivo admite hasta 200 caracteres.']);

        $this->cuentas->bloquearSecretaria($cuenta, $this->registros($request), (string) ($datos['motivo'] ?? ''), $request->user());

        return redirect()->route('clinica.usuarios')
            ->with('estado', 'Bloqueaste a ' . $cuenta->name . ': ya no puede entrar. Puedes desbloquearla cuando quieras.');
    }

    public function desbloquear(Request $request, int $usuario)
    {
        $cuenta = $this->cuentaDelMedico($request, $usuario);

        $this->cuentas->desbloquearSecretaria($cuenta, $this->registros($request));

        return redirect()->route('clinica.usuarios')->with('estado', 'Desbloqueaste a ' . $cuenta->name . ': ya puede entrar de nuevo.');
    }

    public function quitar(Request $request, int $usuario)
    {
        $cuenta = $this->cuentaDelMedico($request, $usuario);

        $resultado = $this->cuentas->quitarAccesoDeSecretaria($cuenta, $this->registros($request));

        return redirect()->route('clinica.usuarios')->with('estado', $resultado['activa']
            ? 'Le quitaste a ' . $cuenta->name . ' el acceso a tu consultorio. Su cuenta sigue activa para el otro consultorio donde trabaja.'
            : 'Le quitaste a ' . $cuenta->name . ' el acceso a tu consultorio. No se borró nada: su cuenta quedó sin acceso.');
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return array<int,string> */
    private function registros(Request $request): array
    {
        return $this->registrosDelMedico($request->attributes->get('contexto_clinico')['medico']);
    }

    /** La cuenta, o 404: una cuenta que no es una secretaría de este médico ni se confirma que existe. */
    private function cuentaDelMedico(Request $request, int $id): User
    {
        $cuenta = User::find($id);

        abort_unless($cuenta && $this->cuentas->esSecretariaDe($cuenta, $this->registros($request)), 404);

        return $cuenta;
    }

    /** El mensaje de “mejora el plan” para la pantalla, sin lanzar la excepción. */
    private function motivoDeCupo(string $regMedico): ?string
    {
        try {
            $this->cupos->exigirCupoSecretaria($regMedico);

            return null;
        } catch (ValidationException $e) {
            return $e->errors()['secretaria'][0] ?? $e->getMessage();
        }
    }

    /** Vuelve a la lista con la clave temporal en la sesión flash: se pinta una vez y se pierde al recargar. */
    private function conClave(User $cuenta, string $clave, string $motivo)
    {
        return redirect()->route('clinica.usuarios')->with('clave_temporal', [
            'nombre' => $cuenta->name,
            'correo' => $cuenta->email,
            'clave' => $clave,
            'motivo' => $motivo,
        ]);
    }

    /** @return array<string,mixed> */
    private function urls(): array
    {
        return [
            'crear' => route('clinica.usuarios.crear'),
            'restablecer' => fn (int $id) => route('clinica.usuarios.restablecer', $id),
            'bloquear' => fn (int $id) => route('clinica.usuarios.bloquear', $id),
            'desbloquear' => fn (int $id) => route('clinica.usuarios.desbloquear', $id),
            'quitar' => fn (int $id) => route('clinica.usuarios.quitar', $id),
        ];
    }
}
