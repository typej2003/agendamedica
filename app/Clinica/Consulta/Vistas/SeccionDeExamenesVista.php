<?php

namespace App\Clinica\Consulta\Vistas;

use Illuminate\Support\Collection;

/**
 * **Los exámenes de una consulta** (WEB-4.7, ítem 7 del panel del legado: *Exámenes*): la lista de lo que
 * se le pidió al paciente, con el nombre resuelto por catálogo, el tipo y el resultado.
 *
 * Es la mitad de **sólo lectura** de la sección —el mapa del dato, con la cita de la fuente, está en
 * `App\Clinica\Consulta\ExamenesDeLaConsulta`— y es una tabla chica: código, nombre, tipo y resultado.
 *
 * Tres decisiones que se ven acá y salen de la fuente:
 *
 *  - **Un examen sin nombre en el catálogo se muestra con su código.** El `LEFT JOIN` de la lectura no
 *    esconde la fila: en el dataset de prueba hay un `codeexamen` que no está en `examenes` y esconderlo
 *    sería mentir sobre lo que tiene la consulta.
 *  - **El resultado casi nunca está**: la columna `resultado` existe en el esquema y en el DataWindow,
 *    pero está **vacía en los 318.469 registros** de la base real. La lista muestra `—` y **no** un
 *    "pendiente": no se puede afirmar que falte algo que la fuente nunca llenó.
 *  - **La observación del conjunto no es el resultado de una fila**: `examen_obs.observacion` (13.344
 *    filas con texto en el dataset) es el texto que el escritorio deja para el grupo de exámenes de esa
 *    consulta. Va arriba de la tabla, con su propio rótulo.
 *
 * El ViewModel no sabe de `Request` ni de base de datos: recibe la colección ya leída.
 */
final class SeccionDeExamenesVista
{
    /**
     * @param  Collection<int,object>  $examenes  las filas ya resueltas por `ExamenesDeLaConsulta`
     * @param  string|null  $observacion  `examen_obs.observacion` de esta consulta, o `null`
     * @param  bool  $hayCatalogo  si el médico tiene exámenes en su catálogo (distingue los dos vacíos)
     */
    public function __construct(
        private Collection $examenes,
        private ?string $observacion = null,
        private bool $hayCatalogo = false,
    ) {
    }

    public function total(): int
    {
        return $this->examenes->count();
    }

    public function hay(): bool
    {
        return $this->examenes->isNotEmpty();
    }

    /**
     * Los exámenes, listos para pintar. `sinNombre` distingue el examen cuyo código no está en el
     * catálogo: la lista lo dice con el código a la vista.
     *
     * @return array<int,array<string,?string>>
     */
    public function examenes(): array
    {
        return $this->examenes
            ->map(fn ($examen) => [
                'codigo' => (string) $examen->codigo,
                'nombre' => (string) $examen->nombre,
                'tipo' => $examen->tipo,
                'resultado' => $examen->resultado,
                'sinNombre' => (string) $examen->nombre === (string) $examen->codigo
                    ? 'No está en tu catálogo de exámenes: se muestra el código.'
                    : null,
            ])
            ->values()
            ->all();
    }

    /** La observación del grupo de exámenes (`examen_obs.observacion`), o `null` si no hay texto. */
    public function observacion(): ?string
    {
        return $this->observacion;
    }

    public function hayObservacion(): bool
    {
        return trim((string) $this->observacion) !== '';
    }

    /**
     * El estado de la sección, como pastilla: cuántos exámenes tiene la consulta. Es información, no un
     * error: una consulta sin exámenes es lo normal en la mayoría de las atenciones.
     */
    public function estado(): array
    {
        if (! $this->hay()) {
            return [
                'tono' => 'neutro',
                'icono' => 'bi-droplet',
                'texto' => 'Sin exámenes',
                'titulo' => 'Esta consulta no tiene exámenes pedidos',
            ];
        }

        return [
            'tono' => 'info',
            'icono' => 'bi-droplet',
            'texto' => $this->total() === 1 ? '1 examen' : $this->total() . ' exámenes',
            'titulo' => 'Los exámenes que tiene pedidos esta consulta',
        ];
    }

    /**
     * El estado vacío de la tabla, con dos textos distintos según por qué está vacía: el catálogo del
     * médico existe y esta consulta no pidió nada, o el catálogo está vacío (el escritorio todavía no lo
     * subió) y entonces no hay nada que pedir. Son dos cosas distintas y el médico tiene que saber cuál
     * le tocó.
     */
    public function sinExamenes(): array
    {
        if (! $this->hayCatalogo) {
            return [
                'icono' => 'bi-clipboard2-x',
                'titulo' => 'Tu catálogo de exámenes está vacío',
                'texto' => 'Los exámenes que se le piden a un paciente salen de la lista que el escritorio '
                    . 'mantiene (la tabla examenes) y que llega a la nube con la sincronización. Mientras '
                    . 'no haya ninguna cargada, esta consulta no puede tener exámenes. Administrar el '
                    . 'catálogo es otra tarea (WEB-8.1).',
            ];
        }

        return [
            'icono' => 'bi-droplet',
            'titulo' => 'Esta consulta no tiene exámenes pedidos',
            'texto' => 'Los exámenes de laboratorio de la consulta los carga el escritorio desde su '
                . 'pestaña de solicitudes; acá se leen para tenerlos a la vista. Lo que sí puedes cargar '
                . 'en esta sección son las imágenes de un estudio.',
        ];
    }
}
