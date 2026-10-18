<?php

namespace App\Clinica\Pacientes\Vistas;

use App\Actions\Pacientes\IdentidadDePaciente;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Vistas\ConValoresDeFormulario;
use App\Especialidades\ConfiguracionDeLaEspecialidad;
use App\Models\Paciente;
use App\Models\Specialty;
use App\Support\FechaClinica;
use Illuminate\Support\Collection;

/**
 * El formulario de **alta y edición de paciente** de la web clínica (WEB-3.2): `/clinica/pacientes/nuevo`
 * y `/clinica/pacientes/{paciente}/editar` son la misma pantalla con el paciente puesto o no.
 *
 * Los campos son los del alta del legado (`w_agregar_pacientes.srw` -> `d_a_pacientes_prueba_2.srd`) que
 * tienen **columna real** en `pacientes`: la cédula con su opción "S.C.", los apellidos y nombres, el
 * sexo, la nacionalidad, la fecha y el lugar de nacimiento, el estado civil, la dirección, el celular, el
 * e-mail, la profesión, la ocupación, la escolaridad, el seguro/empresa y el referido. Lo que **no** entra
 * está dicho en el docblock de `PacienteController::formulario()` (foto, representante) y en `ROADMAP.md`.
 *
 * Los valores del formulario salen de `ConValoresDeFormulario`: primero lo que el usuario escribió
 * (`old()`, para no perder nada cuando la validación falla) y, si no, lo que tiene el paciente.
 */
final class FormularioDePacienteVista
{
    use ConValoresDeFormulario;

    /** La nacionalidad del legado (`dw_1.object.nac`): `V` venezolano, `E` extranjero, `C` la cédula. */
    public const NACIONALIDADES = [
        'V' => 'Venezolano',
        'E' => 'Extranjero',
    ];

    /** El sexo del legado (`values="Masculino M/Femenino F/"`). */
    public const SEXOS = [
        'M' => 'Masculino',
        'F' => 'Femenino',
    ];

    /** El estado civil del legado (`values="Soltero(a) S/Casado(a) C/Divorciado(a) D/Viudo(a) V/"`). */
    public const ESTADOS_CIVILES = [
        'S' => 'Soltero(a)',
        'C' => 'Casado(a)',
        'D' => 'Divorciado(a)',
        'V' => 'Viudo(a)',
    ];

    /**
     * @param  Paciente|null  $editando  el paciente que se edita; `null` = alta. El nombre es el que
     *                                   espera `ConValoresDeFormulario` (de ahí saca los valores que el
     *                                   usuario todavía no tocó).
     * @param  ?string  $numhistoria  el del vínculo (solo para mostrarlo; acá no se asigna)
     * @param  Collection<int,object>  $seguros  el catálogo de `seg_emp` del médico del contexto
     * @param  ?Specialty  $especialidad  la del contexto: de su manifiesto salen los valores por defecto
     *                                    del **alta** (O-01)
     */
    public function __construct(
        private ?Paciente $editando,
        private ?string $numhistoria,
        private Collection $seguros,
        private ?Specialty $especialidad = null,
    ) {
    }

    // ----------------------------------------------------------------------------------- Encabezado

    public function editando(): bool
    {
        return $this->editando !== null;
    }

    public function titulo(): string
    {
        return $this->editando() ? 'Editar la ficha' : 'Nuevo paciente';
    }

    public function subtitulo(): string
    {
        if (! $this->editando()) {
            return 'Nombre, apellido y cédula son lo único obligatorio. Si la cédula ya está cargada, el '
                . 'paciente se enlaza a su ficha y no se pisa ningún dato que ya tenga.';
        }

        $nombre = trim(($this->editando->apellidos ?? '') . ', ' . ($this->editando->nombres ?? ''), ', ');

        return 'Ficha de ' . ($nombre !== '' ? $nombre : 'un paciente sin nombre')
            . ($this->numhistoria !== null ? ' (historia ' . $this->numhistoria . ')' : ' (todavía sin historia)')
            . '. Los cambios son de este consultorio.';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar cambios' : 'Guardar el paciente';
    }

