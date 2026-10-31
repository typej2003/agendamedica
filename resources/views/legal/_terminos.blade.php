<h1>Términos y condiciones</h1>

<p class="legal-entrada">
    Estos términos son el acuerdo entre tú y
    {{ config('legal.responsable.razon_social') }} para usar Doctorísimo: la aplicación móvil, la
    web clínica y el portal de citas.
</p>

@include('legal._identidad')

<h2>1. Aceptación</h2>
<p>
    Al crear una cuenta, al entrar o al usar el servicio aceptas estos términos y la
    <a href="{{ route('legal.privacidad') }}">política de privacidad</a>. Si no estás de acuerdo, no
    uses el servicio.
</p>

<h2>2. Quién puede usar el servicio</h2>
<p>
    Doctorísimo es una herramienta <strong>para profesionales de la salud</strong>. Al registrarte
    declaras que lo eres y que los datos que das son ciertos: nombre, correo, número de registro
    (MPPS) y título. La cuenta queda <strong>pendiente de aprobación</strong> hasta que se revisan
    esos datos; recién entonces se habilitan los módulos de tu especialidad y tu catálogo inicial.
</p>
<p>
    Las secretarías se incorporan por invitación del médico o del consultorio, y actúan por cuenta
    de él. Deben tener su propia cuenta: no se comparten credenciales.
</p>
<p>
    El servicio no está dirigido a menores de edad como usuarios. Los datos de pacientes menores los
    carga el médico con la autorización de su representante.
</p>

<h2>3. Qué es el servicio</h2>
<p>
    Doctorísimo guarda y organiza la información del consultorio: agenda, historia clínica,
    documentos impresos, récipes y reportes. Funciona también <strong>sin conexión</strong> y
    sincroniza cuando vuelve la red.
</p>
<p>
    <strong>No es un servicio de emergencias</strong> y no reemplaza el criterio del médico ni la
    atención presencial.
</p>

<h2>4. Tu cuenta</h2>
<ul>
    <li>Eres responsable de la confidencialidad de tu contraseña y de lo que se haga con tu cuenta.</li>
    <li>
        La cuenta es personal. Si detectas un uso no autorizado, cámbiala y avísanos a
        {{ config('legal.contacto') }}.
    </li>
    <li>Los datos de la cuenta tienen que ser ciertos y estar al día.</li>
</ul>

<h2>5. Planes y pagos</h2>
<ul>
    <li>
        El servicio se presta con un <strong>plan mensual o anual</strong>, con los límites de
        médicos, secretarías e impresión que declara cada plan. Los precios y el detalle se muestran
        al contratar.
    </li>
    <li>
        El pago se procesa con <strong>SyPago</strong>.
        {{ config('legal.responsable.razon_social') }} <strong>factura y emite su comprobante</strong>.
    </li>
    <li>
        La suscripción se renueva al vencer el período. Si no se renueva, el servicio se suspende:
        deja de imprimirse y el acceso queda limitado, sin borrar la información.
    </li>
    <li>
        <strong>No hay reembolsos</strong>, salvo que el servicio haya fallado de forma que te haya
        impedido usarlo. En ese caso, escríbenos y se evalúa el caso concreto.
    </li>
    <li>
        Si bajas de plan, el cambio aplica cuando se quiten los usuarios que excedan el límite
        nuevo.
    </li>
</ul>

<h3>5.1 Latidos y referidos</h3>
<ul>
    <li>
        Los <strong>Latidos</strong> son una unidad de descuento promocional, <strong>no dinero</strong>:
        <strong>100 Latidos = USD 1 de descuento</strong>. No son una cuenta bancaria, un depósito ni una
        wallet, y no generan intereses ni rendimientos.
    </li>
    <li>
        No se retiran, no se transfieren, no se venden y no se convierten a efectivo. Sólo se canjean en
        productos o servicios de Doctorísimo.
    </li>
    <li>
        El programa de referidos acredita Latidos cuando un administrador aprueba el registro del colega.
        El premio se otorga <strong>una sola vez por consultorio referido</strong>, no aplica a
        autorreferencias ni a clientes existentes, y puede anularse si hay fraude o un error.
    </li>
    <li>
        La renovación automática con Latidos viene encendida y puedes apagarla desde «Mi plan». Si está
        encendida y los Latidos cubren el 100 % del plan, se descuentan el día del vencimiento y el
        servicio se extiende; si no alcanzan, no se descuenta nada.
    </li>
    <li>
        Los Latidos no vencen en esta versión. Si das de baja la cuenta, los Latidos se pierden.
    </li>
