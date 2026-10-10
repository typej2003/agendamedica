<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\Jornada;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * El formulario de la cita ("Nueva cita", "Editar cita" y la repregunta por la cita pendiente): el paso 1
 * (dónde y cuándo), el paso 2 (el paciente) y el paso 3 (la cita), con los textos y las opciones ya listos.
 *
 * Los valores de los campos salen de `old()` (lo que el usuario escribió y falló la validación) y, si no
 * hay, de los valores con los que se armó el formulario.
 */
final class FormularioDeCitaVista
{
    /**
     * @param  Collection<int,Office>  $sedes
     * @param  Collection<int,\App\Models\MotivoCita>  $motivos
     * @param  Collection<int,object>  $bloques  los bloques de horario de la sede ese día
     * @param  array<string,mixed>  $valores  los valores iniciales de los campos
     * @param  array{id:?int, nombre:string, numhistoria:?int}|null  $paciente  el paciente elegido
     * @param  array{cita: \App\Models\Cola, centroId: ?int}|null  $pendiente  su cita pendiente, si la tiene
     */
    public function __construct(
        private ?int $citaId,
        private string $accion,
        private Carbon $fecha,
        private Collection $sedes,
        private ?Office $sede,
        private Collection $bloques,
        private ?Jornada $jornada,
        private Collection $motivos,
        private ?array $paciente,
        private ?array $pendiente,
        private array $valores,
        private ?string $avisoNoLaborable,
        private Carbon $ahora,
    ) {
    }

    /** El valor de un campo: lo que quedó de la validación fallida o, si no, el valor inicial. */
    public function valor(string $campo, $defecto = null)
    {
        return old($campo, $this->valores[$campo] ?? $defecto);
    }

    public function editando(): bool
    {
        return $this->citaId !== null;
    }

    public function titulo(): string
    {
        return $this->editando() ? 'Editar cita' : 'Nueva cita';
    }

    public function subtitulo(): string
    {
        return ($this->sede?->medicalCenter->name ?? 'Sin sede') . ' · ' . $this->fecha->format('d/m/Y')
            . ($this->jornada ? ' · próximo #' . $this->jornada->siguienteNumero() : '');
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar cambios' : 'Agendar la cita';
    }

    // ------------------------------------------------------------------------------------ Direcciones

    public function accion(): string
    {
        return $this->accion;
    }

    /** El paso 1 es un GET sobre esta misma pantalla: la sede y el día definen la jornada. */
    public function urlFiltro(): string
    {
        return $this->editando() ? route('clinica.agenda.editar', $this->citaId) : route('clinica.agenda.nueva');
    }