    public function urlGuardar(): string
    {
        return $this->editando()
            ? route('clinica.pacientes.actualizar', $this->editando->getKey())
            : route('clinica.pacientes.guardar');
    }

    public function urlVolver(): string
    {
        return $this->editando()
            ? route('clinica.pacientes.ver', $this->editando->getKey())
            : route('clinica.pacientes');
    }

    public function textoVolver(): string
    {
        return $this->editando() ? 'Ficha del paciente' : 'Pacientes';
    }

    // --------------------------------------------------------------------------------------- Campos

    /**
     * ¿El formulario muestra el paciente como "sin cédula" (la opción "S.C." del legado)?
     *
     * Sí cuando la cédula guardada es el **marcador escrito** (`S.C.`), que es lo que puede haber dejado el
     * legado. **No** cuando el campo está simplemente vacío: en el alta tiene que arrancar sin el tilde
     * —si no, el formulario nunca pediría la cédula, que es obligatoria— y en la edición el tilde se marca
     * a mano (el JS de la pantalla) cuando el paciente no tiene documento.
     *
     * El marcador se reconoce con `IdentidadDePaciente`, que es donde vive esa definición: es la misma que
     * usa la Action para no enlazar dos pacientes distintos sin documento.
     */
    public function sinCedula(): bool
    {
        $valor = $this->valor('cedula');

        return $valor !== null && $valor !== '' && IdentidadDePaciente::esSinCedula($valor);
    }

    /** La cédula escrita, vacía si va sin cédula (el marcador no se muestra como si fuera un documento). */
    public function cedula(): string
    {
        return $this->sinCedula() ? '' : (string) ($this->valor('cedula') ?? '');
    }

    /**
     * Una fecha (`fnacimiento`, `fingreso`) lista para un `<input type="date">`, o `''` si el legado no
     * trae una fecha usable. La decisión —incluido el año imposible que Carbon convierte en el año -1— la
     * toma `FechaClinica`, que es donde vive el conocimiento de las fechas del legado.
     *
     * La **fecha de ingreso** del alta propone **hoy** (O-02, 2026-10-17): es la fecha con la que el
     * paciente entra al consultorio y la que usan los listados entre fechas; sin ella, el que se registra
     * hoy no aparece en «pacientes por rango». Es el hoy **del consultorio** (`FechaDelConsultorio`), no el
     * del servidor: pasadas las 20:00 de Caracas en UTC ya es mañana. Al editar manda la guardada.
     */
    public function fecha(string $campo): string
    {
        return FechaClinica::paraInput($this->valor($campo, $this->fechaPorDefecto($campo)));
    }

    /** La fecha con la que arranca un campo **en el alta**: hoy para `fingreso`, nada para el resto. */
    private function fechaPorDefecto(string $campo): ?string
    {
        if ($this->editando() || $campo !== 'fingreso') {
            return null;
        }

        return FechaDelConsultorio::hoy()->toDateString();
    }

    /** @return array<int,array{valor:string,texto:string}> */
    public function nacionalidades(): array
    {
        return $this->opcionesCon(self::NACIONALIDADES, (string) $this->valor('nac', 'V'));
    }

    /**
     * El sexo del paciente. En el **alta** de ginecología arranca en **Femenino** (O-01, 2026-10-17): la
     * especialidad lo declara en su manifiesto (`paciente.sexo_por_defecto`), así que una especialidad que
     * no lo declare —o un consultorio sin especialidad— sigue abriendo en «— Sin dato —». El valor se puede
     * cambiar y «— Sin dato —» sigue disponible. Al **editar** manda lo guardado: el default es del alta.
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    public function sexos(): array
    {
        return $this->opcionesCon(self::SEXOS, (string) $this->valor('sexo', $this->sexoPorDefecto()));
    }

    /** El sexo del manifiesto de la especialidad, si lo declara y es un código del legado. */
    private function sexoPorDefecto(): ?string
    {
        return $this->editando() ? null : self::sexoPorDefectoDe($this->especialidad);
    }

