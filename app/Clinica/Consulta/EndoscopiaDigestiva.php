<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\EndoscopiaInferior;
use App\Models\EndoscopiaSuperior;
use Illuminate\Database\Eloquent\Model;

/**
 * **Endoscopías digestivas** de la consulta pediátrica (gastroenterología, 2026-10-19): el ítem
 * **«Procedimientos»** del árbol de PediaReport (`tabpage_34`), que abre las dos ventanas
 * `w_cargar_visualizar_endo_superior.srw` y `w_cargar_visualizar_endo_inferior.srw`.
 *
 * Cada una es **una fila por consulta** y su tabla es la del DataWindow:
 *
 * | Tipo | Tabla | Clave |
 * |---|---|---|
 * | `superior` | `endosc_diges_super` | `historia` + `consulta` |
 * | `inferior` | `endosc_diges_infer` | `historia` + `consulta` |
 *
 * `full_texto` es **lo que imprime** el escritorio: el botón de plantillas compone ahí el informe normal
 * (`w_cargar_visualizar_endo_superior.srw:340-366`) y el resto de los campos son los hallazgos por órgano. Las
 * columnas de las imágenes (`ima_primera` … `obs_ima_tercera`) guardan rutas de archivos de la PC del consultorio:
 * la web **no las toca** (las imágenes del paciente viven en `imagen_consulta`, ver `TagDeImagen`).
 *
 * ⚠️ La tabla del **inferior** repite esófago/estómago/duodeno: es el mismo juego de columnas que la superior (así
 * está en el esquema del legado). Se respeta tal cual.
 */
final class EndoscopiaDigestiva
{
    public const SUPERIOR = 'superior';
    public const INFERIOR = 'inferior';

    /** Los dos tipos, con su tabla y su título. */
    private const TIPOS = [
        self::SUPERIOR => ['tabla' => 'endosc_diges_super', 'titulo' => 'Endoscopía digestiva superior'],
        self::INFERIOR => ['tabla' => 'endosc_diges_infer', 'titulo' => 'Endoscopía digestiva inferior'],
    ];

    /**
     * Los campos de cada tipo, en el orden del escritorio: `clave => [etiqueta, columna, clase]`, con
     * `clase` en `texto`, `parrafo`, `fecha` u `opcion` (un estado de un carácter).
     *
     * @var array<string,array<string,array{etiqueta:string, columna:string, clase:string, ayuda?:string}>>
     */
    private const CAMPOS = [
        self::SUPERIOR => [
            'fecha'        => ['etiqueta' => 'Fecha', 'columna' => 'fecha', 'clase' => 'fecha'],
            'equipo'       => ['etiqueta' => 'Equipo', 'columna' => 'equipo', 'clase' => 'texto'],
            'indicacion'   => ['etiqueta' => 'Indicación', 'columna' => 'indicacion', 'clase' => 'texto'],
            'sedacion'     => ['etiqueta' => 'Sedación', 'columna' => 'sedacion', 'clase' => 'texto'],
            'descripcion'  => ['etiqueta' => 'Hallazgos', 'columna' => 'descripcion', 'clase' => 'parrafo'],
            'conclusiones' => ['etiqueta' => 'Conclusiones', 'columna' => 'conclusiones', 'clase' => 'parrafo'],
            'full_texto'   => ['etiqueta' => 'Texto para imprimir', 'columna' => 'full_texto', 'clase' => 'parrafo',
                'ayuda' => 'Es lo que imprime el informe. El escritorio lo arma con su plantilla de estudio normal y después se puede corregir.'],
        ],
        self::INFERIOR => [
            'fecha'        => ['etiqueta' => 'Fecha', 'columna' => 'fecha', 'clase' => 'fecha'],
            'equipo'       => ['etiqueta' => 'Equipo', 'columna' => 'equipo', 'clase' => 'texto'],
            'indicacion'   => ['etiqueta' => 'Indicación', 'columna' => 'indicacion', 'clase' => 'texto'],
            'sedacion'     => ['etiqueta' => 'Sedación', 'columna' => 'sedacion', 'clase' => 'texto'],
            'esofago_estado'      => ['etiqueta' => 'Esófago (estado)', 'columna' => 'esofago_estado', 'clase' => 'opcion',
                'ayuda' => 'Un carácter, como en el escritorio («N» = normal).'],
            'esofago_descripcion' => ['etiqueta' => 'Esófago', 'columna' => 'esofago_descripcion', 'clase' => 'parrafo'],
            'estomago_estado'      => ['etiqueta' => 'Estómago (estado)', 'columna' => 'estomago_estado', 'clase' => 'opcion'],
            'estomago_descripcion' => ['etiqueta' => 'Estómago', 'columna' => 'estomago_descripcion', 'clase' => 'parrafo'],
            'duodeno_estado'      => ['etiqueta' => 'Duodeno (estado)', 'columna' => 'duodeno_estado', 'clase' => 'opcion'],
            'duodeno_descripcion' => ['etiqueta' => 'Duodeno', 'columna' => 'duodeno_descripcion', 'clase' => 'parrafo'],
            'conclusiones' => ['etiqueta' => 'Conclusiones', 'columna' => 'conclusiones', 'clase' => 'parrafo'],
            'full_texto'   => ['etiqueta' => 'Texto para imprimir', 'columna' => 'full_texto', 'clase' => 'parrafo',
                'ayuda' => 'Es lo que imprime el informe.'],
        ],
    ];

