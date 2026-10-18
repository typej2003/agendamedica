<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use Illuminate\Support\Collection;

/**
 * **Los antecedentes del paciente en la consulta** (WEB-4.4, ítem 3 del panel del legado).
 *
 * El ViewModel no sabe de base de datos ni de `Request`: recibe los antecedentes **ya leídos**
 * (`App\Clinica\Consulta\AntecedentesDelPaciente`) y las URLs. La lectura resuelve el nombre del
 * antecedente y el de su tipo; acá sólo se arma lo que la pantalla pinta.
 *
 * Tres cosas que decide esta clase y no la vista:
 *
 *  - **Los grupos son los del catálogo** (los siete tipos del escritorio, en el orden de `codetipo`) y lo
 *    que no se puede clasificar cae en un último grupo `Sin clasificar`, que es una etiqueta de la
 *    pantalla y **no** un tipo inventado (misma decisión que la historia del paciente, WEB-3.5).
 *  - **Los "vacíos" del legado** (`NULL`, `''` y el literal `none`) se muestran como `—` en el detalle, y
 *    el recorte de la fila lo hace el ViewModel para que el listado se lea de un vistazo: el `<input>` del
 *    formulario lleva el texto **completo**, porque es el dato que se edita.
 *  - **De quién son los antecedentes**: del **paciente**, no de la consulta. La pantalla lo dice
 *    (`ayuda()`), porque quien los carga desde acá los está cargando para su historia.
 */
final class SeccionDeAntecedentesVista
{
    /**
     * @param  Collection<int,object>  $antecedentes  lo que devolvió `AntecedentesDelPaciente::delPaciente()`
     * @param  string  $urlGuardar  a dónde postea "Guardar los antecedentes"
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de la guía de UI)
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación (`[]` en la primera carga)
     */
    public function __construct(
        private Collection $antecedentes,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private array $old = [],
        // Los chips de categoría de pediatría (WEB-12.3): los tipos del médico, el que está activo (`''` = todos) y el enlace de cada uno. Sin tipos no hay chips.
        private array $categorias = [],
        private string $tipoActivo = '',
        private ?\Closure $urlDeTipo = null,
    ) {
    }

    /**
     * Los chips de categoría (*Todos*, *Patológicos*, *C.A.*, *Madre*…) con cuántos antecedentes tiene el paciente en cada una. Vacío en las especialidades que
     * no los usan (ginecología conserva su pantalla).
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function chips(): array
    {
        if ($this->categorias === [] || $this->urlDeTipo === null) {
            return [];
        }

        $chips = [['texto' => 'Todos (' . $this->antecedentes->count() . ')', 'url' => ($this->urlDeTipo)(''), 'activa' => $this->tipoActivo === '']];

        foreach ($this->categorias as $categoria) {
            $cuantos = $this->antecedentes->filter(fn ($a) => ($a->tipoCodigo ?? null) === $categoria['codigo'])->count();
            $chips[] = [
                'texto' => $categoria['nombre'] . ' (' . $cuantos . ')',
                'url' => ($this->urlDeTipo)($categoria['codigo']),
                'activa' => $this->tipoActivo === $categoria['codigo'],
            ];
        }

        return $chips;
    }

    /** ¿Hay una categoría elegida? Entonces la lista muestra sólo esa. */
    public function hayCategoria(): bool
    {
        return $this->tipoActivo !== '' && $this->categorias !== [];
    }

