<?php

namespace App\Clinica\Agenda;

use App\Actions\Pacientes\IdentidadDePaciente;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\DuenoDeLaHistoria;
use App\Models\Cola;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use App\Models\PacienteChamo;
use Illuminate\Validation\ValidationException;

/**
 * Los pacientes a los que se les agenda una cita desde la web: el elegido del listado, la ficha que ya
 * exista con la cédula escrita o los datos mínimos de uno nuevo. **No escribe nada**: el alta la hace
 * `CrearPaciente` recién cuando la cita se guarda, para que cancelar no deje un paciente suelto (igual
 * que el móvil).
 */
class PacientesDeCita
{
    /** Un paciente del `reg_medico` del contexto; de otro médico no se muestra ni se confirma que exista. */
    public function delContexto(array $contexto, int $pacienteId): ?Paciente
    {
        // El vínculo es **del consultorio** (`(reg_medico, paciente_id)`), no del médico: el que lo creó
        // pudo ser otro doctor del mismo centro o el centro mismo.
        $relacion = MedicoPaciente::where('reg_medico', $contexto['reg_medico'])
            ->where('paciente_id', $pacienteId)
            ->first();

        return $relacion !== null ? Paciente::find($relacion->paciente_id) : null;
    }

    public function numHistoriaDe(array $contexto, Paciente $paciente): ?int
    {
        $numhistoria = MedicoPaciente::where('reg_medico', $contexto['reg_medico'])
            ->where('paciente_id', $paciente->id)
            ->value('numhistoria');

        return $numhistoria !== null ? (int) $numhistoria : null;
    }

    /**
     * La ficha del paciente de una cita: por `paciente_sinhistoria_id` o por el número de historia,
     * resuelto **con la regla única** (`DuenoDeLaHistoria`) y dentro del registro de la cita.
     *
     * Es la misma regla que la agenda y la consulta: con dos pacientes en el mismo número, el formulario
     * de la cita no puede abrir la ficha de uno y la consulta la del otro.
     *
     * ⚠️ **El orden no es el de la agenda**: esto prefiere el ancla `paciente_sinhistoria_id`, que una cita
     * numerada puede arrastrar de otro paciente. El nombre que se muestra en la agenda y en «Compartir la cita»
     * sale de `nombreDeLaCita()`; unificar este método es otra tarea (cambiaría el formulario de la cita).
     */
    public function deLaCita(Cola $cita): ?Paciente
    {
        if ($cita->paciente_sinhistoria_id !== null) {
            return Paciente::find($cita->paciente_sinhistoria_id);
        }

        if ($cita->numhistoria === null) {
            return null;
        }

        $pacienteId = (new DuenoDeLaHistoria())->de($cita->reg_medico, $cita->numhistoria);

        return $pacienteId !== null ? Paciente::find($pacienteId) : null;
    }

    /**
     * **El nombre del paciente de una cita**, para las pantallas de la agenda que lo nombran en una frase
     * (la pantalla «Compartir la cita», trampa 145).
     *
     * Es **el mismo nombre de la fila de la agenda**, porque resuelve con el mismo orden que
     * `ArmadorDeAgenda::fichaPorHistoria()`: **el número primero**, con su dueño (`DuenoDeLaHistoria`) y dentro
     * del registro de la cita, y `paciente_sinhistoria_id` **sólo** cuando la cita no tiene número. El orden
     * importa: `cola.paciente_sinhistoria_id` es el ancla que se escribe al agendar sin número y **no se limpia**
     * si después se numera la historia, así que una cita numerada puede arrastrar el id de otro paciente.
     *
     * **Pediatría**: ahí el ABM real de pacientes es `paciente_chamo` (una fila por `historia`) y `pacientes`
     * puede estar vacía o ser la de otro registro del médico, así que si por el número no hay nombre en
     * `pacientes` se busca el niño en su tabla, acotado al registro de la cita. En las demás especialidades esa
     * tabla no tiene filas para el registro y no cambia nada.
     *
     * Devuelve `'el paciente'` cuando no hay ningún nombre: es la frase que la pantalla ya usaba.
     */
    public function nombreDeLaCita(Cola $cita): string
    {
        $paciente = $this->deLaCitaComoLaAgenda($cita);

        // El mismo texto que `ArmadorDeAgenda::nombreDe()`: `Apellidos, Nombres` (en pediatría el nombre completo
        // vive en `nombres` y `apellidos` está vacío, así que sale el nombre solo).
        $nombre = $paciente !== null
            ? trim(($paciente->apellidos ?? '') . ', ' . ($paciente->nombres ?? ''), ', ')
            : '';

        return $nombre !== '' ? $nombre : ($this->nombreDelChamo($cita) ?? 'el paciente');
    }

