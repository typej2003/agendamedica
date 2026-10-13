<?php

namespace App\Clinica\Verificacion\Vistas;

use App\Clinica\Verificacion\EstadoDeLaVerificacion;
use App\Clinica\Verificacion\RecetaVerificada;
use App\Clinica\Verificacion\UrlDeVerificacion;
use App\Support\FechaClinica;

/**
 * **La página pública de verificación de un récipe** (WEB-5.6): lo que ve la farmacia que escanea el QR de la hoja.
 *
 * Convierte `RecetaVerificada` en lo que la pantalla pinta: el estado con su color, su icono y su texto (nunca sólo color), la
 * explicación de qué hacer, los datos públicos del récipe y los del médico. **No consulta la base**; se prueba sin ella. Como
 * `RecetaVerificada` no trae nada del paciente ni las indicaciones, esta página tampoco los puede mostrar.
 */
final class VerificacionPublicaVista
{
    public function __construct(
        private RecetaVerificada $receta,
        private string $urlBuscar,
        private ?string $mensajeDelCodigo = null,
        private string $codigoEscrito = '',
    ) {
    }

    public function titulo(): string
    {
        return 'Verificación de récipe';
    }

    public function estado(): string
    {
        return $this->receta->estado;
    }

    /** ¿Hay un récipe detrás (aunque ya no exista)? Con eso la página muestra los datos del médico. */
    public function conRecipe(): bool
    {
        return in_array($this->estado(), [
            EstadoDeLaVerificacion::VIGENTE,
            EstadoDeLaVerificacion::VENCIDO,
            EstadoDeLaVerificacion::ANULADO,
            EstadoDeLaVerificacion::ELIMINADO,
        ], true);
    }

    /** ¿Se muestra el contenido del récipe (fecha y medicamentos)? Sólo mientras es auténtico. */
    public function conContenido(): bool
    {
        return in_array($this->estado(), [EstadoDeLaVerificacion::VIGENTE, EstadoDeLaVerificacion::VENCIDO], true);
    }

    /**
     * El resultado con su color (`tono`), su icono y su texto: nunca sólo color. El `icono` es el nombre de un dibujo de
     * `verificacion/_icono.blade.php` (esta página no carga Bootstrap Icons: es pública, liviana y sin CDN).
     *
     * @return array{tono:string, icono:string, titulo:string, explicacion:string}
     */
    public function resultado(): array
    {
        return match ($this->estado()) {
            EstadoDeLaVerificacion::VIGENTE => [
                'tono' => 'exito',
                'icono' => 'ok',
                'titulo' => 'Récipe auténtico y vigente',
                'explicacion' => 'Fue emitido por el médico que figura abajo desde Doctorisimo.app. Compare los medicamentos y las cantidades con los de la hoja impresa.',
            ],
            EstadoDeLaVerificacion::VENCIDO => [
                'tono' => 'aviso',
                'icono' => 'reloj',
                'titulo' => 'Récipe auténtico pero vencido',
                'explicacion' => 'Fue emitido por el médico que figura abajo desde Doctorisimo.app, pero su vigencia ya terminó'
                    . ($this->receta->vence === null ? '.' : ' el ' . FechaClinica::formato($this->receta->vence) . '.'),
            ],
            EstadoDeLaVerificacion::ANULADO => [
                'tono' => 'peligro',
                'icono' => 'anulado',
                'titulo' => 'Anulado por el médico',
                'explicacion' => 'El médico que lo emitió anuló este código'
                    . ($this->receta->anuladoEn === null ? '' : ' el ' . FechaClinica::formato($this->receta->anuladoEn))
                    . '. No acepte este récipe sin confirmarlo con el consultorio.',
            ],
            EstadoDeLaVerificacion::ELIMINADO => [
                'tono' => 'peligro',
                'icono' => 'eliminado',
                'titulo' => 'Este récipe ya no existe',
                'explicacion' => 'Fue eliminado por el médico que lo emitió. No acepte este récipe sin confirmarlo con el consultorio.',
            ],
            EstadoDeLaVerificacion::EJEMPLO => [
                'tono' => 'info',
                'icono' => 'info',
                'titulo' => 'Código de ejemplo',
                'explicacion' => 'Este código sólo aparece en la hoja de prueba del formato de impresión del médico. No corresponde a ningún récipe.',
            ],
            default => [
                'tono' => 'peligro',
                'icono' => 'pregunta',
                'titulo' => 'No se encontró este récipe',
                'explicacion' => 'El código no corresponde a ningún récipe emitido desde Doctorisimo.app. Puede ser falso o haberse copiado mal: compruébelo con el consultorio.',
            ],
        };
    }

    /** Lo que identifica al récipe: de cuándo es, su vigencia y cuál es. Sólo mientras es auténtico. */
    public function datos(): array
    {
        if (! $this->conContenido()) {
            return [];
        }

        $datos = [['etiqueta' => 'Récipe', 'valor' => $this->receta->esDeLaPareja ? 'Récipe de la pareja' : 'Récipe ' . $this->receta->numero]];

        $datos[] = ['etiqueta' => 'Fecha de emisión', 'valor' => $this->receta->emision === null ? '—' : FechaClinica::formato($this->receta->emision)];
        $datos[] = [
            'etiqueta' => 'Vigencia',
            'valor' => $this->receta->vence === null
                ? $this->receta->vigenciaEnDias . ' días'
                : $this->receta->vigenciaEnDias . ' días · hasta el ' . FechaClinica::formato($this->receta->vence),
        ];

        return $datos;
    }

    /**
     * Los datos del médico que emitió el récipe, o `[]` si no hay récipe detrás o no se encontró al médico.
     *
     * @return array{nombre:string, detalles:array<int,array{etiqueta:string, valor:string}>}|null
     */
    public function medico(): ?array
    {
        $medico = $this->receta->medico;

        if (! $this->conRecipe() || $medico === null) {
            return null;
        }

        $detalles = [];
        foreach ([['Especialidad', $medico['especialidad']], ['RIF', $medico['rif']], ['Registro profesional', $medico['registro']]] as [$etiqueta, $valor]) {
            if ($valor !== null && trim($valor) !== '') {
                $detalles[] = ['etiqueta' => $etiqueta, 'valor' => $valor];
            }
        }

        if ($medico['lugar'] !== []) {
            $detalles[] = ['etiqueta' => 'Consultorio', 'valor' => implode(' · ', $medico['lugar'])];
        }

        return ['nombre' => $medico['nombre'], 'detalles' => $detalles];
    }

    /** @return array<int,array{cantidad:string, nombre:string}> */
    public function medicamentos(): array
    {
        return $this->conContenido() ? $this->receta->medicamentos : [];
    }

    /** Lo que se le recuerda a quien verifica sobre el alcance de esta página. */
    public function nota(): string
    {
        return 'Esta página confirma que el récipe fue emitido desde Doctorisimo.app y muestra su contenido actual. '
            . 'No muestra datos del paciente ni las indicaciones: compárelos con la hoja impresa.';
    }

    // ------------------------------------------------------------------------------------ El código corto

    public function urlBuscar(): string
    {
        return $this->urlBuscar;
    }

    public function direccion(): string
    {
        return UrlDeVerificacion::visible();
    }

    /** El mensaje de un código que no se encontró, si se acaba de tipear uno. */
    public function mensajeDelCodigo(): ?string
    {
        return $this->mensajeDelCodigo;
    }

    public function codigoEscrito(): string
    {
        return $this->codigoEscrito;
    }
}
