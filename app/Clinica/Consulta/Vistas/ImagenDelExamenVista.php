<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\ImagenDelExamen;
use Closure;

/**
 * **La tarjeta de la imagen del examen** (O-09): una sola tarjeta con un **radiobutón** entre la *imagen predeterminada*
 * (el esquema, lo que en A-13 era el dibujo) y el *videocolposcopio* (la foto real del estudio), y **se ve sólo el modo
 * elegido**. Los dos modos se pueden marcar con el mismo lienzo (`lienzo.js`), y lo que se elige es lo que se imprime.
 *
 * Qué decide este ViewModel y la vista no:
 *
 *  - **El modo** y la **foto** que se está marcando: llegan ya resueltos por `ImagenDelExamenDeLaConsulta` (la elección
 *    manda; sin elección abre en el modo que ya tiene datos).
 *  - **Los lienzos**: uno por variante del esquema (con su imagen base, en la colposcopía) y uno por foto **que el
 *    navegador pueda mostrar**, cada uno con sus URLs de trazo y su tamaño —el de la foto, no el marco del esquema—.
 *  - **Las fotos del estudio**: la lista, la subida y la baja son el mismo parcial de siempre (`_imagenes`), que ahora
 *    vive adentro de esta tarjeta y sólo se ve en el modo de la foto.
 *
 * No consulta la base ni toca el disco: recibe el estado ya leído y las clausuras que arman las URLs.
 */
final class ImagenDelExamenVista
{
    /** El icono y el color de las herramientas: los mismos de las mamas y del cuello uterino (A-13). */
    private const COLORES = ['#d62828' => 'Rojo', '#1d4ed8' => 'Azul', '#15803d' => 'Verde', '#000000' => 'Negro', '#f59e0b' => 'Naranja'];

    /** El lado mayor del lienzo de una foto: más resolución que eso no se nota y el PNG del trazo pesa de más. */
    private const LADO_MAXIMO_DEL_LIENZO = 1600;

    /** El tamaño del esquema: el marco de A-13 (`img/clinica/colposcopia-*.svg`). */
    private const MARCO_DEL_ESQUEMA = [520, 400];

    /**
     * @param  string  $examen  `colposcopia` (o `histeroscopia`)
     * @param  object  $estado  lo que devuelve `ImagenDelExamenDeLaConsulta::de()`
     * @param  Closure(string,string):string  $urlEsquema  la dirección de la variante y el verbo (`ver`, `guardar`, `quitar`)
     * @param  Closure(int,string):string  $urlFoto  la dirección del **trazo** de la foto y el verbo
     * @param  Closure(int):string  $urlImagen  la dirección autorizada del **archivo** de la foto (la imagen de base del lienzo)
     * @param  string  $urlElegir  a dónde postea el radiobutón
     * @param  ImagenesDeLaConsultaVista  $imagenes  la lista y la subida de las fotos del examen
     */
    public function __construct(
        private string $examen,
        private object $estado,
        private Closure $urlEsquema,
        private Closure $urlFoto,
        private Closure $urlImagen,
        private string $urlElegir,
        private ImagenesDeLaConsultaVista $imagenes,
    ) {
    }

    public function examen(): string
    {
        return $this->examen;
    }

    public function titulo(): string
    {
        return ImagenDelExamen::titulo($this->examen);
    }

    public function icono(): string
    {
        return ImagenDelExamen::icono($this->examen);
    }

    /** La ayuda de la tarjeta: **una línea** (guía de UI §10.1). */
    public function ayuda(): string
    {
        return 'Marca tus hallazgos sobre el esquema o sobre la foto del estudio: se guarda solo y el informe imprime '
            . 'sólo la imagen que elijas.';
    }

    /** El modo que se está viendo: `esquema` o `foto`. */
    public function modo(): string
    {
        return (string) $this->estado->modo;
    }

    public function esEsquema(): bool
    {
        return $this->modo() === ImagenDelExamen::MODO_ESQUEMA;
    }

    public function esFoto(): bool
    {
        return $this->modo() === ImagenDelExamen::MODO_FOTO;
    }

