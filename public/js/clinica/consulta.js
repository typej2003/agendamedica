/*
 * La consulta (WEB-4.2 y WEB-4.3): el contenido de sus secciones, dentro del shell.
 *
 * Las vistas no llevan JS: declaran lo que quieren con `data-*` y este archivo —el de la pantalla, como
 * `agenda-pacientes.js`— lo resuelve. Hace tres cosas, cada una atada a un bloque de la pantalla:
 *
 * 1. **El buscador de motivos** (`#consulta-motivos`): filtra el catálogo del médico mientras se escribe
 *    (250 ms de espera, como el buscador de pacientes), un motivo por nombre, y ofrece **crear el que
 *    falta** cuando lo escrito no es un motivo del catálogo. Los resultados se pintan con las plantillas
 *    de la vista y los formularios postean como cualquier otro (el JS no manda nada por su cuenta: llena
 *    el `codemotivo` de un formulario y lo deja enviar).
 *
 *    Sin JavaScript la pantalla sigue sirviendo: el servidor ya pintó el catálogo dentro de
 *    `#motivo-resultados`.
 *
 * 2. **El aviso de "hay cambios sin guardar"** de la enfermedad actual (`[data-consulta-enfermedad]`):
 *    el texto clínico se guarda con un botón, así que la pantalla tiene que decir cuándo hay algo escrito
 *    que todavía no está guardado y el navegador tiene que avisar antes de perderlo.
 *
 * 3. **La evaluación física** (WEB-4.3): el mismo aviso de cambios sin guardar, pero de **todo el
 *    formulario** (son veinte campos en dos tablas), y el **IMC** —peso / talla², la cuenta que el
 *    escritorio hace en `gffimc.srf`— recalculado al escribir el peso o la talla. El IMC no se guarda: es
 *    de pantalla.
 *
 * 4. **Los antecedentes** (WEB-4.4): el aviso de cambios sin guardar —el detalle y la casilla de Alerta, que
 *    son lo único que se guarda con el botón—. **Agregar y Quitar no pasan por acá** desde O-07: son `POST`
 *    inmediatos del formulario de cada fila y la pantalla se recarga con la lista ya cambiada, así que no hay
 *    estado intermedio que marcar ni `hidden` que insertar.
 *
 * 5. **Los diagnósticos** (WEB-4.5): el aviso de cambios sin guardar del **detalle**, que es lo único que se
 *    guarda con el botón. «Pasar» y «Quitar» son `POST` inmediatos desde O-27: la pantalla se recarga con la
 *    lista ya cambiada y no hay estado intermedio que marcar.
 *
 * 6. **La evolución** (WEB-4.6): el mismo aviso de cambios sin guardar que la enfermedad actual, para la
 *    sección del ítem 9, y las **plantillas**: cada botón de la lista copia su texto en el campo (que es lo
 *    que el doble clic del `dw_84` hace en el escritorio, `w_consulta_principal.srw:12002`) y deja el
 *    formulario marcado como sucio, porque copiar una plantilla **es escribir**: todavía no está guardada.
 *
 * 7. **La subida de una imagen** (WEB-4.7, ítem 7): el aviso de "elegiste una imagen y no la subiste". Es lo
 *    único que hace falta: el archivo lo manda el `<form enctype="multipart/form-data">` y quien lo valida
 *    —tipo real, tamaño— y le pone el nombre es el servidor (`App\Clinica\Archivos\ArchivosClinicos`). El
 *    script no toca ni el nombre ni el contenido del archivo.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------- Motivos: alta del que falta

    // La lista y el buscador del catálogo son del servidor (WEB-11.3: dos listas, formularios y enlaces).
    // Acá sólo se abre el diálogo de «Crear»: desde el botón «Agregar un motivo nuevo» (campo vacío) o desde
    // «Crear "x"» (que trae lo buscado en `data-motivo-crear`).
    const motivos = document.getElementById('consulta-motivos');

    if (motivos) {
        const dialogo = document.getElementById('dialogo-motivo');
        const campoDescripcion = document.getElementById('motivo-descripcion');

        function abrirCrear(textoInicial) {
            if (!dialogo) return;

            if (campoDescripcion && textoInicial) {
                campoDescripcion.value = textoInicial;
            }

            dialogo.showModal();
            if (campoDescripcion) campoDescripcion.focus();
        }

        const botonNuevo = document.getElementById('motivo-nuevo-boton');
        if (botonNuevo) {
            botonNuevo.addEventListener('click', function () { abrirCrear(''); });
        }

        // Delegado: el botón llega con la lista que `buscar-auto.js` vuelve a pedir al escribir en el buscador.
        motivos.addEventListener('click', function (evento) {
            const boton = evento.target.closest('[data-motivo-crear]');
            if (boton) abrirCrear(boton.dataset.motivoCrear);
        });

        // El diálogo se abre solo después de un error de validación: el servidor dejó `crear_motivo` en la
        // sesión y el campo conserva lo escrito (`old()`).
        if (dialogo && motivos.dataset.crearAbierto === '1') {
            dialogo.showModal();
        }
    }

    // ------------------------------------------------- Aviso de cambios sin guardar (enfermedad actual)

    // Una sección de **texto clínico con guardado explícito**: el aviso de "hay cambios sin guardar" y el
    // aviso del navegador al salir. Son la misma forma —un formulario con su `<textarea>`, su cartel y su
    // "Cancelar"—, así que las dos secciones que la usan (la enfermedad actual, WEB-4.2, y la evolución,
    // WEB-4.6) la comparten en vez de duplicarla.
    function avisoDeTexto(formulario, idAviso) {
        const campo = formulario.querySelector('textarea');
        const aviso = document.getElementById(idAviso);

        if (!campo) return;

        let guardado = campo.value;

        function sucio() {
            return campo.value !== guardado;
        }

        function pintarAviso() {
            if (aviso) aviso.hidden = !sucio();
        }

        campo.addEventListener('input', pintarAviso);

        // El autoguardado de la barra (`consulta-barra.js`) avisa que el texto ya quedó guardado: ese es el nuevo punto de partida.
        formulario.addEventListener('consulta:guardado', function () {
            guardado = campo.value;
            pintarAviso();
        });

        // Cancelar vuelve a la sección sin guardar: el navegador pregunta antes de perder el texto.
        const cancelar = formulario.querySelector('a[href]');
        if (cancelar) {
            cancelar.addEventListener('click', function (evento) {
                if (sucio() && !window.confirm(formulario.dataset.aviso)) {
                    evento.preventDefault();
                }
            });
        }

        window.addEventListener('beforeunload', function (evento) {
            if (!sucio()) return;
            evento.preventDefault();
            // El texto de retorno lo ignora el navegador (muestra el suyo), pero sin algo no avisa.
            evento.returnValue = formulario.dataset.aviso;
        });

        pintarAviso();
    }

    const enfermedad = document.querySelector('[data-consulta-enfermedad]');

    if (enfermedad) {
        avisoDeTexto(enfermedad, 'enfermedad-sin-guardar');
    }

    // ------------------------------------------------- Evaluación física (WEB-4.3)

    const evaluacion = document.querySelector('[data-consulta-evaluacion]');

    if (evaluacion) {
        const aviso = document.getElementById('evaluacion-sin-guardar');
        // Sólo los controles que se guardan (con `name`): el buscador y el título del panel de plantillas no cuentan.
        const campos = Array.prototype.slice.call(evaluacion.querySelectorAll('input[name], textarea[name]'));

        // El valor de partida de cada control, para saber si algo cambió. Se toma del `value`/`textContent`
        // con el que el servidor pintó el formulario (que ya trae el `old()` si el POST falló).
        let guardados = campos.map(function (campo) {
            return campo.value;
        });

        // El autoguardado de la barra (`consulta-barra.js`) avisa que todo quedó guardado: nuevo punto de partida.
        evaluacion.addEventListener('consulta:guardado', function () {
            guardados = campos.map(function (campo) { return campo.value; });
            pintarAviso();
        });

        function sucio() {
            return campos.some(function (campo, indice) {
                return campo.value !== guardados[indice];
            });
        }

        function pintarAviso() {
            if (aviso) aviso.hidden = !sucio();
        }

        campos.forEach(function (campo) {
            campo.addEventListener('input', pintarAviso);
        });

        const cancelar = evaluacion.querySelector('a[href]');
        if (cancelar) {
            cancelar.addEventListener('click', function (evento) {
                if (sucio() && !window.confirm(evaluacion.dataset.aviso)) {
                    evento.preventDefault();
                }
            });
        }

        window.addEventListener('beforeunload', function (evento) {
            if (!sucio()) return;
            evento.preventDefault();
            evento.returnValue = evaluacion.dataset.aviso;
        });

        // El IMC: peso / talla², la misma cuenta de `gffimc.srf` (y `0` si falta alguno de los dos, que acá
        // se muestra como `—`). No se guarda en ninguna columna: se recalcula mientras se escribe.
        const imc = document.querySelector('[data-evaluacion-imc]');
        const peso = document.getElementById('peso');
        const talla = document.getElementById('talla');

        function pintarImc() {
            if (!imc || !peso || !talla) return;

            const p = parseFloat(peso.value.replace(',', '.'));
            const t = parseFloat(talla.value.replace(',', '.'));

            if (!isFinite(p) || !isFinite(t) || p <= 0 || t <= 0) {
                imc.textContent = '—';
                return;
            }

            imc.textContent = (p / (t * t)).toFixed(2).replace('.', ',');
        }

        [peso, talla].forEach(function (campo) {
            if (campo) campo.addEventListener('input', pintarImc);
        });
    }

    // ------------------------------------------------------------------ Antecedentes (WEB-4.4)

    const antecedentes = document.querySelector('[data-consulta-antecedentes]');

    if (antecedentes) {
        const aviso = document.getElementById('antecedentes-sin-guardar');
        // Sólo el detalle y la casilla de Alerta: son lo que se guarda con el botón (O-07). Agregar y Quitar
        // son `POST` inmediatos y ya no dejan estado pendiente en el formulario.
        const campos = Array.prototype.slice.call(
            antecedentes.querySelectorAll(
                'input[type=text][name^="filas["], input[type=checkbox][name^="filas["], input[type=hidden][name^="filas["]',
            ),
        );

        // El estado de partida de cada control, tal como el servidor pintó el formulario (ya trae el
        // `old()` si el POST falló). El checkbox compara `checked`, que es lo que cambia la casilla.
        const guardados = campos.map(function (campo) {
            return campo.type === 'checkbox' ? campo.checked : campo.value;
        });

        function sucio() {
            return campos.some(function (campo, indice) {
                return campo.type === 'checkbox'
                    ? campo.checked !== guardados[indice]
                    : campo.value !== guardados[indice];
            });
        }

        function pintarAviso() {
            if (aviso) aviso.hidden = !sucio();
        }

        campos.forEach(function (campo) {
            campo.addEventListener('input', pintarAviso);
            campo.addEventListener('change', pintarAviso);
        });

        // Cancelar vuelve a la sección sin guardar: el navegador pregunta antes de perder los cambios.
        const cancelar = antecedentes.querySelector('a[href]');
        if (cancelar) {
            cancelar.addEventListener('click', function (evento) {
                if (sucio() && !window.confirm(antecedentes.dataset.aviso)) {
                    evento.preventDefault();
                }
            });
        }

        window.addEventListener('beforeunload', function (evento) {
            if (!sucio()) return;
            evento.preventDefault();
            // El texto de retorno lo ignora el navegador (muestra el suyo), pero sin algo no avisa.
            evento.returnValue = antecedentes.dataset.aviso;
        });

        pintarAviso();
    }

    // ------------------------------------------------------------------- Diagnósticos (WEB-4.5)

    // El mismo patrón que los antecedentes, para la sección del ítem 8: el aviso de "hay cambios sin
    // guardar" —el detalle editado y lo que se va a quitar— y el "Quitar" de cada fila, que no borra nada al
    // instante: marca la fila y agrega su **id** al `POST` del botón guardar, que es donde la baja se aplica
    // (el escritorio también espera al *Guardar* de la ventana para el `dw_8.update()`).
    const diagnosticos = document.querySelector('[data-consulta-diagnosticos]');

    if (diagnosticos) {
        const aviso = document.getElementById('diagnosticos-sin-guardar');
        // Sólo el detalle: es lo que se guarda con el botón (O-27). «Pasar» y «Quitar» son `POST`
        // inmediatos y ya no dejan estado pendiente en el formulario.
        const campos = Array.prototype.slice.call(
            diagnosticos.querySelectorAll('input[type=text][name^="filas["], input[type=hidden][name^="filas["]'),
        );

        // El estado de partida de cada control, tal como el servidor pintó el formulario (ya trae el
        // `old()` si el POST falló).
        const guardados = campos.map(function (campo) {
            return campo.value;
        });

        function sucio() {
            return campos.some(function (campo, indice) {
                return campo.value !== guardados[indice];
            });
        }

        function pintarAviso() {
            if (aviso) aviso.hidden = !sucio();
        }

        campos.forEach(function (campo) {
            campo.addEventListener('input', pintarAviso);
        });

        // Cancelar vuelve a la sección sin guardar: el navegador pregunta antes de perder los cambios.
        const cancelar = diagnosticos.querySelector('a[href]');
        if (cancelar) {
            cancelar.addEventListener('click', function (evento) {
                if (sucio() && !window.confirm(diagnosticos.dataset.aviso)) {
                    evento.preventDefault();
                }
            });
        }

        window.addEventListener('beforeunload', function (evento) {
            if (!sucio()) return;
            evento.preventDefault();
            // El texto de retorno lo ignora el navegador (muestra el suyo), pero sin algo no avisa.
            evento.returnValue = diagnosticos.dataset.aviso;
        });

        pintarAviso();
    }

    // ------------------------------------------------------------------- Evolución (WEB-4.6)

    // El mismo aviso de texto clínico que la enfermedad actual. Las plantillas las maneja el panel
    // (`plantillas-panel.js`, WEB-11.2), que dispara `input` al copiar y así deja el formulario sucio.
    const evolucion = document.querySelector('[data-consulta-evolucion]');

    if (evolucion) {
        avisoDeTexto(evolucion, 'evolucion-sin-guardar');
    }

    // ------------------------------------------- Plantillas de texto de las solapas del examen (WEB-6.5)

    // Las solapas del examen: un formulario `[data-consulta-plantillas]` con su aviso de cambios sin guardar
    // (el id del formulario más `-sin-guardar`). Sus plantillas las copia el panel (`plantillas-panel.js`).
    document.querySelectorAll('[data-consulta-plantillas]').forEach(function (formulario) {
        avisoDeTexto(formulario, formulario.id + '-sin-guardar');
    });

    // ------------------------------------------------------------------- Exámenes e imágenes (WEB-4.7)

    // La única subida de archivo de la consulta: los formularios de imagen (la sección del ítem 7 y las
    // solapas del examen, WEB-6.5). Lo que hace el script es **avisar** —el archivo elegido y todavía sin
    // subir se pierde si se sale—, nada más: el `POST` lo manda el navegador con el `multipart/form-data`
    // del formulario y el archivo lo valida y lo guarda el servidor. El nombre del archivo no se toca acá ni
    // allá: lo pone el servidor. El cartel de cada formulario se declara con `data-aviso-id` (el id del
    // párrafo de aviso), porque el id del formulario y el del cartel no siguen la misma regla: el de la
    // sección de exámenes es `examenes-sin-subir` y su formulario `examenes-subida`.
    document.querySelectorAll('[data-consulta-archivo]').forEach(function (subidaDeImagen) {
        const aviso = document.getElementById(subidaDeImagen.dataset.avisoId || (subidaDeImagen.id + '-sin-subir'));
        const archivo = subidaDeImagen.querySelector('input[type=file]');

        function sucio() {
            return Boolean(archivo && archivo.files && archivo.files.length > 0);
        }

        function pintarAviso() {
            if (aviso) aviso.hidden = !sucio();
        }

        if (archivo) {
            archivo.addEventListener('change', pintarAviso);
            // Elegir un archivo es una acción: el envío lo decide el médico con el botón. Si el formulario
            // se envía, el archivo deja de estar "sin subir" y el navegador se va: no hay nada que avisar.
            subidaDeImagen.addEventListener('submit', function () {
                if (aviso) aviso.hidden = true;
            });
        }

        window.addEventListener('beforeunload', function (evento) {
            if (!sucio()) return;
            evento.preventDefault();
            evento.returnValue = subidaDeImagen.dataset.aviso;
        });

        pintarAviso();
    });

    // ------------------------------------------------- Estudio ecográfico (WEB-6.1)

    // El formulario del eco pélvico: el mismo aviso de "hay cambios sin guardar" que la evaluación física,
    // pero con los desplegables adentro (por eso también escucha `change`, que es el evento de un
    // `<select>`), que son la mitad de los campos del estudio.
    const estudio = document.querySelector('[data-consulta-estudio]');

    if (estudio) {
        const avisoEstudio = document.getElementById('estudio-sin-guardar');
        const camposEstudio = Array.prototype.slice.call(estudio.querySelectorAll('input, select, textarea'));

        // El valor de partida de cada control, tal como lo pintó el servidor (con el `old()` si el POST
        // falló): es contra eso que se decide si algo cambió.
        const guardadosEstudio = camposEstudio.map(function (campo) {
            return campo.value;
        });

        function sucioEstudio() {
            return camposEstudio.some(function (campo, indice) {
                return campo.value !== guardadosEstudio[indice];
            });
        }

        function pintarAvisoEstudio() {
            if (avisoEstudio) avisoEstudio.hidden = !sucioEstudio();
        }

        camposEstudio.forEach(function (campo) {
            campo.addEventListener('input', pintarAvisoEstudio);
            campo.addEventListener('change', pintarAvisoEstudio);
        });

        const cancelarEstudio = estudio.querySelector('a[href]');
        if (cancelarEstudio) {
            cancelarEstudio.addEventListener('click', function (evento) {
                if (sucioEstudio() && !window.confirm(estudio.dataset.aviso)) {
                    evento.preventDefault();
                }
            });
        }

        window.addEventListener('beforeunload', function (evento) {
            if (!sucioEstudio()) return;
            evento.preventDefault();
            evento.returnValue = estudio.dataset.aviso;
        });

        pintarAvisoEstudio();
    }

    // ------------------------------------------------- Control de embarazo (WEB-6.6)

    // El mismo aviso de "hay cambios sin guardar", esta vez con una función genérica: el formulario de la
    // gesta es un formulario más (campos y su cartel), sin nada propio como el IMC de la evaluación.
    function avisoDeFormulario(formulario, idAviso) {
        const campos = Array.prototype.slice.call(formulario.querySelectorAll('input, select, textarea'));
        // El estado de cada control: el valor y, en las casillas, si está marcada. Una casilla tiene siempre
        // el mismo `value` —`S`, el del formulario—, así que sin el `checked` marcar «Final de embarazo» no
        // contaba como un cambio y el aviso no aparecía (O-21: las dos casillas del estado del embarazo).
        const estadoDe = function (campo) {
            return campo.value + (campo.type === 'checkbox' || campo.type === 'radio' ? '|' + campo.checked : '');
        };
        const guardados = campos.map(estadoDe);
        const aviso = document.getElementById(idAviso);

        function sucio() {
            return campos.some(function (campo, indice) {
                return estadoDe(campo) !== guardados[indice];
            });
        }

        function pintar() {
            if (aviso) aviso.hidden = !sucio();
        }

        campos.forEach(function (campo) {
            campo.addEventListener('input', pintar);
            campo.addEventListener('change', pintar);
        });

        const cancelar = formulario.querySelector('a[href]');
        if (cancelar) {
            cancelar.addEventListener('click', function (evento) {
                if (sucio() && !window.confirm(formulario.dataset.aviso)) {
                    evento.preventDefault();
                }
            });
        }

        window.addEventListener('beforeunload', function (evento) {
            if (!sucio()) return;
            evento.preventDefault();
            evento.returnValue = formulario.dataset.aviso;
        });

        pintar();
    }

    // FPP y edad gestacional en vivo al escribir la FUR (sólo presentación; al guardar el servidor recalcula).
    // La regla de verdad es `App\Clinica\Embarazo\EdadGestacional`: FPP = FUR + `data-dias-fpp` días, semanas =
    // int(días / 7) y días = días mod 7, contra `data-hoy` (el día del consultorio, el mismo del servidor). La FUR
    // puede ser `AAAA-MM-DD` (campo date) o `dd/mm/aaaa` (campo tecleado). Vacía o inválida: ambas «—»; futura: FPP sí, EG «—».
    function furEnVivo(formulario, furCampo, fppSalida, egSalida) {
        if (!furCampo || !fppSalida || !egSalida) return;

        const dosCifras = function (n) { return String(n).padStart(2, '0'); };
        const aUtc = function (texto) {
            const t = (texto || '').trim();
            let a; let m; let d;
            let r = /^(\d{4})-(\d{2})-(\d{2})$/.exec(t);

            if (r) { a = +r[1]; m = +r[2]; d = +r[3]; } else {
                r = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(t);
                if (!r) return null;
                d = +r[1]; m = +r[2]; a = +r[3];
            }

            const ms = Date.UTC(a, m - 1, d);
            const f = new Date(ms);
            return f.getUTCFullYear() === a && f.getUTCMonth() === m - 1 && f.getUTCDate() === d ? ms : null;
        };
        const recalcular = function () {
            const fur = aUtc(furCampo.value);
            const hoy = aUtc(formulario.dataset.hoy);
            let fpp = '—';
            let eg = '—';

            if (fur !== null && hoy !== null) {
                const dias = Math.round((hoy - fur) / 86400000);
                // La FPP sale de cualquier FUR válida (como en el servidor); la EG sólo si no es futura.
                const f = new Date(fur + Number(formulario.dataset.diasFpp || 280) * 86400000);
                fpp = dosCifras(f.getUTCDate()) + '/' + dosCifras(f.getUTCMonth() + 1) + '/' + f.getUTCFullYear();

                if (dias >= 0) {
                    const sem = Math.floor(dias / 7);
                    const resto = dias % 7;
                    eg = sem + (sem === 1 ? ' semana' : ' semanas') + ' y ' + resto + (resto === 1 ? ' día' : ' días');
                }
            }

            fppSalida.textContent = fpp;
            egSalida.textContent = eg;
        };

        furCampo.addEventListener('input', recalcular);
        furCampo.addEventListener('change', recalcular);
    }

    const prenatal = document.querySelector('[data-consulta-prenatal]');

    if (prenatal) {
        avisoDeFormulario(prenatal, 'prenatal-sin-guardar');

        furEnVivo(prenatal, prenatal.querySelector('#fur'),
            prenatal.querySelector('#prenatal-fpp'), prenatal.querySelector('#prenatal-eg'));
    }

    // Ecocardiograma fetal: mismas dos cuentas junto al formulario (el servidor las muestra de la FUR guardada).
    const fetal = document.querySelector('[data-consulta-fetal]');

    if (fetal) {
        furEnVivo(fetal, fetal.querySelector('#fur'), fetal.querySelector('#fetal-fpp'), fetal.querySelector('#fetal-eg'));
    }

    // Ecos obstétricos (1.º, 2.º y 3.er trimestre): la misma cuenta arriba del formulario, para que la EG y la
    // FPP se vean al teclear la FUR **sin guardar**, como los dos `compute` del DataWindow del escritorio.
    const obstetrico = document.querySelector('[data-consulta-obstetrico]');

    if (obstetrico) {
        furEnVivo(obstetrico, obstetrico.querySelector('#fur'),
            obstetrico.querySelector('#obstetrico-fpp'), obstetrico.querySelector('#obstetrico-eg'));
    }

    // Las pestañas del embarazo cuyas filas son fechas (Laboratorio y Controles) usan el mismo aviso: el
    // cartel de cada una se llama como su formulario, más `-sin-guardar`.
    document.querySelectorAll('[data-consulta-fechas]').forEach(function (formulario) {
        avisoDeFormulario(formulario, formulario.id + '-sin-guardar');
    });

    // Indicadores incidentes (nefrología pediátrica, 2026-10-19): el panel de números compone un texto y se
    // guarda con un botón, así que lleva el mismo aviso genérico.
    document.querySelectorAll('[data-consulta-indicadores]').forEach(function (formulario) {
        avisoDeFormulario(formulario, formulario.id + '-sin-guardar');
    });

    // Neumología pediátrica (2026-10-19): tres informes de texto con guardado explícito.
    document.querySelectorAll('[data-consulta-neumologia]').forEach(function (formulario) {
        avisoDeFormulario(formulario, formulario.id + '-sin-guardar');
    });

    // Procedimientos (gastroenterología pediátrica, 2026-10-19): las endoscopías, con guardado explícito.
    document.querySelectorAll('[data-consulta-procedimientos]').forEach(function (formulario) {
        avisoDeFormulario(formulario, formulario.id + '-sin-guardar');
    });
})();
