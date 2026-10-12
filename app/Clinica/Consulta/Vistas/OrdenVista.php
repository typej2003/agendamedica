<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EstudiosDeLaConsulta;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;

/**
 * **Las pestañas Laboratorio y Estudios** (WEB-5.4): una orden de varias filas —exámenes de laboratorio o estudios de imágenes— con su
 * «Resumen clínico», que se arma en el navegador (se busca en el catálogo, se agrega, se quita) y se guarda **entera** con un botón.
 *
 * Las dos pestañas son la misma pantalla con otros textos, otro catálogo y otras URLs, así que comparten este ViewModel: se crean con
 * `laboratorio()` o `estudios()`. La diferencia que importa es que el laboratorio agrupa por tipo y ofrece **rutinas** (un tipo de examen
 * completo, de una vez), y los estudios van **en el orden en que se agregaron** y se numeran. Sin base de datos: recibe lo ya leído.
 *
 * El JS (`consulta-ordenes.js`) lee de los `data-*` de la raíz el nombre del campo, las URLs y los textos, y no trae ninguno propio.
 */
final class OrdenVista
{
    public const LABORATORIO = 'laboratorio';

    public const ESTUDIOS = 'estudios';

    /**
     * @param  array<int,array{codigo:string, nombre:string, tipo:?string, aviso:?string}>  $items  lo que tiene la orden (o lo que quedó en el formulario si falló)
     * @param  array<int,array{codigo:string, nombre:string, cantidad:int}>  $tipos  los tipos de examen con exámenes (laboratorio)
     * @param  array<string,string>  $urls  `guardar`, `eliminar`, `imprimir`, `catalogo`, `rutina` (con `__TIPO__`) y `administrar`
     */
    private function __construct(
        private string $clave,
        private array $items,
        private ?string $observacion,
        private bool $esReal,
        private bool $hayCatalogo,
        private array $tipos,
        private array $urls,
    ) {
    }

    /**
     * @param  array<int,array{codigo:string, nombre:string, tipo:?string, aviso:?string}>  $items
     * @param  array<int,array{codigo:string, nombre:string, cantidad:int}>  $tipos
     * @param  array<string,string>  $urls
     */
    public static function laboratorio(array $items, ?string $observacion, bool $esReal, bool $hayCatalogo, array $tipos, array $urls): self
    {
        return new self(self::LABORATORIO, $items, $observacion, $esReal, $hayCatalogo, $tipos, $urls);
    }

    /**
     * @param  array<int,array{codigo:string, nombre:string, tipo:?string, aviso:?string}>  $items
     * @param  array<string,string>  $urls
     */
    public static function estudios(array $items, ?string $observacion, bool $esReal, bool $hayCatalogo, array $urls): self
    {
        return new self(self::ESTUDIOS, $items, $observacion, $esReal, $hayCatalogo, [], $urls);
    }

    public function clave(): string
    {
        return $this->clave;
    }

    public function esLaboratorio(): bool
    {
        return $this->clave === self::LABORATORIO;
    }

    public function titulo(): string
    {
        return $this->esLaboratorio() ? 'Orden de exámenes de laboratorio' : 'Solicitud de estudios';
    }

    public function icono(): string
    {
        return $this->esLaboratorio() ? 'bi-droplet' : 'bi-image';
    }

    /** El nombre del campo de los códigos en el formulario (`examenes[]` o `estudios[]`). */
    public function campo(): string
    {
        return $this->esLaboratorio() ? 'examenes' : 'estudios';
    }

    /** Lo que se pide, en plural y en minúscula (para los textos). */
    public function cosas(): string
    {
        return $this->esLaboratorio() ? 'exámenes' : 'estudios';
    }

    /** @return array<int,array{codigo:string, nombre:string, tipo:?string, aviso:?string}> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return count($this->items);
    }

    /** Cuántos hay en la lista que se edita («3 exámenes en la orden»); el script lo recalcula al agregar y quitar. */
    public function cuenta(): string
    {
        $n = $this->total();

        return $n === 0
            ? ($this->esLaboratorio() ? 'La orden no tiene exámenes.' : 'La solicitud no tiene estudios.')
            : $n . ($this->esLaboratorio() ? ($n === 1 ? ' examen' : ' exámenes') : ($n === 1 ? ' estudio' : ' estudios')) . ($this->esLaboratorio() ? ' en la orden.' : ' en la solicitud.');
    }

    public function observacion(): string
    {
        return (string) ($this->observacion ?? '');
    }

    public function largoDeLaObservacion(): int
    {
        return $this->esLaboratorio() ? LaboratorioDeLaConsulta::LARGO_OBSERVACION : EstudiosDeLaConsulta::LARGO_OBSERVACION;
    }

    public function esReal(): bool
    {
        return $this->esReal;
    }

    public function puedeEliminar(): bool
    {
        return $this->esReal;
    }

    public function hayCatalogo(): bool
    {
        return $this->hayCatalogo;
    }

    /** ¿Ofrece las rutinas (los tipos de examen completos)? Sólo el laboratorio. */
    public function ofreceRutinas(): bool
    {
        return $this->esLaboratorio() && $this->tipos !== [];
    }

    /** @return array<int,array{codigo:string, nombre:string, cantidad:int}> */
    public function tipos(): array
    {
        return $this->tipos;
    }

