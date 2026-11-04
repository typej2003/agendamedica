<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Actions\Medicos\AprobarSolicitudDeMedico;
use App\Actions\Medicos\RechazarSolicitudDeMedico;
use App\Models\SolicitudMedico;
use Illuminate\Validation\ValidationException;

/**
 * **Solicitud de registro del médico** (la que llega de `/registro/medico`), gestionada desde su detalle:
 * aprobarla (con su `reg_medico`, su especialidad y la marca del carnet) o rechazarla con el motivo que se
 * le manda por correo.
 *
 * Son las mismas Actions de la bandeja de solicitudes; acá se acota a las de este médico.
 */
trait GestionaSolicitudes
{
    /** Solicitud abierta en el detalle (null = ninguna). */
    public $solicitudAbiertaId = null;

    public $solRegMedico = '';
    public $solSpecialty = '';
    public $solMotivo = '';
    public $solCarnet = false;

    /** Clave temporal de la última aprobación: ['correo','clave','titulo']. */
    public $claveSolicitud = null;

    public function abrirSolicitud(int $id): void
    {
        $solicitud = $this->solicitudDelMedico($id);

        $this->resetErrorBag();
        $this->solicitudAbiertaId = $solicitud->id;
        $this->solRegMedico = $solicitud->reg_medico ?: $solicitud->regMedicoPropuesto();
        $this->solSpecialty = (string) ($solicitud->specialty_id ?? '');
        $this->solMotivo = (string) $solicitud->motivo_rechazo;
        $this->solCarnet = false;
    }

    public function cerrarSolicitud(): void
    {
        $this->solicitudAbiertaId = null;
        $this->solRegMedico = '';
        $this->solSpecialty = '';
        $this->solMotivo = '';
        $this->solCarnet = false;
        $this->resetErrorBag();
    }

    public function aprobarSolicitud(AprobarSolicitudDeMedico $aprobar): void
    {
        $this->validate(
            ['solRegMedico' => ['required', 'string', 'max:100'], 'solSpecialty' => ['nullable', 'integer']],
            ['solRegMedico.required' => 'Escribe el reg_medico con el que va a trabajar.'],
            ['solRegMedico' => 'reg_medico', 'solSpecialty' => 'especialidad'],
        );

        try {
            $r = $aprobar->ejecutar(
                $this->solicitudDelMedico((int) $this->solicitudAbiertaId),
                auth()->user(),
                $this->solRegMedico,
                $this->solSpecialty !== '' ? (int) $this->solSpecialty : null,
                (bool) $this->solCarnet,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo === 'reg_medico' ? 'solRegMedico' : 'solMotivo', $mensajes[0]);
            }

            return;
        }

        $this->claveSolicitud = [
            'titulo' => match (true) {
                $r['yaTeniaCuenta'] => 'Aprobada: el médico ya tenía su cuenta',
                $r['clave'] === null => 'Aprobada: se habilitó su área médica',
                $r['enlazada'] => 'Aprobada: acceso a la ficha existente',
                default => 'Aprobada: médico creado',
            },
            'correo' => $r['medico']->email,
            'clave' => $r['clave'],
            'yaTeniaCuenta' => $r['yaTeniaCuenta'],
        ];
        $this->cerrarSolicitud();
    }

    public function rechazarSolicitud(RechazarSolicitudDeMedico $rechazar): void
    {
        $this->validate(
            ['solMotivo' => ['required', 'string', 'min:5', 'max:2000']],
            [
                'solMotivo.required' => 'Escribe el motivo: es lo que le llega al médico.',
                'solMotivo.min' => 'El motivo es muy corto (al menos 5 caracteres).',
            ],
            ['solMotivo' => 'motivo'],
        );

        try {
            $enviado = $rechazar->ejecutar($this->solicitudDelMedico((int) $this->solicitudAbiertaId), auth()->user(), $this->solMotivo);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo, $mensajes[0]);
            }

            return;
        }

        session()->flash('message', $enviado
            ? 'Solicitud rechazada: le avisamos al médico por correo con el motivo.'
            : 'Solicitud rechazada, pero el correo no salió: avísale al médico por otro medio.');
        $this->cerrarSolicitud();
    }

    public function cerrarClaveSolicitud(): void
    {
        $this->claveSolicitud = null;
    }

    /** Una solicitud **de este médico**; otra, 404. */
    private function solicitudDelMedico(int $id): SolicitudMedico
    {
        return SolicitudMedico::with('especialidad')
            ->where('medico_id', $this->medico->id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