    /**
     * El radiobutón: los dos modos, con el elegido marcado y la frase que explica cada uno.
     *
     * @return array<int,array{valor:string, texto:string, descripcion:string, activa:bool}>
     */
    public function modos(): array
    {
        return array_map(fn (string $modo) => [
            'valor' => $modo,
            'texto' => ImagenDelExamen::nombreDelModo($this->examen, $modo),
            'descripcion' => ImagenDelExamen::descripcionDelModo($this->examen, $modo),
            'activa' => $modo === $this->modo(),
        ], ImagenDelExamen::MODOS);
    }

    public function urlElegir(): string
    {
        return $this->urlElegir;
    }

    /** La foto elegida (su id), o `null` si el estudio no tiene ninguna. */
    public function fotoElegida(): ?int
    {
        return $this->estado->foto === null ? null : (int) $this->estado->foto->id;
    }

    public function hayFotos(): bool
    {
        return $this->estado->fotos->isNotEmpty();
    }

    public function totalDeFotos(): int
    {
        return $this->estado->fotos->count();
    }

    /** ¿Hay que ofrecer el selector de «foto a marcar»? Sólo cuando hay más de una. */
    public function hayQueElegirLaFoto(): bool
    {
        return count($this->fotos()) > 1;
    }

    /** La clase de la columna de cada lienzo: el esquema de una sola variante ocupa todo el ancho. */
    public function columnas(): string
    {
        return count(ImagenDelExamen::variantes($this->examen)) === 1 ? 'col-12' : 'col-12 col-xl-6';
    }

    /**
     * Los lienzos del **esquema**, uno por variante, con su imagen base elegible.
     *
     * @return array<int,array<string,mixed>>
     */
    public function esquema(): array
    {
        return array_map(function (string $variante): array {
            $estado = $this->estado->esquema[$variante] ?? ['base' => ImagenDelExamen::BASE_POR_DEFECTO, 'trazo' => null];
            $base = ImagenDelExamen::base($this->examen, $estado['base']);
            $tieneTrazo = ($estado['trazo'] ?? null) !== null;

            return [
                'variante' => $variante,
                'titulo' => ImagenDelExamen::tituloDeLaVariante($variante),
                'descripcion' => ImagenDelExamen::descripcionDeLaVariante($variante),
                'base' => $base,
                'bases' => $this->bases($variante, $base),
                'imagen' => asset((string) ImagenDelExamen::ruta($this->examen, $variante, $base)),
                'dibujo' => $tieneTrazo ? ($this->urlEsquema)($variante, 'ver') : null,
                'guardar' => ($this->urlEsquema)($variante, 'guardar'),
                'clave' => ImagenDelExamen::claveDelEsquema($variante),
                'ancho' => self::MARCO_DEL_ESQUEMA[0],
                'alto' => self::MARCO_DEL_ESQUEMA[1],
            ];
        }, ImagenDelExamen::variantes($this->examen));
    }

    /**
     * Los lienzos de las **fotos** del estudio. Se ofrece uno por cada foto que el navegador puede mostrar (un HEIC se
     * descarga, no se marca); el de la foto elegida es el que se ve.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fotos(): array
    {
        $elegida = $this->fotoElegida();
        $lienzos = [];

        foreach ($this->estado->fotos as $foto) {
            $id = (int) $foto->id;

            if (! $foto->enElServidor || $foto->descarga) {
                continue;
            }

            [$ancho, $alto] = $this->marcoDeLaFoto($id);
            $tieneTrazo = isset($this->estado->trazos[$id]);

            $lienzos[] = [
                'id' => $id,
                'nombre' => $foto->nombre,
                // La imagen de base es la **foto** (la ruta autorizada de la imagen), no el trazo.
                'imagen' => ($this->urlImagen)($id),
                'dibujo' => $tieneTrazo ? ($this->urlFoto)($id, 'ver') : null,
                'guardar' => ($this->urlFoto)($id, 'guardar'),
                'clave' => 'foto-' . $id,
                'ancho' => $ancho,
                'alto' => $alto,
                'activa' => $id === $elegida,
            ];
        }

        return $lienzos;
    }

    /** ¿El estudio tiene alguna foto que no se pueda mostrar en el navegador? La tarjeta lo dice. */
    public function hayFotosQueNoSeMuestran(): bool
    {
        return $this->estado->fotos->contains(fn ($foto) => ! $foto->enElServidor || $foto->descarga);
    }