    /**
     * La ficha de la cita con **el orden de la agenda**: el dueño del número si la cita tiene número, y el
     * ancla `paciente_sinhistoria_id` sólo si no lo tiene.
     *
     * No se reusa `deLaCita()` para no cambiar el formulario de la cita, que hoy prefiere el ancla (es una
     * inconsistencia heredada, señalada y no arreglada acá).
     */
    private function deLaCitaComoLaAgenda(Cola $cita): ?Paciente
    {
        if ($cita->numhistoria !== null) {
            $pacienteId = (new DuenoDeLaHistoria())->de($cita->reg_medico, $cita->numhistoria);

            return $pacienteId !== null ? Paciente::find($pacienteId) : null;
        }

        return $cita->paciente_sinhistoria_id !== null
            ? Paciente::find((int) $cita->paciente_sinhistoria_id)
            : null;
    }

    /**
     * El nombre del niño en `paciente_chamo` para la historia de la cita, en el registro de la cita; o `null`.
     */
    private function nombreDelChamo(Cola $cita): ?string
    {
        $historia = CamposDePaciente::historiaNumerica($cita->numhistoria);

        if ($historia === null) {
            return null;
        }

        $nombre = PacienteChamo::where('reg_medico', $cita->reg_medico)
            ->where('historia', $historia)
            ->orderBy('id')
            ->value('nombre');

        return CamposDePaciente::texto($nombre);
    }

