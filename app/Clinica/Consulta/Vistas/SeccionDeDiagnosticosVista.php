<?php

namespace App\Clinica\Consulta\Vistas;

use App\Actions\Consulta\AgregarDiagnosticoAlCatalogo;
use App\Actions\Consulta\GuardarDiagnosticosDeLaConsulta;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Los diagnósticos de la consulta** (WEB-4.5, ítem 8 del panel del legado): lo que la pantalla muestra
 * —los diagnósticos que la consulta tiene, el buscador de su catálogo y el alta del que falta—, ya resuelto.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe los diagnósticos y el catálogo ya
 * leídos por `App\Clinica\Consulta\DiagnosticosDeLaConsulta` y las URLs que resolvió el controlador, y
 * devuelve los textos y banderas que la vista pinta. Se prueba sin base de datos (`tests/Unit/Clinica/`).
 *
 * Cuatro cosas que decide acá y no la vista:
 *
 *  - **«Pasar» y «Quitar» son inmediatos** (O-27, 2026-10-17): cada uno es un `POST` que escribe en el acto y
 *    la fila aparece o desaparece de la lista. El botón de guardar aplica **sólo el detalle** de lo que ya
 *    está (`GuardarDiagnosticosDeLaConsulta`). El aviso de "cambios sin guardar" y el `beforeunload` los
 *    pone `consulta.js` desde los `data-*`.
 *  - **"Ya está en la consulta"** decide qué fila no se ofrece para pasar. No hay estado intermedio de
 *    «pendiente»: agregar escribe.
 *  - **El estado de la sección** (`estado()`): una pastilla que dice si la consulta ya tiene diagnósticos;
 *    no dice "guardado" (de eso se encarga el aviso de cambios sin guardar).
 *  - **La búsqueda viaja en la URL** (`?buscar=`), que es lo que un `GET` puede llevar; el «Pasar» y el
 *    «Quitar» la conservan al volver.
 */
final class SeccionDeDiagnosticosVista
{
    /** El catálogo ya filtrado por la búsqueda. No se promueve: el constructor lo normaliza a colección. */
    private Collection $catalogo;

    /**
     * @param  Collection<int,object>  $diagnosticos  lo que devolvió `DiagnosticosDeLaConsulta::deLaConsulta()`
     * @param  Collection<int,object>  $catalogo  el catálogo del médico ya filtrado por la búsqueda
     * @param  string  $buscar  lo que se escribió en el buscador (viaja por `?buscar=`)
     * @param  string  $urlGuardar  a dónde postea "Guardar los diagnósticos" (el detalle)
     * @param  string  $urlBase  la URL del shell de esta consulta, **sin query**: la base del `action` del
     *                           buscador, del "Cancelar", del «Pasar» y del «Quitar»
     * @param  string  $urlCrear  a dónde postea el alta del diagnóstico nuevo
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación (`[]` en la primera carga)
     */    public function __construct(
        private Collection $diagnosticos,
        ?Collection $catalogo = null,
        private string $buscar = '',
        private string $urlGuardar = '',
        private string $urlBase = '',
        private string $urlCrear = '',
        private array $old = [],
        private ?LengthAwarePaginator $paginador = null,
        // O-27: las rutas de las dos escrituras inmediatas (el código y el id viajan en el cuerpo).
        private string $urlAgregar = '',
        private string $urlQuitar = '',
    ) {
        $this->catalogo = $catalogo ?? new Collection();
    }

    // ------------------------------------------------------------------------------- Los de la consulta

    /** ¿La consulta todavía no tiene ningún diagnóstico? Es el estado vacío de la tarjeta. */
    public function estaVacio(): bool
    {
        return $this->diagnosticos->isEmpty();
    }

    /** Cuántos diagnósticos tiene la consulta: lo que muestra la pastilla del encabezado. */
    public function total(): int
    {
        return $this->diagnosticos->count();
    }

    /** El estado de la sección como pastilla: "Sin diagnóstico" mientras no haya nada y "N diagnósticos" después. */
    public function estado(): array
    {
        if ($this->estaVacio()) {
            return [
                'tono' => 'neutro',
                'icono' => 'bi-dash-circle',
                'texto' => 'Sin diagnóstico',
                'titulo' => 'Esta consulta todavía no tiene diagnósticos',
            ];
        }

        return [
            'tono' => 'marca',
            'icono' => 'bi-clipboard2-check',
            'texto' => $this->total() . ($this->total() === 1 ? ' diagnóstico' : ' diagnósticos'),
            'titulo' => 'Los diagnósticos que tiene esta consulta',
        ];
    }

