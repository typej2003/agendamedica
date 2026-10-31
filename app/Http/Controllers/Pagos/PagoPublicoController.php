<?php

namespace App\Http\Controllers\Pagos;

use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\Pago;
use App\Pagos\Cobros;
use App\Pagos\ClienteSyPago;
use App\Pagos\Vistas\PagoPublicoVista;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * **El link público de pago de una cita** (`/pagar/{token}`): el paciente sin cuenta abre el link que le
 * mandó el consultorio y paga con SyPago (débito inmediato + OTP).
 *
 * El pago se resuelve **por `token_publico`**, nunca por id. El cobro no se reimplementa: se delega en
 * `App\Pagos\Cobros` (`iniciar`, `confirmar` y `conciliar`), que es el mismo motor del checkout del plan
 * y del webhook. Este controlador sólo traduce HTTP, valida los datos del pagador y aplica las guardas
 * de la pantalla.
 *
 * **Privacidad:** el link lo puede abrir cualquiera. La pantalla no lee al paciente; de la cita muestra
 * al médico, la fecha y la hora, y del pago el concepto, los montos y la referencia.
 */
class PagoPublicoController extends Controller
{
    /** `GET /pagar/{token}`: el resumen y el paso en el que está el pago. */
    public function mostrar(string $token)
    {
        $pago = $this->porToken($token);
        $vm = $this->vista($pago);

        // La lista de bancos sólo se pide cuando se va a mostrar el formulario del pagador.
        if ($vm->enDatos() && $vm->puedePagar()) {
            $vm->bancos = $this->bancosDelMetodo($pago);
        }

        return response()->view('pagos-publicos.pagar', ['vm' => $vm]);
    }

