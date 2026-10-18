<?php

namespace App\Clinica\Consulta;

use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;

/**
 * **Las plantillas de texto de los estudios ecográficos** (revisión de Alexander, 2026-10-17): qué tipo de `formato_print`
 * usa cada estudio del ítem 5, en qué **campo de su formulario** se copia la plantilla y con qué nombre se ofrece.
 *
 * Hasta acá las plantillas de las ecografías se podían crear en el catálogo pero **no se ofrecían en ninguna pantalla**: el
 * pedido de una médica que probó la web fue justamente «plantillas listas para ecografías o informes médicos».
 *
 * ## El tipo de cada estudio, leído del escritorio
 *
 * Cada ventana del escritorio tiene su panel de plantillas (`dw_76`, el DataWindow `d_l_enfe_actu_para` sobre
 * `formato_print`), con el tipo fijo en su `retrieve`:
 *
 * | Estudio | Ventana | Tipo |
 * |---|---|---|
 * | Eco ginecológico | `w_cargar_visualizar_ecografia.srw:232` | `UG` |
 * | Eco obstétrico del 1.er trimestre | `w_cargar_visualizar_ecografia_obstetri_1.srw:228` | `O1` |
 * | Eco obstétrico del 2.º trimestre | `w_cargar_visualizar_ecografia_obtetri_2.srw:242` | `O2` |
 * | Eco obstétrico del 3.er trimestre | `w_cargar_visualizar_ecografia_obtetri_3.srw:240` | `O3` |
 * | Eco Doppler perinatal | `w_cargar_visualizar_eco_doppler.srw:228` | `ED` |
 * | Eco mamario | `w_cargar_visualizar_eco_mamario.srw:222` | `UM` |
 * | Eco abdominal | `w_cargar_visualizar_eco_abdominal.srw:221` | `UA` |
 * | Ecocardiograma fetal | `w_cargar_visualizar_ecocardiograma_fetal.srw` | **ninguno** |
 *
 * Los códigos son los del ABM del escritorio (`d_a_formatos.srd:9`): `UG`, `O1`, `O2`, `O3`, `ED`, `UA`, `UM`, `EM`, `ER`. El
 * **ecocardiograma fetal no tiene plantillas en el escritorio** —su ventana no tiene el panel y el ABM no declara un tipo para
 * él—, así que no se le ofrece el botón: inventar un código nuevo lo haría aparecer como «Tipo XX» en el ABM del escritorio,
 * que comparte la tabla. El eco de cuello (`EM`) y el eco renal (`ER`) sí tienen tipo, pero en la web no hay pantalla que los
 * ofrezca (no son del ítem 5 de ginecología).
 *
 * ## A qué campo se copia
 *
 * La plantilla es **texto** y se copia al campo libre del estudio, como en las otras secciones (decisión del usuario,
 * 2026-10-17): no rellena el formulario. La columna es la del **informe/conclusión** de cada estudio, que es el área libre que
 * la pantalla ya edita:
 *
 *  - el eco ginecológico y los tres obstétricos → `observacion` (en el pélvico el rótulo del escritorio es *Anexos*);
 *  - el Doppler → `texto` (su memo grande, el que el escritorio llena con las plantillas en su modo «Plantillas»);
 *  - el mamario → `conclusion` y el abdominal → `conclusiones`: **esas tablas no tienen columna `texto`** (verificado en el
 *    esquema del legado y en las migraciones);
 *  - el ecocardiograma fetal no entra (no tiene plantillas).
 *
 * ⚠️ El escritorio copia la plantilla a **`puro_texto`** (su modo «Plantillas», `mle_1` en cada ventana). La web muestra ese
 * campo en modo lectura y todavía no lo edita, así que la plantilla va al campo libre que sí se edita; el día que la web edite
 * `puro_texto`, esta tabla es el único lugar que hay que cambiar.
 *
 * Sin estado: lee `formato_print` del médico con `PlantillasDelTexto` (la misma lectura de las otras secciones).
 */
