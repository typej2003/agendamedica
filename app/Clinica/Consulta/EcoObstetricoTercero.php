<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\EcoObstetricoTercer;

/**
 * **El eco obstétrico del 2.º y 3.er trimestre de una consulta** (WEB-6.2): el hermano mayor del de primer
 * trimestre, con la misma forma y otra tabla.
 *
 * La clave es la misma —**`reg_medico` + `historia` + `consulta`**— y el objeto de valores también
 * (`EstudioEcografico`, genérico). Lo que cambia es la tabla (`eco_obstetrico_tercer`) y, sobre todo,
 * cuántas columnas: 69, en los tres bloques del escritorio (**biometría** `bio_*`, **anatomía** `ana_*` y
 * **función** `fun_*`) más lo del embarazo y la conclusión.
 *
 * ⚠️ **El 2.º y el 3.er trimestre son la MISMA tabla y el MISMO DataWindow de pantalla**
 * (`d_eco_obstetrico_trimestral_nueva`): lo único que cambia es la ventana y el informe que se imprime
 * («SEGUNDO» o «TERCER TRIMESTRE»). O sea que **si el médico carga el 3.º sobre una consulta que ya tenía el
 * 2.º, lo pisa** — es una rareza del legado, se respeta y no se «arregla»—. Por eso acá hay **un solo**
 * concepto y el rótulo de la sección los nombra a los dos.
 *
 * ⚠️ **No hay fetos múltiples.** La columna `gemelo` existe y la ventana tiene dos radios («1» y «2»), pero
 * el `retrieve` de esa ventana pasa **tres** argumentos contra un DataWindow que declara **dos**: en
 * producción eso falla, y los datos lo confirman (`gemelo` NULL en las 2200 filas del dump y **cero**
 * consultas con dos filas). Una fila por consulta, punto.
 *
 * El mapa sale del DataWindow **vivo** (`datawin_procesos/d_eco_obstetrico_trimestral_nueva.srd`, el que la
 * ventana asigna): los rótulos son los suyos y los códigos de cada lista, los del escritorio. Quedan
 * **fuera** las columnas que el DataWindow no edita: sin control (`eg`, `bio_abdomen`, `ana_relacion`,
 * `ana_corte_tracameral`) y ocultas (`puro_texto`, `gemelo`, `ana_ex_muslo_ppd`, `ana_ex_muslo_ppi`).
 */
final class EcoObstetricoTercero
{
    use MapaDelEcoObstetrico;

    /** La tabla del eco obstétrico del 2.º y 3.er trimestre. */
    public const TABLA = 'eco_obstetrico_tercer';

    /** La columna de la historia (parte de la clave). */
    public const COLUMNA_HISTORIA = 'historia';

    /** La columna de la consulta: **es parte de la clave** (`consulta`, `NOT NULL`). */
    public const COLUMNA_CONSULTA = 'consulta';

