<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\ConstanciaDeLaConsulta;
use App\Support\FechaClinica;

/**
 * **La pestaña Constancia** (WEB-5.3a): el texto fijo de la constancia con los diagnósticos de la consulta
 * auto-importados y **editables**, y la observación del médico.
 *
 * La constancia es **una por consulta** y su texto base no se escribe (lo arma la consulta con el paciente y la fecha):
 * el médico edita los **diagnósticos** y la **observación**. Los diagnósticos que trae el editor son los de la consulta
 * mientras no los toque; si los edita se guardan aparte y la constancia **deja de seguir** a la consulta (la pantalla lo
 * dice y ofrece restaurar). Se guarda con un **botón** —es texto clínico—, con el aviso de cambios sin guardar que pone
 * `consulta-solicitudes.js` desde los `data-*`.
 *
 * Imprimir es siempre posible (el texto base existe para toda consulta, como en el escritorio), pero sólo es un
 * *documento* —se lista en la historia y la secretaría puede imprimirlo desde la cita— cuando el médico escribió algo
 * propio (`QueEsUnDocumento::constancia`). Sin base de datos.
 */
final class ConstanciaVista
{
    /**
     * @param  string  $paciente  el nombre como lo imprime la constancia (`Nombres Apellidos`)
     * @param  string  $automatico  los diagnósticos que arma la consulta
     * @param  string|null  $editados  los diagnósticos que el médico editó, o `null` si sigue a la consulta
     * @param  array<string,string>  $urls  `guardar`, `eliminar` e `imprimir`
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     */
    public function __construct(
        private string $paciente,
        private string $fecha,
        private string $automatico,
        private ?string $editados,
        private ?string $observacion,
        private bool $conContenidoPropio,
        private array $urls,
        private array $old = [],
    ) {
    }

    /** El texto fijo con el paciente y el día ya puestos. */
    public function textoBase(): string
    {
        return ConstanciaDeLaConsulta::textoBase($this->paciente, FechaClinica::formato($this->fecha));
    }

    /** Lo que muestra el campo de diagnósticos: lo que se acaba de escribir, lo editado o el automático. */
    public function diagnosticos(): string
    {
        return (string) ($this->old['diagnosticos'] ?? $this->editados ?? $this->automatico);
    }

    /** El texto que arma la consulta: lo que vuelve el botón «Usar los de la consulta». */
    public function automatico(): string
    {
        return $this->automatico;
    }

    public function observacion(): string
    {
        return (string) ($this->old['observacion'] ?? $this->observacion ?? '');
    }

    /** ¿Los diagnósticos siguen a los de la consulta (no hay una copia editada)? */
    public function siguenALaConsulta(): bool
    {
        return $this->editados === null;
    }

    public function sinDiagnosticos(): bool
    {
        return $this->automatico === '' && $this->editados === null;
    }

    public function tieneContenidoPropio(): bool
    {
        return $this->conContenidoPropio;
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

    /** Quitar lo propio sólo tiene sentido si hay algo propio guardado. */
    public function puedeQuitar(): bool
    {
        return $this->conContenidoPropio;
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        return $this->conContenidoPropio
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Con observación o diagnósticos propios', 'titulo' => 'Es un documento: se lista en la historia y la secretaría puede imprimirlo']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Solo el texto base', 'titulo' => 'Se imprime con el texto base y los diagnósticos de la consulta'];
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en la constancia. Si sales ahora, se pierden.';
    }

    public function avisoQuitar(): string
    {
        return '¿Quitar lo propio de la constancia? Se borran tu observación y los diagnósticos que editaste; '
            . 'la constancia vuelve a su texto base con los diagnósticos de la consulta.';
    }

    public function ayuda(): string
    {
        return 'El texto base siempre es el mismo. Los diagnósticos se traen de esta consulta y puedes ajustarlos; '
            . 'la observación va al final.';
    }
}
