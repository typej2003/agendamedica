<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Clinica\CitaOnline\ConfiguracionDeCitaOnline;
use App\Models\Evolucion;

/**
 * **Cita online del médico**, desde su detalle: cuánto se cobra al reservar (en porcentaje) y si su agenda
 * se publica en el portal de reservas y en el bot.
 *
 * Es la misma fila de `evolucion` y las mismas reglas que la pantalla `/clinica/configuracion/cita-online`;
 * los valores por defecto (100 % y publicada) los aplica `ConfiguracionDeCitaOnline`.
 */
trait GestionaCitaOnline
{
    /** El porcentaje como se escribe (0–100). */
    public $citaPorcentaje = '100';

    public $citaPublicar = true;

    /** Lo que el paciente lee antes de venir (opcional). */
    public $citaMinutosAntes = '';
    public $citaAcompanantes = '';
    public $citaIndicaciones = '';

    public function cargarCitaOnline(): void
    {
        $configuracion = ConfiguracionDeCitaOnline::de(
            Evolucion::where('reg_medico', (string) $this->medico->reg_medico)->first(),
        );

        $this->citaPorcentaje = $configuracion->porcentajeParaElCampo();
        $this->citaPublicar = $configuracion->publicaAgenda();
        $this->citaMinutosAntes = $configuracion->minutosAntes() === null ? '' : (string) $configuracion->minutosAntes();
        $this->citaAcompanantes = $configuracion->acompanantes() === null ? '' : (string) $configuracion->acompanantes();
        $this->citaIndicaciones = (string) $configuracion->indicaciones();
    }

    public function guardarCitaOnline(): void
    {
        $this->validate([
            'citaPorcentaje' => ['required', 'numeric', 'min:0', 'max:100'],
            'citaPublicar' => ['boolean'],
            'citaMinutosAntes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'citaAcompanantes' => ['nullable', 'integer', 'min:0', 'max:10'],
            'citaIndicaciones' => ['nullable', 'string', 'max:255'],
        ], [
            'citaPorcentaje.required' => 'Indica el porcentaje que se cobra al reservar.',
            'citaPorcentaje.numeric' => 'El porcentaje tiene que ser un número.',
            'citaPorcentaje.min' => 'El porcentaje no puede ser menor que 0.',
            'citaPorcentaje.max' => 'El porcentaje no puede ser mayor que 100.',
            'citaMinutosAntes.integer' => 'Los minutos antes tienen que ser un número entero.',
            'citaMinutosAntes.max' => 'Los minutos antes no pueden pasar de 240.',
            'citaAcompanantes.integer' => 'Los acompañantes tienen que ser un número entero.',
            'citaAcompanantes.max' => 'Los acompañantes no pueden pasar de 10.',
            'citaIndicaciones.max' => 'Las indicaciones no pueden pasar de 255 caracteres.',
        ], [
            'citaPorcentaje' => 'porcentaje',
            'citaMinutosAntes' => 'minutos antes',
            'citaAcompanantes' => 'acompañantes',
            'citaIndicaciones' => 'indicaciones',
        ]);

        $evolucion = Evolucion::firstOrNew(['reg_medico' => (string) $this->medico->reg_medico]);
        $evolucion->porcentaje_cita_online_bp = ConfiguracionDeCitaOnline::puntosBaseDe($this->citaPorcentaje);
        $evolucion->publicar_agenda = (bool) $this->citaPublicar;
        $evolucion->cita_linea_minutos_antes = $this->enteroONulo($this->citaMinutosAntes);
        $evolucion->cita_linea_acompanantes = $this->enteroONulo($this->citaAcompanantes);
        $evolucion->cita_linea_indicaciones = trim((string) $this->citaIndicaciones) ?: null;

        // `clave` es NOT NULL en el esquema legado sin default: una fila nueva rompería el insert.
        if (! $evolucion->exists && $evolucion->clave === null) {
            $evolucion->clave = 0;
        }

        $evolucion->save();

        session()->flash('message', 'Listo: se guardó la configuración de la cita online.');
    }

    private function enteroONulo(mixed $valor): ?int
    {
        return $valor === null || $valor === '' ? null : (int) $valor;
    }
}