final class PlantillasDelEstudio
{
    /**
     * Los estudios que ofrecen plantillas: `tipo de estudio => [tipos de formato_print, columna destino, nombre]`.
     *
     * El nombre es el del cajón («Plantillas de …»). Un estudio que no está acá no ofrece el botón.
     *
     * @var array<string,array{tipos:array<int,string>, columna:string, nombre:string}>
     */
    private const ESTUDIOS = [
        TiposDeEstudio::GINECOLOGICO => ['tipos' => ['UG'], 'columna' => 'observacion', 'nombre' => 'eco ginecológico'],
        TiposDeEstudio::OBSTETRICO => ['tipos' => ['O1'], 'columna' => 'observacion', 'nombre' => 'eco obstétrico del 1.er trimestre'],
        TiposDeEstudio::SEGUNDO => ['tipos' => ['O2'], 'columna' => 'observacion', 'nombre' => 'eco obstétrico del 2.º trimestre'],
        TiposDeEstudio::TERCERO => ['tipos' => ['O3'], 'columna' => 'observacion', 'nombre' => 'eco obstétrico del 3.er trimestre'],
        TiposDeEstudio::DOPPLER => ['tipos' => ['ED'], 'columna' => 'texto', 'nombre' => 'eco Doppler perinatal'],
        TiposDeEstudio::MAMARIO => ['tipos' => ['UM'], 'columna' => 'conclusion', 'nombre' => 'eco mamario'],
        TiposDeEstudio::ABDOMINAL => ['tipos' => ['UA'], 'columna' => 'conclusiones', 'nombre' => 'eco abdominal'],
        // El ecocardiograma fetal no tiene plantillas en el escritorio: ver el docblock de la clase.
        TiposDeEstudio::FETAL => ['tipos' => [], 'columna' => 'texto_inicio', 'nombre' => 'ecocardiograma fetal'],
    ];

    /**
     * Los tipos de `formato_print` que ofrece un estudio (vacío si no ofrece ninguno, como el ecocardiograma fetal).
     *
     * Lo usa `AlcanceDeLaEspecialidad` para no ofrecer en otra especialidad las plantillas de un estudio que no tiene: el mapa
     * de arriba es el único lugar donde está esa relación, así que un estudio nuevo hereda la regla.
     *
     * @return array<int,string>
     */
    public static function tiposDe(string $tipoDeEstudio): array
    {
        return self::ESTUDIOS[$tipoDeEstudio]['tipos'] ?? [];
    }

    /**
     * Las plantillas de un estudio, ya listas para el cajón, o `null` si ese estudio no ofrece plantillas.
     *
     * `etiquetas` es `código => nombre` con los nombres del catálogo (`TiposDePlantillaDeTexto`): es lo que
     * muestra el desplegable del cajón si algún día un estudio tiene más de un tipo.
     *
     * @return array{tipos:array<int,string>, etiquetas:array<string,string>, columna:string, nombre:string, lista:array<int,array{titulo:string, texto:string}>}|null
     */
    public static function de(string $regMedico, string $tipoDeEstudio): ?array
    {
        $estudio = self::ESTUDIOS[$tipoDeEstudio] ?? null;

        if ($estudio === null || $estudio['tipos'] === []) {
            return null;
        }

        $lista = [];
        $etiquetas = [];

        foreach ($estudio['tipos'] as $tipo) {
            $etiquetas[$tipo] = TiposDePlantillaDeTexto::nombre($tipo);

            foreach (PlantillasDelTexto::de($regMedico, $tipo) as $plantilla) {
                $lista[] = ['titulo' => $plantilla->titulo, 'texto' => $plantilla->texto];
            }
        }

        return [
            'tipos' => $estudio['tipos'],
            'etiquetas' => $etiquetas,
            'columna' => $estudio['columna'],
            'nombre' => $estudio['nombre'],
            'lista' => $lista,
        ];
    }
}
