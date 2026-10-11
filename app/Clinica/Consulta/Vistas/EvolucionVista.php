<?php

namespace App\Clinica\Consulta\Vistas;

use Illuminate\Support\Collection;

/**
 * **La evolución de una consulta** (WEB-4.6, ítem 9 del panel del legado): el texto de seguimiento
 * (`consultas.evolucion`) y —cuando el médico los tiene— las **plantillas** que el escritorio ofrece al
 * lado del campo.
 *
 * Es la misma pantalla que la enfermedad actual (WEB-4.2), con la misma decisión de producto y por las
 * mismas razones: es **texto clínico**, así que se guarda con un **botón explícito** y no con
 * autoguardado, y por eso la pantalla **avisa** cuando hay algo escrito sin guardar y el navegador avisa
 * antes de perderlo (lo resuelve `public/js/clinica/consulta.js` a partir de los `data-*`, no la vista).
 *
 * Dos cosas propias de esta sección, que salen de la fuente (`EvolucionDeLaConsulta` tiene las citas):
 *
 *  - **Las plantillas se aplican al campo, no se guardan solas.** El doble clic del escritorio copia la
 *    `descripcion` de `formato_print` en el `multilineedit` de la evolución (`w_consulta_principal.srw:12002`)
 *    y el médico la termina de editar antes de guardar. Elegir una plantilla es *escribir*, y el aviso de
 *    cambios sin guardar tiene que aparecer igual: la plantilla no es un dato guardado.
 *  - **Una consulta sin plantillas lo dice**, en vez de mostrar una lista vacía: es el estado real de la
 *    mayoría de las instalaciones y el médico tiene que poder entender que el campo se escribe a mano.
 *
 * El ViewModel no sabe de `Request` ni de base de datos: recibe el texto y las plantillas ya leídos.
 */
final class EvolucionVista
{
    /**
     * @param  string|null  $texto  lo que hoy dice `consultas.evolucion` (de sólo lectura para el
     *                              ViewModel: el valor que se edita lo trae el `old()` del formulario)
     * @param  Collection<int,object{codigo:string, titulo:string, texto:string}>  $plantillas  las de este médico
     * @param  string  $urlGuardar  a dónde postea "Guardar" (el texto va como `evolucion`)
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de los formularios de la guía de UI)
     */
    public function __construct(
        private ?string $texto,
        private Collection $plantillas,
        private string $urlGuardar,
        private string $urlVolver = '',
    ) {
    }

    /** El texto tal como está guardado, o `null` si la consulta todavía no tiene evolución escrita. */
    public function texto(): ?string
    {
        return $this->texto;
    }

    /**
     * El valor que muestra el `<textarea>`: lo que se acaba de escribir (`old()`, después de un error de
     * validación **no se borra lo escrito**, guía de UI §3) o lo que ya estaba guardado.
     */
    public function valor(string $old = ''): string
    {
        return $old !== '' ? $old : (string) $this->texto;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** ¿La consulta todavía no tiene evolución? Es lo que dice el marcador del campo. */
    public function estaVacia(): bool
    {
        return trim((string) $this->texto) === '';
    }

    /**
     * El estado de la sección, como pastilla: "Sin escribir" mientras no haya texto. No dice "guardado"
     * cuando hay texto —de eso se encarga el aviso de cambios sin guardar—, sino que el dato existe.
     */
    public function estado(): array
    {
        return $this->estaVacia()
            ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin escribir', 'titulo' => 'Todavía no hay evolución en esta consulta']
            : ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Escrita', 'titulo' => 'La consulta ya tiene evolución'];
    }

    /** La ayuda del campo: qué se espera y qué pasa al salir. */
    public function ayuda(): string
    {
        return 'Cómo sigue el paciente: qué se le indicó, qué evolución tuvo, qué hay que controlar. Se '
            . 'guarda con el botón: si sales sin guardar, el navegador te avisa antes de perder el texto.';
    }

    /** El marcador del `<textarea>`: qué escribir. */
    public function marcador(): string
    {
        return 'Evolución, indicaciones y plan, con tus palabras.';
    }

    /** ¿El médico tiene plantillas de evolución cargadas (las que sube su escritorio)? */
    public function hayPlantillas(): bool
    {
        return $this->plantillas->isNotEmpty();
    }

    public function totalPlantillas(): int
    {
        return $this->plantillas->count();
    }

    /**
     * Las plantillas, listas para pintar. El `texto` viaja en un `data-*` y no en un `<option>`: el script
     * lo copia al campo al elegirla, que es exactamente lo que hace el doble clic del escritorio.
     *
     * @return array<int,array{codigo:string, titulo:string, texto:string}>
     */
    public function plantillas(): array
    {
        return $this->plantillas
            ->map(fn ($plantilla) => [
                'codigo' => (string) $plantilla->codigo,
                'titulo' => (string) $plantilla->titulo,
                'texto' => (string) $plantilla->texto,
            ])
            ->values()
            ->all();
    }

    /** El estado vacío de la lista de plantillas: por qué no hay y qué hacer. */
    public function sinPlantillas(): array
    {
        return [
            'icono' => 'bi-file-earmark-text',
            'titulo' => 'Sin plantillas de evolución',
            'texto' => 'Tu escritorio no tiene cargadas plantillas de evolución (la lista «Evolución» de la '
                . 'tabla formato_print), así que este campo se escribe a mano. Si las cargas en el escritorio '
                . 'y sincronizas, aparecen acá.',
        ];
    }

    /** El aviso de "hay cambios sin guardar": el texto que usa el navegador y el cartel de la pantalla. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en la evolución. Si sales ahora, se pierden.';
    }
}
