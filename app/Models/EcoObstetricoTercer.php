<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **El eco obstétrico del tercer trimestre** (`eco_obstetrico_tercer`, WEB-6.2): la ecografía del embarazo
 * avanzado, con sus **biometrías**, su **anatomía** y su **función placentaria**.
 *
 * Los tres bloques de columnas son los del escritorio y se leen por su prefijo:
 *
 *  - **`bio_*`** (biometría): BP, CC, OF, IC, IOE, IO, APC, APT, aorta, abdomen, TA, CA, húmero, cúbito,
 *    fémur, tibia, sacro, **peso fetal**, talla y sexo.
 *  - **`ana_*`** (anatomía): polo cefálico, ventrículos cerebrales, cerebelo, rostro, actitud fetal, columna,
 *    tórax, relación, corazón (corte de cuatro cámaras y tracto de salida), estómago, intestino, paredes
 *    abdominales, riñones, vejiga y las cuatro extremidades (brazos y muslos).
 *  - **`fun_*`** (función): líquido amniótico e índice, ubicación, **grado**, grosor y cordón de la placenta,
 *    movimientos respiratorios y tono fetal.
 *
 * Más `gestacion`, `situacion`, `dorso`, `presentacion`, `movimientos_feto`, `reactividad_cardiaca`,
 * `liquido_amniotico`, `conclusion` y `observacion`.
 *
 * ⚠️ **La clave es `historia` + `consulta`** (`consulta` es `NOT NULL`): **una fila por consulta**, como el
 * eco pélvico. `gesta`, `fur` y `eg` son datos copiados o tecleados, no la clave.
 *
 * ⚠️ **`gemelo` y `puro_texto`**: `gemelo` es la marca de un embarazo múltiple y `puro_texto` el informe en
 * texto libre del escritorio. Las variantes `eco_obstetrico_tercer_o` y `_tercer_2_o` son este mismo estudio
 * **sin** esas dos columnas (vacías en el dump).
 */
class EcoObstetricoTercer extends Model
{
    use HasFactory;

    protected $table = 'eco_obstetrico_tercer';

    /** La tabla entera: qué **edita** la web lo dice su Action. */
    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'gesta', 'fur', 'eg', 'fecha',
        'gestacion', 'situacion', 'dorso', 'presentacion',
        // Biometría.
        'bio_ca_bp', 'bio_ca_cc', 'bio_ca_of', 'bio_in_ic', 'bio_or_ioe', 'bio_or_io',
        'bio_car_apc', 'bio_car_apt', 'bio_aorta', 'bio_abdomen', 'bio_ta', 'bio_ca',
        'bio_humero', 'bio_cubito', 'bio_femur', 'bio_tibia', 'bio_sacro', 'bio_peso_fetal',
        'bio_talla', 'bio_sexo',
        // Anatomía.
        'ana_polo_cefalico', 'ana_ventriculos_cerebrales', 'ana_cerebelo', 'ana_rostro_fetal',
        'ana_actitud_fetal', 'ana_columna_vertebral', 'ana_torax', 'ana_relacion', 'ana_corazon',
        'ana_corte_tracameral', 'ana_tracto_de_salida', 'ana_estomago', 'ana_intestino',
        'ana_paredes_abdominales', 'ana_rinone', 'ana_vejiga',
        'ana_ex_brazo_an_md', 'ana_ex_brazo_an_mi', 'ana_ex_muslo_ppd', 'ana_ex_muslo_ppi',
        // Función.
        'fun_liquido_amniotico', 'fun_indice_la', 'fun_dp_ubicacion', 'fun_dp_grado', 'fun_dp_grosor',
        'fun_dp_cordon_umbilical', 'fun_dp_movimientos_respiratorios', 'fun_dp_tono_fetal',
        // El resto.
        'conclusion', 'observacion', 'movimientos_feto', 'reactividad_cardiaca', 'liquido_amniotico',
        'puro_texto', 'gemelo',
    ];

    protected $casts = [
        'historia' => 'integer',
        'consulta' => 'integer',
        'gesta' => 'integer',
    ];
}
