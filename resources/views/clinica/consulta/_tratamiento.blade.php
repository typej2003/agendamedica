{{-- El tratamiento de la consulta (WEB-5.7, ítem TRATAMIENTO del panel del legado, `tabpage_40`): un texto libre con las
     plantillas `TR` al lado, guardado en `consultas.laringe`. Es la misma pantalla que las solapas de texto del
     examen (un campo, sus plantillas y el aviso de cambios sin guardar), así que reusa su parcial: el dato sale de
     `App\Clinica\Consulta\TextosDelExamen`. --}}
<div class="consulta-seccion" id="consulta-tratamiento">
    @include('clinica.consulta._evaluacion-texto', ['seccion' => $seccion, 'imagenes' => null])
</div>
