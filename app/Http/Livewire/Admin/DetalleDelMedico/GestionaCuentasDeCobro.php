<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Models\CuentaBancaria;
use App\Models\PreferenciaDeCobro;
use App\Pagos\CuentasDeCobroDelMedico;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * **Métodos de pago del médico**, desde su detalle: las cuentas de pago móvil con las que puede cobrar sus
 * citas, cuál es la predeterminada, si están activas y quién recauda (la plataforma o el consultorio).
 *
 * Es la misma tabla y el mismo método de pago que `/clinica/cobros`; las credenciales de confirmación
 * automática del banco se siguen cargando en la pantalla del médico.
 */
trait GestionaCuentasDeCobro
{
    /** Formulario de cuenta abierto (null = cerrado). */
    public $cuentaForm = false;

    public $cuentaId = null;
    public $ctaBanco = '';
    public $ctaTelefono = '';
    public $ctaNumero = '';
    public $ctaTitular = '';
    public $ctaDocTipo = 'V';
    public $ctaDocNumero = '';

    /** `plataforma` | `medico`: quién cobra las citas. */
    public $recaudador = PreferenciaDeCobro::PLATAFORMA;

    public function cargarCuentasDeCobro(): void
    {
        $this->recaudador = PreferenciaDeCobro::de((string) $this->medico->reg_medico);
        $this->cerrarCuentaForm();
    }

    public function abrirCuentaNueva(): void
    {
        $this->resetErrorBag();
        $this->cuentaId = null;
        $this->ctaBanco = array_key_first((array) config('pagos.pago_movil.catalogo', [])) ?: '';
        $this->ctaTelefono = '';
        $this->ctaNumero = '';
        $this->ctaTitular = '';
        $this->ctaDocTipo = 'V';
        $this->ctaDocNumero = '';
        $this->cuentaForm = true;
    }

    public function abrirCuentaEditar(int $id): void
    {
        $cuenta = $this->cuentaDelMedico($id);

        $this->resetErrorBag();
        $this->cuentaId = $cuenta->id;
        $this->ctaBanco = (string) $cuenta->banco_codigo;
        $this->ctaTelefono = (string) $cuenta->telefono;
        $this->ctaNumero = (string) $cuenta->numero;
        $this->ctaTitular = (string) $cuenta->titular;
        $this->ctaDocTipo = (string) ($cuenta->documento_tipo ?: 'V');
        $this->ctaDocNumero = (string) $cuenta->documento_numero;
        $this->cuentaForm = true;
    }

    public function cerrarCuentaForm(): void
    {
        $this->cuentaForm = false;
        $this->cuentaId = null;
        $this->ctaBanco = '';
        $this->ctaTelefono = '';
        $this->ctaNumero = '';
        $this->ctaTitular = '';
        $this->ctaDocTipo = 'V';
        $this->ctaDocNumero = '';
        $this->resetErrorBag();
    }

    public function guardarCuenta(CuentasDeCobroDelMedico $cuentas): void
    {
        $catalogo = array_keys((array) config('pagos.pago_movil.catalogo', []));

        $this->validate([
            'ctaBanco' => ['required', Rule::in($catalogo)],
            'ctaTelefono' => ['nullable', 'string', 'max:15'],
            'ctaNumero' => ['nullable', 'string', 'max:30'],
            'ctaTitular' => ['required', 'string', 'max:120'],
            'ctaDocTipo' => ['required', Rule::in(CuentaBancaria::DOCUMENTOS)],
            'ctaDocNumero' => ['required', 'string', 'max:20'],
        ], [
            'ctaBanco.required' => 'Elige el banco de la cuenta.',
            'ctaTitular.required' => 'Escribe el nombre del titular.',
            'ctaDocNumero.required' => 'Falta el documento del titular.',
            'ctaBanco.in' => 'Elige un banco de la lista.',
        ], [
            'ctaBanco' => 'banco',
            'ctaTelefono' => 'teléfono',
            'ctaNumero' => 'número de cuenta',
            'ctaTitular' => 'titular',
            'ctaDocTipo' => 'tipo de documento',
            'ctaDocNumero' => 'documento',
        ]);

        $registro = $this->cuentaId ? $this->cuentaDelMedico((int) $this->cuentaId) : null;

        try {
            $cuenta = $cuentas->guardar((string) $this->medico->reg_medico, [
                'banco_codigo' => $this->ctaBanco,
                'telefono' => $this->ctaTelefono,
                'numero' => $this->ctaNumero,
                'titular' => $this->ctaTitular,
                'documento_tipo' => $this->ctaDocTipo,
                'documento_numero' => $this->ctaDocNumero,
            ], $registro);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo, $mensajes[0]);
            }

            return;
        }

        $this->cerrarCuentaForm();
        session()->flash('message', 'Guardamos la cuenta de ' . $cuenta->bancoNombre() . '.');
    }

    public function predeterminarCuenta(int $id, CuentasDeCobroDelMedico $cuentas): void
    {
        $cuenta = $this->cuentaDelMedico($id);
        $cuentas->predeterminar($cuenta);

        session()->flash('message', 'Ahora cobra con ' . $cuenta->bancoNombre() . ' por defecto.');
    }

    public function alternarCuenta(int $id, CuentasDeCobroDelMedico $cuentas): void
    {
        $cuenta = $this->cuentaDelMedico($id);
        $cuentas->alternarActiva($cuenta);

        session()->flash('message', 'Cuenta ' . $cuenta->bancoNombre() . ($cuenta->activa ? ' activada.' : ' desactivada.'));
    }

    public function guardarRecaudador(CuentasDeCobroDelMedico $cuentas): void
    {
        $this->validate(['recaudador' => ['required', Rule::in([PreferenciaDeCobro::PLATAFORMA, PreferenciaDeCobro::MEDICO])]]);

        try {
            $elegido = $cuentas->preferencia((string) $this->medico->reg_medico, $this->recaudador);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo, $mensajes[0]);
            }

            return;
        }

        $this->recaudador = $elegido;
        session()->flash('message', $elegido === PreferenciaDeCobro::MEDICO
            ? 'Sus citas se cobran en sus cuentas.'
            : 'Sus citas se cobran con la cuenta de la plataforma.');
    }

    private function cuentaDelMedico(int $id): CuentaBancaria
    {
        return CuentaBancaria::where('propietario', CuentaBancaria::MEDICO)
            ->where('reg_medico', (string) $this->medico->reg_medico)
            ->whereKey($id)
            ->firstOrFail();
    }
}
