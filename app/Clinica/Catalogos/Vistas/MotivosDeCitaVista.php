<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\ReglasDelMotivoDeCita;
use App\Clinica\Catalogos\MotivosDeCitaDelCatalogo;
use App\Models\MotivoCita;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * La pantalla **«Motivos de cita»** del catálogo (`/clinica/catalogos/motivos-cita`, WEB-8.1c): las razones con las que el
 * médico agenda, cada una con su **precio** (el monto que toma la cita cuando no se escribe otro), su **sede** y la marca de
 * **varias citas pendientes** (WEB-2.17). Como todo ViewModel de la web clínica no consulta la base: recibe la página ya
 * leída y las URLs que resolvió el controlador.
 *
 * El diálogo es **uno** para alta y edición; el código lo asigna el sistema y no se edita. La marca de varias citas se cambia
 * desde el diálogo y también desde el menú de la fila (la acción de la pantalla de WEB-2.17, que esta absorbió).
 */
final class MotivosDeCitaVista
{
    use ComparteElListadoDeCatalogo;

    /**
     * @param  array<int,string>  $sedes  las sedes del médico, `id => nombre` (el `<select>` del formulario)
     * @param  array<string,mixed>  $urls  `guardar`, y `quitar` y `alternar` (funciones de id, que arma el controlador con `route()`)
     */
    public function __construct(
        private LengthAwarePaginator $filas,
        private string $buscar,
        private int $total,
        private ?MotivoCita $editando,
        private array $sedes,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    protected function slug(): string
    {
        return 'motivos-cita';
    }

    public function titulo(): string
    {
        return 'Motivos de cita';
    }

    public function subtitulo(): string
    {
        if ($this->hayBusqueda()) {
            $total = $this->filas->total();

            return ($total === 0 ? 'Sin resultados' : $total . ($total === 1 ? ' motivo' : ' motivos')) . ' para «' . $this->buscar . '».';
        }

        return $this->total . ($this->total === 1 ? ' motivo' : ' motivos')
            . '. Son las razones con las que agendas; el precio de cada una es el monto de la cita cuando no escribes otro.';
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayBusqueda()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ningún motivo coincide con «' . $this->buscar . '»',
                'texto' => 'Prueba con otra palabra, con el código o con la sede. No importan las tildes ni las mayúsculas.',
            ];
        }

        return [
            'icono' => 'bi-tags',
            'titulo' => 'Todavía no tienes motivos de cita',
            'texto' => 'Crea el primero: es el que vas a elegir al agendar una cita, con el precio que se cobra por defecto.',
        ];
    }

    // ------------------------------------------------------------------------------------------- La lista

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->filas->items())->map(fn (MotivoCita $fila) => $this->fila($fila))->all();
    }

    /** @return array<string,mixed> */
    private function fila(MotivoCita $fila): array
    {
        $razon = trim((string) $fila->tipo_atencion) !== '' ? trim((string) $fila->tipo_atencion) : 'Motivo ' . trim((string) $fila->codigo);
        $repetidos = (int) $fila->getAttribute('repetidos');
        $mismoCodigo = (int) $fila->getAttribute('con_el_mismo_codigo');
        $marcado = (bool) $fila->admite_varias_citas_pendientes;

        return [
            'id' => (int) $fila->id,
            'razon' => $razon,
            'codigo' => trim((string) $fila->codigo),
            'sede' => MotivosDeCitaDelCatalogo::sedeDelMotivo($fila),
            'precio' => $fila->precio === null ? null : number_format((float) $fila->precio, 2, ',', '.'),
            'repetido' => $repetidos > 1 ? 'Hay ' . $repetidos . ' con este nombre en esta sede' : null,
            'mismoCodigo' => $mismoCodigo > 1 ? 'Código en ' . $mismoCodigo . ' sedes' : null,
            'marca' => $marcado
                ? ['tono' => 'info', 'icono' => 'bi-people', 'texto' => 'Varias citas']
                : ['tono' => 'neutro', 'icono' => 'bi-person', 'texto' => 'Una sola cita'],
            'accion' => $marcado
                ? ['texto' => 'Volver a una sola cita', 'icono' => 'bi-person', 'title' => 'Volver a preguntar cuando el paciente ya tenga una cita pendiente']
                : ['texto' => 'Admitir varias citas', 'icono' => 'bi-people', 'title' => 'No preguntar por la cita pendiente con este motivo'],
            'urlEditar' => $this->url(['editar' => $fila->id]),
            'urlAlternar' => ($this->urls['alternar'])((int) $fila->id),
            'urlQuitar' => ($this->urls['quitar'])((int) $fila->id),
            'avisoQuitar' => '¿Quitar «' . $razon . '» del catálogo? Si hay citas con este motivo, no se borra y te digo cuántas.',
        ];
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el motivo' : 'Nuevo motivo de cita';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear el motivo';
    }

    public function codigoEditando(): ?string
    {
        return $this->editando() ? trim((string) $this->editando->codigo) : null;
    }

    public function largoRazon(): int
    {
        return ReglasDelMotivoDeCita::LARGO_RAZON;
    }

    /** La razón: lo que quedó de un `POST` que falló o la del motivo que se edita. */
    public function razon(): string
    {
        return (string) old('razon', $this->editando?->tipo_atencion ?? '');
    }

    /** El precio: lo que quedó de un `POST` que falló o el del motivo que se edita (con punto, que es lo que lee el campo). */
    public function precio(): string
    {
        if (old('precio') !== null) {
            return (string) old('precio');
        }

        $precio = $this->editando?->precio;

        return $precio === null ? '' : number_format((float) $precio, 2, '.', '');
    }

    /** La sede elegida en el diálogo: la del `POST` que falló, la del motivo que se edita, o ninguna (todas). */
    public function sedeSeleccionada(): string
    {
        $valor = old('office_id', $this->editando?->office_id);

        return $valor === null ? '' : (string) $valor;
    }

    /**
     * Las opciones del `<select>` de la sede: «Todas las sedes», cada sede del médico y —si el motivo que se edita apunta a una
     * que ya no está en su lista— esa, para que editarlo no se la cambie sin querer.
     *
     * @return array<int,array{valor:string, texto:string}>
     */
    public function opcionesDeSede(): array
    {
        $opciones = [['valor' => '', 'texto' => 'Todas las sedes']];

        foreach ($this->sedes as $id => $nombre) {
            $opciones[] = ['valor' => (string) $id, 'texto' => $nombre];
        }

        $actual = $this->sedeSeleccionada();

        if ($actual !== '' && ! isset($this->sedes[(int) $actual])) {
            $opciones[] = ['valor' => $actual, 'texto' => 'Sede ' . $actual . ' (no está entre tus sedes)'];
        }

        return $opciones;
    }

    /** ¿El médico tiene sedes para elegir? Sin ellas el motivo es de «todas» y el selector sobra. */
    public function haySedes(): bool
    {
        return $this->sedes !== [];
    }

    public function admiteVarias(): bool
    {
        if (old('admite_varias') !== null) {
            return (string) old('admite_varias') === '1';
        }

        return (bool) $this->editando?->admite_varias_citas_pendientes;
    }
}
