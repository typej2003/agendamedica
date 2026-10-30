<?php

/*
|--------------------------------------------------------------------------
| Datos legales del servicio
|--------------------------------------------------------------------------
|
| **La identidad del responsable en un solo lugar.** La página de privacidad, los términos y la de
| eliminación de cuenta repiten el mismo bloque (razón social, RIF, domicilio, contacto y fecha de
| vigencia): si cada vista lo escribiera por su cuenta, se desincronizarían en la primera corrección.
|
| Lo que publica la app en Google Play usa el nombre **Doctorísimo** (el `android:label` del manifiesto)
| y el desarrollador **DDR Sistemas, C.A.** Los nombres de esta ficha y los de la ficha de Play tienen que
| coincidir: si en Play Console el desarrollador figura con otro texto, se cambia acá.
|
| El **alojamiento** está en Valencia, España: de ahí sale la cláusula de transferencia internacional de
| la política de privacidad. Si algún día se muda, se cambia sólo esta clave.
|
*/

return [

    // La app, como figura en la ficha de Google Play.
    'app' => 'Doctorísimo',

    'responsable' => [
        'razon_social' => 'DDR Sistemas, C.A.',
        'rif'          => 'J-31512955-8',
        'domicilio'    => 'Av. Vollmer, Edificio Normandie, Piso 6, Oficina 612, San Bernardino, Caracas, Venezuela',
        'pais'         => 'Venezuela',
    ],

    // El único buzón publicado para privacidad, bajas de cuenta y soporte legal.
    'contacto' => 'ddrsistemas@gmail.com',

    // Dónde se aloja la producción (cláusula de transferencia internacional).
    'alojamiento' => 'Valencia, España',

    // Fecha de la última publicación de los tres documentos. Se actualiza al cambiar el texto.
    'vigente_desde' => '2026-10-30',

    // El plazo de gracia de la baja de cuenta: se puede cancelar iniciando sesión o desde el correo.
    'dias_de_gracia_de_la_baja' => 30,

];
