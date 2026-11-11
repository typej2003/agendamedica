<?php

namespace App\Http\Livewire\Admin\Concerns;

use App\Models\Medico;
use App\Services\CuentaService;
use Illuminate\Validation\ValidationException;

/**
 * **Borrar (archivar) la cuenta de un doctor** desde el panel. Lo comparten la lista de «Usuarios · Médicos» y
 * el detalle del médico, para que haga exactamente lo mismo en los dos sitios.
 *
 * La lógica vive en `CuentaService::archivarMedico()` / `restaurarMedico()`; acá sólo está la ventana: se pide
 * **escribir el nombre del médico** para confirmar (una baja sin vuelta atrás no se hace con un clic de más) y
 * un motivo opcional que queda en `medicos.archivado_motivo`.
 *
 * El componente que lo use debe tener las propiedades `$modal` y `$medicoId`, y un método `cerrarModal()`, e
 * incluir la vista parcial `livewire.admin.partials.archivar-cuenta`.
 */
trait ArchivaLaCuenta
{
    /** Lo que se escribió para confirmar la baja: tiene que coincidir con el nombre del médico. */
    public $confirmacionArchivo = '';

    /** Motivo opcional de la baja (queda en la ficha, para la auditoría). */
    public $motivoArchivo = '';

    public function abrirArchivo(int $medicoId)
    {
        $this->resetErrorBag();
        $this->medicoId = Medico::findOrFail($medicoId)->id;
        $this->confirmacionArchivo = '';
        $this->motivoArchivo = '';
        $this->modal = 'archivar';
    }

    public function cerrarArchivo()
    {
        $this->confirmacionArchivo = '';
        $this->motivoArchivo = '';
        $this->cerrarModal();
    }

    /** Confirma la baja: valida lo escrito y archiva. Devuelve lo que decida la pantalla (`despuesDeArchivar`). */
    public function confirmarArchivo(CuentaService $cuentas)
    {
        $medico = Medico::findOrFail($this->medicoId);

        $this->validate([
            'confirmacionArchivo' => 'required|string|max:255',
            'motivoArchivo'       => 'nullable|string|max:255',
        ], [
            'confirmacionArchivo.required' => 'Escribe el nombre del médico para confirmar.',
            'confirmacionArchivo.max'      => 'El nombre admite hasta 255 caracteres.',
            'motivoArchivo.max'            => 'El motivo admite hasta 255 caracteres.',
        ]);

        if (mb_strtolower(trim($this->confirmacionArchivo)) !== mb_strtolower((string) $medico->nombre_mostrar)) {
            $this->addError('confirmacionArchivo', 'Escribe el nombre del médico tal como aparece para confirmar.');

            return null;
        }

        try {
            $cuentas->archivarMedico($medico, (string) $this->motivoArchivo, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('archivo', $e->validator->errors()->first());

            return null;
        }

        $nombre = $medico->nombre_mostrar;
        $this->cerrarArchivo();

        return $this->despuesDeArchivar($medico, $nombre);
    }

    /**
     * Qué hace la pantalla al terminar. Por defecto se queda en la lista con el aviso; el detalle del médico
     * la sobrescribe para volver al listado (la ficha ya no se muestra).
     */
    protected function despuesDeArchivar(Medico $medico, string $nombre)
    {
        session()->flash('message', 'Borraste la cuenta de ' . $nombre
            . '. Su correo, su teléfono y su carnet quedaron libres; sus historias, citas y récipes se conservan.');

        return null;
    }

    /** Devuelve a la vida una cuenta archivada (lo que se pueda: el correo sólo si sigue libre). */
    public function restaurarArchivo(int $medicoId, CuentaService $cuentas)
    {
        try {
            $r = $cuentas->restaurarMedico(Medico::findOrFail($medicoId));
        } catch (ValidationException $e) {
            $this->addError('cuenta', $e->validator->errors()->first());

            return;
        }

        session()->flash('message', $r['correo_restaurado']
            ? 'Cuenta restaurada: puede volver a entrar con su correo de siempre.'
            : 'Cuenta restaurada, pero su correo original (' . $r['correo_original'] . ') ya lo usa otra cuenta: ponle uno nuevo desde Editar.');
    }
}
