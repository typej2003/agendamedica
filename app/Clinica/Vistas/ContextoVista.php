<?php

namespace App\Clinica\Vistas;

use App\Models\Medico;

/**
 * La pantalla de **elegir consultorio** (`/clinica/contexto`): con qué médico, especialidad y **consultorio**
 * se opera, **por pasos** (2026-11-06).
 *
 * El médico elige de a uno: primero el médico (sólo la secretaría con varios centros), después la
 * especialidad —si hay más de una— y al final el consultorio, que se confirma con un click en su tarjeta (ya
 * no hay botón «Trabajar aquí»). Cada elección **se colapsa** en una línea con el dato y «Cambiar», para que
 * la pantalla no muestre todas las opciones a la vez.
 *
 * ⚠️ La clave `sedes` del contexto son los **consultorios** (`offices`) del médico, no las filas del
 * catálogo de sedes (`medical_centers`): el nombre viene del armado del contexto y se dejó como está (no se
 * renombra), pero la pantalla lo rotula "Consultorio" porque es lo que lista.
 *
 * **Una sola tarjeta para el doctor** (corrección del usuario, 2026-10-20): el médico que inició sesión es
 * **siempre él** —no puede tomar la identidad de un centro—, así que sus registros (el personal y los
 * centros donde trabaja) **no** son tarjetas separadas: son una sola tarjeta con **sus** especialidades y
 * sus **consultorios**, que combinan los suyos con los del centro. Cada consultorio sabe de qué `reg_medico`
 * es, y el que se elige fija el contexto (el del consultorio). La secretaría sí ve **un paso por centro**,
 * porque ahí el médico del contexto es la fila del centro.
 *
 * **No se pregunta lo que ya está decidido** (decisión del usuario, 2026-10-19): si el médico tiene **una
 * sola especialidad**, no se ofrece entre qué elegir —se muestra cuál es—; si tiene **uno o ningún
 * consultorio**, el paso del consultorio lo dice (con un único consultorio se entra clickeándolo igual). La
 * elección se sigue ofreciendo en cuanto haya algo entre qué elegir (dos especialidades, dos consultorios).
 */
final class ContextoVista
{
    /** La etiqueta de los consultorios que no son de un centro: los del propio médico. */
    public const PROPIOS = 'Mis consultorios';

    /** Las tarjetas, calculadas una vez: la vista y `pasos()` llaman a `medicos()` varias veces. */
    private ?array $tarjetas = null;

    /**
     * @param  array<string,array{medico:object, centro:?object, especialidades:iterable, sedes:\Illuminate\Support\Collection}>  $disponibles
     * @param  array<string,mixed>|null  $actual  el contexto vigente (`ContextoTrabajo::actual()`), si hay: la
     *                                            pantalla arranca con esos pasos colapsados
     */
    public function __construct(private array $disponibles, private ?array $actual = null)
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

    /** ¿La pantalla tiene que preguntar con quién se trabaja? (la secretaría de varios centros). */
    public function hayVariosMedicos(): bool
    {
        return count($this->medicos()) > 1;
    }

    /**
     * El número de cada paso que se ve, en orden: `medico` (sólo con varios médicos), `especialidad` (sólo si
     * hay alguna entre qué elegir) y `consultorio`, que siempre está.
     *
     * @return array{medico?:int, especialidad?:int, consultorio:int}
     */
    public function pasos(): array
    {
        $pasos = [];
        $numero = 0;

        if ($this->hayVariosMedicos()) {
            $pasos['medico'] = ++$numero;
        }

        foreach ($this->medicos() as $medico) {
            if ($medico['elegirEspecialidad']) {
                $pasos['especialidad'] = ++$numero;
                break;
            }
        }

        $pasos['consultorio'] = ++$numero;

        return $pasos;
    }

    /** `medicos.id` del contexto vigente, o `null`. */
    public function medicoActual(): ?int
    {
        return isset($this->actual['medico']) ? (int) $this->actual['medico']->id : null;
    }

    /** `specialties.id` del contexto vigente, o `null`. */
    public function especialidadActual(): ?int
    {
        return isset($this->actual['specialty']) ? (int) $this->actual['specialty']->id : null;
    }