    /**
     * **El mapa del dato**, en los bloques del escritorio (que tiene rotulados *DATOS BIOMÉTRICOS*,
     * *DATOS ANATÓMICOS*, *PLACENTA* y *DATOS FUNCIONALES*).
     *
     * Las escalas del escritorio van con sus textos y sus códigos: los `ana_*` de normalidad son
     * *Normal*→`N` / *Anormal*→`A` (salvo los que tienen su propio par), y las cinco escalas clínicas
     * (*movimientos del feto*, *actividad cardíaca*, *líquido amniótico*, *movimientos respiratorios* y
     * *tono fetal*) van de `0` a `2` como las guarda el escritorio.
     *
     * @return array<int,array{titulo:string, icono:string, campos:array<int,array<string,mixed>>}>
     */
    public static function grupos(): array
    {
        $normal = ['Normal' => 'N', 'Anormal' => 'A'];

        return [
            ['titulo' => 'El embarazo', 'icono' => 'bi-calendar-heart', 'campos' => [
                ['columna' => 'fecha', 'etiqueta' => 'Fecha', 'tipo' => 'fecha', 'ayuda' => 'La fecha del estudio.'],
                self::texto('fur', 'FUR', 'La fecha de la última regla: se teclea.'),
                self::lista('gesta', 'Gesta', ['I' => '1', 'II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5',
                    'VI' => '6', 'VII' => '7', 'VIII' => '8', 'IX' => '9', 'X' => '10', 'XI' => '11']),
                self::lista('gestacion', 'Gestación', ['Única' => 'U', 'Múltiple' => 'M']),
                self::lista('situacion', 'Situación', ['Longitudinal' => 'L', 'Transversa' => 'T', 'Oblicua' => 'O'],
                    'El escritorio escribe «Longitudina»; la web lo rotula bien.'),
                self::lista('dorso', 'Dorso', ['Derecho' => 'D', 'Izquierdo' => 'I', 'Superior' => 'S', 'Inferior' => 'F']),
                self::lista('presentacion', 'Presentación', ['Cefálica' => 'C', 'Podálica' => 'P']),
            ]],

            ['titulo' => 'Datos biométricos', 'icono' => 'bi-rulers', 'campos' => [
                self::numero('bio_ca_bp', 'DBP', 'El diámetro biparietal (mm).'),
                self::numero('bio_ca_cc', 'CC', 'El perímetro cefálico (mm).'),
                self::numero('bio_ca_of', 'DOF', 'El diámetro occipitofrontal (mm).'),
                self::numero('bio_in_ic', 'IC', 'El índice cefálico (mm).'),
                self::numero('bio_or_ioe', 'IOE', 'El diámetro interorbitario externo (mm).'),
                self::numero('bio_or_io', 'IOI', 'El diámetro interorbitario interno (mm).'),
                self::numero('bio_car_apc', 'APC', 'El área del corazón (mm).'),
                self::numero('bio_car_apt', 'APT', 'El área del tórax (mm).'),
                self::numero('bio_aorta', 'Aorta'),
                self::numero('bio_ta', 'TA', 'El diámetro torácico (mm).'),
                self::numero('bio_ca', 'CA', 'La circunferencia abdominal (mm).'),
                self::numero('bio_humero', 'Húmero'),
                self::numero('bio_cubito', 'Cúbito'),
                self::numero('bio_femur', 'Fémur'),
                self::numero('bio_tibia', 'Tibia'),
                self::numero('bio_sacro', 'Sacro'),
                self::numero('bio_peso_fetal', 'Peso F.', 'El peso fetal estimado (gramos).'),
                self::numero('bio_talla', 'Talla'),
                self::lista('bio_sexo', 'Sexo', ['Masculino' => 'M', 'Femenino' => 'F']),
            ]],

            ['titulo' => 'Datos anatómicos', 'icono' => 'bi-person', 'campos' => [
                self::lista('ana_polo_cefalico', 'P. Cefálico', $normal),
                self::lista('ana_ventriculos_cerebrales', 'V. Cerebrales', $normal),
                self::lista('ana_cerebelo', 'Cerebelo', $normal),
                self::lista('ana_rostro_fetal', 'Rostro fetal', $normal),
                self::lista('ana_actitud_fetal', 'Actitud fetal', ['Flexión' => 'F', 'Deflexión' => 'D']),
                self::lista('ana_columna_vertebral', 'C. vertebral', ['Visible' => 'V', 'Anormal' => 'A']),
                self::lista('ana_torax', 'Tórax', $normal),
                self::lista('ana_corazon', 'Corazón', $normal),
                self::lista('ana_tracto_de_salida', 'T. de salida', $normal),
                self::lista('ana_estomago', 'Estómago', ['Lleno' => 'L', 'Vacío' => 'V']),
                self::lista('ana_intestino', 'Intestino', $normal),
                self::lista('ana_paredes_abdominales', 'Pared abdominal', $normal),
                self::lista('ana_rinone', 'Riñones', $normal),
                self::lista('ana_vejiga', 'Vejiga', ['Lleno' => 'L', 'Vacío' => 'V']),
                self::lista('ana_ex_brazo_an_md', 'Extremidades', ['Normales' => 'V', 'Anormales' => 'N']),
                self::lista('ana_ex_brazo_an_mi', 'Pliegues', ['I' => '1', 'II' => '2', 'III' => '3', 'IV' => '4']),
            ]],

            ['titulo' => 'Placenta y líquido', 'icono' => 'bi-droplet-half', 'campos' => [
                self::lista('fun_liquido_amniotico', 'Líquido amniótico',
                    ['Normal' => 'N', 'Oligohidramnios' => 'O', 'Polihidramnios' => 'P']),
                self::numero('fun_indice_la', 'ILA (Phelan)', 'El índice de líquido amniótico.'),
                self::lista('fun_dp_ubicacion', 'Ubicación', ['Anterior' => 'A', 'Posterior' => 'P', 'Fúndica' => 'F',
                    'Lateral derecho' => 'D', 'Lateral izquierdo' => 'I', 'Previa' => 'M']),
                self::lista('fun_dp_grado', 'Grado', ['0' => '0', 'I' => 'I', 'II' => 'II', 'III' => 'III']),
                self::numero('fun_dp_grosor', 'Grosor', 'El grosor de la placenta (mm).'),
                self::lista('fun_dp_cordon_umbilical', 'Cordón umbilical',
                    ['Visible con sus 3 elementos vasculares' => 'N', 'Anormal' => 'A']),
                self::lista('fun_dp_movimientos_respiratorios', 'Movimientos respiratorios', ['0' => 'O', '2' => '2']),
                self::lista('fun_dp_tono_fetal', 'Tono fetal', ['0' => '0', '2' => '2']),
            ]],

            ['titulo' => 'El feto', 'icono' => 'bi-activity', 'campos' => [
                self::lista('movimientos_feto', 'Movimientos del feto', ['0' => '0', '2' => '2']),
                self::lista('reactividad_cardiaca', 'Actividad cardíaca', ['0' => '0', '2' => '2']),
                self::lista('liquido_amniotico', 'Líquido amniótico', ['0' => '0', '2' => '2']),
            ]],

            ['titulo' => 'La conclusión', 'icono' => 'bi-file-earmark-text', 'campos' => [
                ['columna' => 'conclusion', 'etiqueta' => 'Conclusiones', 'tipo' => 'area',
                    'ayuda' => 'Las conclusiones del estudio (la columna se llama `conclusion`).'],
                ['columna' => 'observacion', 'etiqueta' => 'Observaciones', 'tipo' => 'area',
                    'ayuda' => 'Las observaciones (acá el rótulo coincide con la columna).'],
            ]],
        ];
    }

    /**
     * Las columnas que la pantalla edita, en el orden del mapa: es lo que la Action guarda.
     *
     * @return array<int,string>
     */
    public static function columnas(): array
    {
        $columnas = [];

        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $columnas[] = $campo['columna'];
            }
        }

        return $columnas;
    }

    /** El estudio de una consulta, o el objeto vacío si esa consulta no lo tiene. */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /** La fila de `eco_obstetrico_tercer` de una consulta, o `null`. */
    public function filaDe(Consulta $consulta): ?EcoObstetricoTercer
    {
        return EcoObstetricoTercer::query()
            ->where('reg_medico', $consulta->reg_medico)
            ->where(self::COLUMNA_HISTORIA, (int) $consulta->numhistoria)
            ->where(self::COLUMNA_CONSULTA, (int) $consulta->nroconsulta)
            ->first();
    }

    /** La fila para escribir: la que ya existe o una nueva con su clave puesta. */
    public function filaParaEscribir(Consulta $consulta): EcoObstetricoTercer
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new EcoObstetricoTercer([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
        ]);
    }
}
