<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\HospitalizacionDeLaConsulta;

/**
 * **La pestaña Hospitalización** (WEB-5.3b): la orden de hospitalización de la consulta —el lugar, el diagnóstico provisional y las
 * recomendaciones—, una por consulta y con guardado explícito.
 *
 * El diagnóstico **arranca vacío** (la fuente no lo importa), pero la pestaña ofrece copiarle los diagnósticos de la consulta con un
 * botón: como las plantillas, **escribe en el campo y no guarda** (lo resuelve `consulta-solicitudes.js` desde los `data-*`). Imprimir
 * sólo se ofrece cuando hay una orden de verdad (`QueEsUnDocumento::hospitalizacion`). Sin base de datos.
 */
final class HospitalizacionVista
{
    /**
     * @param  string  $diagnosticosDeLaConsulta  los diagnósticos de la consulta como los arma la constancia (vacío si no tiene)
     * @param  array<string,string>  $urls  `guardar`, `eliminar` e `imprimir`
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     */
    public function __construct(
        private ?string $hospital,
        private ?string $diagnostico,
        private ?string $recomendaciones,
        private bool $esReal,
        private string $diagnosticosDeLaConsulta,
        private array $urls,
        private array $old = [],
    ) {
    }

    public function hospital(): string
    {
        return (string) ($this->old['hospital'] ?? $this->hospital ?? '');
    }

    public function diagnostico(): string
    {
        return (string) ($this->old['diagnostico'] ?? $this->diagnostico ?? '');
    }

    public function recomendaciones(): string
    {
        return (string) ($this->old['recomendaciones'] ?? $this->recomendaciones ?? '');
    }

    public function largoDelHospital(): int
    {
        return HospitalizacionDeLaConsulta::LARGO_HOSPITAL;
    }

    public function esReal(): bool
    {
        return $this->esReal;
    }

    /** Los diagnósticos de la consulta, para el botón «Usar los diagnósticos de la consulta». */
    public function diagnosticosDeLaConsulta(): string
    {
        return $this->diagnosticosDeLaConsulta;
    }

    public function hayDiagnosticosDeLaConsulta(): bool
    {
        return $this->diagnosticosDeLaConsulta !== '';
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
        return $this->esReal
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Con orden', 'titulo' => 'Esta consulta tiene una orden de hospitalización']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin orden', 'titulo' => 'Esta consulta no tiene orden de hospitalización'];
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en la orden de hospitalización. Si sales ahora, se pierden.';
    }

    public function avisoEliminar(): string
    {
        return '¿Eliminar la orden de hospitalización de esta consulta? Se borran el lugar, el diagnóstico y las recomendaciones.';
    }

    public function ayuda(): string
    {
        return 'Una orden por consulta: dónde se hospitaliza, con qué diagnóstico provisional y qué recomendaciones. '
            . 'Se guarda con el botón y se imprime en media hoja, con el membrete de tu consultorio.';
    }
}
