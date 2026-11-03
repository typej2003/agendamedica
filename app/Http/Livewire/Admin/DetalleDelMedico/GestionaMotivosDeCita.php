<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Actions\Catalogos\CrearMotivoDeCita;
use App\Actions\Catalogos\EditarMotivoDeCita;
use App\Actions\Catalogos\QuitarMotivoDeCita;
use App\Models\MotivoCita;
use InvalidArgumentException;

/**
 * **Motivos de cita del médico**, administrados desde su detalle: el catálogo con el que se agenda, con su
 * precio, su sede y la marca de varias citas pendientes.
 *
 * Las reglas son las mismas Actions que la pantalla `/clinica/catalogos/motivos-cita` (código del servidor,
 * mayúsculas, no duplicar por sede y no borrar un motivo que alguna cita usa); acá sólo se acota al
 * `reg_medico` de este médico.
 */
trait GestionaMotivosDeCita
{
    /** Formulario abierto (null = lista). */
    public $motivoEditandoId = null;
    public $motivoForm = false;

    public $motivoRazon = '';
    public $motivoPrecio = '';
    public $motivoOffice = '';
    public $motivoVarias = false;

    public function abrirMotivoNuevo(): void
    {
        $this->resetErrorBag();
        $this->motivoEditandoId = null;
        $this->motivoRazon = '';
        $this->motivoPrecio = '';
        $this->motivoOffice = '';
        $this->motivoVarias = false;
        $this->motivoForm = true;
    }

    public function abrirMotivoEditar(int $id): void
    {
        $motivo = $this->motivoDelMedico($id);

        $this->resetErrorBag();
        $this->motivoEditandoId = $motivo->id;
        $this->motivoRazon = (string) $motivo->tipo_atencion;
        $this->motivoPrecio = $motivo->precio === null ? '' : (string) $motivo->precio;
        $this->motivoOffice = $motivo->office_id === null ? '' : (string) $motivo->office_id;
        $this->motivoVarias = (bool) $motivo->admite_varias_citas_pendientes;
        $this->motivoForm = true;
    }

    public function cerrarMotivo(): void
    {
        $this->motivoEditandoId = null;
        $this->motivoForm = false;
        $this->reset(['motivoRazon', 'motivoPrecio', 'motivoOffice', 'motivoVarias']);
        $this->resetErrorBag();
    }

    public function guardarMotivo(CrearMotivoDeCita $crear, EditarMotivoDeCita $editar): void
    {
        $this->validate([
            'motivoRazon' => 'nullable|string|max:300',
            'motivoPrecio' => 'nullable|numeric|min:0',
            'motivoOffice' => 'nullable|string|max:20',
        ], [
            'motivoRazon.max' => 'El nombre del motivo es demasiado largo.',
            'motivoPrecio.numeric' => 'El precio debe ser un número.',
            'motivoPrecio.min' => 'El precio no puede ser negativo.',
            'motivoOffice.max' => 'Elige una sede de la lista.',
        ], [
            'motivoRazon' => 'nombre del motivo',
            'motivoPrecio' => 'precio',
            'motivoOffice' => 'sede',
        ]);

        $entrada = [
            'razon' => $this->motivoRazon,
            'precio' => $this->motivoPrecio === '' ? null : $this->motivoPrecio,
            'office_id' => $this->motivoOffice === '' ? null : $this->motivoOffice,
            'admite_varias' => $this->motivoVarias ? '1' : '0',
        ];

        try {
            if ($this->motivoEditandoId === null) {
                $creado = $crear->ejecutar($this->medico, (string) $this->medico->reg_medico, $entrada);
                $razon = trim((string) $creado->tipo_atencion);
                session()->flash('message', 'Creaste «' . $razon . '» en los motivos de cita (código ' . trim((string) $creado->codigo) . ').');
            } else {
                $resultado = $editar->ejecutar(
                    $this->motivoDelMedico((int) $this->motivoEditandoId),
                    $this->medico,
                    $entrada,
                );
                $razon = trim((string) $resultado->fila->tipo_atencion);

                if ($resultado->huboConflicto()) {
                    $this->addError('motivoRazon', 'Otro equipo editó «' . $razon . '» más recientemente y no se pisó: vuelve a abrirlo y revisa los cambios.');

                    return;
                }

                session()->flash('message', $resultado->sinCambios()
                    ? '«' . $razon . '» no tenía cambios.'
                    : 'Guardaste «' . $razon . '».');
            }
        } catch (InvalidArgumentException $e) {
            $this->addError($this->campoDelErrorDelMotivo($e->getMessage()), $e->getMessage());

            return;
        }

        $this->cerrarMotivo();
    }

    public function alternarMotivo(int $id, EditarMotivoDeCita $editar): void
    {
        $motivo = $this->motivoDelMedico($id);
        $marcado = ! (bool) $motivo->admite_varias_citas_pendientes;

        $resultado = $editar->ejecutar($motivo, $this->medico, ['admite_varias' => $marcado]);
        $razon = trim((string) $motivo->tipo_atencion) !== '' ? trim((string) $motivo->tipo_atencion) : trim((string) $motivo->codigo);

        if ($resultado->huboConflicto()) {
            session()->flash('error', 'Otro equipo editó «' . $razon . '» más recientemente y no se pisó: revisa la lista.');

            return;
        }

        session()->flash('message', $marcado
            ? 'Listo: con «' . $razon . '» el paciente puede tener varias citas pendientes.'
            : 'Listo: con «' . $razon . '» se vuelve a avisar de la cita pendiente.');
    }

    public function quitarMotivo(int $id, QuitarMotivoDeCita $quitar): void
    {
        $resultado = $quitar->ejecutar($this->motivoDelMedico($id), []);

        if (! $resultado->quitado) {
            session()->flash('error', 'No se quitó «' . $resultado->nombre . '» porque está en ' . $resultado->enUso
                . '. Déjalo en el catálogo: esas citas lo siguen mostrando.');

            return;
        }

        session()->flash('message', 'Quitaste «' . $resultado->nombre . '» de los motivos de cita.');
    }

    /** Un motivo **de este médico**; de otro, 404. */
    private function motivoDelMedico(int $id): MotivoCita
    {
        return MotivoCita::with('office.medicalCenter')
            ->where('reg_medico', (string) $this->medico->reg_medico)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function campoDelErrorDelMotivo(string $mensaje): string
    {
        return match (true) {
            str_contains($mensaje, 'sede de la lista') => 'motivoOffice',
            str_contains($mensaje, 'precio') => 'motivoPrecio',
            default => 'motivoRazon',
        };
    }
}
