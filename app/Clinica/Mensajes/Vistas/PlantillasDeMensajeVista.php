<?php

namespace App\Clinica\Mensajes\Vistas;

use App\Models\Evolucion;
use App\Notificaciones\AnalisisDeUnaPlantilla;
use App\Notificaciones\LargoDeSms;
use App\Notificaciones\PlantillaDeMensaje;
use App\Notificaciones\PlantillasDeMensaje;

/**
 * **La pantalla «Mensajes a pacientes»** (WEB-8.4, `/clinica/mensajes`): los dos editores de las plantillas del médico, la de
 * **citas** (el recordatorio) y la de **cumpleaños** (el saludo), cada uno con sus botones de etiquetas, su contador de caracteres y
 * de SMS, sus avisos y una vista previa con datos de ejemplo.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe la fila de `evolucion` ya leída y las URLs ya armadas. El
 * texto de un editor sale de lo que quedó de una validación fallida (`old()`, sólo en el editor que falló) y, si no hay, de lo
 * guardado: un error nunca borra lo que se escribió. El análisis (vista previa, largo y avisos) lo calcula `AnalisisDeUnaPlantilla` —
 * el mismo que responde la vista previa en vivo—, así que la pantalla al cargar y al escribir dice lo mismo.
 */
final class PlantillasDeMensajeVista
{
    /**
     * @param  array<string,string>  $ejemplo  `doctor`, `centro` y `fecha` (ya formateada) del ejemplo
     * @param  array<string,array{guardar:string, restablecer:string, vistaPrevia:string}>  $urls  por plantilla (`cita`, `cumple`)
     */
    public function __construct(
        private string $registro,
        private ?Evolucion $evolucion,
        private ?string $registroDelContexto,
        private array $ejemplo,
        private array $urls,
        private string $urlCumpleanos,
    ) {
    }

    // -------------------------------------------------------------------------------------------- Encabezado

    public function titulo(): string
    {
        return 'Mensajes a pacientes';
    }

    public function subtitulo(): string
    {
        return 'Los textos que salen solos al recordar una cita y al saludar un cumpleaños. Cada mensaje se arma con tus palabras y los datos del paciente.';
    }

    public function sinRegistro(): bool
    {
        return $this->registro === '';
    }

    /**
     * El aviso de que las plantillas son del médico y no del consultorio: si el consultorio elegido es de otro de sus registros,
     * se dice bajo cuál se guardan (como el formato de impresión, WEB-8.2). `null` si es el mismo.
     */
    public function avisoDeRegistro(): ?string
    {
        if ($this->sinRegistro() || $this->registroDelContexto === null || $this->registroDelContexto === $this->registro) {
            return null;
        }

        return 'Las plantillas son del médico y no de cada consultorio: se guardan bajo su registro principal ('
            . $this->registro . ') y valen para todos sus consultorios, también para el que tiene el registro '
            . $this->registroDelContexto . '.';
    }

    public function urlCumpleanos(): string
    {
        return $this->urlCumpleanos;
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar. La vista previa muestra lo que escribiste; el envío usa lo último que guardaste.';
    }

    // ------------------------------------------------------------------------------------------- Los editores

    /**
     * Un editor por plantilla, listo para pintar.
     *
     * @return array<int,array<string,mixed>>
     */
    public function editores(): array
    {
        return array_map(fn (PlantillaDeMensaje $plantilla) => $this->editor($plantilla), PlantillasDeMensaje::todas());
    }

    /** @return array<string,mixed> */
    private function editor(PlantillaDeMensaje $plantilla): array
    {
        $guardado = $this->guardado($plantilla);
        $texto = old('_plantilla') === $plantilla->slug ? (string) old('texto', $guardado) : $guardado;
        $analisis = AnalisisDeUnaPlantilla::de($plantilla, $texto, $plantilla->datosDeEjemplo($this->ejemplo));
        $personalizada = trim($guardado) !== '';

        return [
            'slug' => $plantilla->slug,
            'ancla' => 'plantilla-' . $plantilla->slug,
            'titulo' => $plantilla->titulo,
            'paraQueSirve' => $plantilla->paraQueSirve,
            'texto' => $texto,
            'porDefecto' => $plantilla->porDefecto,
            'maximo' => $plantilla->maximo(),
            'personalizada' => $personalizada,
            'estado' => $personalizada
                ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Con tu texto']
                : ['tono' => 'neutro', 'icono' => 'bi-chat-left-text', 'texto' => 'Usa el mensaje por defecto'],
            'etiquetas' => array_map(
                fn (string $etiqueta, array $datos) => ['etiqueta' => $etiqueta, 'texto' => $datos['texto'], 'ayuda' => $datos['ayuda']],
                array_keys($plantilla->etiquetas),
                array_values($plantilla->etiquetas),
            ),
            'analisis' => $analisis,
            'sms' => $this->frase($analisis['sms']),
            'urlGuardar' => $this->urls[$plantilla->slug]['guardar'],
            'urlRestablecer' => $this->urls[$plantilla->slug]['restablecer'],
            'urlVistaPrevia' => $this->urls[$plantilla->slug]['vistaPrevia'],
            'avisoRestablecer' => '¿Restablecer «' . $plantilla->titulo . '»? Se borra tu texto y vuelve a salir el mensaje por defecto.',
        ];
    }

    /** El texto guardado de una plantilla (vacío si el médico nunca la escribió). */
    private function guardado(PlantillaDeMensaje $plantilla): string
    {
        return (string) $this->evolucion?->getAttribute($plantilla->columna);
    }

    /**
     * Cómo se lee el largo en SMS: «1 SMS · 87 de 160» o «3 SMS · 188 de 153 por mensaje (Unicode: 67)».
     *
     * @param  array{codificacion:string, unidades:int, segmentos:int, limite:int, limite_uno:int}  $sms
     */
    private function frase(array $sms): string
    {
        return self::fraseDeSms($sms);
    }

    /** La misma frase que arma el JS con la respuesta del servidor; vive acá para que la pantalla cargada y la viva digan igual. */
    public static function fraseDeSms(array $sms): string
    {
        if ($sms['segmentos'] === 0) {
            return 'Sin texto.';
        }

        $alfabeto = $sms['codificacion'] === 'gsm7' ? 'sin tildes ni símbolos' : 'con tildes o símbolos: cuenta menos';
        $cuenta = $sms['segmentos'] === 1
            ? '1 SMS (' . $sms['unidades'] . ' de ' . $sms['limite'] . ')'
            : $sms['segmentos'] . ' SMS (' . $sms['unidades'] . ' en trozos de ' . $sms['limite'] . ')';

        return $cuenta . ', ' . $alfabeto . '.';
    }

    /** Lo que sí no tiene este límite, para que el médico lo sepa sin buscarlo. */
    public function notaDeCanales(): string
    {
        return 'Por SMS un mensaje largo se parte en varios y cada uno se cobra: uno entra hasta en ' . LargoDeSms::GSM_UNO
            . ' caracteres, o en ' . LargoDeSms::UNICODE_UNO . ' si lleva tildes o emojis. WhatsApp y el correo no tienen ese límite.';
    }

    public function notaDeWhatsapp(): string
    {
        return 'Por WhatsApp sale la plantilla aprobada por Meta (sólo con el nombre del paciente): el texto de acá se registra pero no es lo que se transmite.';
    }
}
