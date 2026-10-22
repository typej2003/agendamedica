<?php

namespace App\Clinica\Consultorio\Vistas;

use App\Models\MedicalCenter;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Support\Collection;

/**
 * **Sedes y consultorios** (`/clinica/sedes`, pedido de Alexander y del usuario del 2026-10-20): una sola pantalla
 * donde antes había dos («Sedes» y «Consultorios»). Cada sede es una tarjeta con **sus consultorios** adentro, y las
 * altas y ediciones van en **paneles laterales** (`<x-ui.dialogo lateral>`), así crear el consultorio no obliga a
 * salir a otro menú a crear la sede.
 *
 * Los paneles de la pantalla:
 *
 *  - **sede** (alta/edición): al dar de alta una sede, la pantalla vuelve con la pregunta
 *    «¿Agregar un consultorio en esta sede?» (`sede_creada` en la sesión), porque lo normal es crearla para trabajar ahí.
 *  - **consultorio** (alta/edición): con la sede ya elegida si se abrió desde su tarjeta (`?en=`) o si hay una sola, y
 *    con «Nueva sede» al lado del selector, que abre el panel de la **sede rápida** encima —sin perder lo escrito— y la
 *    crea por `fetch` (el mismo `POST /clinica/sedes`, que responde JSON).
 *  - **horario**: el alta de un bloque del consultorio (A-10: los horarios se ven y se cargan sin salir de la pantalla).
 *
 * Los formularios mandan un `formulario` oculto: comparten nombres (`id`, `phone`, `activo`) y sin él un error en la
 * sede reescribiría el teléfono del consultorio con lo que se puso en la sede.
 *
 * La sede es del **catálogo de la clínica** y el consultorio **del médico del contexto**: sin contexto
 * (`$consultorios === null`) la pantalla muestra las sedes y nada del consultorio.
 */
