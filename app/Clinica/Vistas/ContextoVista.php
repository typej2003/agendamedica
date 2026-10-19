<?php

namespace App\Clinica\Vistas;

/**
 * La pantalla de **elegir consultorio** (`/clinica/contexto`): con qué médico/registro, especialidad y
 * **consultorio** se opera. Una tarjeta por médico al que el usuario tiene acceso.
 *
 * ⚠️ La clave `sedes` del contexto son los **consultorios** (`offices`) del médico, no las filas del
 * catálogo de sedes (`medical_centers`): el nombre viene del armado del contexto y se dejó como está (no se
 * renombra), pero la pantalla lo rotula "Consultorio" porque es lo que lista.
 *
 * **No se pregunta lo que ya está decidido** (decisión del usuario, 2026-10-19): si el médico tiene **una
 * sola especialidad** y **uno o ningún consultorio**, esos dos campos no se ofrecen —se entra con el único
 * valor que hay— y la pantalla sólo muestra cuál es. La elección se sigue ofreciendo en cuanto haya algo
 * entre qué elegir (dos especialidades, dos consultorios), y el formulario manda el valor fijo en un campo
 * oculto para que el contexto quede fijado explícitamente (sin consultorios no se manda: queda «todos»).
 */
final class ContextoVista
{
    /** @param  array<string,array{medico:object, especialidades:iterable, sedes:\Illuminate\Support\Collection}>  $disponibles */
    public function __construct(private array $disponibles)
    {
    }

    public function sinAcceso(): bool
    {
        return $this->disponibles === [];
    }

    public function urlGuardar(): string
    {
        return route('clinica.contexto.guardar');
    }

    public function urlSedes(): string
    {
        return route('clinica.sedes');
    }

    public function urlConsultorios(): string
    {
        return route('clinica.consultorios');
    }

    /** @return array<int,array<string,mixed>> */
    public function medicos(): array
    {
        $medicos = [];

        foreach ($this->disponibles as $regMedico => $acceso) {
            $medico = $acceso['medico'];

            $especialidades = collect($acceso['especialidades'])
                ->map(fn ($e) => ['valor' => $e->id, 'texto' => $e->name])
                ->values();

            $sedes = $acceso['sedes']->map(fn ($sede) => [
                'valor' => $sede->id,
                'texto' => ($sede->medicalCenter->name ?? 'Sede') . ($sede->office_number ? ' · ' . $sede->office_number : ''),
            ])->values();

            $medicos[] = [
                'regMedico'      => (string) $regMedico,
                'nombre'         => trim(($medico->prefix ?? '') . ' ' . $medico->name . ' ' . $medico->lastname),
                'licencia'       => $medico->license_number ?: null,
                'especialidades' => $especialidades->all(),
                'sedes'          => $sedes->all(),
                // Sin consultorios no hay sede que elegir: la agenda no puede calcular la jornada. El camino
                // es darlos de alta (WEB-2.8b.2), y para eso hay que elegir primero con quién trabajar.
                'sinConsultorios' => $sedes->isEmpty(),
                // Qué se pregunta y qué no: con **un solo** consultorio (o ninguno) no hay elección que hacer
                // (ver la nota de la clase); con dos o más se ofrece el `<select>` de siempre.
                // `consultorioFijo` es lo que la pantalla muestra en lugar del campo, y `sinConsultorios`
                // deja el aviso de darlos de alta. La especialidad sigue la misma regla, salvo que **sin**
                // ninguna se deja el `<select>` vacío que ya avisaba que no hay nada asignado.
                'elegirEspecialidad' => $especialidades->count() !== 1,
                'especialidadFija'   => $especialidades->count() === 1 ? $especialidades->first() : null,
                'elegirConsultorio'  => $sedes->count() > 1,
                'consultorioFijo'    => $sedes->count() === 1 ? $sedes->first() : null,
            ];
        }

        return $medicos;
    }

    /**
     * ¿Queda algo que elegir en esta pantalla? Es lo que decide el encabezado: preguntar «¿con qué
     * consultorio vas a trabajar?» cuando ya está todo decidido confunde (la pantalla no ofrece nada).
     *
     * Cuenta también la elección de **médico**: la secretaría que trabaja para dos médicos —aunque cada uno
     * tenga una especialidad y un consultorio— sí tiene que decir con cuál entra.
     */
    public function hayAlgoQueElegir(): bool
    {
        $medicos = $this->medicos();

        if (count($medicos) > 1) {
            return true;
        }

        foreach ($medicos as $medico) {
            if ($medico['elegirEspecialidad'] || $medico['elegirConsultorio']) {
                return true;
            }
        }

        return false;
    }
}
