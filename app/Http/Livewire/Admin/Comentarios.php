<?php

namespace App\Http\Livewire\Admin;

use App\Actions\Comentarios\DescartarComentario;
use App\Actions\Comentarios\ResponderComentario;
use App\Models\Comentario;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * **Bandeja de "Ayúdanos a mejorar"** (ROADMAP §9): los comentarios que nos mandan desde la web clínica, para
 * responderlos o descartarlos.
 *
 * Abre en **nuevos** —lo que espera respuesta—, con las pestañas para ver los respondidos y los descartados:
 * es lo que permite ir marcando lo ya atendido sin perder de vista lo que falta. Igual que `Cuentas` y
 * `ApiKeys`, el permiso se vuelve a comprobar en cada acción de Livewire (`hydrate`), no sólo al entrar.
 */
class Comentarios extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /** `nuevo` | `respondido` | `descartado` | `todos`. */
    public $estado = Comentario::NUEVO;

    /** El comentario abierto, si hay uno. */
    public $comentarioId = null;

    /** Lo que se está escribiendo como respuesta. */
    public $respuesta = '';

    public function mount(): void
    {
        $this->autorizar();
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

    public function updatingEstado(): void
    {
        $this->resetPage();
        $this->cerrar();
    }

    public function abrir(int $comentarioId): void
    {
        $comentario = Comentario::findOrFail($comentarioId);
        $this->comentarioId = $comentario->id;
        // Si ya tenía respuesta, se abre con ella para poder corregirla.
        $this->respuesta = (string) $comentario->respuesta;
        $this->resetErrorBag();
    }

    public function cerrar(): void
    {
        $this->comentarioId = null;
        $this->respuesta = '';
        $this->resetErrorBag();
    }

    public function responder(ResponderComentario $responder): void
    {
        $this->validate(
            ['respuesta' => ['required', 'string', 'min:5', 'max:2000']],
            [
                'respuesta.required' => 'Escribí la respuesta.',
                'respuesta.min' => 'La respuesta es muy corta (al menos 5 caracteres).',
                'respuesta.max' => 'La respuesta es muy larga (hasta 2000 caracteres).',
            ],
        );

        $responder->ejecutar(Comentario::findOrFail($this->comentarioId), auth()->user(), $this->respuesta);

        session()->flash('message', 'Respuesta enviada: el usuario la ve en «Ayúdanos a mejorar».');
        $this->cerrar();
    }

    public function descartar(int $comentarioId, DescartarComentario $descartar): void
    {
        $descartar->ejecutar(Comentario::findOrFail($comentarioId));

        session()->flash('message', 'Comentario descartado: queda guardado, fuera de la lista de los nuevos.');
        if ($this->comentarioId === $comentarioId) {
            $this->cerrar();
        }
    }

    /** Devuelve un comentario a la lista de nuevos (nos equivocamos, o volvió a aparecer el problema). */
    public function reabrir(int $comentarioId): void
    {
        Comentario::findOrFail($comentarioId)->update([
            'estado' => Comentario::NUEVO,
            'respuesta' => null,
            'respondido_por' => null,
            'respondido_el' => null,
        ]);

        session()->flash('message', 'El comentario volvió a la lista de los nuevos.');
        if ($this->comentarioId === $comentarioId) {
            $this->cerrar();
        }
    }

    /** Cuántos hay en cada estado, para las pestañas. */
    public function conteos(): array
    {
        $conteos = Comentario::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->all();

        foreach (Comentario::ESTADOS as $estado) {
            $conteos[$estado] = (int) ($conteos[$estado] ?? 0);
        }

        return $conteos;
    }

    public function render()
    {
        $comentarios = Comentario::query()
            ->with(['usuario', 'medico'])
            ->when($this->estado !== 'todos', fn ($query) => $query->where('estado', $this->estado))
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('livewire.admin.comentarios', [
            'comentarios' => $comentarios,
            'conteos' => $this->conteos(),
            'seleccionado' => $this->comentarioId
                ? Comentario::with(['usuario', 'medico', 'respondedor'])->find($this->comentarioId)
                : null,
        ]);
    }
}