final class SedesYConsultoriosVista
{
    private const ABREVIATURAS = [1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Ju', 5 => 'Vi', 6 => 'Sa', 7 => 'Do'];

    /**
     * @param  Collection<int,MedicalCenter>  $sedes  las que se listan (activas, o todas con `verTodas`)
     * @param  Collection<int,Office>|null  $consultorios  los del médico del contexto, con `schedules`; `null` sin contexto
     * @param  Collection<int,\App\Models\City>  $ciudades
     * @param  string|null  $panel  el panel pedido por la URL (`?panel=sede|consultorio`)
     * @param  int|null  $sedePreelegida  la sede del consultorio nuevo (`?en=`)
     * @param  int|null  $sedeCreada  la sede recién creada, para la pregunta (`sede_creada` de la sesión)
     */
    public function __construct(
        private Collection $sedes,
        private ?Collection $consultorios,
        private Collection $ciudades,
        private bool $verTodas,
        private ?MedicalCenter $sedeEditando,
        private ?Office $consultorioEditando,
        private ?string $panel,
        private ?int $sedePreelegida,
        private ?int $sedeCreada,
        private int $totalActivas,
    ) {
    }

    // ------------------------------------------------------------------------------------- Navegación

    /** La URL de la pantalla con el filtro actual (y lo que se le sume). */
    public function url(array $query = []): string
    {
        return route('clinica.sedes', array_merge($this->verTodas ? ['todas' => 1] : [], $query));
    }

    public function urlVolver(): string
    {
        return route('clinica.inicio');
    }

    public function verTodas(): int
    {
        return $this->verTodas ? 1 : 0;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function filtros(): array
    {
        return [
            ['texto' => 'Activas (' . $this->totalActivas . ')', 'url' => route('clinica.sedes'), 'activa' => ! $this->verTodas],
            ['texto' => 'Todas', 'url' => route('clinica.sedes', ['todas' => 1]), 'activa' => $this->verTodas],
        ];
    }

    public function urlNuevaSede(): string
    {
        return $this->url(['panel' => 'sede']);
    }

    /** ¿Hay un médico en el contexto? Sin él no hay consultorios que mostrar ni que cargar. */
    public function puedeCargarConsultorios(): bool
    {
        return $this->consultorios !== null;
    }

    /**
     * El aviso con el que la pantalla dice **por qué no ofrece cargar consultorios** y qué hacer.
     *
     * Sin contexto la pantalla sólo puede mostrar el catálogo de sedes, y antes eso era un callejón: el médico
     * nuevo llegaba acá, no veía ningún botón y el menú lateral se quedaba en «Inicio». Hoy la pantalla resuelve
     * sola el dueño cuando hay **un solo registro** (lo hace `clinica.contexto:consultorios`), así que llegar a
     * este aviso significa que hay algo que elegir de verdad: dos registros, dos médicos o dos especialidades.
     */
    public function avisoSinContexto(): ?string
    {
        return $this->puedeCargarConsultorios()
            ? null
            : 'Los consultorios son del médico con el que trabajas: elige con cuál antes de cargarlos.';
    }

    public function urlElegirConsultorio(): string
    {
        return route('clinica.contexto');
    }

    // ------------------------------------------------------------------------------------- Las tarjetas

    public function estaVacio(): bool
    {
        return $this->sedes->isEmpty();
    }

    /** ¿No hay ni una sede activa en la clínica? Es el primer paso, y la tarjeta guía lo dice. */
    public function sinNingunaSede(): bool
    {
        return $this->totalActivas === 0;
    }

    public function tituloVacio(): string
    {
        return $this->sinNingunaSede() ? 'Empieza por tu primera sede' : 'No hay sedes que mostrar';
    }

    /**
     * Las tarjetas a la vista: las sedes **donde el médico tiene consultorio** (más la que se acaba de crear). Si no
     * tiene ninguno —o no hay contexto—, todas: no tendría sentido esconder el catálogo justo cuando hay que elegir.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tarjetas(): array
    {
        return $this->sedesPropias()->map(fn (MedicalCenter $sede) => $this->tarjeta($sede))->values()->all();
    }

    /**
     * El resto del catálogo de la clínica (sedes sin consultorios de este médico), plegado debajo: el catálogo es
     * compartido y en producción tiene las sedes de todos, que sólo interesan para empezar a atender en una.
     *
     * @return array<int,array<string,mixed>>
     */
    public function otrasSedes(): array
    {
        $propias = $this->sedesPropias()->map(fn (MedicalCenter $sede) => (int) $sede->id)->all();

        return $this->sedes
            ->reject(fn (MedicalCenter $sede) => in_array((int) $sede->id, $propias, true))
            ->map(fn (MedicalCenter $sede) => $this->tarjeta($sede))
            ->values()
            ->all();
    }

    public function tituloOtrasSedes(): string
    {
        return 'Otras sedes de la clínica (' . count($this->otrasSedes()) . ')';
    }

    /** @return Collection<int,MedicalCenter> */
    private function sedesPropias(): Collection
    {
        if ($this->consultorios === null || $this->consultorios->isEmpty()) {
            return $this->sedes;
        }

        $conConsultorio = $this->consultorios->map(fn (Office $consultorio) => (int) $consultorio->medical_center_id)->unique()->all();

        return $this->sedes
            ->filter(fn (MedicalCenter $sede) => in_array((int) $sede->id, $conConsultorio, true) || (int) $sede->id === $this->sedeCreada)
            ->values();
    }

    /** @return array<string,mixed> */
    private function tarjeta(MedicalCenter $sede): array
    {
        $consultorios = $this->consultorios === null
            ? collect()
            : $this->consultorios->filter(fn (Office $consultorio) => (int) $consultorio->medical_center_id === (int) $sede->id);

        return [
            'id'          => (int) $sede->id,
            'nombre'      => $sede->name,
            'direccion'   => $sede->address,
            'ciudad'      => $sede->city->name ?? null,
            'telefono'    => $sede->phone ?: null,
            'activa'      => (bool) $sede->activo,
            'estado'      => $sede->activo ? null : ['tono' => 'neutro', 'icono' => 'bi-slash-circle', 'texto' => 'Inactiva'],
            'urlEditar'   => $this->url(['sede' => $sede->id]),
            'urlActivar'  => route('clinica.sedes.activar', $sede->id),
            // La baja es desactivar: la cita guarda `medical_center_id` y el historial tiene que poder seguir leyendo el lugar.
            'activar'     => $sede->activo
                ? ['texto' => 'Desactivar', 'icono' => 'bi-slash-circle', 'peligro' => true]
                : ['texto' => 'Activar', 'icono' => 'bi-check-circle', 'peligro' => false],
            'consultorios' => $consultorios->map(fn (Office $consultorio) => $this->filaDeConsultorio($consultorio))->values()->all(),
            // Un consultorio sólo se cuelga de una sede abierta (`GuardarConsultorio`).
            'puedeAgregar' => $this->puedeCargarConsultorios() && (bool) $sede->activo,
            'urlNuevoConsultorio' => $this->url(['panel' => 'consultorio', 'en' => $sede->id]),
        ];
    }

    /** @return array<string,mixed> */
    private function filaDeConsultorio(Office $consultorio): array
    {
        $modalidades = Office::modalidades();
        $texto = $modalidades[$consultorio->modalidad] ?? $consultorio->modalidad;

        return [
            'id'        => (int) $consultorio->id,
            'nombre'    => $consultorio->office_number,
            'modalidad' => $consultorio->modalidad === Office::MODALIDAD_ORDEN
                ? ['tono' => 'info', 'icono' => 'bi-people', 'texto' => $texto]
                : ['tono' => 'neutro', 'icono' => 'bi-clock', 'texto' => $texto],
            'duracion'  => $consultorio->duracion_cita ? $consultorio->duracion_cita . ' min' : null,
            'estado'    => $consultorio->activo ? null : ['tono' => 'neutro', 'icono' => 'bi-slash-circle', 'texto' => 'Inactivo'],
            'bloques'   => $this->bloques($consultorio),
            'urlHorarios'      => route('clinica.consultorios.horarios', $consultorio),
            'urlEditar'        => $this->url(['consultorio' => $consultorio->id]),
            'urlActivar'       => route('clinica.consultorios.activar', $consultorio->id),
            'urlGuardarBloque' => route('clinica.consultorios.horarios.guardar', $consultorio),
            'tituloHorario'    => 'Horario · ' . $consultorio->office_number,
            'activar'   => $consultorio->activo
                ? ['texto' => 'Desactivar', 'icono' => 'bi-slash-circle', 'peligro' => true]
                : ['texto' => 'Activar', 'icono' => 'bi-check-circle', 'peligro' => false],
        ];
    }

    /**
     * Los bloques de la semana en orden (lunes a domingo, y por hora), cada uno con su papelera.
     *
     * @return array<int,array<string,mixed>>
     */
    private function bloques(Office $consultorio): array
    {
        return $consultorio->schedules
            ->sortBy(fn (OfficeSchedule $bloque) => [(int) $bloque->dia_semana, (string) $bloque->hora_inicio])
            ->map(fn (OfficeSchedule $bloque) => [
                'id'          => (int) $bloque->id,
                'dia'         => self::ABREVIATURAS[(int) $bloque->dia_semana] ?? $bloque->nombreDelDia(),
                'diaCompleto' => $bloque->nombreDelDia(),
                'horario'     => $bloque->rango(),
                'cupo'        => $bloque->cupo ? $bloque->cupo . ' pacientes' : 'sin tope',
                'urlEliminar' => route('clinica.consultorios.horarios.eliminar', [$consultorio, $bloque]),
                // No borra nada clínico, pero cambia la jornada con la que se mide el cupo: se confirma nombrando el bloque.
                'confirmar'   => '¿Quitar el bloque del ' . $bloque->nombreDelDia() . ' de ' . $bloque->rango()
                    . '? Esas horas dejan de contarse como jornada; las citas que ya tenía no se borran.',
            ])
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------------------------- Qué panel se abre

    /**
     * El panel que la pantalla abre al cargar: el del formulario que volvió con errores, el que pide la URL (editar,
     * crear) o la pregunta de la sede recién creada, en ese orden. `null` = ninguno.
     */
    public function panelAlCargar(): ?string
    {
        $fallido = old('formulario');
        if (in_array($fallido, ['sede', 'consultorio', 'horario'], true)) {
            return $fallido === 'sede' || $this->puedeCargarConsultorios() ? $fallido : null;
        }

        if ($this->sedeEditando !== null || $this->panel === 'sede') {
            return 'sede';
        }

        if ($this->puedeCargarConsultorios() && ($this->consultorioEditando !== null || $this->panel === 'consultorio')) {
            return 'consultorio';
        }

        return $this->preguntaSedeCreada() !== null ? 'sede-creada' : null;
    }

    /**
     * «Sede creada. ¿Agregar un consultorio en <sede>?»: sólo si hay médico en el contexto (sin él no hay consultorio
     * que cargar) y la sede está en la lista.
     *
     * @return array{id:int, nombre:string, url:string}|null
     */
    public function preguntaSedeCreada(): ?array
    {
        if ($this->sedeCreada === null || ! $this->puedeCargarConsultorios()) {
            return null;
        }

        $sede = $this->sedes->first(fn (MedicalCenter $sede) => (int) $sede->id === $this->sedeCreada);

        return $sede === null ? null : [
            'id'     => (int) $sede->id,
            'nombre' => $sede->name,
            'url'    => $this->url(['panel' => 'consultorio', 'en' => $sede->id]),
        ];
    }

    // ------------------------------------------------------------------------------------- Panel de la sede

    public function urlGuardarSede(): string
    {
        return route('clinica.sedes.guardar');
    }

    public function editandoSede(): bool
    {
        return $this->sedeEditando !== null;
    }

    public function idSede()
    {
        return $this->deEsteFormulario('sede') ? old('id') : $this->sedeEditando?->id;
    }

    public function valorSede(string $campo, $defecto = null)
    {
        return $this->deEsteFormulario('sede')
            ? old($campo, $defecto)
            : ($this->sedeEditando?->{$campo} ?? $defecto);
    }

    public function tituloSede(): string
    {
        return $this->editandoSede() ? 'Editar la sede' : 'Nueva sede';
    }

    public function botonSede(): string
    {
        return $this->editandoSede() ? 'Guardar cambios' : 'Registrar la sede';
    }

    public function sedeSeSigueUsando(): bool
    {
        if ($this->deEsteFormulario('sede')) {
            return (bool) old('activo');
        }

        return (bool) ($this->sedeEditando?->activo ?? true);
    }

    /**
     * El estado y el país no se preguntan: se derivan de la ciudad (los exige el esquema migrado y una ciudad
     * pertenece a un solo estado). `$conValor = false` es para el panel de la sede rápida, que nace vacío.
     *
     * @return array<int,array{valor:int, texto:string, elegida:bool}>
     */
    public function opcionesDeCiudad(bool $conValor = true): array
    {
        $elegida = $conValor ? (int) $this->valorSede('city_id') : 0;

        return $this->ciudades->map(fn ($ciudad) => [
            'valor'   => (int) $ciudad->id,
            'texto'   => $ciudad->name . ' — ' . ($ciudad->state->name ?? ''),
            'elegida' => $elegida === (int) $ciudad->id,
        ])->values()->all();
    }

    // ------------------------------------------------------------------------------------- Panel del consultorio

    public function urlGuardarConsultorio(): string
    {
        return route('clinica.consultorios.guardar');
    }

    public function editandoConsultorio(): bool
    {
        return $this->consultorioEditando !== null;
    }

    public function idConsultorio()
    {
        return $this->deEsteFormulario('consultorio') ? old('id') : $this->consultorioEditando?->id;
    }

    public function valorConsultorio(string $campo, $defecto = null)
    {
        return $this->deEsteFormulario('consultorio')
            ? old($campo, $defecto)
            : ($this->consultorioEditando?->{$campo} ?? $defecto);
    }

    public function tituloConsultorio(): string
    {
        return $this->editandoConsultorio() ? 'Editar el consultorio' : 'Nuevo consultorio';
    }

    public function botonConsultorio(): string
    {
        return $this->editandoConsultorio() ? 'Guardar cambios' : 'Registrar el consultorio';
    }

    public function consultorioSeSigueUsando(): bool
    {
        if ($this->deEsteFormulario('consultorio')) {
            return (bool) old('activo');
        }

        return (bool) ($this->consultorioEditando?->activo ?? true);
    }

    /**
     * Las sedes donde se puede cargar un consultorio (las activas). La elegida es la del formulario que volvió, la del
     * consultorio que se edita, la de la tarjeta desde la que se abrió (`?en=`) o, si hay **una sola**, ésa.
     *
     * @return array<int,array{valor:int, texto:string, elegida:bool}>
     */
    public function opcionesDeSede(): array
    {
        $activas = $this->sedes->filter(fn (MedicalCenter $sede) => (bool) $sede->activo)->sortBy('name')->values();

        $elegida = (int) $this->valorConsultorio('medical_center_id', $this->sedePreelegida
            ?? ($activas->count() === 1 ? $activas->first()->id : null));

        return $activas->map(fn (MedicalCenter $sede) => [
            'valor'   => (int) $sede->id,
            'texto'   => $sede->name,
            'elegida' => $elegida === (int) $sede->id,
        ])->all();
    }

    /** @return array<int,array{valor:string, texto:string, elegida:bool}> */
    public function opcionesDeModalidad(): array
    {
        $elegida = $this->valorConsultorio('modalidad', Office::MODALIDAD_ORDEN);

        return collect(Office::modalidades())
            ->map(fn ($texto, $clave) => ['valor' => $clave, 'texto' => $texto, 'elegida' => $elegida === $clave])
            ->values()
            ->all();
    }

    public function duracionPorDefecto(): int
    {
        return Office::DURACION_CITA_POR_DEFECTO;
    }

    // ------------------------------------------------------------------------------------- Panel del horario

    /**
     * El alta de un bloque cuando volvió con errores: a qué consultorio iba y lo que quedó escrito (la guía de UI pide
     * no borrar lo escrito ante un error). Sin error, el panel nace vacío y el script le pone el consultorio.
     *
     * @return array{accion:string, titulo:string, consultorio:string, dias:array<int,int>, hora_inicio:string, hora_fin:string, cupo:string}
     */
    public function horario(): array
    {
        $vacio = ['accion' => '', 'titulo' => 'Horario', 'consultorio' => '', 'dias' => [], 'hora_inicio' => '', 'hora_fin' => '', 'cupo' => ''];

        if (! $this->deEsteFormulario('horario') || $this->consultorios === null) {
            return $vacio;
        }

        $consultorio = $this->consultorios->first(fn (Office $consultorio) => (string) $consultorio->id === (string) old('consultorio'));
        if ($consultorio === null) {
            return $vacio;
        }

        return [
            'accion'      => route('clinica.consultorios.horarios.guardar', $consultorio),
            'titulo'      => 'Horario · ' . $consultorio->office_number,
            'consultorio' => (string) $consultorio->id,
            'dias'        => array_map('intval', (array) old('dias', [])),
            'hora_inicio' => (string) old('hora_inicio', ''),
            'hora_fin'    => (string) old('hora_fin', ''),
            'cupo'        => (string) old('cupo', ''),
        ];
    }

    private function deEsteFormulario(string $formulario): bool
    {
        return old('formulario') === $formulario;
    }
}
