<?php

namespace App\Clinica\Impresion\Vistas;

use App\Actions\Configuracion\GuardarFormatoDelRecipe;
use App\Actions\Configuracion\LimitesDelMembrete;
use App\Clinica\Archivos\ImagenesDelMembrete;
use App\Clinica\Impresion\FormatoGuardado;
use App\Clinica\Impresion\Membrete;
use App\Models\RecipeFormato;

/**
 * **La pantalla "Formato de impresión"** (WEB-8.2): cómo salen el membrete, la firma y el sello del médico en el récipe y
 * en los demás documentos. Tiene cuatro secciones, cada una con su botón de guardar: los textos del membrete, el logo, el
 * estilo de cada bloque con el color de la línea, y la firma con el sello. A un lado, la vista previa de la hoja.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe lo guardado (`FormatoGuardado`) y las URLs ya
 * armadas. Los valores de los campos salen de lo que quedó de una validación fallida (`old()`) y, si no hay, de lo guardado:
 * un error nunca borra lo que se escribió.
 */
final class FormatoDeImpresionVista
{
    /** Las cinco fuentes y el nombre de las de Windows a las que equivalen (el legado guarda el nombre de Windows). */
    private const FUENTES = [
        'arimo' => 'Arimo (como Arial)',
        'tinos' => 'Tinos (como Times)',
        'cousine' => 'Cousine (como Courier)',
        'comic_neue' => 'Comic Neue (como Comic Sans)',
        'dejavu_sans' => 'DejaVu Sans (como Verdana)',
    ];

    private const ALINEACIONES = [
        'izquierda' => ['texto' => 'Izquierda', 'icono' => 'bi-text-left'],
        'centro' => ['texto' => 'Centro', 'icono' => 'bi-text-center'],
        'derecha' => ['texto' => 'Derecha', 'icono' => 'bi-text-right'],
    ];

    private const TAMANOS_DEL_LOGO = ['chico' => 'Chico', 'mediano' => 'Mediano', 'grande' => 'Grande'];

    private const COLORES = [
        'negro' => 'Negro', 'blanco' => 'Blanco', 'rojo' => 'Rojo', 'marron' => 'Marrón', 'fucsia' => 'Fucsia',
        'verde' => 'Verde', 'gris' => 'Gris', 'amarillo' => 'Amarillo', 'azul' => 'Azul',
    ];

    /** Los textos de `evolucion` que se editan acá, con su etiqueta y su ayuda. */
    private const CAMPOS_DEL_MEMBRETE = [
        'especialidad' => ['etiqueta' => 'Especialidad', 'ayuda' => 'Sale debajo de tu nombre. Ej.: Ginecología y Obstetricia.', 'columnas' => 'col-md-8'],
        'rif' => ['etiqueta' => 'RIF', 'ayuda' => 'Sale debajo de la especialidad.', 'columnas' => 'col-md-4'],
        'linea_1' => ['etiqueta' => 'Pie, línea 1: dirección', 'ayuda' => null, 'columnas' => 'col-12'],
        'linea_2' => ['etiqueta' => 'Pie, línea 2: teléfonos', 'ayuda' => null, 'columnas' => 'col-md-6'],
        'linea_3' => ['etiqueta' => 'Pie, línea 3: correo', 'ayuda' => null, 'columnas' => 'col-md-6'],
    ];

    private const BLOQUES_DE_TEXTO = [
        'medico' => ['titulo' => 'Nombre del médico', 'ayuda' => 'El nombre sale de tu cuenta; aquí sólo eliges cómo se ve.'],
        'especialidad' => ['titulo' => 'Especialidad', 'ayuda' => 'El texto se escribe arriba, en «Datos del membrete».'],
        'rif' => ['titulo' => 'RIF', 'ayuda' => 'El texto se escribe arriba, en «Datos del membrete».'],
        'pie' => ['titulo' => 'Pie de página', 'ayuda' => 'Las tres líneas del pie comparten este estilo.'],
    ];

    /**
     * @param  array{vistaPrevia:string, vistaPreviaCompleta:string, membrete:string, logo:string, estilo:string, firma:string, restablecer:string}  $urls
     */
    public function __construct(
        private FormatoGuardado $guardado,
        private string $nombreDelMedico,
        private ?string $registroDelContexto,
        private array $urls,
    ) {
    }

    // -------------------------------------------------------------------------------------------- Encabezado

    public function titulo(): string
    {
        return 'Formato de impresión';
    }

    public function subtitulo(): string
    {
        return 'Cómo salen tu membrete, tu firma y tu sello en el récipe y en los demás documentos. Lo comparten tú y tu asistente.';
    }

    public function nombreDelMedico(): string
    {
        return $this->nombreDelMedico;
    }

    /** ¿El médico no tiene un registro principal? Sin él no hay dónde guardar el formato. */
    public function sinRegistro(): bool
    {
        return $this->guardado->registro === '';
    }

