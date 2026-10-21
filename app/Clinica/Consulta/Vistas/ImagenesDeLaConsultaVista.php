<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\TagDeImagen;
use App\Support\FechaClinica;
use Illuminate\Support\Collection;

/**
 * **La subida de imágenes de una consulta** (WEB-4.7, ítem 7 del panel del legado: *Exámenes*).
 *
 * Es la mitad nueva del ítem: la sección lista lo que ya está (`SeccionDeExamenesVista`) y este ViewModel
 * es el formulario que carga una imagen nueva. La fila **cuelga de la consulta** —`imagen_consulta`
 * llaveada por `nrohistoria` + `nroconsulta`, la misma llave de `consultas`; ver
 * `App\Clinica\Consulta\ImagenesDeLaConsulta`, con la cita de la fuente— y **el archivo va al disco
 * privado del API** (`storage/app/clinica`, ver `App\Clinica\Archivos\ArchivosClinicos`): nada de rutas
 * adivinables ni de archivos servidos por el servidor web.
 *
 * Tres cosas que el ViewModel decide y la vista no:
 *
 *  - **Lo que se puede ver y lo que no.** Una imagen que subió la web se abre por su ruta autorizada; una
 *    que quedó del escritorio tiene su archivo en el disco de la PC del consultorio y **no** se puede
 *    abrir desde acá (PLAN-WEB.md §3.9): la fila se lista —el médico tiene que saber que existe— y dice
 *    dónde está. Prometer un enlace que da 404 sería peor que decir la verdad.
 *  - **La fecha de la subida** (`created_at`), ya formateada, y la descripción tal como quedó (los
 *    "vacíos" del legado —`NULL`, `''` y el literal `none`— son "sin descripción", no un texto).
 *  - **Las URLs**, que resuelve el controlador (como en todas las secciones de la web clínica) y llegan
 *    acá como funciones: la de la descarga lleva el id de la fila y la del borrado también.
 *
 * El ViewModel no sabe de `Request` ni de base de datos: recibe la colección ya leída.
 */
