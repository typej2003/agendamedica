<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\ReglasDelSeguroDeEmpresa;
use App\Clinica\Pacientes\CatalogoDeSeguros;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\SegEmp;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * La pantalla de **seguros / empresas del consultorio** (`/clinica/catalogos/seguros`, O-62): el catálogo `seg_emp`
 * del médico con su buscador y el diálogo de alta y edición (nombre, RIF, teléfono y dirección).
 *
 * Como todo ViewModel de la web clínica no consulta la base: recibe la página ya leída y las URLs que resolvió el
 * controlador. El **código** se muestra y no se edita: es con el que el paciente guarda su seguro.
 *
 * La fila `00` es **PERSONAL** (el default del alta del paciente, `App\Clinica\Pacientes\CatalogoDeSeguros::PERSONAL`):
 * se lista como una más —el médico puede cambiarle el nombre— y el diálogo avisa qué es.
 */
final class SegurosVista
{
    use ComparteElListadoDeCatalogo;

    /** @param  array<string,mixed>  $urls  `guardar` y `quitar` (una función de id) */
    public function __construct(
        private LengthAwarePaginator $filas,
        private string $buscar,
        private int $total,
        private ?SegEmp $editando,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    protected function slug(): string
    {
        return 'seguros';
    }

    public function subtitulo(): string
    {
        if ($this->hayBusqueda()) {
            $total = $this->filas->total();

            return ($total === 0 ? 'Sin resultados' : $total . ($total === 1 ? ' seguro o empresa' : ' seguros y empresas'))
                . ' para «' . $this->buscar . '».';
        }

        return $this->total . ($this->total === 1 ? ' seguro o empresa' : ' seguros y empresas')
            . '. Son los que se eligen como «Seguro / empresa» en la ficha del paciente.';
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayBusqueda()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ningún seguro coincide con «' . $this->buscar . '»',
                'texto' => 'Prueba con el nombre, el RIF o el código. No importan las tildes ni las mayúsculas.',
            ];
        }

        return [
            'icono' => 'bi-shield-check',
            'titulo' => 'Todavía no tienes seguros ni empresas',
            'texto' => 'Crea el primero: es el que vas a elegir como «Seguro / empresa» en el alta de un paciente.',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->filas->items())->map(fn (SegEmp $fila) => $this->fila($fila))->all();
    }

    /** @return array<string,mixed> */
    private function fila(SegEmp $fila): array
    {
        $nombre = CamposDePaciente::texto($fila->nombre);
        $nombre = $nombre !== null ? preg_replace('/\s+/u', ' ', $nombre) : 'Seguro sin nombre';
        $codigo = trim((string) $fila->codesegemp);
        $rif = CamposDePaciente::texto($fila->rif);
        $telefono = CamposDePaciente::texto($fila->telef);
        $direccion = CamposDePaciente::texto($fila->direccion);
        $direccion = $direccion === null ? null : Str::limit(preg_replace('/\s+/u', ' ', $direccion), 120);

        // Una línea por fila (pauta de Alexander): se ven el nombre, el RIF y el teléfono; la dirección queda en el
        // `title` y en el diálogo de edición.
        $contacto = implode(' · ', array_filter([$rif, $telefono], fn ($valor) => $valor !== null && $valor !== ''));
        $title = 'Código ' . $codigo . ($rif !== null ? ' · RIF ' . $rif : '') . ($direccion !== null ? ' · ' . $direccion : '');

        return [
            'id' => (int) $fila->getKey(),
            'nombre' => $nombre,
            'codigo' => $codigo,
            'rif' => $rif,
            'telefono' => $telefono,
            'direccion' => $direccion,
            'contacto' => $contacto !== '' ? $contacto : null,
            'title' => $title,
            'esPersonal' => $codigo === CatalogoDeSeguros::PERSONAL,
            'urlEditar' => $this->url(['editar' => $fila->getKey()]),
            'urlQuitar' => ($this->urls['quitar'])((int) $fila->getKey()),
            'avisoQuitar' => '¿Quitar «' . $nombre . '» del catálogo? Si algún paciente lo tiene como su seguro, no se borra y te digo quién.',
        ];
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el seguro' : 'Nuevo seguro o empresa';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear el seguro';
    }

    public function codigoEditando(): ?string
    {
        return $this->editando() ? trim((string) $this->editando->codesegemp) : null;
    }

    /** ¿Se está editando la fila `00` (PERSONAL), la que el alta del paciente elige por defecto? */
    public function esPersonal(): bool
    {
        return $this->codigoEditando() === CatalogoDeSeguros::PERSONAL;
    }

    /** El valor de un campo del formulario: el del `old()` o el de la fila que se edita. */
    public function texto(string $campo): string
    {
        $valor = $this->valor($campo);

        return $valor === null ? '' : (string) $valor;
    }

    public function largoNombre(): int
    {
        return ReglasDelSeguroDeEmpresa::LARGO_NOMBRE;
    }

    public function largoRif(): int
    {
        return ReglasDelSeguroDeEmpresa::LARGO_RIF;
    }

    public function largoTelefono(): int
    {
        return ReglasDelSeguroDeEmpresa::LARGO_TELEFONO;
    }

    public function largoDireccion(): int
    {
        return ReglasDelSeguroDeEmpresa::LARGO_DIRECCION;
    }
}