    /**
     * El aviso de que el formato es del médico y no del consultorio: si el consultorio elegido es de otro de sus registros,
     * se dice bajo cuál se guarda. `null` si es el mismo (no hay nada que aclarar).
     */
    public function avisoDeRegistro(): ?string
    {
        if ($this->sinRegistro()) {
            return null;
        }

        if ($this->registroDelContexto === null || $this->registroDelContexto === $this->guardado->registro) {
            return null;
        }

        return 'El formato es del médico y no de cada consultorio: se guarda bajo su registro principal ('
            . $this->guardado->registro . ') y vale para todos sus consultorios, también para el que tiene el registro '
            . $this->registroDelContexto . '.';
    }

    public function urlVistaPrevia(): string
    {
        return $this->urls['vistaPrevia'];
    }

    public function urlVistaPreviaCompleta(): string
    {
        return $this->urls['vistaPreviaCompleta'];
    }

    public function urlGuardarMembrete(): string
    {
        return $this->urls['membrete'];
    }

    public function urlGuardarLogo(): string
    {
        return $this->urls['logo'];
    }

    public function urlGuardarEstilo(): string
    {
        return $this->urls['estilo'];
    }

    public function urlGuardarFirma(): string
    {
        return $this->urls['firma'];
    }

    public function urlRestablecer(): string
    {
        return $this->urls['restablecer'];
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en esta sección. La vista previa muestra lo que está guardado.';
    }

    // ------------------------------------------------------------------------------ Datos del membrete

    /**
     * Los textos de `evolucion` que la hoja imprime, con el valor que se muestra.
     *
     * @return array<int,array{campo:string, etiqueta:string, ayuda:?string, columnas:string, maximo:int, valor:string}>
     */
    public function camposDelMembrete(): array
    {
        $campos = [];

        foreach (self::CAMPOS_DEL_MEMBRETE as $campo => $datos) {
            $campos[] = [
                'campo' => $campo,
                'etiqueta' => $datos['etiqueta'],
                'ayuda' => $datos['ayuda'],
                'columnas' => $datos['columnas'],
                'maximo' => (int) LimitesDelMembrete::de($campo),
                'valor' => (string) old($campo, $this->guardado->texto($campo)),
            ];
        }

        return $campos;
    }

    // ---------------------------------------------------------------------------------------------- Logo

    public function urlDelLogo(): ?string
    {
        return $this->guardado->membrete->logoUrl;
    }

    public function tieneLogo(): bool
    {
        return $this->urlDelLogo() !== null;
    }

    /** ¿Hay un valor en `evolucion.logo` aunque el archivo ya no esté en el disco? Para ofrecer quitarlo igual. */
    public function logoGuardado(): bool
    {
        return $this->guardado->texto('logo') !== null;
    }

    public function ayudaDeLasImagenes(): string
    {
        return ImagenesDelMembrete::textoAceptado() . '.';
    }

    /** @return array<int,array{valor:string, texto:string, marcada:bool}> */
    public function tamanosDelLogo(): array
    {
        $actual = $this->valorDe('logo', 'tamano');

        return array_map(
            fn (string $valor, string $texto) => ['valor' => $valor, 'texto' => $texto, 'marcada' => $actual === $valor],
            array_keys(self::TAMANOS_DEL_LOGO),
            self::TAMANOS_DEL_LOGO,
        );
    }

    /** @return array<string,mixed> el bloque del logo: alineación, borde y visibilidad con su valor */
    public function logo(): array
    {
        return [
            'alineacion' => $this->valorDe('logo', 'alineacion'),
            'borde' => (bool) $this->valorDe('logo', 'borde'),
            'visible' => (bool) $this->valorDe('logo', 'visible'),
        ];
    }

    // -------------------------------------------------------------------------------- Estilo de cada bloque

    /**
     * Los cuatro bloques de texto del membrete con su estilo.
     *
     * @return array<int,array{elemento:string, titulo:string, ayuda:string, fuente:string, alineacion:string, negrita:bool, italica:bool, subrayado:bool, borde:bool, visible:bool}>
     */
    public function bloquesDeTexto(): array
    {
        $bloques = [];

        foreach (self::BLOQUES_DE_TEXTO as $elemento => $datos) {
            $bloques[] = [
                'elemento' => $elemento,
                'titulo' => $datos['titulo'],
                'ayuda' => $datos['ayuda'],
                'fuente' => (string) $this->valorDe($elemento, 'fuente'),
                'alineacion' => (string) $this->valorDe($elemento, 'alineacion'),
                'negrita' => (bool) $this->valorDe($elemento, 'negrita'),
                'italica' => (bool) $this->valorDe($elemento, 'italica'),
                'subrayado' => (bool) $this->valorDe($elemento, 'subrayado'),
                'borde' => (bool) $this->valorDe($elemento, 'borde'),
                'visible' => (bool) $this->valorDe($elemento, 'visible'),
            ];
        }

        return $bloques;
    }

