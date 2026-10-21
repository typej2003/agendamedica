<?php

namespace App\Http\Livewire\Admin\Sedes;

use App\Actions\Consultorio\GuardarConsultorio;
use App\Actions\Consultorio\GuardarSede;
use App\Clinica\Consultorio\SedesDeLaClinica;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Panel · **Sedes del médico** (`/admin/medicos/{medico}/sedes`): el primer nivel de «dónde atiende», para que un
 * administrador ayude al médico a configurarse (sedes → consultorios → horarios y cupos).
 *
 * La sede es **del catálogo de la clínica** (la comparten todos los que atienden ahí), así que acá:
 *
 *  - **Agregar** es elegir una sede del catálogo (o registrar una nueva con `GuardarSede`) **y su primer
 *    consultorio** (`GuardarConsultorio`): sin consultorio la sede no es «del médico».
 *  - **Editar** cambia la ficha de la sede para todos (se avisa cuántos otros médicos la usan).
 *  - **Quitar** da de baja **los consultorios del médico** en esa sede (`activo = false`, la regla de
 *    `GuardarConsultorio`: nunca se borran) y no toca la sede, que puede ser de otros.
 */
class SedesDelMedico extends Component
{
    use ConfiguraUnMedico;

    public $verInactivas = false;

    /** Ventana abierta: `agregar` | `editar` | `quitar` | null */
    public $modal = null;
    public $sedeId = null;

    /** Agregar: `existente` (del catálogo) o `nueva`. */
    public $modo = 'existente';
    public $sede_existente_id = '';

    /** Ficha de la sede (nueva o en edición). */
    public $name = '';
    public $address = '';
    public $phone = '';
    public $city_id = '';

    /** Primer consultorio (sólo en «Agregar»). */
    public $office_number = '';
    public $office_phone = '';
    public $modalidad = Office::MODALIDAD_ORDEN;
    public $duracion_cita = '';

    public function mount(Medico $medico)
    {
        $this->autorizar();
        $this->medico = $medico;
    }

    public function render(SedesDeLaClinica $catalogo)
    {
        $tenant = $this->lector()->tenant($this->medico);
        $seleccionada = $this->sedeId ? MedicalCenter::with('city')->find($this->sedeId) : null;

        return view('livewire.admin.sedes.sedes-del-medico', [
            'tenant'       => $tenant,
            'nombre'       => $this->nombreDelMedico(),
            'sedes'        => $tenant ? $this->lector()->sedes($this->medico, (bool) $this->verInactivas) : collect(),
            'catalogo'     => $this->modal === 'agregar' ? $this->lector()->sedesDelCatalogo() : collect(),
            'ciudades'     => in_array($this->modal, ['agregar', 'editar'], true) ? $catalogo->ciudades() : collect(),
            'modalidades'  => Office::modalidades(),
            'seleccionada' => $seleccionada,
            'otros'        => $seleccionada ? $this->lector()->otrosMedicosEnLaSede($this->medico, $seleccionada) : 0,
            'aQuitar'      => $this->modal === 'quitar' && $seleccionada
                ? $this->lector()->consultoriosEn($this->medico, $seleccionada, false)
                : collect(),
            'citas'        => $this->modal === 'quitar' && $seleccionada
                ? $this->lector()->citasDesdeHoy($this->medico, $seleccionada)
                : 0,
        ]);
    }

    /* ------------------------------ Agregar ------------------------------ */

    public function abrirAgregar()
    {
        $this->tenantOFallar();
        $this->limpiar();
        $this->modal = 'agregar';
    }

    public function guardarAlta(GuardarSede $guardarSede, GuardarConsultorio $guardarConsultorio)
    {
        $tenant = $this->tenantOFallar();

        $reglas = $this->reglasDelConsultorio();
        if ($this->modo === 'nueva') {
            $reglas += $this->reglasDeLaSede(null);
        } else {
            $reglas['sede_existente_id'] = ['required', 'integer', Rule::exists('medical_centers', 'id')->where('activo', true)];
        }

        $this->validate($reglas, $this->mensajes());

        try {
            $sede = DB::transaction(function () use ($guardarSede, $guardarConsultorio, $tenant) {
                $sede = $this->modo === 'nueva'
                    ? $guardarSede->ejecutar($this->datosDeLaSede(true))
                    : MedicalCenter::activos()->findOrFail((int) $this->sede_existente_id);

                $guardarConsultorio->ejecutar([
                    'medical_center_id' => $sede->id,
                    'office_number'     => $this->office_number,
                    'phone'             => $this->office_phone,
                    'modalidad'         => $this->modalidad,
                    'duracion_cita'     => $this->duracion_cita === '' || $this->duracion_cita === null ? null : (int) $this->duracion_cita,
                    'activo'            => true,
                ], $this->medico->id, $tenant);

                return $sede;
            });
        } catch (ValidationException $e) {
            $this->mostrarErrores($e, ['phone' => 'office_phone']);

            return;
        }

        session()->flash('message', "Sede {$sede->name} agregada a {$this->nombreDelMedico()} con el consultorio "
            . trim((string) $this->office_number) . '. Sigue con sus horarios desde «Ver consultorios».');
        $this->cerrarModal();
    }