    public function urlVolver(): string
    {
        return route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->fecha->toDateString(), 'sede' => $this->sede?->id ?? 'todas']);
    }

    public function urlBuscarPacientes(): string
    {
        return route('clinica.pacientes.buscar');
    }

    public function urlNoLaborables(): string
    {
        return route('clinica.agenda.no-laborables');
    }

    /** Los motivos de cita y su marca de "varias citas" (WEB-2.17). */
    public function urlMotivos(): string
    {
        return route('clinica.agenda.motivos');
    }

    // --------------------------------------------------------------------------------------- Paso 1

    public function fecha(): string
    {
        return $this->fecha->toDateString();
    }

    public function fechaTexto(): string
    {
        return $this->fecha->format('d/m/Y');
    }

    public function sedeId(): ?int
    {
        return $this->sede?->id;
    }

    public function sinSedes(): bool
    {
        return $this->sedes->isEmpty();
    }

    public function faltaElegirSede(): bool
    {
        return $this->sede === null && $this->sedes->isNotEmpty();
    }

    /**
     * Sin sede elegida no se preselecciona ninguna: la sede decide la jornada y el lugar, y elegirla por
     * el usuario sería agendar donde no corresponde.
     *
     * @return array<int,array{valor:string, texto:string, elegida:bool}>
     */
    public function opcionesDeSede(): array
    {
        $opciones = [];

        if ($this->faltaElegirSede()) {
            $opciones[] = ['valor' => '', 'texto' => '— Elige una sede —', 'elegida' => true];
        }

        foreach ($this->sedes as $sede) {
            $texto = ($sede->medicalCenter->name ?? 'Sede') . ($sede->office_number ? ' — ' . $sede->office_number : '');
            $opciones[] = ['valor' => (string) $sede->id, 'texto' => $texto, 'elegida' => $this->sede !== null && $this->sede->id === $sede->id];
        }

        if ($this->sinSedes()) {
            $opciones[] = ['valor' => '', 'texto' => 'Sin sedes configuradas', 'elegida' => false];
        }

        return $opciones;
    }

    /**
     * El día no laborable (WEB-2.8): el escritorio **aborta** el agendamiento cuando el día tiene motivo
     * (`w_nueva_cita_7.srw:698-706`); acá se avisa con el mismo texto y se deja decidir: "hoy es feriado
     * pero el Dr. atiende igual" es un caso real.
     */
    public function avisoNoLaborable(): ?string
    {
        return $this->avisoNoLaborable;
    }

    /** La jornada de esa sede ese día: el cupo y el número que le tocaría al próximo paciente. */
    public function jornada(): ?JornadaVista
    {
        return $this->jornada ? new JornadaVista($this->jornada, [], [], $this->ahora, false) : null;
    }

    /** ¿La hora elegida no cae en ningún bloque configurado de ese día? */
    public function horaFueraDeBloque(): bool
    {
        return $this->jornada !== null && $this->bloques->isNotEmpty() && $this->jornada->horario === null;
    }

    // --------------------------------------------------------------------------------------- Paso 2

    /** La pregunta por la cita pendiente: no se escribió nada todavía, así que decide el usuario. */
    public function pendiente(): ?array
    {
        if ($this->pendiente === null) {
            return null;
        }

        $otra = $this->pendiente['cita'];
        $sede = $this->sedes->firstWhere('medical_center_id', $this->pendiente['centroId']);

        return [
            'paciente' => $this->paciente['nombre'] ?? 'Este paciente',
            'fecha'    => $otra->fecha->format('d/m/Y'),
            'hora'     => $otra->hora_ini ? substr($otra->hora_ini, 0, 5) : null,
            'sede'     => $sede ? ($sede->medicalCenter->name ?? 'esa sede') : null,
        ];
    }

    /** @return array{id:?int, nombre:string, numhistoria:?int}|null */
    public function paciente(): ?array
    {
        return $this->paciente;
    }

    public function pacienteId()
    {
        return $this->paciente['id'] ?? $this->valor('paciente_id');
    }

    /** El alta de un paciente nuevo se muestra abierta si ya se empezó a escribir. */
    public function altaAbierta(): bool
    {
        return (bool) $this->valor('nuevo_cedula');
    }

    // --------------------------------------------------------------------------------------- Paso 3

    /** En una sede con hora de cita la hora es la cita; por orden de llegada la pone el bloque. */
    public function esConHora(): bool
    {
        return $this->sede !== null && $this->sede->modalidad === Office::MODALIDAD_HORA;
    }

    public function mostrarBloques(): bool
    {
        return ! $this->esConHora() && $this->bloques->count() > 1;
    }

    /** @return array<int,array{valor:int, texto:string, elegido:bool}> */
    public function opcionesDeBloque(): array
    {
        return $this->bloques->map(fn ($bloque, $indice) => [
            'valor'   => (int) $indice,
            'texto'   => substr($bloque->hora_inicio, 0, 5) . ' a ' . substr($bloque->hora_fin, 0, 5),
            'elegido' => (int) $this->valor('bloque', 0) === (int) $indice,
        ])->values()->all();
    }

    /**
     * Los motivos del catálogo; si el de la cita no está (se guardó otro código), se agrega para no
     * perderlo al editar.
     *
     * @return array<int,array{valor:string, texto:string, elegido:bool}>
     */
    public function opcionesDeMotivo(): array
    {
        $elegido = (string) $this->valor('motivo');
        $opciones = $this->motivos->map(fn ($motivo) => [
            'valor'   => (string) $motivo->codigo,
            'texto'   => $motivo->tipo_atencion ?: (string) $motivo->codigo,
            'elegido' => $elegido === (string) $motivo->codigo,
        ])->values()->all();

        if ($elegido !== '' && ! $this->motivos->contains(fn ($m) => (string) $m->codigo === $elegido)) {
            $opciones[] = ['valor' => $elegido, 'texto' => $elegido . ' (no está en el catálogo)', 'elegido' => true];
        }

        return $opciones;
    }

    /** El precio del motivo elegido: es lo que se cobra si el monto se deja vacío. */
    public function precioDelMotivo()
    {
        return $this->motivos->firstWhere('codigo', $this->valor('motivo'))?->precio;
    }

    /**
     * El motivo elegido **admite varias citas pendientes** (WEB-2.17): con él no se pregunta por la cita
     * anterior. Se dice acá para que el usuario entienda por qué no le preguntaron —y para que, con un
     * motivo común, sepa que la marca existe—. Sale del mismo catálogo con el que se armó el `<select>`.
     */
    public function motivoAdmiteVarias(): bool
    {
        return (bool) $this->motivos->firstWhere('codigo', $this->valor('motivo'))?->admite_varias_citas_pendientes;
    }
}
