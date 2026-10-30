@extends('layouts.app')

@section('title', 'Eliminar mi cuenta · Doctorísimo')
@section('description', 'Cómo pedir la eliminación de tu cuenta de Doctorísimo y de los datos asociados.')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')
    <div class="container legal">
        <article class="legal-documento">
            <p class="legal-volver"><a href="{{ route('welcome') }}">&larr; Volver a Doctorísimo</a></p>

            <h1>Eliminar mi cuenta</h1>

            <p class="legal-entrada">
                Desde acá puedes pedir que se elimine tu cuenta de <strong>{{ config('legal.app') }}</strong> y
                los datos asociados. La aplicación la desarrolla
                <strong>{{ config('legal.responsable.razon_social') }}</strong>.
            </p>

            @include('legal._identidad')

            @if (session('baja_enviada'))
                <div class="legal-aviso" role="status">
                    <strong>Revisa tu correo.</strong>
                    Si ese correo corresponde a una cuenta de Doctorísimo, te enviamos un mensaje con el enlace
                    para confirmar la baja y otro para cancelarla. La baja <strong>no empieza</strong> hasta que
                    abras el primero.
                </div>
            @endif

            <h2>Cómo pedirla, paso a paso</h2>
            <ol class="legal-pasos">
                <li>
                    Escribe abajo el <strong>correo con el que entras a Doctorísimo</strong> y envía el
                    formulario.
                </li>
                <li>
                    Te llega un correo de confirmación a esa dirección. Ábrelo y pulsa
                    <strong>«Confirmar la baja»</strong>.
                </li>
                <li>
                    Desde ese momento la cuenta queda programada para borrarse
                    <strong>en {{ config('legal.dias_de_gracia_de_la_baja') }} días</strong>.
                </li>
                <li>
                    Durante esos {{ config('legal.dias_de_gracia_de_la_baja') }} días puedes arrepentirte:
                    <strong>iniciando sesión</strong> en la web o en la app, o con el enlace
                    <strong>«Cancelar la baja»</strong> que trae el mismo correo.
                </li>
            </ol>
            <p>
                Pasado el plazo sin que la canceles, la baja se ejecuta. No hace falta que escribas a soporte ni
                que des explicaciones.
            </p>

            <h2>Qué se elimina y qué se conserva</h2>
            <p>
                <strong>Se elimina</strong> todo lo generado por esa cuenta:
            </p>
            <ul>
                <li>La cuenta y el acceso (correo, contraseña y datos de perfil).</li>
                <li>La ficha del médico y su número de registro.</li>
                <li>
                    Las cuentas de las secretarías que sólo trabajan en ese consultorio, y el consultorio con sus
                    sedes, consultorios y horarios.
                </li>
                <li>Las historias clínicas, las consultas y las citas de ese registro.</li>
                <li>Las imágenes y los archivos de todo tipo que se hayan subido, y los reportes generados.</li>
                <li>La suscripción y los datos asociados a ella.</li>
            </ul>
            <p>
                <strong>Se conserva</strong> únicamente el registro de que la baja se hizo (el correo, la fecha y
                el estado de la solicitud), sin datos clínicos, para poder demostrar que se cumplió. Ese registro
                no se usa para nada más.
            </p>
            <p>
                El plazo de {{ config('legal.dias_de_gracia_de_la_baja') }} días existe para que puedas
                arrepentirte: una vez ejecutada, la eliminación <strong>no se puede deshacer</strong>.
            </p>

            <h2>Pedir la baja</h2>

            <form method="POST" action="{{ route('legal.baja.solicitar') }}" class="legal-formulario">
                @csrf

                <div class="legal-campo">
                    <label for="email">Correo de la cuenta</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autocomplete="email"
                        inputmode="email"
                        placeholder="tucorreo@ejemplo.com"
                        aria-describedby="email-ayuda @error('email') email-error @enderror"
                    >
                    <p id="email-ayuda" class="legal-ayuda">
                        Es el correo con el que entras a la web clínica o a la app.
                    </p>
                    @error('email')
                        <p id="email-error" class="legal-error">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="legal-boton">Enviar el enlace de confirmación</button>

                <p class="legal-nota">
                    Te respondemos siempre lo mismo, exista o no esa cuenta, para no revelar qué correos están
                    registrados.
                </p>
            </form>

            <h2>Si no puedes entrar a tu correo</h2>
            <p>
                Si perdiste el acceso al correo de la cuenta, escríbenos a
                <a href="mailto:{{ config('legal.contacto') }}">{{ config('legal.contacto') }}</a> desde la
                dirección que recuerdes y cuéntanos qué pasó. Vamos a pedirte algún dato que permita comprobar
                que la cuenta es tuya antes de borrar nada.
            </p>

            <h2>Para saber más</h2>
            <p>
                Qué datos se tratan y por cuánto tiempo, en la
                <a href="{{ route('legal.privacidad') }}">política de privacidad</a>. Las condiciones del
                servicio, en los <a href="{{ route('legal.terminos') }}">términos y condiciones</a>.
            </p>
        </article>
    </div>
@endsection