</ul>

<h2>6. Responsabilidad clínica</h2>
<p>
    <strong>La decisión médica es tuya.</strong> Doctorísimo es una herramienta de registro y de
    apoyo: no diagnostica, no prescribe y no sugiere tratamientos por su cuenta. Todo lo que la
    aplicación calcula o propone (una dosis por peso, una curva de crecimiento, un resumen) lo
    revisas y lo confirmas tú antes de usarlo.
</p>
<p>
    Eres responsable de la veracidad de lo que cargas y del uso que le das frente a tus pacientes.
</p>

<h2>7. Datos de los pacientes</h2>
<p>
    La historia clínica es <strong>tuya y de tu paciente</strong>: tú eres el responsable del dato.
    {{ config('legal.responsable.razon_social') }} sólo lo guarda y lo procesa por tu cuenta, para
    que el servicio funcione. No lo usa para fines propios ni lo cede a terceros con fines
    comerciales.
</p>
<p>
    Te comprometes a cargar sólo los datos que necesitas, a obtener el consentimiento que
    corresponda y a guardar la confidencialidad que exige tu profesión.
</p>

<h2>8. Uso aceptable</h2>
<p>No puedes:</p>
<ul>
    <li>Usar el servicio para algo distinto de la gestión de tu consultorio.</li>
    <li>Intentar acceder a datos de otros médicos o de otros pacientes.</li>
    <li>Copiar, revender o explotar comercialmente el servicio sin autorización escrita.</li>
    <li>Introducir código malicioso ni interferir con el funcionamiento del sistema.</li>
    <li>Suplantar a otra persona o crear cuentas con datos falsos.</li>
</ul>

<h2>9. Disponibilidad, conexión y respaldo</h2>
<p>
    Trabajamos para que el servicio esté disponible, pero <strong>no se garantiza una
    disponibilidad ininterrumpida</strong>: hay mantenimientos y fallas posibles. La aplicación
    funciona sin conexión y vuelca lo cargado al sincronizar; aun así, revisa que tus datos hayan
    subido.
</p>
<p>
    El respaldo de la información clínica es una responsabilidad compartida: el sistema guarda y
    replica, y el médico conserva sus propios respaldos cuando su norma profesional se lo exige.
</p>

<h2>10. Propiedad intelectual</h2>
<p>
    El software, la marca y los textos de Doctorísimo son de
    {{ config('legal.responsable.razon_social') }}. Se te da una licencia de uso personal y no
    exclusiva, mientras tu suscripción esté vigente. Los datos que cargas siguen siendo tuyos.
</p>

<h2>11. Límite de responsabilidad</h2>
<p>
    En la medida en que la ley lo permita,
    {{ config('legal.responsable.razon_social') }} no responde por daños indirectos, lucro cesante
    ni pérdida de información derivados del uso del servicio, y su responsabilidad total se limita
    al monto pagado por la suscripción en los últimos doce meses. Nada de esto limita la
    responsabilidad que la ley no permita limitar.
</p>

<h2>12. Suspensión y terminación</h2>
<p>
    Puedes dar de baja tu cuenta cuando quieras, desde la
    <a href="{{ route('legal.baja.formulario') }}">página de eliminación de cuenta</a>. Nosotros
    podemos suspender una cuenta por falta de pago o por un uso que viole estos términos; si la
    suspensión es por uso indebido, se avisa el motivo.
</p>

<h2>13. Cambios en los términos</h2>
<p>
    Si estos términos cambian, se publican acá con su nueva fecha de vigencia. Los cambios de fondo
    se avisan por correo a las cuentas activas; si no estás de acuerdo, puedes dar de baja la cuenta
    antes de que empiecen a regir.
</p>

<h2>14. Ley aplicable y jurisdicción</h2>
<p>
    Estos términos se rigen por las leyes de la República Bolivariana de Venezuela. Cualquier
    controversia se somete a los <strong>tribunales de la ciudad de Caracas</strong>.
</p>

<h2>15. Contacto</h2>
<p>
    {{ config('legal.responsable.razon_social') }} —
    <a href="mailto:{{ config('legal.contacto') }}">{{ config('legal.contacto') }}</a>
</p>

<p class="legal-pie-documento">
    Ver también la <a href="{{ route('legal.privacidad') }}">política de privacidad</a> y cómo
    <a href="{{ route('legal.baja.formulario') }}">eliminar tu cuenta</a>.
</p>