    /**
     * El sexo con el que arranca un alta **en esa especialidad** (O-01), o `null` si no lo declara.
     *
     * Es la regla compartida por las dos formas de dar de alta un paciente en la web: el formulario de
     * la ficha (esta clase) y el alta mínima del paso 2 de «Nueva cita» (`FormularioDeCitaVista`), que la
     * recibe armada. Un código que no es del catálogo no se inventa.
     */
    public static function sexoPorDefectoDe(?Specialty $especialidad): ?string
    {
        $sexo = ConfiguracionDeLaEspecialidad::valor($especialidad, 'paciente.sexo_por_defecto');

        return is_string($sexo) && array_key_exists($sexo, self::SEXOS) ? $sexo : null;
    }

    /** @return array<int,array{valor:string,texto:string}> */
    public function estadosCiviles(): array
    {
        return $this->opcionesCon(self::ESTADOS_CIVILES, (string) $this->valor('codeestado'));
    }

    /**
     * El seguro/empresa del consultorio. Arranca en `00` (PERSONAL, el default del escritorio,
     * `w_agregar_pacientes.srw:378`) solo en el **alta**: en la edición, un paciente sin seguro se queda
     * sin seguro en vez de que la pantalla le invente uno.
     *
     * Si el paciente ya trae un código que no está en el catálogo (de otra instalación o de un catálogo
     * que todavía no bajó), la opción se agrega para no perderlo al guardar.
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    public function seguros(): array
    {
        $elegido = (string) $this->seguroElegido();

        $opciones = $this->seguros->map(fn ($seguro) => [
            'valor' => (string) $seguro->codesegemp,
            'texto' => trim((string) $seguro->nombre),
        ])->values()->all();

        if ($elegido !== '') {
            $esta = collect($opciones)->contains(fn ($opcion) => $opcion['valor'] === $elegido);

            if (! $esta) {
                $opciones[] = ['valor' => $elegido, 'texto' => $elegido . ' (no está en el catálogo)'];
            }
        }

        return $opciones;
    }

    /**
     * El código del seguro que el `<select>` tiene que dejar elegido: lo que el usuario escribió
     * (`old()`, para no perderlo cuando la validación falla), lo que tiene el paciente, o el **default del
     * alta**.
     */
    public function seguroElegido(): ?string
    {
        return $this->valor('codesegemp', $this->seguroPorDefecto());
    }

    /**
     * El seguro con el que arranca el **alta**: `00` (PERSONAL), el default del escritorio. Solo si ese
     * código está en el catálogo del consultorio: donde no hay catálogo la pantalla no inventa un seguro.
     */
    private function seguroPorDefecto(): ?string
    {
        if ($this->editando() || ! $this->seguros->contains(fn ($seguro) => (string) $seguro->codesegemp === '00')) {
            return null;
        }

        return '00';
    }

    public function sinSeguros(): bool
    {
        return $this->seguros->isEmpty();
    }

    // --------------------------------------------------------------------------------------- Atender

    /**
     * A dónde ir si el usuario contesta que sí a "¿Atenderá a este paciente hoy?".
     *
     * El escritorio, cuando la respuesta es sí, **pasa al paciente a la cola del día** y pregunta la razón
     * de consulta (`04-historia-clinica.md`). La web no duplica ese flujo —la cola del día y la razón son
     * de la agenda—: el atajo abre el alta de cita con el paciente ya elegido, que es donde la razón se
     * pide (WEB-2.2). La respuesta por defecto es **no**: agendar es una decisión, no un efecto del alta.
     */
    public function urlAtenderHoy(): string
    {
        return route('clinica.agenda.nueva', [
            'fecha'       => FechaDelConsultorio::hoy()->toDateString(),
            'paciente_id' => $this->editando?->getKey(),
        ]);
    }

    public function urlCancelar(): string
    {
        return $this->urlVolver();
    }

    public function urlFicha(): string
    {
        return route('clinica.pacientes.ver', $this->editando->getKey());
    }

    /** @param  array<string,string>  $opciones */
    private function opcionesCon(array $opciones, string $elegido): array
    {
        return collect($opciones)
            ->map(fn ($texto, $valor) => ['valor' => (string) $valor, 'texto' => $texto, 'elegido' => (string) $valor === $elegido])
            ->values()
            ->all();
    }
}
