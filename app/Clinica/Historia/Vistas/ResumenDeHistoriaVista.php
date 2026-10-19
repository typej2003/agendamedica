<?php

namespace App\Clinica\Historia\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * El **"Historia clínica"** que se imprime (`/clinica/pacientes/{id}/historia/imprimir`, WEB-3.5). Es el
 * `d_historia_clinica_resumen` del legado: el encabezado con el paciente, la cédula y la fecha, y **todas**
 * las consultas de la historia con sus secciones.
 *
 * El nombre obedece a la regla de vocabulario de Alexander (2026-10-17): **"la historia" es el archivo
 * impreso** y lo que se ve en pantalla es el **resumen**. Por eso la hoja se titula "Historia clínica" y la
 * pantalla "Resumen del paciente"; la clase conserva su nombre viejo para no renombrar medio módulo.
 *
 * Qué **no** es: la hoja "HISTORIA CLINICA" de **una** consulta (`d_historia_clinica` /
 * `d_historia_clinica_ver`, con el examen físico completo, la evaluación por sistemas y el membrete
 * configurable). Esa hoja pertenece a la pantalla de la consulta (F4) y al motor de impresión de
 * documentos (WEB-5.5), y no se duplica acá.
 *
 * El resumen no recorta los textos: una impresión es el documento, no un listado. El membrete lo imprime
 * la vista con el nombre del médico y la fecha del resumen (`FechaDelConsultorio::hoy()`, la fecha del
 * consultorio y no la del servidor).
 */
final class ResumenDeHistoriaVista
{
    /**
     * @param  Collection<int, object>  $consultas  lo que devolvió `HistoriaDelPaciente::consultas()`
     * @param  bool  $pediatria  en pediatría tres columnas del examen físico significan otra cosa (ver `examenFisico()`)
     */
    public function __construct(
        private object $ficha,
        private string $medico,
        private Collection $consultas,
        private CarbonInterface $fecha,
        private bool $pediatria = false,
    ) {
    }

    public function titulo(): string
    {
        return 'Historia clínica';
    }

    public function medico(): string
    {
        return $this->medico;
    }

    public function paciente(): string
    {
        return CamposDePaciente::nombre($this->ficha->apellidos, $this->ficha->nombres);
    }

    public function cedula(): string
    {
        return CamposDePaciente::cedula($this->ficha->cedula) ?? 'Sin cédula';
    }

    public function historia(): string
    {
        $numero = CamposDePaciente::numeroDeHistoria($this->ficha->numhistoria);

        return $numero === null ? 'Sin número de historia' : 'H. ' . $numero;
    }

    public function fecha(): string
    {
        return FechaClinica::formato($this->fecha->toDateString());
    }

    public function tieneConsultas(): bool
    {
        return $this->consultas->isNotEmpty();
    }

    /**
     * Cada consulta con sus secciones, en el orden del panel del legado (motivo, enfermedad actual,
     * diagnóstico, evolución, examen físico). Las secciones sin contenido **no se imprimen**: el legado
     * crea las consultas con la mayoría de los campos vacíos y una hoja llena de "—" no se lee.
     *
     * @return array<int, array{fecha:string, numero:string, secciones:array<int, array{titulo:string, texto:string}>}>
     */
    public function consultas(): array
    {
        return $this->consultas->map(function ($consulta) {
            $secciones = [];

            $motivos = array_values(array_filter((array) ($consulta->motivos ?? [])));
            $diagnosticos = array_values(array_filter((array) ($consulta->diagnosticos ?? [])));

            $this->agregar($secciones, 'Motivo de consulta', implode(' · ', $motivos) ?: null);
            $this->agregar($secciones, 'Enfermedad actual', $consulta->enfermedadactual ?? null);
            $this->agregar($secciones, 'Diagnóstico', implode(' · ', $diagnosticos) ?: null);
            $this->agregar($secciones, 'Evolución', $consulta->evolucion ?? null);
            $this->agregar($secciones, 'Examen físico', $this->examenFisico($consulta));

            return [
                'fecha'     => FechaClinica::formato($consulta->fecha),
                'numero'    => 'Consulta ' . (int) $consulta->nroconsulta,
                'secciones' => $secciones,
            ];
        })->all();
    }

    /**
     * El examen físico de la consulta: el resultado comparativo si lo hay y, después, las seis secciones
     * que imprime el resumen del legado (`d_historia_clinica_resumen.srd`, band=detail: faringe, nariz,
     * oído, laringe, cuello, otros).
     *
     * ⚠️ **En pediatría tres de esas columnas son otra cosa** (2026-10-19): el escritorio de pediatría guarda en
     * `nariz`, `laringe` y `oido` los textos de su ítem *Exámenes/Estudios* —*Exámenes*, *Imagenología* y *Estudios
     * exploratorios*—, así que la hoja los rotula así. Son las mismas columnas: lo que cambia es el nombre.
     */
    private function examenFisico(object $consulta): ?string
    {
        $partes = [];

        $etiquetas = $this->pediatria
            ? [
                'resultadoexamencomp' => 'Resultado del examen',
                'faringe'             => 'Faringe',
                'nariz'               => 'Exámenes',
                'oido'                => 'Estudios exploratorios',
                'laringe'             => 'Imagenología',
                'cuello'              => 'Cuello',
                'otros'               => 'Otros',
            ]
            : [
                'resultadoexamencomp' => 'Resultado del examen',
                'faringe'             => 'Faringe',
                'nariz'               => 'Nariz',
                'oido'                => 'Oído',
                'laringe'             => 'Laringe',
                'cuello'              => 'Cuello',
                'otros'               => 'Otros',
            ];

        foreach ($etiquetas as $columna => $etiqueta) {
            $texto = CamposDePaciente::texto($consulta->{$columna} ?? null);

            if ($texto !== null) {
                $partes[] = $etiqueta . ': ' . $texto;
            }
        }

        return $partes === [] ? null : implode('. ', $partes);
    }

    /** @param  array<int, array{titulo:string, texto:string}>  $secciones */
    private function agregar(array &$secciones, string $titulo, ?string $texto): void
    {
        $limpio = CamposDePaciente::texto(preg_replace('/\s+/u', ' ', (string) $texto));

        if ($limpio !== null) {
            $secciones[] = ['titulo' => $titulo, 'texto' => $limpio];
        }
    }
}
