<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\InformeDeLaConsulta;
use App\Support\FechaClinica;
use Illuminate\Support\Collection;

/**
 * **La pestaña Informe médico** (WEB-5.3a): el *Para*, el texto y la fecha, con las dos ayudas para escribirlo.
 *
 * Tres formas de llegar al texto, todas terminan en el **mismo editor** y ninguna guarda por su cuenta:
 *
 *  1. escribirlo a mano;
 *  2. **elegir una plantilla** del médico (`formato_print`, tipo `IM`): copia su texto en el campo;
 *  3. **copiar un informe anterior** del mismo paciente: copia su *Para* y su texto.
 *
 * Elegir una plantilla o copiar un informe **es escribir**: el aviso de «cambios sin guardar» aparece igual y el guardado
 * sigue siendo el botón (lo resuelve `consulta-solicitudes.js` desde los `data-*`; el texto viaja en un atributo, no en
 * un `<option>`). Un **informe por consulta**, que se corrige en el lugar.
 *
 * Desde WEB-5.3c esta pestaña ofrece además el **resumen médico** (`d_resumen_medico_gra`), la hoja que se arma sola con
 * los datos de toda la consulta: es la otra mitad de la pestaña del escritorio y no se edita acá (`ResumenMedico`).
 * Sin base de datos.
 */
final class InformeVista
{
    /**
     * @param  Collection<int,object{codigo:string, titulo:string, texto:string}>  $plantillas
     * @param  Collection<int,object{consulta:int, fecha:?string, para:?string, texto:string}>  $anteriores
     * @param  array<string,string>  $urls  `guardar`, `eliminar`, `imprimir` e `imprimirResumen`
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     * @param  bool  $hayResumen  ¿el **resumen médico** de esta consulta (WEB-5.3c) tiene algo que imprimir?
     */
    public function __construct(
        private ?string $para,
        private ?string $texto,
        private ?string $fecha,
        private bool $esReal,
        private string $fechaPorDefecto,
        private Collection $plantillas,
        private Collection $anteriores,
        private array $urls,
        private array $old = [],
        private bool $hayResumen = false,
    ) {
    }

    /** El *Para* del campo: lo escrito, lo guardado o —en un informe nuevo— el del escritorio. */
    public function para(): string
    {
        if (array_key_exists('para', $this->old)) {
            return (string) $this->old['para'];
        }

        return (string) ($this->esReal ? ($this->para ?? '') : InformeDeLaConsulta::PARA_POR_DEFECTO);
    }

    public function texto(): string
    {
        return (string) ($this->old['descripcion'] ?? ($this->esReal ? $this->texto : null) ?? '');
    }

    /** La fecha para el `<input type="date">`: lo escrito, la guardada o la de hoy en el consultorio. */
    public function fecha(): string
    {
        return (string) ($this->old['fecha'] ?? ($this->esReal ? FechaClinica::paraInput($this->fecha) : '') ?: $this->fechaPorDefecto);
    }

    public function esReal(): bool
    {
        return $this->esReal;
    }

    public function largoDelPara(): int
    {
        return InformeDeLaConsulta::LARGO_PARA;
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

    public function hayAnteriores(): bool
    {
        return $this->anteriores->isNotEmpty();
    }

    /** @return array<int,array{titulo:string, resumen:string, para:string, texto:string}> */
    public function anteriores(): array
    {
        return $this->anteriores->map(fn (object $informe) => [
            'titulo' => 'Consulta ' . $informe->consulta . ' · ' . FechaClinica::formato($informe->fecha),
            'resumen' => $this->recorte($informe->texto),
            'para' => (string) ($informe->para ?? ''),
            'texto' => $informe->texto,
        ])->values()->all();
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

    /**
     * **El resumen médico** (WEB-5.3c): la hoja que se arma sola con los datos de toda la consulta. Vive en
     * esta misma pestaña porque así la tiene el escritorio (`tabpage_14`: el texto del informe y, al lado,
     * el radio del resumen); el botón se ofrece **sólo si hay algo que resumir**, que no es una fila que se
     * cree al abrir la consulta sino lo que la consulta tenga cargado.
     */
    public function ofreceResumen(): bool
    {
        return $this->hayResumen;
    }

    public function urlImprimirResumen(): string
    {
        return $this->urls['imprimirResumen'];
    }

    public function puedeEliminar(): bool
    {
        return $this->esReal;
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        return $this->esReal
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Con informe', 'titulo' => 'Esta consulta tiene un informe']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin informe', 'titulo' => 'Esta consulta no tiene informe'];
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el informe. Si sales ahora, se pierden.';
    }

    public function avisoEliminar(): string
    {
        return '¿Eliminar el informe médico de esta consulta? Se borran el «Para», el texto y la fecha.';
    }

    public function ayuda(): string
    {
        return 'Un informe por consulta. Escríbelo, parte de una plantilla o copia uno anterior del mismo paciente: '
            . 'el texto se lleva al campo para que lo termines, y se guarda con el botón.';
    }

    private function recorte(string $texto, int $largo = 110): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return mb_strlen($texto) > $largo ? rtrim(mb_substr($texto, 0, $largo)) . '…' : $texto;
    }
}