    /** `POST /pagar/{token}/iniciar`: guarda los datos del pagador y pide el OTP a SyPago. */
    public function iniciar(Request $request, string $token, Cobros $cobros): RedirectResponse
    {
        $pago = $this->porToken($token);
        $vm = $this->vista($pago);

        if (! $vm->puedePagar()) {
            return $this->volver($pago, 'error', $this->motivoParaNoPagar($vm));
        }

        if (! $vm->enDatos()) {
            return $this->volver($pago, 'estado', 'Ya te enviamos el código. Escríbelo para confirmar el pago.');
        }

        if (! $pago->metodoPago) {
            return $this->volver($pago, 'error', 'Este link no tiene un método de pago. Pídele al consultorio que te envíe uno nuevo.');
        }

        $datos = $request->validate([
            'documento_tipo' => ['required', Rule::in(CuentaBancaria::DOCUMENTOS)],
            'documento_numero' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:120'],
            'banco_codigo' => ['required', 'digits:4'],
            'cuenta' => ['required', 'string', 'max:30'],
        ], [
            'documento_tipo.required' => 'Elige el tipo de tu documento.',
            'documento_numero.required' => 'Falta tu documento.',
            'nombre.required' => 'Falta tu nombre.',
            'banco_codigo.required' => 'Falta el banco.',
            'banco_codigo.digits' => 'El código del banco son 4 dígitos (por ejemplo 0172).',
            'cuenta.required' => 'Falta la cuenta o el teléfono desde donde pagas.',
        ], [
            'documento_tipo' => 'tipo de documento', 'documento_numero' => 'documento',
            'nombre' => 'nombre', 'banco_codigo' => 'banco', 'cuenta' => 'cuenta o teléfono',
        ]);

        $numero = preg_replace('/\D/', '', (string) $datos['cuenta']);

        // La misma regla del checkout del plan: no se pregunta si es cuenta o pago móvil, el formato del
        // número lo dice. Un teléfono venezolano son 11 dígitos que empiezan con 04; una cuenta son 20 que
        // empiezan con el código del banco.
        if (preg_match('/^04\d{9}$/', $numero)) {
            $datos['tipo_cuenta'] = 'pago_movil';
            $datos['telefono'] = $numero;
        } elseif (strlen($numero) === 20 && str_starts_with($numero, (string) $datos['banco_codigo'])) {
            $datos['tipo_cuenta'] = 'cuenta';
        } else {
            throw ValidationException::withMessages([
                'cuenta' => 'Escribe una cuenta de 20 dígitos que empiece con el código del banco, '
                    . 'o el teléfono del pago móvil (11 dígitos, empieza con 04).',
            ]);
        }

        $datos['cuenta'] = $numero;

        $resultado = $cobros->iniciar($pago, $datos);

        return $this->volver($pago, $resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /** `POST /pagar/{token}/confirmar`: autoriza el débito con el código que llegó al teléfono. */
    public function confirmar(Request $request, string $token, Cobros $cobros): RedirectResponse
    {
        $pago = $this->porToken($token);
        $vm = $this->vista($pago);

        if (! $vm->puedeConfirmar()) {
            return $this->volver($pago, 'error', $this->motivoParaNoPagar($vm));
        }

        $datos = $request->validate([
            'otp' => ['required', 'string', 'min:4', 'max:10'],
        ], [
            'otp.required' => 'Escribe el código que te llegó al teléfono.',
            'otp.min' => 'El código tiene al menos 4 dígitos.',
        ], ['otp' => 'código']);

        $resultado = $cobros->confirmar($pago, $datos['otp']);

        return $this->volver($pago, $resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /**
     * `POST /pagar/{token}/consultar`: reconsulta a SyPago cuando la confirmación quedó en proceso.
     * No inicia ningún cobro; sólo refleja lo que SyPago ya sabe.
     */
    public function consultar(string $token, Cobros $cobros): RedirectResponse
    {
        $pago = $this->porToken($token);
        $vm = $this->vista($pago);

        if ($vm->aprobado()) {
            return $this->volver($pago, 'estado', 'Este pago ya estaba aprobado.');
        }

        if ($vm->citaYaPagada()) {
            return $this->volver($pago, 'error', $this->motivoParaNoPagar($vm));
        }

        if (! $pago->estaPendiente()) {
            return $this->volver($pago, 'error', $this->motivoParaNoPagar($vm));
        }

        $resultado = $cobros->conciliar($pago);

        return $this->volver($pago, $resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /* ------------------------------------------------------------------ */
    /* Interno                                                             */
    /* ------------------------------------------------------------------ */

    /** El pago del link. Un token que no existe es un 404 seco: no se dice nada más. */
    private function porToken(string $token): Pago
    {
        $pago = Pago::where('token_publico', $token)->first();

        abort_if($pago === null, 404);

        return $pago;
    }

    private function vista(Pago $pago): PagoPublicoVista
    {
        $cita = $pago->esDeCita() ? $pago->cita() : null;

        return new PagoPublicoVista(
            $pago,
            $cita,
            $this->medicoDe($pago, $cita),
            $this->otroPagoAprobado($pago),
        );
    }

    /**
     * El médico de la cita: el asignado (`cola.medico_id`) y, si no lo tiene, el del consultorio por la
     * relación legacy (`MedicoRegistro`). Es sólo para mostrar el nombre; no se expone nada más.
     */
    private function medicoDe(Pago $pago, ?Cola $cita): ?Medico
    {
        if ($cita?->medico_id) {
            $medico = Medico::find((int) $cita->medico_id);

            if ($medico) {
                return $medico;
            }
        }

        // Ojo: `cola.medico` es la clave legacy (un entero), no la relación; hay que invocar el método.
        if ($cita) {
            $medico = $cita->medico()->first();

            if ($medico) {
                return $medico;
            }
        }

        return Medico::where('reg_medico', $pago->reg_medico)->first();
    }

    /** ¿La cita ya tiene **otro** pago aprobado? Es la guarda para no cobrarla dos veces. */
    private function otroPagoAprobado(Pago $pago): bool
    {
        if (! $pago->esDeCita() || ! $pago->pagable_id) {
            return false;
        }

        return Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $pago->pagable_id)
            ->where('estado', Pago::APROBADO)
            ->where('id', '!=', $pago->id)
            ->exists();
    }

    /**
     * El catálogo de bancos de SyPago para el débito inmediato. Se cachea sólo lo que llegó bien: si
     * SyPago no responde, el formulario cae a un campo libre de cuatro dígitos y no se bloquea el pago.
     *
     * @return list<array{codigo:string,nombre:string}>
     */
    private function bancosDelMetodo(Pago $pago): array
    {
        $metodo = $pago->metodoPago;

        if (! $metodo || ! $metodo->estaConfigurado()) {
            return [];
        }

        try {
            $bancos = Cache::remember(
                'pagos.publico.bancos.' . $metodo->id,
                now()->addHours(6),
                fn (): array => ClienteSyPago::para($metodo)->bancos(),
            );
        } catch (Throwable $e) {
            return [];
        }

        $activos = [];
        $conOtp = [];

        foreach ($bancos as $banco) {
            if (! ($banco['activo'] ?? false)) {
                continue;
            }

            $fila = [
                'codigo' => (string) ($banco['codigo'] ?? ''),
                'nombre' => (string) ($banco['nombre'] ?? ''),
            ];

            if ($fila['codigo'] === '') {
                continue;
            }

            $activos[] = $fila;

            if ($banco['debito_otp'] ?? false) {
                $conOtp[] = $fila;
            }
        }

        // El cobro es con OTP: si SyPago marcó bancos con débito OTP, ésos son los que se ofrecen.
        $lista = $conOtp !== [] ? $conOtp : $activos;

        usort($lista, fn (array $a, array $b): int => strcmp($a['nombre'], $b['nombre']));

        return $lista;
    }

    /** El mensaje de la guarda que impidió seguir, en el orden de la pantalla. */
    private function motivoParaNoPagar(PagoPublicoVista $vm): string
    {
        if ($vm->aprobado()) {
            return 'Este pago ya está aprobado.';
        }
        if ($vm->vencido()) {
            return 'Este link venció. Pídele al consultorio que te envíe uno nuevo.';
        }
        if ($vm->fallado()) {
            return 'Este pago no se completó. Pídele al consultorio que te envíe un link nuevo.';
        }
        if ($vm->citaYaPagada() || $vm->citaSinSaldo()) {
            return 'Esta cita ya está pagada. No tienes que pagar otra vez.';
        }

        return 'Este pago no se puede continuar desde este link. Pídele al consultorio que te envíe uno nuevo.';
    }

    private function volver(Pago $pago, string $clave, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('pagar.mostrar', $pago->token_publico)
            ->with($clave, $mensaje);
    }
}
