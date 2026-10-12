<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Consulta\AgregarDoctorAlCatalogo;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Doctor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * La pantalla de **médicos referidos** del catálogo (`/clinica/catalogos/medicos-referidos`, WEB-8.1b): la libreta de
 * colegas del médico (`doctores`) con su buscador y el diálogo de alta y edición. Como todo ViewModel de la web clínica no
 * consulta la base: recibe la página ya leída y las URLs que resolvió el controlador.
 *
 * El **código** (la `cedula` de `doctores`, que en la práctica es un correlativo) se muestra y no se edita: lo da el alta y
 * es con lo que las referencias enlazan. La especialidad es un `<select>` del catálogo `especial`; la de un médico cuyo
 * código el catálogo no trae se conserva como una opción aparte para que editarlo no se la borre.
 */
final class DoctoresVista
{
    use ComparteElListadoDeCatalogo;

    /**
     * @param  array<string,string>  $especialidades  las del médico, `código => nombre`
     * @param  array<string,mixed>  $urls  `guardar` y `quitar` (una función de id)
     */
    public function __construct(
        private LengthAwarePaginator $filas,
        private string $buscar,
        private array $especialidades,
        private int $total,
        private ?Doctor $editando,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    protected function slug(): string
    {
        return 'medicos-referidos';
    }

    public function subtitulo(): string
    {
        if ($this->hayBusqueda()) {
            $total = $this->filas->total();

            return ($total === 0 ? 'Sin resultados' : $total . ($total === 1 ? ' médico' : ' médicos'))
                . ' para «' . $this->buscar . '».';
        }

        return $this->total . ($this->total === 1 ? ' médico' : ' médicos') . '. Son a quienes refieres a tus pacientes.';
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayBusqueda()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ningún médico coincide con «' . $this->buscar . '»',
                'texto' => 'Prueba con el apellido, la clínica o la especialidad. No importan las tildes ni las mayúsculas.',
            ];
        }

        return [
            'icono' => 'bi-person-lines-fill',
            'titulo' => 'Todavía no tienes médicos referidos',
            'texto' => 'Crea el primero: es a quien vas a referir a un paciente desde la consulta.',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->filas->items())->map(fn (Doctor $fila) => $this->fila($fila))->all();
    }

    /** @return array<string,mixed> */
    private function fila(Doctor $fila): array
    {
        $nombre = trim(trim((string) $fila->nombres) . ' ' . trim((string) $fila->apellidos));
        $nombre = $nombre !== '' ? $nombre : 'Médico sin nombre';
        $repetidos = (int) $fila->getAttribute('repetidos');
        $codigoDeEspecialidad = CamposDePaciente::texto($fila->codeespecial);
        $especialidad = $fila->getAttribute('especialidad_nombre')
            ?? ($codigoDeEspecialidad === null ? null : 'Especialidad ' . $codigoDeEspecialidad);
        $direccion = CamposDePaciente::texto($fila->direccion);

        return [
            'id' => (int) $fila->id,
            'nombre' => $nombre,
            'codigo' => (string) (int) $fila->cedula,
            'especialidad' => $especialidad,
            'clinica' => CamposDePaciente::texto($fila->clinica),
            'ciudad' => CamposDePaciente::texto($fila->ciudad),
            'telefono' => CamposDePaciente::texto($fila->telefono),
            'direccion' => $direccion === null ? null : Str::limit(preg_replace('/\s+/u', ' ', $direccion), 120),
            'repetido' => $repetidos > 1 ? 'Hay ' . $repetidos . ' con este nombre y especialidad' : null,
            'urlEditar' => $this->url(['editar' => $fila->id]),
            'urlQuitar' => ($this->urls['quitar'])((int) $fila->id),
            'avisoQuitar' => '¿Quitar a «' . $nombre . '» del catálogo? Si alguna referencia lo nombra, no se borra y te digo dónde.',
        ];
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el médico' : 'Nuevo médico referido';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear el médico';
    }

    /** El código del médico que se edita (el alta lo da el servidor y no se muestra). */
    public function codigoEditando(): ?string
    {
        return $this->editando() ? (string) (int) $this->editando->cedula : null;
    }

    /** El valor de un campo de texto del formulario: el del `old()` o el del médico que se edita. */
    public function texto(string $campo): string
    {
        $valor = $this->valor($campo);

        return $valor === null ? '' : (string) $valor;
    }

    public function especialidadSeleccionada(): string
    {
        return $this->texto('codeespecial');
    }

    /**
     * Las opciones del `<select>` de la especialidad. Si el médico que se edita tiene un código que el catálogo no trae,
     * se conserva como una opción más.
     *
     * @return array<int,array{valor:string, texto:string}>
     */
    public function opcionesDeEspecialidad(): array
    {
        $opciones = [];

        foreach ($this->especialidades as $codigo => $nombre) {
            $opciones[] = ['valor' => (string) $codigo, 'texto' => $nombre];
        }

        $actual = $this->especialidadSeleccionada();

        if ($actual !== '' && ! isset($this->especialidades[$actual])) {
            $opciones[] = ['valor' => $actual, 'texto' => 'Especialidad ' . $actual . ' (no está en el catálogo)'];
        }

        return $opciones;
    }

    public function hayEspecialidades(): bool
    {
        return $this->especialidades !== [];
    }

    public function largoApellidos(): int
    {
        return AgregarDoctorAlCatalogo::LARGO_APELLIDOS;
    }

    public function largoNombres(): int
    {
        return AgregarDoctorAlCatalogo::LARGO_NOMBRES;
    }

    public function largoClinica(): int
    {
        return AgregarDoctorAlCatalogo::LARGO_CLINICA;
    }

    public function largoCiudad(): int
    {
        return AgregarDoctorAlCatalogo::LARGO_CIUDAD;
    }

    public function largoTelefono(): int
    {
        return AgregarDoctorAlCatalogo::LARGO_TELEFONO;
    }

    public function largoDireccion(): int
    {
        return AgregarDoctorAlCatalogo::LARGO_DIRECCION;
    }

    public function largoNota(): int
    {
        return AgregarDoctorAlCatalogo::LARGO_NOTA;
    }
}
