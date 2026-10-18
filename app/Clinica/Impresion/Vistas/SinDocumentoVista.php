<?php

namespace App\Clinica\Impresion\Vistas;

/**
 * **La página que dice que no hay nada que imprimir** (O-16): la que ve el médico cuando pide la hoja de un documento que
 * todavía no existe —un estudio que no se guardó, una solicitud sin datos— en vez de la página «No encontramos esa página».
 *
 * No es un 404: la consulta y el documento son válidos, lo que falta es el dato. Dice qué hacer («Guarda los datos del estudio
 * para poder imprimir») y ofrece la vuelta a la sección.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe el nombre del documento y la vuelta ya resueltos.
 */
final class SinDocumentoVista
{
    /**
     * @param  string  $documento  cómo se llama el documento que se quiso imprimir (`Eco pélvico`, `Reposo médico`…); `null` si
     *                             la consulta no tiene ninguno de los que se pidieron
     * @param  array{volver:string, volverTexto:string}  $urls
     */
    public function __construct(
        private ?string $documento,
        private array $urls,
    ) {
    }

    public function titulo(): string
    {
        return 'No hay nada que imprimir';
    }

    /** Qué se quiso imprimir, en una frase: «La hoja del eco pélvico todavía no existe.» */
    public function detalle(): string
    {
        if ($this->documento === null) {
            return 'Esta consulta todavía no tiene ese documento.';
        }

        return 'La hoja de ' . mb_strtolower($this->documento) . ' todavía no existe.';
    }

    /**
     * El respaldo de O-16: lo que hay que hacer, con las mismas palabras del botón que guarda y después imprime.
     */
    public function mensaje(): string
    {
        return 'Guarda los datos del estudio para poder imprimir.';
    }

    public function urlVolver(): string
    {
        return $this->urls['volver'];
    }

    public function textoVolver(): string
    {
        return $this->urls['volverTexto'];
    }
}