    /**
     * Las filas del formulario: el nombre, el código y el detalle editable, con el `old()` de un POST que
     * falló para no borrar lo escrito (guía de UI §3).
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(): array
    {
        return $this->diagnosticos->map(function (object $diagnostico) {
            $id = (int) $diagnostico->id;
            $viejo = is_array($this->old['filas'][$id] ?? null) ? $this->old['filas'][$id] : null;

            $detalle = $viejo !== null && array_key_exists('detalle', $viejo)
                ? trim((string) $viejo['detalle'])
                : (string) ($diagnostico->detalle ?? '');

            return [
                'id' => $id,
                'codigo' => (string) $diagnostico->codigo,
                'nombre' => (string) $diagnostico->nombre,
                'detalle' => $detalle,
                // Un código que ya no está en el catálogo se muestra igual (el legado no borra
                // diagnósticos, pero los datos son datos): se avisa para que el médico sepa de dónde sale.
                'avisoCatalogo' => $diagnostico->enCatalogo ? null : 'ya no está en tu catálogo',
            ];
        })->all();
    }

    // ------------------------------------------------------------------------------------ El buscador

    /** Los enlaces de página del catálogo (WEB-11.3). */
    public function paginador(): ?LengthAwarePaginator
    {
        return $this->paginador;
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function urlCrear(): string
    {
        return $this->urlCrear;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    /**
     * La URL del «Pasar» de una fila del catálogo (O-27): un `POST` inmediato. El código y la búsqueda viajan
     * en campos ocultos del formulario de la fila, así que la URL no lleva parámetros.
     */
    public function urlAgregar(): string
    {
        return $this->urlAgregar;
    }

    /** La URL del «Quitar» de una fila (O-27): un `POST` inmediato, con el id de la fila en el cuerpo. */
    public function urlQuitar(): string
    {
        return $this->urlQuitar;
    }

    /** La URL de esta sección (con su `?seccion=`): el "Cancelar" de la guía de UI y la vuelta del buscador. */
    public function urlVolver(): string
    {
        return $this->urlBase . '?seccion=' . ConsultaShellVista::SECCION_DIAGNOSTICO;
    }

    /**
     * Los resultados del buscador, con la bandera que decide qué ofrece cada fila. Desde O-27 no hay estado
     * intermedio de «pendiente»: «Pasar» escribe.
     */
    public function resultados(): array
    {
        return $this->catalogo->map(fn (object $diagnostico) => [
            'codigo' => trim((string) $diagnostico->codediagnostico),
            'descripcion' => (string) $diagnostico->descripcion,
            'yaEsta' => $this->diagnosticos->contains(fn (object $cargado) => $cargado->codigo === trim((string) $diagnostico->codediagnostico)),
        ])->all();
    }

    /** El tope del detalle de una fila, para el `maxlength` del control (`detalle_diagnostco` es `varchar(100)`). */
    public function limiteDetalle(): int
    {
        return GuardarDiagnosticosDeLaConsulta::LARGO_DETALLE;
    }

    /** El tope del nombre de un diagnóstico nuevo (`diagnosticos.descripcion` es `varchar(50)`). */
    public function limiteDescripcion(): int
    {
        return AgregarDiagnosticoAlCatalogo::LARGO_MAXIMO;
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en los diagnósticos. Si sales ahora, se pierden.';
    }

    /**
     * La ayuda del formulario: **son de esta consulta**, «Pasar» y «Quitar» se aplican al instante y el
     * detalle se guarda con el botón (a diferencia de los antecedentes, que son del paciente).
     */
    public function ayuda(): string
    {
        return 'Estos diagnósticos son de esta consulta: lo que pases o quites acá vale para esta atención '
            . 'y lo verás en la historia del paciente. Pasar y Quitar se aplican al instante; el detalle se '
            . 'guarda con el botón.';
    }

    /** La ayuda del buscador: de dónde sale el catálogo y cómo se busca. */
    public function ayudaDelBuscador(): string
    {
        return 'Busca en tu catálogo de diagnósticos: es el mismo que usa el escritorio, así que lo que '
            . 'agregues acá lo verás allá. Un diagnóstico por nombre y sin distinguir tildes.';
    }

    /** La ayuda del alta: el código lo da el servidor y el nombre se guarda en mayúsculas. */
    public function ayudaDelAlta(): string
    {
        return 'Si el diagnóstico no está en tu catálogo, créalo: el código lo asigna el sistema (el '
            . 'correlativo de cuatro dígitos del escritorio) y se guarda en mayúsculas. Necesita conexión.';
    }

    /** El estado vacío del buscador, con el nombre que falta en el catálogo. */
    public function sinResultados(): array
    {
        return [
            'icono' => 'bi-search',
            'titulo' => 'Tu catálogo no tiene diagnósticos que coincidan',
            'texto' => $this->buscar === ''
                ? 'Tu catálogo está vacío: crea el primero abajo.'
                : 'Prueba con otra palabra o créalo abajo: se agrega a tu catálogo y a la consulta.',
        ];
    }
}
