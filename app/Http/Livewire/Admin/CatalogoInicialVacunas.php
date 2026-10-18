<?php

namespace App\Http\Livewire\Admin;

use App\Clinica\Vacunas\ConvencionDeVacunas;
use App\Models\Vacuna;
use App\Models\VacunaDosis;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * **Catálogo inicial · Vacunas** (WEB-12.8): el ABM, desde el panel, de las vacunas y las dosis que el sistema le entrega a cada médico de
 * pediatría como punto de partida (decisión de Alexander, 2026-10-17: cada médico tiene su propia configuración y el catálogo inicial lo armamos
 * nosotros). Opera **únicamente sobre las filas sin dueño** (`reg_medico` NULL) de `vacunas` y `vacunas_dosis`: jamás toca las de un
 * consultorio. La copia al médico es `App\Actions\Catalogos\CopiarVacunasIniciales`.
 *
 * Como las demás pantallas del panel, `hydrate()` vuelve a comprobar el permiso en cada acción de Livewire (Root o Administrador).
 */
class CatalogoInicialVacunas extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';

    /** Ventana abierta: `vacuna` | `dosis` | `borrar` | null */
    public $modal = null;

    /** La vacuna inicial sobre la que se trabaja (`null` = alta) y los campos de su formulario. */
    public $vacunaId = null;
    public $codi_vacuna = '';
    public $vacuna = '';
    public $edad_inicial_meses = '';
    public $fija = 'O';
    public $sexo = 'A';
    public $cobertura = '';

    /** La dosis que se edita (`null` = alta) y los campos de su formulario. */
    public $dosisId = null;
    public $dosis_numero = '';
    public $dosis_descri = '';
    public $meses = '';

    /** Casilla de «confirmo» al borrar una vacuna que tiene dosis. */
    public $confirmaBorrado = false;

    public function hydrate()
    {
        $this->autorizar();
    }

    public function mount()
    {
        $this->autorizar();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $busqueda = trim($this->search);

        $vacunas = Vacuna::query()->whereNull('reg_medico')
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('vacuna', 'like', "%{$busqueda}%")->orWhere('codi_vacuna', 'like', "%{$busqueda}%")))
            ->orderBy('edad_inicial_meses')->orderBy('vacuna')
            ->paginate(15);

        $dosis = VacunaDosis::query()->whereNull('reg_medico')
            ->whereIn('codi_vacuna', $vacunas->pluck('codi_vacuna'))
            ->orderBy('dosis')->get()->groupBy('codi_vacuna');

        $seleccionada = $this->vacunaId ? Vacuna::whereNull('reg_medico')->find($this->vacunaId) : null;

        return view('livewire.admin.catalogo-inicial-vacunas', [
            'vacunas' => $vacunas,
            'dosisPorVacuna' => $dosis,
            'seleccionada' => $seleccionada,
            'dosisDeLaSeleccionada' => $seleccionada
                ? VacunaDosis::whereNull('reg_medico')->where('codi_vacuna', $seleccionada->codi_vacuna)->orderBy('dosis')->get()
                : collect(),
            'total' => Vacuna::whereNull('reg_medico')->count(),
        ]);
    }

    /* ------------------------------------------------------------------ Vacuna */

    public function abrirVacuna($id = null)
    {
        $this->resetErrorBag();
        $this->reset(['codi_vacuna', 'vacuna', 'edad_inicial_meses', 'cobertura']);
        $this->fija = 'O';
        $this->sexo = 'A';
        $this->vacunaId = null;

        if ($id) {
            $v = $this->inicial((int) $id);
            $this->vacunaId = $v->id;
            $this->codi_vacuna = (string) $v->codi_vacuna;
            $this->vacuna = (string) $v->vacuna;
            $this->edad_inicial_meses = $v->edad_inicial_meses === null ? '' : (string) $v->edad_inicial_meses;
            $this->fija = $v->fija ?: 'O';
            $this->sexo = $v->sexo ?: 'A';
            $this->cobertura = (string) $v->cobertura;
        }

        $this->modal = 'vacuna';
    }

    public function guardarVacuna()
    {
        $this->codi_vacuna = strtoupper(trim((string) $this->codi_vacuna));

        $this->validate([
            'codi_vacuna' => ['required', 'string', 'max:4'],
            'vacuna' => ['required', 'string', 'max:100'],
            'edad_inicial_meses' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'fija' => ['required', 'in:' . implode(',', array_keys(ConvencionDeVacunas::FIJA))],
            'sexo' => ['nullable', 'in:' . implode(',', array_keys(ConvencionDeVacunas::SEXO))],
            'cobertura' => ['nullable', 'string', 'max:200'],
        ], $this->avisosDeVacuna());

        $repetida = Vacuna::whereNull('reg_medico')->where('codi_vacuna', $this->codi_vacuna)
            ->when($this->vacunaId, fn ($q) => $q->where('id', '!=', $this->vacunaId))->exists();

        if ($repetida) {
            $this->addError('codi_vacuna', 'Ya hay una vacuna inicial con ese código.');

            return;
        }

        $datos = [
            'codi_vacuna' => $this->codi_vacuna,
            'vacuna' => trim($this->vacuna),
            'edad_inicial_meses' => $this->edad_inicial_meses === '' || $this->edad_inicial_meses === null ? null : (int) $this->edad_inicial_meses,
            'fija' => $this->fija,
            'sexo' => $this->sexo ?: null,
            'cobertura' => trim((string) $this->cobertura) === '' ? null : trim($this->cobertura),
        ];

        $editando = (bool) $this->vacunaId;

        DB::transaction(function () use ($datos) {
            if ($this->vacunaId) {
                $v = $this->inicial((int) $this->vacunaId);
                $anterior = $v->codi_vacuna;
                $v->update($datos);
                // Si cambia el código, sus dosis iniciales lo siguen (las de un médico no se tocan).
                if ($anterior !== $datos['codi_vacuna']) {
                    VacunaDosis::whereNull('reg_medico')->where('codi_vacuna', $anterior)->update(['codi_vacuna' => $datos['codi_vacuna']]);
                }
            } else {
                Vacuna::create($datos + ['reg_medico' => null]);
            }
        });

        session()->flash('message', $editando ? 'Vacuna inicial actualizada.' : 'Vacuna inicial agregada.');
        $this->cerrar();
    }

    /* ------------------------------------------------------------------ Borrar */

    public function abrirBorrar($id)
    {
        $v = $this->inicial((int) $id);
        $this->vacunaId = $v->id;
        $this->confirmaBorrado = false;
        $this->resetErrorBag();
        $this->modal = 'borrar';
    }

    public function borrarVacuna()
    {
        $v = $this->inicial((int) $this->vacunaId);
        $dosis = VacunaDosis::whereNull('reg_medico')->where('codi_vacuna', $v->codi_vacuna)->count();

        if ($dosis > 0 && ! $this->confirmaBorrado) {
            $this->addError('confirmaBorrado', "Tiene {$dosis} dosis: marca la casilla para confirmar que se borran junto con la vacuna.");

            return;
        }

        DB::transaction(function () use ($v) {
            VacunaDosis::whereNull('reg_medico')->where('codi_vacuna', $v->codi_vacuna)->delete();
            $v->delete();
        });

        session()->flash('message', 'Vacuna inicial eliminada. Los consultorios que ya la copiaron la conservan.');
        $this->cerrar();
    }

    /* ------------------------------------------------------------------ Dosis */

    public function abrirDosis($id)
    {
        $v = $this->inicial((int) $id);
        $this->vacunaId = $v->id;
        $this->limpiarDosis();
        $this->modal = 'dosis';
    }

    public function editarDosis($id)
    {
        $d = $this->dosisInicial((int) $id);
        $this->dosisId = $d->id;
        $this->dosis_numero = (string) $d->dosis;
        $this->dosis_descri = (string) $d->dosis_descri;
        $this->meses = $d->meses === null ? '' : rtrim(rtrim(number_format((float) $d->meses, 2, '.', ''), '0'), '.');
        $this->resetErrorBag();
    }

    public function nuevaDosis()
    {
        $this->limpiarDosis();
    }

    public function guardarDosis()
    {
        $v = $this->inicial((int) $this->vacunaId);

        $this->validate([
            'dosis_numero' => ['required', 'integer', 'min:1', 'max:99'],
            'dosis_descri' => ['nullable', 'string', 'max:50'],
            'meses' => ['nullable', 'numeric', 'min:0', 'max:1200'],
        ], [
            'dosis_numero.required' => 'Escribe el número de la dosis.',
            'dosis_numero.integer' => 'El número de la dosis tiene que ser un entero.',
            'dosis_numero.min' => 'El número de la dosis empieza en 1.',
            'dosis_numero.max' => 'El número de la dosis no puede pasar de 99.',
            'dosis_descri.max' => 'La descripción no puede pasar de 50 caracteres.',
            'meses.numeric' => 'Los meses tienen que ser un número.',
            'meses.min' => 'Los meses no pueden ser negativos.',
            'meses.max' => 'Revisa los meses: es demasiado.',
        ]);

        $repetida = VacunaDosis::whereNull('reg_medico')->where('codi_vacuna', $v->codi_vacuna)->where('dosis', (int) $this->dosis_numero)
            ->when($this->dosisId, fn ($q) => $q->where('id', '!=', $this->dosisId))->exists();

        if ($repetida) {
            $this->addError('dosis_numero', 'Esta vacuna ya tiene una dosis con ese número.');

            return;
        }

        $datos = [
            'dosis' => (int) $this->dosis_numero,
            'dosis_descri' => trim((string) $this->dosis_descri) === '' ? null : trim($this->dosis_descri),
            'meses' => $this->meses === '' || $this->meses === null ? null : (float) $this->meses,
        ];

        if ($this->dosisId) {
            $this->dosisInicial((int) $this->dosisId)->update($datos);
        } else {
            VacunaDosis::create($datos + ['reg_medico' => null, 'codi_vacuna' => $v->codi_vacuna]);
        }

        session()->flash('message', 'Dosis guardada.');
        $this->limpiarDosis();
    }

    public function quitarDosis($id)
    {
        $this->dosisInicial((int) $id)->delete();
        session()->flash('message', 'Dosis eliminada.');
        $this->limpiarDosis();
    }

    private function limpiarDosis(): void
    {
        $this->reset(['dosisId', 'dosis_numero', 'dosis_descri', 'meses']);
        $this->resetErrorBag();
    }

    public function cerrar()
    {
        $this->modal = null;
        $this->vacunaId = null;
        $this->confirmaBorrado = false;
        $this->limpiarDosis();
    }

    /* ------------------------------------------------------------------ Adentro */

    /** La vacuna **inicial** (sin dueño): una con dueño no se encuentra, así que ninguna acción llega a ella. */
    private function inicial(int $id): Vacuna
    {
        return Vacuna::whereNull('reg_medico')->findOrFail($id);
    }

    private function dosisInicial(int $id): VacunaDosis
    {
        return VacunaDosis::whereNull('reg_medico')->findOrFail($id);
    }

    /** @return array<string,string> */
    private function avisosDeVacuna(): array
    {
        return [
            'codi_vacuna.required' => 'Escribe el código de la vacuna.',
            'codi_vacuna.max' => 'El código no puede pasar de 4 caracteres.',
            'vacuna.required' => 'Escribe el nombre de la vacuna.',
            'vacuna.max' => 'El nombre no puede pasar de 100 caracteres.',
            'edad_inicial_meses.integer' => 'La edad inicial va en meses, con un número entero.',
            'edad_inicial_meses.min' => 'La edad inicial no puede ser negativa.',
            'edad_inicial_meses.max' => 'Revisa la edad inicial: es demasiado.',
            'fija.required' => 'Elige si es obligatoria o recomendada.',
            'fija.in' => 'Elige si es obligatoria o recomendada.',
            'sexo.in' => 'El sexo elegido no es válido.',
            'cobertura.max' => 'La cobertura no puede pasar de 200 caracteres.',
        ];
    }
}
