@props(['url'])
{{-- Cabecera de los correos del framework (verificación de correo, restablecer contraseña): el logo de
     Doctorísimo en vez del nombre de la app. El resto de las plantillas de correo siguen siendo las de Laravel. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('img/marca/correo-logo.png') }}" width="178" height="48" alt="Doctorísimo" style="border:0;width:178px;height:48px;">
</a>
</td>
</tr>
