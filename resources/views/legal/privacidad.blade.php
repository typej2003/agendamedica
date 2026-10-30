@extends('layouts.app')

@section('title', 'Política de privacidad · Doctorísimo')
@section('description', 'Qué datos trata Doctorísimo, para qué, con quién se comparten y cómo pedir su eliminación.')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')
    <div class="container legal">
        <article class="legal-documento">
            <p class="legal-volver"><a href="{{ route('welcome') }}">&larr; Volver a Doctorísimo</a></p>

            <h1>Política de privacidad</h1>

            <p class="legal-entrada">
                Doctorísimo es una aplicación de gestión de consultorios médicos: la usan médicos y sus
                secretarías para atender pacientes. Esta política explica qué datos se tratan, para qué, con
                quién se comparten, cuánto tiempo se guardan y cómo se pide su eliminación.
            </p>

            @include('legal._identidad')

            <h2>1. Quién trata tus datos</h2>
            <p>
                El responsable del sitio, de la aplicación y de las cuentas es
                {{ config('legal.responsable.razon_social') }}.
            </p>
            <p>
                Con los <strong>datos clínicos de los pacientes</strong> hay dos papeles distintos:
            </p>
            <ul>
                <li>
                    <strong>El médico es el responsable.</strong> Decide qué se carga en la historia, para qué
                    se usa y a quién se le muestra. La historia clínica es suya y de su paciente.
                </li>
                <li>
                    <strong>{{ config('legal.responsable.razon_social') }} es el encargado.</strong> Guarda y
                    procesa esos datos por cuenta del médico, sólo para que el servicio funcione, y no los usa
                    para fines propios.
                </li>
            </ul>

            <h2>2. Qué datos se tratan</h2>

            <h3>De los médicos y las secretarías (quienes tienen cuenta)</h3>
            <ul>
                <li>Nombre y apellidos, correo electrónico y teléfono.</li>
                <li>
                    Número de registro profesional (MPPS) y título, que se revisan al aprobar la cuenta; la
                    especialidad y las subespecialidades que declares.
                </li>
                <li>Los datos del consultorio: sedes, consultorios, horarios y motivos de consulta.</li>
                <li>Los datos de la suscripción y de los pagos.</li>
                <li>Datos técnicos de uso: cuándo entras, desde qué equipo y qué errores ocurren.</li>
            </ul>

            <h3>De los pacientes (los carga el médico o su secretaría)</h3>
            <ul>
                <li>Identificación: nombre, cédula, fecha de nacimiento, sexo, teléfono, dirección y correo.</li>
                <li>
                    <strong>Datos de salud</strong>: motivos de consulta, antecedentes, exámenes, diagnósticos,
                    tratamientos, récipes, embarazo, crecimiento y vacunas, entre otros.
                </li>
                <li>Imágenes y archivos que se adjuntan a una consulta (ecografías, resultados, fotos).</li>
                <li>Las citas y su historial de atención.</li>
            </ul>

            <h3>Datos de los menores de edad</h3>
            <p>
                La pediatría carga datos de niños y adolescentes. Esos datos los incorpora el médico con la
                <strong>autorización de quien ejerce la representación</strong> del menor, que es quien la
                obtiene en el consultorio. Si eres representante y quieres corregir o eliminar datos de un
                menor, escribe a {{ config('legal.contacto') }}.
            </p>

            <h3>Del portal de citas</h3>
            <p>
                Un paciente puede reservar una cita sin tener cuenta, dejando su nombre, su teléfono y su
                correo. Esos datos se usan sólo para agendar y avisar la cita.
            </p>

            <h2>3. Para qué se usan</h2>
            <ul>
                <li>Prestar el servicio: guardar la historia clínica, agendar, imprimir documentos y sincronizar.</li>
                <li>Identificar a quien entra y darle el acceso que le corresponde.</li>
                <li>Cobrar la suscripción y emitir su comprobante.</li>
                <li>Avisar por correo lo que hace falta para usar la cuenta (por ejemplo, la confirmación de una baja).</li>
                <li>Corregir errores y mantener la seguridad del servicio.</li>
            </ul>
            <p>
                <strong>No se usan para publicidad</strong>, no se venden y no se ceden a terceros con fines
                comerciales.
            </p>

            <h2>4. Con quién se comparten</h2>
            <p>Sólo con los proveedores necesarios para que el servicio funcione, y sólo lo indispensable:</p>
            <ul>
                <li><strong>SyPago</strong>, para procesar el pago de la suscripción.</li>
                <li>El proveedor de <strong>correo</strong>, para enviar los avisos de la cuenta.</li>
                <li>El proveedor de <strong>alojamiento</strong>, donde viven la base de datos y los archivos.</li>
                <li><strong>Google Play</strong>, como canal de distribución de la aplicación.</li>
            </ul>
            <p>
                También se entregan datos cuando lo exige una autoridad competente, en los términos de la ley.
            </p>

            <h2>5. Dónde se alojan</h2>
            <p>
                La producción está alojada en <strong>{{ config('legal.alojamiento') }}</strong>, fuera de
                Venezuela. Eso implica una <strong>transferencia internacional</strong> de los datos: el
                alojamiento los guarda por cuenta de
                {{ config('legal.responsable.razon_social') }} y con las mismas obligaciones de
                confidencialidad.
            </p>

            <h2>6. Cuánto tiempo se guardan</h2>
            <ul>
                <li>
                    Mientras la cuenta esté activa, para poder prestar el servicio.
                </li>
                <li>
                    Si se pide la baja, se abre un plazo de <strong>{{ config('legal.dias_de_gracia_de_la_baja') }} días</strong>
                    para poder arrepentirse. Pasado ese plazo, la cuenta y los datos asociados se eliminan
                    (ver <a href="{{ route('legal.baja.formulario') }}">eliminar mi cuenta</a>).
                </li>
                <li>
                    Queda el registro de que la baja se hizo (correo, fecha y estado), sin datos clínicos, para
                    poder demostrar que se cumplió.
                </li>
            </ul>

            <h2>7. Tus derechos</h2>
            <p>Puedes pedir, escribiendo a {{ config('legal.contacto') }}:</p>
            <ul>
                <li><strong>Acceso</strong>: saber qué datos tuyos se tratan.</li>
                <li><strong>Rectificación</strong>: corregir los que estén mal.</li>
                <li><strong>Cancelación</strong>: pedir la baja de la cuenta y el borrado de sus datos.</li>
                <li><strong>Oposición</strong>: oponerte a un uso concreto.</li>
            </ul>
            <p>
                Los datos clínicos de un paciente los corrige el médico que los cargó: si eres paciente,
                dirígete a tu médico. Si no obtienes respuesta, escríbenos y te ayudamos a canalizarlo.
            </p>

            <h2>8. Seguridad</h2>
            <p>
                El acceso requiere autenticación, las contraseñas se guardan cifradas y los archivos clínicos
                viven en un almacenamiento privado, sin URL pública: se sirven sólo a quien tiene permiso sobre
                ellos. Ningún sistema es infalible; si ocurre un incidente que afecte tus datos, se avisa a
                quien corresponda.
            </p>

            <h2>9. Cookies</h2>
            <p>
                El sitio usa las cookies necesarias para mantener la sesión y proteger los formularios. No se
                usan cookies publicitarias ni de seguimiento entre sitios.
            </p>

            <h2>10. Cambios</h2>
            <p>
                Si esta política cambia, se publica acá con su nueva fecha de vigencia. Los cambios de fondo se
                avisan por correo a las cuentas activas.
            </p>

            <h2>11. Contacto</h2>
            <p>
                {{ config('legal.responsable.razon_social') }} —
                <a href="mailto:{{ config('legal.contacto') }}">{{ config('legal.contacto') }}</a>
            </p>

            <p class="legal-pie-documento">
                Ver también los <a href="{{ route('legal.terminos') }}">términos y condiciones</a> y cómo
                <a href="{{ route('legal.baja.formulario') }}">eliminar tu cuenta</a>.
            </p>
        </article>
    </div>
@endsection
