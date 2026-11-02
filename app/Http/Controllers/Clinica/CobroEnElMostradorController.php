<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pagos\CobrarEnElMostrador;
use App\Clinica\Agenda\Vistas\CobroEnElMostradorVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\Pago;
use App\Pagos\Cobros;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * **Cobrar una cita en el mostrador** (O-53, 2026-11-02): la pantalla del consultorio con los dos caminos.
 *
 * - **Pasarela**: se crea el pago con el canal `mostrador` y se pide el OTP al teléfono del paciente; el
 *   código se confirma acá mismo. Es el mismo motor del link público (`App\Pagos\Cobros`).
 * - **Manual**: efectivo, punto de venta o transferencia. El pago nace aprobado, con su medio, y la cita se
 *   escribe con la misma acción del mostrador.
 *
 * La cita es la del `reg_medico` del contexto (`ResuelveLaCitaDelContexto`): la de otro médico responde 404.
 * Los roles son los del grupo de la agenda —médico, secretaría y Root—, que es quien cobra en el mostrador.
 *
 * Una cita ya cobrada no se vuelve a cobrar: la Action lo comprueba bajo el bloqueo de la fila y la pantalla
 * lo dice antes de ofrecer nada.
 */
class CobroEnElMostradorController extends Controller
{
    use ResuelveLaCitaDelContexto;

    public function mostrar(Request $request, Cola $cola, CobrarEnElMostrador $cobrar): View
    {
        $cita = $this->citaDelContexto($request, $cola);
        $metodo = $cobrar->metodo();

        return view('clinica.agenda.cobro', [
            'vm' => new CobroEnElMostradorVista(
                $cita,
                $cobrar->pagosDe($cita),
                $metodo !== null,
                $metodo?->nombre,
                $this->medicoDe($cita),
            ),
        ]);
    }

    /**
     * **Cobrar con la pasarela**: crea el pago del saldo y pide el código al teléfono del pagador.
     *
     * El formulario pide los datos del débito (documento, nombre, banco y cuenta o teléfono) con las mismas
     * reglas que el link público: el formato del número decide si es cuenta o pago móvil.
     */
    public function pasarela(Request $request, Cola $cola, CobrarEnElMostrador $cobrar, Cobros $cobros): RedirectResponse
    {
        $cita = $this->citaDelContexto($request, $cola);

        $datos = $request->validate([
            'documento_tipo' => ['required', Rule::in(CuentaBancaria::DOCUMENTOS)],
            'documento_numero' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:120'],
            'banco_codigo' => ['required', 'digits:4'],
            'cuenta' => ['required', 'string', 'max:30'],
        ], [
            'documento_tipo.required' => 'Elige el tipo de documento del paciente.',
            'documento_numero.required' => 'Falta el documento del paciente.',
            'nombre.required' => 'Falta el nombre del pagador.',
            'banco_codigo.required' => 'Falta el banco.',
            'banco_codigo.digits' => 'El código del banco son 4 dígitos (por ejemplo 0172).',
            'cuenta.required' => 'Falta la cuenta o el teléfono desde donde se paga.',
        ]);

        $numero = preg_replace('/\D/', '', (string) $datos['cuenta']);

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

        try {
            $pago = $cobrar->crearParaLaPasarela($cita, $request->user()?->id);
        } catch (RuntimeException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        $resultado = $cobros->iniciar($pago, $datos);

        return back()->with($resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /** **Confirmar el débito** con el código que llegó al teléfono del pagador. */
    public function confirmar(Request $request, Cola $cola, Pago $pago, Cobros $cobros): RedirectResponse
    {
        $cita = $this->citaDelContexto($request, $cola);

        // El pago tiene que ser de **esta** cita y de este cobro: el id viaja en la URL.
        abort_unless(
            $pago->pagable_tipo === Pago::PAGABLE_CITA
            && (int) $pago->pagable_id === (int) $cita->id
            && $pago->canal === Pago::CANAL_MOSTRADOR,
            404,
        );

        $datos = $request->validate([
            'otp' => ['required', 'digits:6'],
        ], [
            'otp.required' => 'Escribe el código que llegó al teléfono.',
            'otp.digits' => 'El código son 6 dígitos.',
        ]);

        $resultado = $cobros->confirmar($pago, $datos['otp']);

        return back()->with($resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /**
     * **Registrar un cobro manual**: efectivo, punto de venta, transferencia u otro. El pago nace aprobado y
     * la cita queda escrita con la misma acción del mostrador.
     */
    public function manual(Request $request, Cola $cola, CobrarEnElMostrador $cobrar): RedirectResponse
    {
        $cita = $this->citaDelContexto($request, $cola);

        $datos = $request->validate([
            'recibido' => ['required', 'numeric', 'min:0.01'],
            'monto' => ['nullable', 'numeric', 'min:0'],
            'medio' => ['required', Rule::in(array_keys(CobrarEnElMostrador::MEDIOS))],
            'nota' => ['nullable', 'string', 'max:200'],
        ], [
            'recibido.required' => 'Escribe cuánto se cobró.',
            'recibido.min' => 'Lo cobrado tiene que ser mayor que cero.',
            'medio.required' => 'Elige con qué se cobró.',
        ]);

        try {
            $cobrar->registrarManual(
                $cita,
                (float) $datos['recibido'],
                $datos['medio'],
                isset($datos['monto']) ? (float) $datos['monto'] : null,
                $request->user()?->id,
                $datos['nota'] ?? null,
            );
        } catch (RuntimeException $excepcion) {
            throw ValidationException::withMessages(['recibido' => $excepcion->getMessage()]);
        }

        return back()->with('estado', 'Cobro registrado.');
    }

    /** El nombre del médico de la cita, para el subtítulo. */
    private function medicoDe(Cola $cita): ?string
    {
        return $cita->medico_id
            ? \App\Models\Medico::find((int) $cita->medico_id)?->nombre_mostrar
            : $cita->medico()->first()?->nombre_mostrar;
    }
}
