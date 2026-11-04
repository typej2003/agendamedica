<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Services\CuentaService;
use Illuminate\Validation\ValidationException;

/**
 * **Especialidades y postgrados del médico**, administrados desde su detalle. Usa el mismo formulario Blade
 * que el panel de Especialidades y la misma lógica de `CuentaService`, que es la única que escribe el pivote
 * `medico_specialty` y decide qué módulos ve el médico en `/clinica`.
 */
trait GestionaEspecialidades
{
    /** Ids tildados (como texto, tal como llegan del formulario). */
    public $seleccionadas = [];
    public $postgrados = [];

    /** Aviso que no es un error: el médico quedó sin ninguna especialidad activa. */
    public $avisoEspecialidad = null;

    public function cargarEspecialidades(): void
    {
        $this->seleccionadas = $this->medico->specialties()->pluck('specialties.id')->map(fn ($id) => (string) $id)->all();
        $this->postgrados = $this->medico->subespecialidades()->pluck('subespecialidades.id')->map(fn ($id) => (string) $id)->all();
        $this->avisoEspecialidad = null;
    }

    public function guardarEspecialidades(CuentaService $cuentas): void
    {
        try {
            $cambios = $cuentas->asignarEspecialidades($this->medico, (array) $this->seleccionadas);
            // Los postgrados van después: asignar especialidades puede haber quitado los huérfanos.
            $cuentas->asignarSubespecialidades($this->medico->fresh('specialties'), (array) $this->postgrados);

            $this->medico = $this->medico->fresh(['specialties', 'subespecialidades']);
            $this->cargarEspecialidades();
            $this->avisoEspecialidad = $this->advertenciaSinEspecialidad();

            session()->flash('message', $this->textoDeGuardado($cambios));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo, $mensajes[0]);
            }
        }
    }

    /** @param  array<string,mixed>  $cambios */
    private function textoDeGuardado(array $cambios): string
    {
        $nombre = trim($this->medico->name . ' ' . $this->medico->lastname);

        if (($cambios['asignadas'] ?? 0) === 0 && ($cambios['quitadas'] ?? 0) === 0) {
            return "Sin cambios: {$nombre} ya tenía esas especialidades.";
        }

        $partes = [];
        if (($cambios['asignadas'] ?? 0) > 0) {
            $partes[] = $cambios['asignadas'] . ' asignada' . ($cambios['asignadas'] > 1 ? 's' : '');
        }
        if (($cambios['quitadas'] ?? 0) > 0) {
            $partes[] = $cambios['quitadas'] . ' quitada' . ($cambios['quitadas'] > 1 ? 's' : '');
        }
        if (($cambios['postgradosQuitados'] ?? 0) > 0) {
            $partes[] = $cambios['postgradosQuitados'] . ' postgrado' . ($cambios['postgradosQuitados'] > 1 ? 's' : '')
                . ' quitado' . ($cambios['postgradosQuitados'] > 1 ? 's' : '') . ' por quedarse sin su especialidad';
        }

        return "Especialidades de {$nombre} actualizadas: " . implode(' y ', $partes) . '.';
    }

    private function advertenciaSinEspecialidad(): ?string
    {
        if ($this->medico->specialties->where('activo', true)->isNotEmpty()) {
            return null;
        }

        $nombre = trim($this->medico->name . ' ' . $this->medico->lastname);

        return $this->medico->specialties->isEmpty()
            ? "{$nombre} quedó sin ninguna especialidad: su consultorio en la web no va a mostrar módulos hasta que le asignes una."
            : "Las especialidades de {$nombre} están inactivas, así que su consultorio en la web no va a mostrar módulos.";
    }
}