    public function numerada(): bool
    {
        return ! $this->esLaboratorio();
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

    public function urlCatalogo(): string
    {
        return $this->urls['catalogo'];
    }

    /** La URL del JSON de una rutina, con `__TIPO__` donde va el código del tipo. */
    public function urlRutina(): string
    {
        return $this->urls['rutina'] ?? '';
    }

    /** El catálogo de exámenes (el ABM de WEB-8.1b), donde se crea lo que falta. */
    public function urlAdministrar(): string
    {
        return $this->urls['administrar'];
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        if (! $this->esReal) {
            return ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => $this->esLaboratorio() ? 'Sin orden' : 'Sin solicitud',
                'titulo' => $this->esLaboratorio() ? 'Esta consulta no tiene orden de laboratorio' : 'Esta consulta no tiene solicitud de estudios'];
        }

        $n = $this->total();

        return ['tono' => 'exito', 'icono' => 'bi-check2-circle',
            'texto' => $n === 0 ? 'Con resumen' : $n . ($this->esLaboratorio() ? ($n === 1 ? ' examen' : ' exámenes') : ($n === 1 ? ' estudio' : ' estudios')),
            'titulo' => $this->esLaboratorio() ? 'Esta consulta tiene una orden de laboratorio' : 'Esta consulta tiene una solicitud de estudios'];
    }

    public function avisoSinGuardar(): string
    {
        return $this->esLaboratorio()
            ? 'Hay cambios sin guardar en la orden de laboratorio. Si sales ahora, se pierden.'
            : 'Hay cambios sin guardar en la solicitud de estudios. Si sales ahora, se pierden.';
    }

    public function avisoEliminar(): string
    {
        return $this->esLaboratorio()
            ? '¿Eliminar la orden de laboratorio de esta consulta? Se quitan todos sus exámenes y el resumen clínico.'
            : '¿Eliminar la solicitud de estudios de esta consulta? Se quitan todos sus estudios y el resumen clínico.';
    }

    public function ayuda(): string
    {
        return $this->esLaboratorio()
            ? 'Busca en tu catálogo los exámenes que le pides al paciente, o agrega una rutina completa. La orden se guarda entera con el botón '
                . 'y se imprime en media hoja, con los exámenes agrupados por tipo.'
            : 'Busca en tu catálogo los estudios de imágenes que le pides al paciente. Salen en la hoja en el orden de la lista. La solicitud se '
                . 'guarda entera con el botón y se imprime en media hoja.';
    }

    public function etiquetaDelResumen(): string
    {
        return 'Resumen clínico';
    }

    public function ayudaDelResumen(): string
    {
        return $this->esLaboratorio()
            ? 'Opcional. Sale en la hoja bajo los exámenes (por ejemplo, el motivo o lo que se quiere descartar).'
            : 'Opcional. Sale en la hoja bajo los estudios (por ejemplo, el motivo del estudio).';
    }

    public function etiquetaDelBuscador(): string
    {
        return $this->esLaboratorio() ? 'Busca en tu catálogo de exámenes' : 'Busca en tu catálogo de estudios';
    }

    public function placeholderDelBuscador(): string
    {
        return $this->esLaboratorio() ? 'Escribe el nombre del examen' : 'Escribe el nombre del estudio';
    }

    public function ayudaDelBuscador(): string
    {
        return $this->esLaboratorio()
            ? 'Escribe parte del nombre (sin importar tildes ni mayúsculas) o elige un tipo. Lo que agregas entra a la orden; se guarda con el botón.'
            : 'Escribe parte del nombre (sin importar tildes ni mayúsculas). Lo que agregas entra a la solicitud; se guarda con el botón.';
    }

    /** El estado vacío de la lista de la orden. */
    public function vacio(): array
    {
        return $this->esLaboratorio()
            ? ['icono' => 'bi-droplet', 'titulo' => 'Todavía no hay exámenes en la orden', 'texto' => 'Búscalos en el catálogo de abajo o agrega una rutina completa.']
            : ['icono' => 'bi-image', 'titulo' => 'Todavía no hay estudios en la solicitud', 'texto' => 'Búscalos en el catálogo de abajo.'];
    }

    /** El estado vacío cuando el médico no tiene catálogo (no hay nada que buscar). */
    public function sinCatalogo(): array
    {
        return $this->esLaboratorio()
            ? ['icono' => 'bi-clipboard2-x', 'titulo' => 'Tu catálogo de exámenes está vacío',
                'texto' => 'Los exámenes salen de tu catálogo (el que mantiene el escritorio o el que crees en Catálogos → Exámenes).']
            : ['icono' => 'bi-clipboard2-x', 'titulo' => 'Tu catálogo no tiene estudios de imágenes',
                'texto' => 'Los estudios son los exámenes del catálogo con el tipo «ESTUDIO DE IMAGENES». Créalos en Catálogos → Exámenes.'];
    }

    public function textoDelEnlaceAlCatalogo(): string
    {
        return $this->esLaboratorio() ? '¿No está el examen? Créalo en Catálogos → Exámenes' : '¿No está el estudio? Créalo en Catálogos → Exámenes con el tipo «ESTUDIO DE IMAGENES»';
    }

    public function textoDeGuardar(): string
    {
        return $this->esLaboratorio() ? 'Guardar la orden' : 'Guardar la solicitud';
    }

    public function textoDeEliminar(): string
    {
        return $this->esLaboratorio() ? 'Eliminar la orden' : 'Eliminar la solicitud';
    }

    /** La confirmación de una rutina grande (laboratorio): se pregunta antes de pasar muchos exámenes de golpe. */
    public function umbralDeRutina(): int
    {
        return 40;
    }
}
