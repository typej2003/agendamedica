<?php

namespace App\Clinica\Pacientes\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lo que muestra el listado de pacientes (`/clinica/pacientes`): textos ya formateados, el estado de
 * cada historia y el mensaje de "no hay nada". La vista solo lo recorre.
 *
 * Cada campo pasa por `CamposDePaciente`: el legado guarda la cédula ausente como `S.C.` y el número de
 * historia como texto que puede venir vacío, y la guía de UI pide `—`/"Sin cédula" cuando el dato no está.
 */
final class ListadoDePacientesVista
{
    public function __construct(
        private LengthAwarePaginator $pacientes,
        private string $buscar = '',
    ) {
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function hayBusqueda(): bool
    {
        return $this->buscar !== '';
    }

    public function paginador(): LengthAwarePaginator
    {
        return $this->pacientes;
    }

    public function haySobrantes(): bool
    {
        return $this->pacientes->hasPages();
    }

    public function subtitulo(): string
    {
        $total = $this->pacientes->total();

        if ($this->hayBusqueda()) {
            return $total === 0
                ? 'Sin resultados para «' . $this->buscar . '».'
                : $total . ($total === 1 ? ' resultado' : ' resultados') . ' para «' . $this->buscar . '».';
        }

        return $total . ($total === 1 ? ' paciente' : ' pacientes') . ' en el registro con el que estás trabajando.';
    }

    /** "Mostrando 1–25 de 134", o vacío cuando no hay filas. */
    public function rango(): string
    {
        if ($this->pacientes->total() === 0) {
            return '';
        }

        return 'Mostrando ' . $this->pacientes->firstItem() . '–' . $this->pacientes->lastItem()
            . ' de ' . $this->pacientes->total();
    }

    public function estaVacio(): bool
    {
        return $this->pacientes->total() === 0;
    }

    /** El estado vacío: distingue "no hay nada todavía" de "tu búsqueda no encontró nada". */
    public function vacio(): array
    {
        return $this->hayBusqueda()
            ? ['icono' => 'bi-search', 'titulo' => 'Ningún paciente coincide con la búsqueda', 'texto' => 'Prueba con otro nombre, la cédula o el n.º de historia.']
            : ['icono' => 'bi-people', 'titulo' => 'Este consultorio todavía no tiene pacientes', 'texto' => 'Los pacientes aparecen aquí cuando se registran o se sincronizan.'];
    }

    /** @return array<int, array<string, mixed>> */
    public function filas(): array
    {
        return collect($this->pacientes->items())->map(fn ($paciente) => $this->fila($paciente))->all();
    }

    private function fila(object $paciente): array
    {
        $edad = FechaClinica::edad($paciente->fnacimiento);
        $cedula = CamposDePaciente::cedula($paciente->cedula);
        $historia = CamposDePaciente::numeroDeHistoria($paciente->numhistoria);

        return [
            'id'         => (int) $paciente->id,
            'url'        => route('clinica.pacientes.ver', $paciente->id),
            'nombre'     => CamposDePaciente::nombre($paciente->apellidos, $paciente->nombres),
            'cedula'     => $cedula !== null ? 'C.I. ' . $cedula : 'Sin cédula',
            'historia'   => $historia !== null
                ? ['tono' => 'marca', 'icono' => 'bi-journal-medical', 'texto' => 'Historia ' . $historia]
                : ['tono' => 'aviso', 'icono' => 'bi-exclamation-triangle', 'texto' => 'Sin historia'],
            'edad'       => $edad !== null ? $edad . ($edad === 1 ? ' año' : ' años') : null,
            'nacimiento' => FechaClinica::formato($paciente->fnacimiento),
            'telefono'   => CamposDePaciente::texto($paciente->telefono),
            'correo'     => CamposDePaciente::texto($paciente->email),
        ];
    }
}