    /** `offices.id` del contexto vigente, o `null` («todos los consultorios»). */
    public function consultorioActual(): ?int
    {
        return isset($this->actual['office']) ? (int) $this->actual['office']->id : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function medicos(): array
    {
        if ($this->tarjetas !== null) {
            return $this->tarjetas;
        }

        $grupos = [];

        foreach ($this->disponibles as $regMedico => $acceso) {
            $medico = $acceso['medico'];
            $id = $medico->id;
            $centro = $acceso['centro'] ?? null;
            // Con qué nombre se agrupa en el menú cada consultorio de este registro (ver `etiquetaDelMenu()`).
            $etiquetaDelMenu = $this->etiquetaDelMenu($centro, $medico);

            $grupo = $grupos[$id] ?? [
                'id'             => (int) $id,
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
                    // La tarjeta del consultorio se lee por partes: la sede y su número, no el título armado.
                    'sede'      => $sede->medicalCenter->name ?? 'Sede',
                    'numero'    => $sede->office_number,
                    // El `reg_medico` **del consultorio**: es el que fija el contexto al elegirlo (el
                    // personal del médico o el centro donde está ese consultorio).
                    'regMedico' => (string) $regMedico,
                    // La sede (`medical_centers`) del consultorio: «Todas las sedes» sólo se ofrece si el menú junta más de una.
                    'sedeId'    => (int) $sede->medical_center_id,
                    // El nombre del médico dueño del registro, para agrupar el menú: `null` = los
                    // consultorios personales del médico ("Mis consultorios").
                    'centro'    => $etiquetaDelMenu,
                    // ¿Es el consultorio del contexto vigente? La tarjeta lo marca al volver a cambiar.
                    'actual'    => $this->consultorioActual() === (int) $sede->id,
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

            // La especialidad del contexto, si es de las que este médico ofrece: es el paso ya elegido.
            $elegida = $especialidades->firstWhere('valor', $this->especialidadActual());

            $medicos[] = [
                'id'             => $grupo['id'],
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
                // Los consultorios de la tarjeta, agrupados por su dueño y con su «Todas las sedes».
                'grupos'         => $this->gruposDeConsultorios($sedes->all()),
                'elegida'        => $elegida,
                'actual'         => $this->medicoActual() === $grupo['id'],
                // Sin consultorios no hay sede que elegir: la agenda no puede calcular la jornada. El camino
                // es darlos de alta (WEB-2.8b.2), y para eso hay que elegir primero con quién trabajar.
                'sinConsultorios' => $sedes->isEmpty(),
                // Qué se pregunta y qué no: con **una sola** especialidad no hay elección que hacer y se
                // muestra cuál es; con dos o más se ofrecen las tarjetas. Sin ninguna se muestra el aviso y
                // el paso del consultorio queda igual (el contexto se fija sin especialidad).
                'elegirEspecialidad' => $especialidades->count() !== 1,
                'especialidadFija'   => $especialidades->count() === 1 ? $especialidades->first() : null,
                'elegirConsultorio'  => $sedes->count() > 1,
            ];
        }

        return $this->tarjetas = $medicos;
    }

    /**
     * Los consultorios de un médico agrupados por su dueño (los propios primero), con la opción «Todas las
     * sedes» del grupo cuando junta más de una sede de **un solo** `reg_medico`.
     *
     * @param  array<int,array<string,mixed>>  $sedes
     * @return array<int,array{etiqueta:string, sedes:array<int,array<string,mixed>>, todas:?string, todasReg:string}>
     */
    private function gruposDeConsultorios(array $sedes): array
    {
        return collect($sedes)
            ->groupBy(fn (array $sede) => $sede['centro'] ?? self::PROPIOS)
            ->sortKeysUsing(fn (string $a, string $b) => $this->ordenDelGrupo($a, $b))
            ->map(function ($delGrupo, $etiqueta) {
                $delGrupo = collect($delGrupo)->values();
                $unRegistro = $delGrupo->pluck('regMedico')->unique()->count() === 1;

                return [
                    'etiqueta' => (string) $etiqueta,
                    'sedes'    => $delGrupo->all(),
                    // «Todas las sedes» fija el contexto sin consultorio y abarca los de **ese** registro: no
                    // se ofrece si el grupo mezcla dos (el consultorio hijo del mismo doctor). El vacío del
                    // formulario solamente puede decir un `reg_medico`.
                    'todas' => $delGrupo->pluck('sedeId')->unique()->count() > 1 && $unRegistro
                        ? ($etiqueta === self::PROPIOS
                            ? 'Todas las sedes de mis consultorios'
                            : 'Todas las sedes de ' . $etiqueta)
                        : null,
                    'todasReg' => (string) $delGrupo->first()['regMedico'],
                ];
            })
            ->values()
            ->all();
    }

    /** «Mis consultorios» primero; el resto, por nombre. */
    private function ordenDelGrupo(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        if ($a === self::PROPIOS) {
            return -1;
        }

        if ($b === self::PROPIOS) {
            return 1;
        }

        return strcmp($a, $b);
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
