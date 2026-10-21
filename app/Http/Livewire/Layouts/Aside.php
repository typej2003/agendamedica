<?php

namespace App\Http\Livewire\Layouts;

use App\Models\Comentario;
use App\Models\Pago;
use App\Models\SolicitudMedico;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Aside extends Component
{
    /**
     * Controla si el sidebar está minimizado o no.
     * @var bool
     */
    public $isMinimized = false;

    /**
     * Alterna el estado del sidebar entre minimizado y expandido.
     */
    public function toggleMinimize()
    {
        $this->isMinimized = !$this->isMinimized;
    }

    /**
     * Renderiza la vista del componente.
     */
    public function render()
    {
        return view('livewire.layouts.aside', [
            'avisos' => $this->avisos(),
        ]);
    }

    /**
     * Lo que espera al administrador en cada bandeja, para el número junto al ítem del menú: pagos sin
     * confirmar (pendientes o en proceso), solicitudes de médicos sin responder y comentarios nuevos de
     * «Ayúdanos a mejorar». Son tres `count()` y sólo se calculan para quien ve esas bandejas.
     *
     * @return array{pagos: int, solicitudes: int, comentarios: int}
     */
    private function avisos(): array
    {
        $usuario = Auth::user();

        if (! $usuario || ! $usuario->esAdministrador()) {
            return ['pagos' => 0, 'solicitudes' => 0, 'comentarios' => 0];
        }

        return [
            'pagos' => Pago::whereIn('estado', [Pago::PENDIENTE, Pago::EN_PROCESO])->count(),
            'solicitudes' => SolicitudMedico::where('estado', SolicitudMedico::PENDIENTE)->count(),
            'comentarios' => Comentario::where('estado', Comentario::NUEVO)->count(),
        ];
    }
}