<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\DietaDeLaConsulta;
use Illuminate\Support\Collection;

/**
 * **La pestaña Dietas / Recomendaciones** (WEB-5.3b): el título (opcional) y el texto de la dieta o de las recomendaciones de la
 * consulta, una por consulta y con guardado explícito.
 *
 * Se escribe **libre** o **partiendo de una plantilla** del médico (`formato_print`, tipo `DI`): elegir una plantilla copia su título
 * y su texto en el editor y **no guarda** —el aviso de «cambios sin guardar» aparece igual y el guardado sigue siendo el botón—; lo
 * resuelve `consulta-solicitudes.js` desde los `data-*` (el texto viaja en un atributo, no en un `<option>`). Sin base de datos.
 */
final class DietaVista
{
    /**
     * @param  Collection<int,object{codigo:string, titulo:string, texto:string}>  $plantillas
     * @param  array<string,string>  $urls  `guardar`, `eliminar` e `imprimir`
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     */
    public function __construct(
        private ?string $titulo,
        private ?string $texto,
        private bool $esReal,
        private Collection $plantillas,
        private array $urls,
        private array $old = [],
        // En pediatría la pestaña se llama *Alimentación* (WEB-12.4): es la misma fila de `dieta_paciente`.
        private string $rotulo = 'Dieta / Recomendaciones',
        // En pediatría, además, es el **ítem 9** de la consulta y los textos dicen «alimentación» (2026-10-19).
        private bool $alimentacion = false,
    ) {
    }

    public function rotulo(): string
    {
        return $this->rotulo;
    }

    /** El botón de guardar: «Guardar la dieta» o, en pediatría, «Guardar la alimentación». */
    public function rotuloBoton(): string
    {
        return $this->alimentacion ? 'Guardar la alimentación' : 'Guardar la dieta';
    }

    /** El rótulo del campo de texto, con la misma palabra que el resto de la pantalla. */
    public function etiquetaDelTexto(): string
    {
        return $this->alimentacion
            ? 'Texto de la alimentación o de las recomendaciones'
            : 'Texto de la dieta o de las recomendaciones';
    }

    public function titulo(): string
    {
        return (string) ($this->old['titulo'] ?? $this->titulo ?? '');
    }

    public function texto(): string
    {
        return (string) ($this->old['descripcion'] ?? $this->texto ?? '');
    }

    public function largoDelTitulo(): int
    {
        return DietaDeLaConsulta::LARGO_TITULO;
    }

    public function esReal(): bool
    {
        return $this->esReal;
    }

    public function hayPlantillas(): bool
    {
        return $this->plantillas->isNotEmpty();
    }

    /** @return array<int,array{codigo:string, titulo:string, texto:string}> */
    public function plantillas(): array
    {
        return $this->plantillas
            ->map(fn (object $plantilla) => ['codigo' => $plantilla->codigo, 'titulo' => $plantilla->titulo, 'texto' => $plantilla->texto])
            ->values()
            ->all();
    }

    public function urlGuardar(): string
    {
        return $this->urls['guardar'];
    }

    public function urlEliminar(): string
    {
        return $this->urls['eliminar'];
    }

    public function urlImprimir(): string
    {
        return $this->urls['imprimir'];
    }

    public function puedeEliminar(): bool
    {
        return $this->esReal;
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        // En pediatría la pastilla no dice «dieta» (la pantalla es *Alimentación*): dice si está cargada, como las
        // solapas de texto del examen. Fuera de pediatría se queda el texto de siempre.
        if ($this->alimentacion) {
            return $this->esReal
                ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargada', 'titulo' => 'Esta consulta ya tiene la alimentación o las recomendaciones']
                : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar', 'titulo' => 'Todavía no hay alimentación ni recomendaciones en esta consulta'];
        }

        return $this->esReal
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Con dieta', 'titulo' => 'Esta consulta tiene dieta o recomendaciones']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin dieta', 'titulo' => 'Esta consulta no tiene dieta ni recomendaciones'];
    }

    /** El botón de eliminar: «Eliminar la dieta» o, en pediatría, «Eliminar la alimentación». */
    public function rotuloEliminar(): string
    {
        return $this->alimentacion ? 'Eliminar la alimentación' : 'Eliminar la dieta';
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en ' . ($this->alimentacion ? 'la alimentación' : 'la dieta') . '. Si sales ahora, se pierden.';
    }

    public function avisoEliminar(): string
    {
        return $this->alimentacion
            ? '¿Eliminar la alimentación / recomendaciones de esta consulta? Se borran el título y el texto.'
            : '¿Eliminar la dieta / recomendaciones de esta consulta? Se borran el título y el texto.';
    }

    public function ayuda(): string
    {
        return $this->alimentacion
            ? 'La alimentación o el conjunto de recomendaciones del paciente, una por consulta. Escríbela o parte de una '
                . 'de tus plantillas: el título y el texto se llevan al campo para que los termines, y se guarda con el botón.'
            : 'Una dieta o conjunto de recomendaciones por consulta. Escríbela o parte de una de tus plantillas: '
                . 'el título y el texto se llevan al campo para que los termines, y se guarda con el botón.';
    }
}