    /** El estado vacío del modo de la foto: no hay nada que marcar todavía. */
    public function sinFotos(): array
    {
        return $this->hayFotos()
            ? [
                'icono' => 'bi-image',
                'titulo' => 'Esta foto no se puede mostrar en el navegador',
                'texto' => 'Es una foto HEIC o quedó en el disco del consultorio: se lista abajo para que la descargues.',
            ]
            : [
                'icono' => 'bi-camera',
                'titulo' => 'Todavía no hay foto del estudio',
                'texto' => 'Sube la foto del videocolposcopio y después puedes marcar tus hallazgos encima.',
            ];
    }

    /** Las fotos del estudio (la lista, la baja y la subida), que la tarjeta muestra en el modo de la foto. */
    public function imagenes(): ImagenesDeLaConsultaVista
    {
        return $this->imagenes;
    }

    /**
     * La pastilla del estado: qué imagen es la del examen y si está marcada.
     *
     * @return array{tono:string, icono:string, texto:string, titulo:string}
     */
    public function estado(): array
    {
        $conTrazo = $this->esFoto()
            ? ($this->fotoElegida() !== null && isset($this->estado->trazos[$this->fotoElegida()]))
            : $this->hayTrazoDelEsquema();

        if ($this->esFoto() && ! $this->hayFotos()) {
            return ['tono' => 'neutro', 'icono' => 'bi-camera', 'texto' => 'Sin foto',
                'titulo' => 'Elegiste el videocolposcopio y el estudio todavía no tiene la foto'];
        }

        if ($conTrazo) {
            return ['tono' => 'exito', 'icono' => 'bi-brush', 'texto' => 'Con marcas',
                'titulo' => 'La imagen de este examen tiene marcas del médico'];
        }

        return $this->esFoto()
            ? ['tono' => 'info', 'icono' => 'bi-camera', 'texto' => 'Foto', 'titulo' => 'La imagen de este examen es la foto del estudio']
            : ['tono' => 'neutro', 'icono' => 'bi-image', 'texto' => 'Sin marcas', 'titulo' => 'La imagen de este examen es el esquema'];
    }

    /** @return array<string,string> `#hex => nombre`, como los de las mamas. */
    public function colores(): array
    {
        return self::COLORES;
    }

    public function avisoBorrar(): string
    {
        return '¿Borrar todo el trazo de este esquema? No se puede deshacer.';
    }

    public function avisoBorrarFoto(): string
    {
        return '¿Borrar todo el trazo de esta foto? La foto queda: sólo se borra la marca.';
    }

    /**
     * Las opciones del selector de imagen base del esquema, con la elegida marcada y la URL de cada una (el JS la cambia
     * sin recargar).
     *
     * @return array<int,array{valor:string, texto:string, imagen:string, elegida:bool}>
     */
    private function bases(string $variante, string $elegida): array
    {
        $opciones = [];

        foreach (ImagenDelExamen::bases($this->examen) as $valor => $texto) {
            $opciones[] = [
                'valor' => $valor,
                'texto' => $texto,
                'imagen' => asset((string) ImagenDelExamen::ruta($this->examen, $variante, $valor)),
                'elegida' => $valor === $elegida,
            ];
        }

        return $opciones;
    }

    /**
     * El marco del lienzo de una foto: el de la foto, achicado si es enorme (así el PNG del trazo no pesa de más y la
     * proporción se mantiene, que es lo que hace que la marca caiga en el mismo lugar al imprimir).
     *
     * @return array{0:int,1:int}
     */
    private function marcoDeLaFoto(int $imagen): array
    {
        [$ancho, $alto] = $this->estado->medidas[$imagen] ?? self::MARCO_DEL_ESQUEMA;
        $escala = min(1, self::LADO_MAXIMO_DEL_LIENZO / max($ancho, $alto));

        return [max(1, (int) round($ancho * $escala)), max(1, (int) round($alto * $escala))];
    }

    /** ¿Alguna variante del esquema tiene trazo guardado? */
    private function hayTrazoDelEsquema(): bool
    {
        foreach ($this->estado->esquema as $variante) {
            if (($variante['trazo'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