    /**
     * A quién se le está agendando, **sin escribir nada**: el paciente elegido del listado o la ficha que
     * ya exista con la cédula nueva (el alta enlaza, no duplica).
     *
     * @return array{paciente: ?Paciente, numhistoria: ?int}
     */
    public function identificar(array $contexto, array $valores): array
    {
        if (! empty($valores['paciente_id'])) {
            $paciente = $this->delContexto($contexto, (int) $valores['paciente_id']);

            // De otro médico no se agenda desde acá (la búsqueda tampoco lo ofrece).
            abort_if($paciente === null, 404);

            return ['paciente' => $paciente, 'numhistoria' => $this->numHistoriaDe($contexto, $paciente)];
        }

        $cedula = trim((string) ($valores['nuevo_cedula'] ?? ''));
        if (trim((string) ($valores['nuevo_nombres'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'paciente_id' => 'Elige un paciente de la lista o carga los datos de uno nuevo.',
            ]);
        }

        // Sin cédula (vacía o `S.C.`) no se enlaza con nadie: es una ficha nueva (`CrearPaciente`)… salvo que sea la que este mismo
        // consultorio acaba de crear con los mismos datos (el doble clic en «Guardar»: sin esto nacen dos fichas y dos citas).
        $paciente = IdentidadDePaciente::esSinCedula($cedula) ? $this->fichaRecienCreada($contexto, $valores) : Paciente::where('cedula', $cedula)->first();
        if ($paciente === null) {
            return ['paciente' => null, 'numhistoria' => null];
        }

        // La cédula ya existe: es la misma persona. Si ya es paciente de este médico, su número de
        // historia es el que va en la cita.
        return ['paciente' => $paciente, 'numhistoria' => $this->numHistoriaDe($contexto, $paciente)];
    }

    /** La ficha sin cédula que este consultorio creó hace pocos minutos con el mismo nombre, apellido y teléfono, o `null`. */
    private function fichaRecienCreada(array $contexto, array $valores): ?Paciente
    {
        $nombres = trim((string) ($valores['nuevo_nombres'] ?? ''));
        $apellidos = trim((string) ($valores['nuevo_apellidos'] ?? ''));
        $telefono = $this->texto($valores['nuevo_telefono'] ?? null);

        if ($nombres === '' || $apellidos === '' || $telefono === null) {
            return null;
        }

        return Paciente::query()
            ->where('nombres', $nombres)
            ->where('apellidos', $apellidos)
            ->where('telefono', $telefono)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->whereIn('id', MedicoPaciente::where('reg_medico', $contexto['reg_medico'])->select('paciente_id'))
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string,mixed> Las columnas del alta mínima de paciente (las mismas que el móvil). */
    public function columnasDelNuevo(array $valores): array
    {
        $columnas = [
            'cedula'    => $this->texto($valores['nuevo_cedula'] ?? null),
            'nombres'   => trim((string) ($valores['nuevo_nombres'] ?? '')),
            // En pediatría el nombre es el completo y el apellido viene vacío: se guarda nulo, no ''.
            'apellidos' => $this->texto($valores['nuevo_apellidos'] ?? null),
            'telefono'  => $this->texto($valores['nuevo_telefono'] ?? null),
            'email'     => $this->texto($valores['nuevo_email'] ?? null),
            'sexo'      => $this->texto($valores['nuevo_sexo'] ?? null),
            'fnacimiento' => $this->texto($valores['nuevo_fnacimiento'] ?? null),
            'nac'         => $this->texto($valores['nuevo_nac'] ?? null),
            'lnacimiento' => $this->texto($valores['nuevo_lnacimiento'] ?? null),
            'direccion'   => $this->texto($valores['nuevo_direccion'] ?? null),
            // Los campos de PediaReport que sí viven en `pacientes`: el grupo etario (que el escritorio
            // guarda en `escolaridad`), el referido (`dependencia`) y la foto.
            'escolaridad' => $this->texto($valores['nuevo_grupo_etario'] ?? null),
            'dependencia' => $this->texto($valores['nuevo_referido'] ?? null),
            'foto_pac'    => $this->texto($valores['nuevo_foto_pac'] ?? null),
        ];

        // La fecha de ingreso se agrega **sólo si vino**: si la clave viajara en `null`, `CrearPaciente`
        // no le pondría la de hoy (él sólo la completa cuando la clave no está).
        $fingreso = $this->texto($valores['nuevo_fingreso'] ?? null);
        if ($fingreso !== null) {
            $columnas['fingreso'] = $fingreso;
        }

        return $columnas;
    }

    /**
     * La ficha del paciente elegido, para mostrarla en el formulario. Si todavía no se guardó (alta nueva)
     * se arma con lo que se escribió, para que la repregunta por la cita pendiente no la pierda de vista.
     *
     * @return array{id: ?int, nombre: string, numhistoria: ?int}|null
     */
    public function ficha(?Paciente $paciente, ?int $numhistoria, array $valores = []): ?array
    {
        if ($paciente !== null) {
            return [
                'id'          => $paciente->id,
                'nombre'      => trim(($paciente->apellidos ?? '') . ', ' . ($paciente->nombres ?? ''), ', '),
                'numhistoria' => $numhistoria,
            ];
        }

        $nombres = trim((string) ($valores['nuevo_nombres'] ?? ''));
        $apellidos = trim((string) ($valores['nuevo_apellidos'] ?? ''));
        if ($nombres === '' && $apellidos === '') {
            return null;
        }

        return ['id' => null, 'nombre' => trim($apellidos . ', ' . $nombres, ', '), 'numhistoria' => null];
    }

    private function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
