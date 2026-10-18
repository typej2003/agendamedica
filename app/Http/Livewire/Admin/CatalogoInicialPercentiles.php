<?php

namespace App\Http\Livewire\Admin;

use App\Actions\Catalogos\GuardarPercentilInicial;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Component;

/**
 * **Catálogo inicial · Referencia de crecimiento** (WEB-7.4.3e): el ABM, desde el panel, de las filas de `percentil_nino` y `percentil_nina` sin dueño (`reg_medico` NULL),
 * que es la referencia que ve todo médico de pediatría que no cargó la suya (`ReferenciaDeCrecimiento`). Es la `d_modifi_percentiles` del escritorio. **No hay
 * edición por médico.** La regla vive en `GuardarPercentilInicial` (rangos sin solaparse, bajo ≤ medio ≤ alto); acá sólo se arma el formulario.
 *
 * Como las demás pantallas del panel, `hydrate()` vuelve a comprobar el permiso en cada acción de Livewire (Root o Administrador).
 */
class CatalogoInicialPercentiles extends Component
{
    /** `nino` | `nina` */
    public $sexo = 'nino';

    /** Ventana abierta: `fila` | `borrar` | null */
    public $modal = null;

    public $filaId = null;

    /** @var array<string,string> los campos del formulario (`edad_ini_dia`, `edad_fin_dia`, `edad_texto`, `edad_meses`, `anos` y `<medida>_b|m|a`) */
    public $f = [];

    public function hydrate()
    {
        $this->autorizar();
    }

    public function mount()
    {
        $this->autorizar();
        $this->f = $this->vacio();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    public function render()
    {
        $filas = $this->tabla()->whereNull('reg_medico')->orderBy('edad_ini_dia')->get();

        return view('livewire.admin.catalogo-inicial-percentiles', [
            'filas' => $filas,
            'huecos' => GuardarPercentilInicial::huecos($filas),
            'medidas' => GuardarPercentilInicial::MEDIDAS,
            'cuentas' => ['nino' => DB::table('percentil_nino')->whereNull('reg_medico')->count(), 'nina' => DB::table('percentil_nina')->whereNull('reg_medico')->count()],
        ]);
    }

    public function elegir(string $sexo)
    {
        if (isset(GuardarPercentilInicial::TABLAS[$sexo])) {
            $this->sexo = $sexo;
        }
    }

    public function abrir($id = null)
    {
        $this->resetErrorBag();
        $this->f = $this->vacio();
        $this->filaId = null;

        if ($id) {
            $fila = $this->tabla()->whereNull('reg_medico')->where('id', (int) $id)->first();
            abort_if($fila === null, 404);
            $this->filaId = $fila->id;
            foreach (array_keys($this->f) as $campo) {
                $valor = $fila->{$campo} ?? null;
                $this->f[$campo] = $valor === null ? '' : rtrim(rtrim(number_format((float) $valor, 3, '.', ''), '0'), '.');
            }
            $this->f['edad_texto'] = (string) $fila->edad_texto;
        }

        $this->modal = 'fila';
    }

    public function guardar()
    {
        $this->resetErrorBag();

        try {
            app(GuardarPercentilInicial::class)->ejecutar($this->sexo, $this->f, $this->filaId ? (int) $this->filaId : null);
        } catch (InvalidArgumentException $e) {
            $this->addError('f.' . GuardarPercentilInicial::campoDelError($e->getMessage()), $e->getMessage());

            return;
        }

        session()->flash('message', $this->filaId ? 'Fila de la referencia actualizada.' : 'Fila agregada a la referencia.');
        $this->cerrar();
    }

    public function abrirBorrar($id)
    {
        $this->filaId = (int) $id;
        $this->resetErrorBag();
        $this->modal = 'borrar';
    }

    public function borrar()
    {
        try {
            app(GuardarPercentilInicial::class)->quitar($this->sexo, (int) $this->filaId);
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
            $this->cerrar();

            return;
        }

        session()->flash('message', 'Fila eliminada. Los consultorios con su propia referencia no cambian.');
        $this->cerrar();
    }

    public function cerrar()
    {
        $this->modal = null;
        $this->filaId = null;
        $this->resetErrorBag();
    }

    private function tabla()
    {
        return DB::table(GuardarPercentilInicial::TABLAS[$this->sexo]);
    }

    /** @return array<string,string> */
    private function vacio(): array
    {
        $campos = ['edad_ini_dia' => '', 'edad_fin_dia' => '', 'edad_texto' => '', 'edad_meses' => '', 'anos' => ''];

        foreach (array_keys(GuardarPercentilInicial::MEDIDAS) as $medida) {
            foreach (['b', 'm', 'a'] as $letra) {
                $campos[$medida . '_' . $letra] = '';
            }
        }

        return $campos;
    }
}
