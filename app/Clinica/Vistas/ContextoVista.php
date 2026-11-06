<?php

namespace App\Clinica\Vistas;

use App\Models\Medico;

/**
 * La pantalla de **elegir consultorio** (`/clinica/contexto`): con qué médico, especialidad y **consultorio**
 * se opera. **Una tarjeta por médico**.
 *
 * ⚠️ La clave `sedes` del contexto son los **consultorios** (`offices`) del médico, no las filas del
 * catálogo de sedes (`medical_centers`): el nombre viene del armado del contexto y se dejó como está (no se
 * renombra), pero la pantalla lo rotula "Consultorio" porque es lo que lista.
 *
 * **Una sola tarjeta para el doctor** (corrección del usuario, 2026-10-20): el médico que inició sesión es
 * **siempre él** —no puede tomar la identidad de un centro—, así que sus registros (el personal y los
 * centros donde trabaja) **no** son tarjetas separadas: son una sola tarjeta con **sus** especialidades y
 * un menú de **consultorio que combina los suyos con los del centro**. Cada consultorio sabe de qué
 * `reg_medico` es, y el que se elige fija el contexto (el del consultorio). La secretaría sí ve **una
 * tarjeta por centro**, porque ahí el médico del contexto es la fila del centro.
 *
 * **No se pregunta lo que ya está decidido** (decisión del usuario, 2026-10-19): si el médico tiene **una
 * sola especialidad** y **uno o ningún consultorio**, esos dos campos no se ofrecen —se entra con el único
 * valor que hay— y la pantalla sólo muestra cuál es. La elección se sigue ofreciendo en cuanto haya algo
 * entre qué elegir (dos especialidades, dos consultorios), y el formulario manda el valor fijo en un campo
 * oculto para que el contexto quede fijado explícitamente (sin consultorios no se manda: queda «todos»).
 */
final class ContextoVista
{
    /** @param  array<string,array{medico:object, centro:?object, especialidades:iterable, sedes:\Illuminate\Support\Collection}>  $disponibles */
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
        return route('clinica.sedes');
    }

    /** @return array<int,array<string,mixed>> */
    public function medicos(): array
    {
        $grupos = [];

        foreach ($this->disponibles as $regMedico => $acceso) {
            $medico = $acceso['medico'];
            $id = $medico->id;
            $centro = $acceso['centro'] ?? null;
            // Con qué nombre se agrupa en el menú cada consultorio de este registro (ver `etiquetaDelMenu()`).
            $etiquetaDelMenu = $this->etiquetaDelMenu($centro, $medico);

            $grupo = $grupos[$id] ?? [
                'medico'         => $medico,
                'especialidades' => [],
                'sedes'          => [],
                'registros'      => [],
                'esCentro'       => (bool) ($acceso['es_centro'] ?? false),
            ];

            foreach ($acceso['especialidades'] as $especialidad) {
                $grupo['especialidades'][$especialidad->id] = [
                    'valor' => $especialidad->id,
                    'texto' => $especialidad->name,
                ];
            }

            foreach ($acceso['sedes'] as $sede) {
                $grupo['sedes'][$sede->id] = [
                    'valor'     => $sede->id,
                    'texto'     => ($sede->medicalCenter->name ?? 'Sede') . ($sede->office_number ? ' · ' . $sede->office_number : ''),
                    // El `reg_medico` **del consultorio**: es el que fija el contexto al elegirlo (el
                    // personal del médico o el centro donde está ese consultorio).
                    'regMedico' => (string) $regMedico,
                    // La sede (`medical_centers`) del consultorio: «Todas las sedes» sólo se ofrece si el menú junta más de una.
                    'sedeId'    => (int) $sede->medical_center_id,
                    // El nombre del médico dueño del registro, para agrupar el menú: `null` = los
                    // consultorios personales del médico ("Mis consultorios").
                    'centro'    => $etiquetaDelMenu,
                ];
            }

            $grupo['registros'][$regMedico] = (string) $regMedico;
            $grupos[$id] = $grupo;
        }

        $medicos = [];

        foreach ($grupos as $grupo) {
            $especialidades = collect($grupo['especialidades'])->values();
            $sedes = collect($grupo['sedes'])->values();
            $medico = $grupo['medico'];

            $medicos[] = [
                // El registro de la tarjeta es el del **médico**: su consultorio personal (o el del centro,
                // en la secretaría). Los consultorios de los centros viajan con cada opción del menú.
                'regMedico'      => (string) $medico->reg_medico,
                'nombre'         => trim(($medico->prefix ?? '') . ' ' . $medico->name . ' ' . $medico->lastname),
                'licencia'       => $medico->license_number ?: null,
                // ¿La tarjeta es de un **centro médico**? (Fase 2 del cambio de `reg_medico`): en la
                // secretaría el médico del contexto es la fila del centro, y conviene decir que lo es.
                'esCentro'       => $grupo['esCentro'],
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
     * La etiqueta con la que el menú de consultorios agrupa las sedes de un registro: `null` = «Mis
     * consultorios».
     *
     * El menú agrupa por el **dueño del `reg_medico`**. Un consultorio puede vivir en una **ficha hija**
     * (`medicos.medico_padre_id`): el segundo consultorio del mismo doctor, que se crea sin cuenta y con el
     * nombre del doctor. Ahí manda el **padre** —si es el médico del contexto, el consultorio va en «Mis
     * consultorios»; si es de otro doctor, va con el nombre de ese padre—, porque la ficha hija repite el
     * nombre del doctor y parecía otro médico con sus propios consultorios (2026-11-06).
     */
    private function etiquetaDelMenu(?Medico $duenio, Medico $medico): ?string
    {
        if ($duenio === null) {
            return null;
        }

        // El consultorio hijo se agrupa donde se agrupa su consultorio principal.
        $duenio = $duenio->padre ?? $duenio;

        $propias = array_filter([(int) $medico->id, (int) $medico->medico_padre_id]);
        if (in_array((int) $duenio->id, $propias, true)) {
            return null;
        }

        return trim(($duenio->prefix ?? '') . ' ' . $duenio->name . ' ' . $duenio->lastname);
    }

    /**
     * ¿Queda algo que elegir en esta pantalla? Es lo que decide el encabezado: preguntar «¿con qué
     * consultorio vas a trabajar?» cuando ya está todo decidido confunde (la pantalla no ofrece nada).
     *
     * Cuenta también la elección de **médico**: la secretaría que trabaja para dos centros —aunque cada uno
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