    /* ------------------------------ Editar ------------------------------- */

    public function abrirEditar(int $sedeId)
    {
        $sede = $this->sedeDelMedico($sedeId);

        $this->limpiar();
        $this->sedeId = $sede->id;
        $this->name = (string) $sede->name;
        $this->address = (string) $sede->address;
        $this->phone = (string) $sede->phone;
        $this->city_id = (string) $sede->city_id;
        $this->modal = 'editar';
    }

    public function guardarEdicion(GuardarSede $guardarSede)
    {
        $sede = $this->sedeDelMedico((int) $this->sedeId);

        $this->validate($this->reglasDeLaSede($sede->id), $this->mensajes());

        // `GuardarSede` reactiva la sede si no le llega `activo`: se le pasa el que tiene, porque editar la
        // dirección no es reabrir una sede que alguien cerró.
        $guardarSede->ejecutar($this->datosDeLaSede((bool) $sede->activo), $sede);

        session()->flash('message', 'Sede ' . trim((string) $this->name) . ' actualizada.');
        $this->cerrarModal();
    }

    /* ------------------------------ Quitar ------------------------------- */

    public function abrirQuitar(int $sedeId)
    {
        $sede = $this->sedeDelMedico($sedeId);

        $this->limpiar();
        $this->sedeId = $sede->id;
        $this->modal = 'quitar';
    }

    /**
     * Da de baja los consultorios **del médico** en la sede. No se borra nada: el consultorio define la jornada
     * de las citas que ya tiene (`GuardarConsultorio`), y la sede es del catálogo.
     */
    public function confirmarQuitar()
    {
        $sede = $this->sedeDelMedico((int) $this->sedeId);

        $consultorios = $this->lector()->consultoriosEn($this->medico, $sede, false);
        foreach ($consultorios as $consultorio) {
            $consultorio->update(['activo' => false]);
        }

        $n = $consultorios->count();
        session()->flash('message', "Sede {$sede->name} quitada a {$this->nombreDelMedico()}: "
            . $n . ($n === 1 ? ' consultorio dado' : ' consultorios dados') . ' de baja. '
            . 'Se reactivan desde «Ver inactivas» › «Ver consultorios».');
        $this->cerrarModal();
    }

    public function cerrarModal()
    {
        $this->limpiar();
        $this->modal = null;
    }

    /* ------------------------------ Ayudas ------------------------------- */

    /** Una sede donde el médico tiene (o tuvo) consultorio; cualquier otra, 404. */
    private function sedeDelMedico(int $sedeId): MedicalCenter
    {
        $this->tenantOFallar();

        abort_unless(
            $this->lector()->consultorios($this->medico)->where('medical_center_id', $sedeId)->exists(),
            404
        );

        return MedicalCenter::findOrFail($sedeId);
    }

    private function limpiar(): void
    {
        $this->resetErrorBag();
        $this->sedeId = null;
        $this->modo = 'existente';
        $this->sede_existente_id = '';
        $this->name = '';
        $this->address = '';
        $this->phone = '';
        $this->city_id = '';
        $this->office_number = '';
        $this->office_phone = '';
        $this->duracion_cita = '';
        $this->modalidad = Office::MODALIDAD_ORDEN;
    }

    private function datosDeLaSede(bool $activo): array
    {
        return [
            'name'    => $this->name,
            'address' => $this->address,
            'phone'   => $this->phone,
            'city_id' => (int) $this->city_id,
            'activo'  => $activo,
        ];
    }

    /** Las mismas reglas que `/clinica/sedes` (`SedeController::guardar`). */
    private function reglasDeLaSede(?int $ignorar): array
    {
        return [
            'name'    => ['required', 'string', 'max:200', Rule::unique('medical_centers', 'name')->ignore($ignorar)],
            'address' => ['required', 'string', 'max:1000'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
        ];
    }

    /** Las mismas reglas que `/clinica/consultorios` (`ConsultorioController::guardar`). */
    private function reglasDelConsultorio(): array
    {
        return [
            'office_number' => ['required', 'string', 'max:50'],
            'office_phone'  => ['nullable', 'string', 'max:50'],
            'modalidad'     => ['required', 'in:' . implode(',', array_keys(Office::modalidades()))],
            'duracion_cita' => ['nullable', 'integer', 'min:5', 'max:480'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'name.required'              => 'Pon el nombre de la sede.',
            'name.unique'                => 'Ya hay una sede con ese nombre: elígela en «Sede existente».',
            'address.required'           => 'La dirección es obligatoria: es lo que identifica al lugar.',
            'city_id.required'           => 'Elige la ciudad.',
            'sede_existente_id.required' => 'Elige la sede.',
            'sede_existente_id.exists'   => 'Esa sede no existe o está desactivada.',
            'office_number.required'     => 'Pon el número o el nombre del consultorio.',
            'duracion_cita.min'          => 'La duración tiene que ser de al menos 5 minutos.',
        ];
    }
}
