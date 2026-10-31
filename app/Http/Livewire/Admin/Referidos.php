<?php

namespace App\Http\Livewire\Admin;

use App\Models\ConfiguracionLatidos;
use App\Models\Referido;
use App\Referidos\AnularReferido;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * **Referidos** (`/admin/referidos`, fase 4): la configuración del programa y la lista de referidos.
 *
 * El premio se configura en Latidos (no en dólares): cuánto gana el referente, cuánto el referido, cuánto
 * dura la atribución y el tope mensual. La anulación devuelve los Latidos a los dos lados; si alguno ya los
 * gastó, no se fuerza el negativo y se avisa.
 */
class Referidos extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $referidosActivos = true;
    public $latidosReferente = 2000;
    public $latidosReferido = 2000;
    public $vigenciaDias = 90;
    public $topeMensual = 0;
    public $urlDescarga = '';

    public $referidoId = null;
    public $motivoAnulacion = '';

    public function mount(): void
    {
        $this->autorizar();
        $this->cargarConfiguracion();
    }

    public function hydrate(): void
    {
        $this->autorizar();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    private function cargarConfiguracion(): void
    {
        $config = ConfiguracionLatidos::actual();
        $this->referidosActivos = $config->referidosActivos();
        $this->latidosReferente = (int) $config->latidos_referente;
        $this->latidosReferido = (int) $config->latidos_referido;
        $this->vigenciaDias = (int) $config->vigencia_atribucion_dias;
        $this->topeMensual = (int) $config->tope_mensual_por_referente;
        $this->urlDescarga = (string) ($config->url_descarga ?? '');
    }

    public function guardarConfiguracion(): void
    {
        $this->autorizar();

        $datos = $this->validate([
            'referidosActivos' => ['boolean'],
            'latidosReferente' => ['required', 'integer', 'min:0', 'max:1000000'],
            'latidosReferido' => ['required', 'integer', 'min:0', 'max:1000000'],
            'vigenciaDias' => ['required', 'integer', 'min:1', 'max:365'],
            'topeMensual' => ['required', 'integer', 'min:0', 'max:1000'],
            'urlDescarga' => [
                'nullable', 'string', 'max:255',
                function (string $atributo, $valor, \Closure $fallar) {
                    if ($valor !== null && $valor !== '' && ! filter_var($valor, FILTER_VALIDATE_URL)) {
                        $fallar('El link de descarga tiene que ser una URL completa.');
                    }
                },
            ],
        ], [
            'latidosReferente.required' => 'Escribe cuántos Latidos gana el referente.',
            'latidosReferido.required' => 'Escribe cuántos Latidos gana el referido.',
            'vigenciaDias.min' => 'La atribución tiene que durar al menos un día.',
        ]);

        ConfiguracionLatidos::actual()->update([
            'referidos_activos' => (bool) $datos['referidosActivos'],
            'latidos_referente' => (int) $datos['latidosReferente'],
            'latidos_referido' => (int) $datos['latidosReferido'],
            'vigencia_atribucion_dias' => (int) $datos['vigenciaDias'],
            'tope_mensual_por_referente' => (int) $datos['topeMensual'],
            'url_descarga' => trim((string) ($datos['urlDescarga'] ?? '')) ?: null,
        ]);

        $this->cargarConfiguracion();
        session()->flash('message', 'Configuración de referidos guardada.');
    }

    public function abrir(int $referidoId): void
    {
        $this->referidoId = Referido::findOrFail($referidoId)->id;
        $this->motivoAnulacion = '';
        $this->resetErrorBag();
    }

    public function cerrar(): void
    {
        $this->referidoId = null;
        $this->motivoAnulacion = '';
        $this->resetErrorBag();
    }

    public function anular(AnularReferido $accion): void
    {
        $this->autorizar();
        $datos = $this->validate([
            'motivoAnulacion' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'motivoAnulacion.required' => 'Escribe el motivo de la anulación.',
            'motivoAnulacion.min' => 'El motivo tiene que explicar la anulación.',
        ]);

        $referido = Referido::findOrFail($this->referidoId);

        try {
            $accion->ejecutar($referido, $datos['motivoAnulacion'], auth()->user());
        } catch (ValidationException $e) {
            $this->addError('motivoAnulacion', $e->validator->errors()->first('referido') ?: 'No se pudo anular.');
            return;
        } catch (RuntimeException $e) {
            $this->addError('motivoAnulacion', $e->getMessage());
            return;
        }

        session()->flash('message', 'Referido anulado y Latidos revertidos.');
        $this->cerrar();
    }

    public function render()
    {
        return view('livewire.admin.referidos', [
            'referidos' => Referido::with(['referente', 'referido'])->latest('id')->paginate(20),
            'seleccionado' => $this->referidoId ? Referido::with(['referente', 'referido'])->find($this->referidoId) : null,
            'totalAcreditado' => (int) Referido::where('estado', Referido::ACREDITADO)->count(),
            'totalRechazado' => (int) Referido::where('estado', Referido::RECHAZADO)->count(),
            'totalAnulado' => (int) Referido::where('estado', Referido::ANULADO)->count(),
        ]);
    }
}