final class ImagenesDeLaConsultaVista
{
    /**
     * @param  Collection<int,object>  $imagenes  las filas ya resueltas por `ImagenesDeLaConsulta`
     * @param  string  $urlSubir  a dónde postea el formulario de subida
     * @param  callable(int):string  $urlDescargar  la ruta autorizada que sirve el archivo de esa fila
     * @param  callable(int):string  $urlQuitar  a dónde postea la baja de esa fila
     * @param  string|null  $examen  la **solapa del examen** de la que son estas imágenes (WEB-6.5: `colposcopia`), o
     *                               `null` para la sección genérica de exámenes. Cuando viene, la subida marca la
     *                               etiqueta de ese examen (`TagDeImagen`) y **no** se pide descripción: la columna
     *                               `observacion` es una sola (trampa 116)
     */
    public function __construct(
        private Collection $imagenes,
        private string $urlSubir,
        private $urlDescargar,
        private $urlQuitar,
        private ?string $examen = null,
        // «Esta consulta | Todas» (WEB-11.22): las opciones del chip, `[texto, url, activa]`, y la consulta que se está mirando.
        private array $alcances = [],
        private ?int $consultaActual = null,
        // El aviso de la baja de una fila (O-09): la tarjeta de la imagen del examen lo cambia cuando la foto tiene un
        // trazo guardado, porque el trazo se va con ella. `null` deja el aviso de siempre.
        private $avisoQuitar = null,
    ) {
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function alcances(): array
    {
        return $this->alcances;
    }

    /** La solapa del examen de estas imágenes, o `null` si son las de la sección genérica. */
    public function examen(): ?string
    {
        return $this->examen;
    }

    /**
     * La etiqueta con la que se marcan las imágenes que se suben desde acá: la del examen (`TagDeImagen`), o
     * `null` en la sección genérica (donde la columna lleva la descripción que teclea el médico).
     */
    public function etiqueta(): ?string
    {
        return $this->examen === null ? null : TagDeImagen::deLaSolapa($this->examen);
    }

    /** ¿El formulario pide descripción? Sólo en la sección genérica: en un examen la columna lleva su etiqueta. */
    public function pideDescripcion(): bool
    {
        return $this->examen === null;
    }

    public function total(): int
    {
        return $this->imagenes->count();
    }

    public function hay(): bool
    {
        return $this->imagenes->isNotEmpty();
    }

    public function urlSubir(): string
    {
        return $this->urlSubir;
    }

    /**
     * Las imágenes, listas para pintar.
     *
     * `puedeVerse` es la bandera que decide si hay enlace: es `true` sólo cuando el archivo está en el
     * disco del servidor. `origen` es el texto que se muestra en su lugar cuando no lo está, y no dice
     * "error": dice dónde quedó el archivo, que es lo que el médico necesita saber.
     *
     * @return array<int,array<string,mixed>>
     */
    public function imagenes(): array
    {
        return $this->imagenes
            ->map(function ($imagen) {
                $id = (int) $imagen->id;

                return [
                    'id' => $id,
                    // Una imagen de un examen no tiene descripción: tiene la **etiqueta** de su examen.
                    'etiqueta' => $imagen->etiqueta ?? null,
                    // De qué consulta es (sólo se muestra cuando se miran **todas**): las de otra consulta se ven pero no se quitan desde acá.
                    'consulta' => (int) ($imagen->nroconsulta ?? 0),
                    'deOtraConsulta' => $this->consultaActual !== null && (int) ($imagen->nroconsulta ?? 0) !== $this->consultaActual,
                    'descripcion' => $imagen->descripcion,
                    'fecha' => FechaClinica::formato($imagen->fecha),
                    'nombre' => $imagen->nombre,
                    'puedeVerse' => (bool) $imagen->enElServidor,
                    // HEIC/HEIF: el navegador no lo muestra, se descarga (WEB-T.1).
                    'esDescarga' => (bool) ($imagen->descarga ?? false),
                    'url' => $imagen->enElServidor ? ($this->urlDescargar)($id) : null,
                    'urlQuitar' => ($this->urlQuitar)($id),
                    // El texto de la confirmación de la baja: la tarjeta de la imagen del examen avisa que el trazo se va
                    // con la foto (O-09).
                    'confirmar' => $this->avisoQuitar === null
                        ? '¿Quitar esta imagen de la consulta? Se borra el archivo del servidor y no se puede deshacer.'
                        : ($this->avisoQuitar)($id),
                    'origen' => $imagen->enElServidor
                        ? 'En el servidor'
                        : 'En el disco del consultorio',
                    'detalle' => $imagen->enElServidor
                        ? (($imagen->descarga ?? false)
                            ? 'Es una foto HEIC: el navegador no la muestra, se descarga. Sólo la baja el médico dueño de la historia.'
                            : 'Se abre en una pestaña nueva; sólo la ve el médico dueño de la historia.')
                        : 'Quedó en la PC donde se cargó. '
                            . 'Todavía no se subió a la nube (se sube con la herramienta subir-imagenes) o se omitió por no ser una imagen.',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * El estado de la lista: "Sin imágenes" (con la invitación a subir la primera) o el conteo. La
     * sección vacía **no** es un error: es el estado normal de una consulta que no tiene estudios con
     * imagen.
     */
    public function estado(): array
    {
        if ($this->total() === 0) {
            return [
                'tono' => 'neutro',
                'icono' => 'bi-image',
                'texto' => 'Sin imágenes',
                'titulo' => 'Esta consulta todavía no tiene imágenes cargadas',
            ];
        }

        return [
            'tono' => 'exito',
            'icono' => 'bi-images',
            'texto' => $this->total() === 1 ? '1 imagen' : $this->total() . ' imágenes',
            'titulo' => 'Las imágenes cargadas de esta consulta',
        ];
    }

    /** El estado vacío de la lista: qué es y cómo se llena. */
    public function sinImagenes(): array
    {
        if ($this->examen !== null) {
            return [
                'icono' => 'bi-image',
                'titulo' => 'Todavía no hay imágenes en ' . $this->nombreDelExamen(),
                'texto' => 'Puedes subir las fotos de este examen. La imagen queda guardada en el servidor y '
                    . 'sólo la ves tú, que eres el dueño de la historia.',
            ];
        }

        return [
            'icono' => 'bi-image',
            'titulo' => 'Todavía no hay imágenes en esta consulta',
            'texto' => 'Puedes subir la foto de un estudio, una ecografía o una colposcopia con su '
                . 'descripción. La imagen queda guardada en el servidor y sólo la ves tú, que eres el '
                . 'dueño de la historia.',
        ];
    }

    /** El texto de la ayuda de la subida cuando las imágenes son de un examen. */
    public function ayudaDelExamen(): string
    {
        return 'Son las fotos de ' . $this->nombreDelExamen() . ': se guardan con la etiqueta de este examen.';
    }

    /** Cómo se llama el examen del que son estas imágenes (`colposcopía`). */
    private function nombreDelExamen(): string
    {
        return TagDeImagen::nombre(TagDeImagen::deLaSolapa((string) $this->examen)) ?? 'este examen';
    }

    /**
     * El aviso de las filas del escritorio, cuando hay alguna.
     *
     * No es un error de la pantalla: es una limitación conocida y anotada. El escritorio guardó **la ruta
     * del archivo** en el disco de esa PC (medido: `C:\Users\IBYTE\Downloads\…` en los 366 registros del
     * dataset de prueba), así que el archivo no está en el servidor y no hay nada que servir. Copiarlo a
     * la nube es un proceso aparte (WEB-T.1).
     */
    public function avisoDelEscritorio(): ?string
    {
        if (! $this->imagenes->contains(fn ($imagen) => ! $imagen->enElServidor)) {
            return null;
        }

        return 'Las imágenes marcadas «En el disco del consultorio» se cargaron en la PC del consultorio: la base '
            . 'guarda la ruta del archivo en esa PC, no el archivo. Se listan para que no se pierdan de '
            . 'vista, pero no se pueden abrir desde la web hasta que el consultorio corra la herramienta que sube las imágenes.';
    }

    /** La ayuda del campo del archivo: qué se acepta y qué pasa con el nombre del archivo. */
    public function ayudaArchivo(): string
    {
        return ArchivosClinicos::textosAceptados() . ', hasta ' . ArchivosClinicos::textoMaximo() . '.';
    }

    /** La ayuda de la descripción: por qué se pide. */
    public function ayudaDescripcion(): string
    {
        return 'Es lo que se lee en la lista.';
    }

    /** El aviso del navegador al salir: la subida de un archivo se pierde si se sale sin enviarla. */
    public function avisoSinGuardar(): string
    {
        return 'Elegiste una imagen y no la subiste. Si sales ahora, se pierde.';
    }

    /**
     * La descripción que muestra el campo después de un error de validación. El **archivo** no se puede
     * conservar —un `<input type="file">` no se puede repoblar—, así que ante un error lo único que se
     * mantiene es la descripción y la ayuda del campo lo dice.
     *
     * El parámetro es `?string` porque `old()` devuelve `null` cuando el campo no vino en el POST (pasa en
     * el mismo request en que se sube la imagen: el error lo tiene el archivo, no la descripción). Se midió
     * en Chrome: con el tipo `string` el `old()` nulo reventaba la pantalla con un 500 en vez de mostrar el
     * mensaje del archivo.
     */
    public function descripcionElegida(?string $old = null): string
    {
        return (string) $old;
    }
}
