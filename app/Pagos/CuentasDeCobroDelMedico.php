<?php

namespace App\Pagos;

use App\Models\CuentaBancaria;
use App\Models\MetodoPago;
use App\Models\PreferenciaDeCobro;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * **Las cuentas de cobro de un consultorio, desde el panel** (`/admin/cuentas/medicos/{medico}`).
 *
 * Es la misma tabla `cuentas_bancarias` que administra el médico en `/clinica/cobros` y el mismo método de
 * pago de pago móvil que resuelve el link de la cita. Desde el panel se cargan los datos de la cuenta; la
 * confirmación automática del banco (credenciales) se sigue configurando en `/clinica/cobros`, porque son
 * claves del comercio que el médico carga en su pantalla. Acá una cuenta nueva nace **manual** y, al editar,
 * se conserva la conciliación y sus credenciales.
 */
final class CuentasDeCobroDelMedico
{
    /** @return Collection<int,CuentaBancaria> */
    public function de(string $regMedico): Collection
    {
        return CuentaBancaria::where('propietario', CuentaBancaria::MEDICO)
            ->where('reg_medico', $regMedico)
            ->orderByDesc('predeterminada')
            ->orderBy('id')
            ->get();
    }

    /**
     * Alta o edición de una cuenta del consultorio.
     *
     * @param  array{banco_codigo:string, telefono?:?string, numero?:?string, titular:string, documento_tipo:string, documento_numero:string}  $datos
     */
    public function guardar(string $regMedico, array $datos, ?CuentaBancaria $cuenta = null): CuentaBancaria
    {
        $telefono = (string) preg_replace('/\D/', '', (string) ($datos['telefono'] ?? ''));
        $numero = (string) preg_replace('/\D/', '', (string) ($datos['numero'] ?? ''));

        if ($telefono === '' && $numero === '') {
            throw ValidationException::withMessages([
                'ctaTelefono' => 'Carga el teléfono del pago móvil o el número de cuenta.',
            ]);
        }

        if ($telefono !== '' && ! preg_match('/^04\d{9}$/', $telefono)) {
            throw ValidationException::withMessages(['ctaTelefono' => 'El teléfono tiene 11 dígitos y empieza por 04.']);
        }

        $valores = [
            'propietario' => CuentaBancaria::MEDICO,
            'reg_medico' => $regMedico,
            'moneda' => 'VES',
            'tipo' => $telefono !== '' ? CuentaBancaria::PAGO_MOVIL : CuentaBancaria::CUENTA,
            'banco_codigo' => $datos['banco_codigo'],
            'telefono' => $telefono !== '' ? $telefono : null,
            'numero' => $numero !== '' ? $numero : null,
            'titular' => trim($datos['titular']),
            'documento_tipo' => $datos['documento_tipo'],
            'documento_numero' => $datos['documento_numero'],
        ];

        if ($cuenta === null) {
            $valores['conciliacion'] = CuentaBancaria::CONCILIACION_MANUAL;
            $valores['activa'] = true;
            $valores['predeterminada'] = ! CuentaBancaria::where('propietario', CuentaBancaria::MEDICO)
                ->where('reg_medico', $regMedico)
                ->exists();
            $cuenta = CuentaBancaria::create($valores);
        } else {
            // La conciliación y sus credenciales se respetan: se configuran en `/clinica/cobros`.
            $cuenta->update($valores);
        }

        $this->sincronizarMetodo($cuenta);

        return $cuenta;
    }

    public function predeterminar(CuentaBancaria $cuenta): void
    {
        CuentaBancaria::where('propietario', CuentaBancaria::MEDICO)
            ->where('reg_medico', $cuenta->reg_medico)
            ->update(['predeterminada' => false]);

        $cuenta->update(['predeterminada' => true]);
    }

    public function alternarActiva(CuentaBancaria $cuenta): void
    {
        $cuenta->update(['activa' => ! $cuenta->activa]);

        // El método de pago de esa cuenta sigue el estado de la cuenta: desactivarla la saca de la oferta.
        MetodoPago::where('cuenta_bancaria_id', $cuenta->id)->update(['activo' => $cuenta->activa]);
    }

    /** Elige quién recauda las citas: la plataforma o el consultorio. */
    public function preferencia(string $regMedico, string $recaudador): string
    {
        $recaudador = $recaudador === PreferenciaDeCobro::MEDICO ? PreferenciaDeCobro::MEDICO : PreferenciaDeCobro::PLATAFORMA;

        if ($recaudador === PreferenciaDeCobro::MEDICO) {
            $tieneMetodo = MetodoPago::delMedico($regMedico)
                ->activos()
                ->paraUso(MetodoPago::COBROS)
                ->where('pasarela', 'pago_movil')
                ->get()
                ->contains(fn (MetodoPago $metodo): bool => $metodo->estaConfigurado());

            if (! $tieneMetodo) {
                throw ValidationException::withMessages([
                    'recaudador' => 'Primero carga una cuenta de pago móvil: sin ella no puede cobrar sus citas.',
                ]);
            }
        }

        PreferenciaDeCobro::fijar($regMedico, $recaudador);

        return $recaudador;
    }

    /** Cada cuenta tiene su método de pago: es lo que resuelve el link de la cita. */
    private function sincronizarMetodo(CuentaBancaria $cuenta): MetodoPago
    {
        $metodo = MetodoPago::where('propietario', MetodoPago::MEDICO)
            ->where('reg_medico', $cuenta->reg_medico)
            ->where('cuenta_bancaria_id', $cuenta->id)
            ->where('pasarela', 'pago_movil')
            ->first();

        $valores = [
            'propietario' => MetodoPago::MEDICO,
            'reg_medico' => $cuenta->reg_medico,
            'pasarela' => 'pago_movil',
            'variante' => 'pago_movil',
            'nombre' => 'Pago móvil · ' . $cuenta->bancoNombre(),
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::COBROS,
            'orden' => $metodo?->orden ?? 0,
        ];

        if ($metodo === null) {
            $valores['webhook_token'] = Str::random(64);
            $metodo = MetodoPago::create($valores);
        } else {
            $metodo->update($valores);
        }

        return $metodo;
    }
}
