<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`examenes_paciente_consulta`**: el panel de laboratorio de la consulta pediátrica (nefrología), una fila
 * por consulta, con la clave del legado **`hhistoria` + `hconsulta`** (las dos con «h» de más, como están en el
 * esquema real) y las 54 columnas numéricas del panel.
 *
 * La escribe el escritorio en su `UPDATE` (`w_consulta_principal.srw:2924-2960`) y la lee al abrir la consulta
 * (`dw_76.retrieve`, `:25651`), así que la web tiene que leer y escribir **la misma fila** o los números se
 * pierden. El mapa de qué campo del panel va a qué columna está en `App\Clinica\Consulta\IndicadoresIncidentes`.
 */
class ExamenPacienteConsulta extends Model
{
    protected $table = 'examenes_paciente_consulta';

    protected $fillable = [
        'hhistoria', 'hconsulta', 'fecha',
        'urea', 'cr', 'sodio', 'potasio', 'cloro', 'calcio', 'fosforo', 'magnesio', 'paratohormona',
        'ferritina', 'trasferrina', 'proteinuria', 'depuracion_creatinina', 'ac_urico', 'ac_urico_u',
        'creatinina_u', 'sodio_u', 'potasio_u', 'cloro_u', 'fosforo_u', 'magnesio_u', 'albumina_u',
        'calcio_u', 'citrato_u', 'oxelato_u', 'g_v_ph', 'g_v_pco2', 'g_v_hco3', 'g_v_eb', 'ac_urico_serico',
        'hb', 'hto', 'hb_glicosilada', 'triglicerido', 'colesterol', 'creatinina_s', 'v_urina',
        'creatinina_u_24', 'sodio_u_24', 'fosforo_u_24', 'cloro_u_24', 'calcio_u_24', 'aci_urico_u_24',
        'potasio_u_24', 'oxato_u_24', 'citrato_u_24',
    ];

    protected $casts = [
        'hhistoria' => 'integer',
        'hconsulta' => 'integer',
        'fecha'     => 'date',
    ];
}