    /** El nombre de la categoría elegida, para decirlo cuando no tiene antecedentes. */
    public function nombreDeLaCategoria(): string
    {
        foreach ($this->categorias as $categoria) {
            if ($categoria['codigo'] === $this->tipoActivo) {
                return $categoria['nombre'];
            }
        }

        return '';
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** ¿El paciente todavía no tiene ningún antecedente cargado? */
    public function estaVacio(): bool
    {
        return $this->antecedentes->isEmpty();
    }

    /** Cuántos antecedentes tiene el paciente: lo que muestra la pastilla del encabezado. */
    public function total(): int
    {
        return $this->antecedentes->count();
    }

    /** Cuántos están marcados como **alerta** (`tipo2 = 'A'`), que es lo que el escritorio resalta. */
    public function alertas(): int
    {
        return $this->antecedentes->filter(fn ($antecedente) => (bool) $antecedente->alerta)->count();
    }

    /**
     * Los antecedentes **agrupados por tipo**, en el orden del catálogo (el de las solapas del escritorio)
     * y con `Sin clasificar` al final.
     *
     * @return array<int,array{titulo:string, filas:array<int,array<string,mixed>>}>
     */
    public function grupos(): array
    {
        $grupos = [];

        foreach ($this->antecedentes as $antecedente) {
            if ($this->hayCategoria() && ($antecedente->tipoCodigo ?? null) !== $this->tipoActivo) {
                continue;
            }

            $titulo = $antecedente->tipoNombre ?? 'Sin clasificar';

            $grupos[$titulo] ??= ['titulo' => $titulo, 'filas' => []];
            $grupos[$titulo]['filas'][] = $this->fila($antecedente);
        }

        // "Sin clasificar" al final: los grupos del catálogo primero, en el orden en que la lectura los
        // trae (por `codetipo`, o sea el de las solapas).
        uksort($grupos, fn ($a, $b) => ($a === 'Sin clasificar' ? 1 : 0) <=> ($b === 'Sin clasificar' ? 1 : 0));

        return array_values($grupos);
    }

    /**
     * El estado de la sección como pastilla: "Sin cargar" mientras no haya nada y "Cargados" cuando ya
     * hay algo. No dice "guardado" (de eso se encarga el aviso de cambios sin guardar).
     */
    public function estado(): array
    {
        if ($this->estaVacio()) {
            return ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Este paciente todavía no tiene antecedentes cargados'];
        }

        $alertas = $this->alertas();

        return ['tono' => 'exito', 'icono' => 'bi-check2-circle',
            'texto' => $alertas === 0 ? 'Cargados' : 'Con alerta',
            'titulo' => $alertas === 0
                ? 'Los antecedentes de este paciente están cargados en su historia'
                : $alertas . ($alertas === 1 ? ' antecedente marcado' : ' antecedentes marcados') . ' como alerta'];
    }

    /**
     * La ayuda del formulario, que dice lo más importante de la sección: **de quién son** los antecedentes.
     *
     * Es una decisión clínica y de la fuente (ver `AntecedentesDelPaciente`): no son de esta consulta, son
     * del paciente, así que lo que se cargue acá se ve en su historia y en su próxima consulta.
     */
    public function ayuda(): string
    {
        return 'Estos antecedentes son del paciente, no de esta consulta: los que ves —y los que agregues— '
            . 'quedan en su historia clínica y los verás en sus próximas consultas. Se guardan con el botón.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en los antecedentes. Si sales ahora, se pierden.';
    }

    /**
     * Una fila del formulario: el nombre, el código, el detalle y la casilla de alerta, con el `old()` de
     * un POST que falló para no borrar lo escrito (guía de UI §3).
     *
     * @return array<string,mixed>
     */
    private function fila(object $antecedente): array
    {
        $id = (int) $antecedente->id;
        $viejo = is_array($this->old['filas'][$id] ?? null) ? $this->old['filas'][$id] : null;

        $detalle = $viejo !== null && array_key_exists('detalle', $viejo)
            ? trim((string) $viejo['detalle'])
            : (string) ($antecedente->detalle ?? '');

        $alerta = $viejo !== null && array_key_exists('alerta', $viejo)
            ? in_array($viejo['alerta'], ['A', 'a', '1', 1, true, 'on'], true)
            : (bool) $antecedente->alerta;

        return [
            'id' => $id,
            'codigo' => (string) $antecedente->codigo,
            'nombre' => (string) $antecedente->nombre,
            'detalle' => $detalle,
            'detalleCorto' => $this->recortar($detalle),
            'alerta' => $alerta,
            // Un antecedente cuyo código ya no está en el catálogo se muestra igual (el legado no borra
            // antecedentes, pero los datos son datos): se avisa para que el médico sepa de dónde sale.
            'avisoCatalogo' => $antecedente->enCatalogo ? null : 'ya no está en tu catálogo',
        ];
    }

    /** El texto de una fila: espacios colapsados y, si no entra, cortado con `…`. */
    private function recortar(?string $texto): ?string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', (string) $texto));

        if ($limpio === '') {
            return null;
        }

        return mb_strlen($limpio) <= 160 ? $limpio : mb_substr($limpio, 0, 157) . '…';
    }
}