    /** @return array<int,string> */
    public static function tipos(): array
    {
        return array_keys(self::TIPOS);
    }

    public static function existe(string $tipo): bool
    {
        return isset(self::TIPOS[$tipo]);
    }

    public static function titulo(string $tipo): string
    {
        return self::TIPOS[$tipo]['titulo'] ?? '';
    }

    public static function tabla(string $tipo): string
    {
        return self::TIPOS[$tipo]['tabla'] ?? '';
    }

    /** @return array<string,array{etiqueta:string, columna:string, clase:string, ayuda?:string}> */
    public static function campos(string $tipo): array
    {
        return self::CAMPOS[$tipo] ?? [];
    }

    /** Las columnas de la tabla que escribe la web (las de las imágenes no se tocan). @return array<int,string> */
    public static function columnas(string $tipo): array
    {
        return array_values(array_unique(array_column(self::campos($tipo), 'columna')));
    }

    /** El modelo de esa tabla, con la clave del DataWindow. */
    public function modelo(string $tipo): string
    {
        return $tipo === self::INFERIOR ? EndoscopiaInferior::class : EndoscopiaSuperior::class;
    }

    /**
     * Los valores guardados, por clave (la fecha en `Y-m-d`). Los campos vacíos vuelven como `null`.
     *
     * @return array<string,?string>
     */
    public function de(Consulta $consulta, string $tipo): array
    {
        $fila = $this->fila($consulta, $tipo);
        $valores = [];

        foreach (self::campos($tipo) as $clave => $campo) {
            if ($campo['clase'] === 'fecha') {
                $valores[$clave] = $fila?->fecha?->format('Y-m-d');

                continue;
            }

            $valor = $fila?->{$campo['columna']};
            $valores[$clave] = $valor === null ? null : (string) $valor;
        }

        return $valores;
    }

    public function fila(Consulta $consulta, string $tipo): ?Model
    {
        /** @var class-string<Model> $clase */
        $clase = $this->modelo($tipo);

        return $clase::where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->first();
    }

    public function filaParaEscribir(Consulta $consulta, string $tipo): Model
    {
        return $this->fila($consulta, $tipo) ?? $this->modelo($tipo)::create([
            'reg_medico' => $consulta->reg_medico,
            'historia'   => (int) $consulta->numhistoria,
            'consulta'   => (int) $consulta->nroconsulta,
        ]);
    }

    /** ¿La consulta tiene cargada esa endoscopía? (algún campo con datos) */
    public function tieneDatos(Consulta $consulta, string $tipo): bool
    {
        return array_filter($this->de($consulta, $tipo), fn ($valor) => $valor !== null && $valor !== '') !== [];
    }
}