    /** @return array<string,string> `id => texto` de las cinco fuentes, con la de Windows a la que equivalen */
    public function fuentes(): array
    {
        return self::FUENTES;
    }

    /** @return array<string,array{texto:string, icono:string}> */
    public function alineaciones(): array
    {
        return self::ALINEACIONES;
    }

    /** @return array<int,array{valor:string, texto:string, marcado:bool}> */
    public function colores(): array
    {
        $actual = (string) old('color_linea', $this->guardado->membrete->colorLinea);

        return array_map(
            fn (string $valor, string $texto) => ['valor' => $valor, 'texto' => $texto, 'marcado' => $actual === $valor],
            array_keys(self::COLORES),
            self::COLORES,
        );
    }

    // ----------------------------------------------------------------------------------------- Firma y sello

    /**
     * La firma y el sello, cada uno con su imagen actual y los textos de sus controles.
     *
     * @return array<int,array{campo:string, url:?string, tiene:bool, alt:string, etiqueta:string, quitar:string}>
     */
    public function firmaYSello(): array
    {
        $firma = $this->guardado->membrete->firmaUrl;
        $sello = $this->guardado->membrete->selloUrl;

        return [
            [
                'campo' => 'firma', 'url' => $firma, 'tiene' => $firma !== null, 'alt' => 'Firma actual del médico',
                'etiqueta' => $firma !== null ? 'Reemplazar la firma' : 'Subir la firma', 'quitar' => 'Quitar la firma actual',
            ],
            [
                'campo' => 'sello', 'url' => $sello, 'tiene' => $sello !== null, 'alt' => 'Sello actual del médico',
                'etiqueta' => $sello !== null ? 'Reemplazar el sello' : 'Subir el sello', 'quitar' => 'Quitar el sello actual',
            ],
        ];
    }

    /** Dónde va el sello: lo que quedó de una validación fallida y, si no hay, lo guardado (por defecto, al centro). */
    public function posicionDelSello(): string
    {
        return (string) old('sello_posicion', $this->guardado->membrete->selloPosicion);
    }

    // -------------------------------------------------------------------------- Estado y "restablecer"

    /** @return array{tono:string, icono:string, texto:string} */
    public function estadoDelLogo(): array
    {
        return $this->estado($this->logoEsDelLegado());
    }

    /** @return array{tono:string, icono:string, texto:string} */
    public function estadoDeLosTextos(): array
    {
        return $this->estado($this->textosSonDelLegado());
    }

    /** @return array{tono:string, icono:string, texto:string} */
    public function estadoDelSello(): array
    {
        return $this->estado($this->selloEsDelLegado());
    }

    public function logoEsDelLegado(): bool
    {
        return $this->guardado->membrete->elementos['logo'] == RecipeFormato::elementosPorDefecto()['logo'];
    }

    public function textosSonDelLegado(): bool
    {
        $defecto = RecipeFormato::elementosPorDefecto();

        foreach (RecipeFormato::ELEMENTOS_TEXTO as $elemento) {
            if ($this->guardado->membrete->elementos[$elemento] != $defecto[$elemento]) {
                return false;
            }
        }

        return $this->guardado->membrete->colorLinea === 'negro';
    }

    public function selloEsDelLegado(): bool
    {
        return $this->guardado->membrete->selloPosicion === 'centro';
    }

    public function seccionLogo(): string
    {
        return GuardarFormatoDelRecipe::SECCION_LOGO;
    }

    public function seccionTextos(): string
    {
        return GuardarFormatoDelRecipe::SECCION_TEXTOS;
    }

    public function seccionSello(): string
    {
        return GuardarFormatoDelRecipe::SECCION_SELLO;
    }

    // ----------------------------------------------------------------------------------------------- Adentro

    /** @return array{tono:string, icono:string, texto:string} */
    private function estado(bool $delLegado): array
    {
        return $delLegado
            ? ['tono' => 'neutro', 'icono' => 'bi-check2', 'texto' => 'Valores del legado']
            : ['tono' => 'info', 'icono' => 'bi-sliders', 'texto' => 'Personalizado'];
    }

    /**
     * El valor de una opción de un bloque: lo que quedó de una validación fallida y, si no hay, lo guardado (con los
     * valores del legado donde falta algo). Los booleanos de `old()` llegan como `"0"`/`"1"`.
     */
    private function valorDe(string $elemento, string $opcion): mixed
    {
        $guardado = $this->guardado->membrete->elementos[$elemento][$opcion] ?? null;
        $valor = old("elementos.{$elemento}.{$opcion}", $guardado);

        return in_array($opcion, ['negrita', 'italica', 'subrayado', 'borde', 'visible'], true)
            ? filter_var($valor, FILTER_VALIDATE_BOOLEAN)
            : $valor;
    }

    public function membrete(): Membrete
    {
        return $this->guardado->membrete;
    }
}
