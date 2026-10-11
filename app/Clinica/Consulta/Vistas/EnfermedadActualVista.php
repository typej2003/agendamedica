<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **La enfermedad actual de una consulta** (WEB-4.2, ítem 2 del panel del legado): el texto libre de lo
 * que el paciente tiene hoy (`consultas.enfermedadactual`).
 *
 * Tres decisiones de producto que esta pantalla hace explícitas (todo lo demás es una tarjeta con un
 * `<textarea>`):
 *
 *  - **Editable** (pedido de Alexander, 2026-10-09, TODO-WEB.md WEB-4.2): el móvil la muestra de sólo
 *    lectura y la web la edita. El texto que ya escribió el **escritorio** se ve **tal cual** (el legado
 *    guarda el párrafo completo, con sus saltos de línea y todo), porque es el mismo dato: no hay copia.
 *  - **Guardado explícito, no autoguardado.** Un botón ("Guardar la enfermedad actual") y no un guardado
 *    silencioso al salir: es texto clínico y el médico tiene que poder decidir cuándo queda escrito.
 *  - **Se avisa que hay cambios sin guardar.** Es la consecuencia de lo anterior: si se puede salir sin
 *    guardar, la pantalla tiene que decirlo. El aviso y el bloqueo del navegador al salir los resuelve
 *    `public/js/clinica/consulta.js` a partir de `data-*`, no la vista.
 *
 * El ViewModel no sabe de `Request` ni de base de datos: recibe el texto ya leído y las URLs.
 */
final class EnfermedadActualVista
{
    /**
     * @param  string|null  $texto  lo que hoy dice `consultas.enfermedadactual` (de sólo lectura para el
     *                              ViewModel: el valor que se edita lo trae el `old()` del formulario)
     * @param  string  $urlGuardar  a dónde postea "Guardar" (el texto va como `enfermedadactual`)
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de los formularios de la guía de UI)
     */
    public function __construct(
        private ?string $texto,
        private string $urlGuardar,
        private string $urlVolver = '',
    ) {
    }

    /** El texto tal como está guardado, o `null` si la consulta todavía no tiene nada escrito. */
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

    /** ¿La consulta todavía no tiene enfermedad actual? Es lo que dice el marcador del campo. */
    public function estaVacia(): bool
    {
        return trim((string) $this->texto) === '';
    }

    /**
     * El estado de la sección, como pastilla: "Sin escribir" mientras el médico no haya anotado nada.
     * No dice "guardado" cuando hay texto —de eso se encarga el aviso de cambios sin guardar—, sino que
     * el dato existe, que es lo que el médico quiere confirmar de un vistazo.
     */
    public function estado(): array
    {
        return $this->estaVacia()
            ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin escribir', 'titulo' => 'Todavía no hay enfermedad actual en esta consulta']
            : ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Escrita', 'titulo' => 'La consulta ya tiene enfermedad actual'];
    }

    /** La ayuda del campo: qué se espera y qué pasa al salir. */
    public function ayuda(): string
    {
        return 'Lo que el paciente tiene hoy, con tus palabras. Se guarda con el botón: si sales sin guardar, '
            . 'el navegador te avisa antes de perder el texto.';
    }

    /** El marcador del `<textarea>`: qué escribir. */
    public function marcador(): string
    {
        return 'Motivo de la visita, desde cuándo, qué la desencadenó, cómo evolucionó…';
    }
}
