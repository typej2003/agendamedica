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
 * 4. **Los antecedentes** (WEB-4.4): el mismo aviso de cambios sin guardar —el detalle, la casilla de
 *    Alerta y lo que el buscador dejó tildado— y el **"Quitar"** de cada fila, que no borra nada al
 *    instante: marca la fila y agrega su código al `POST` del botón guardar, que es donde la baja se
 *    aplica. El `hidden` que se inserta es la única forma de decirle al servidor "esto ya no está" sin
 *    duplicar el estado en la vista.
 *
 * 5. **Los diagnósticos** (WEB-4.5): el mismo aviso y el mismo "Quitar" que los antecedentes, para la
 *    sección del ítem 8. Acá la fila se identifica por su **id** (el detalle editado y la baja viajan en el
 *    mismo `POST`) y el `quitar[]` que inserta el script es el que la Action usa para borrar.
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

    // ------------------------------------------------------------------- Buscador de motivos

    const motivos = document.getElementById('consulta-motivos');

    if (motivos) {
        const caja = document.getElementById('motivos-busqueda');
        const texto = document.getElementById('motivo-texto');
        const lista = document.getElementById('motivo-resultados');
        const ayuda = document.getElementById('motivo-ayuda');
        const plantilla = document.getElementById('motivo-plantilla');
        const plantillaCrear = document.getElementById('motivo-crear-plantilla');
        const botonNuevo = document.getElementById('motivo-nuevo-boton');
        const dialogo = document.getElementById('dialogo-motivo');
        const campoDescripcion = document.getElementById('motivo-descripcion');
        const urlBuscar = motivos.dataset.urlBuscar;
        const ayudaInicial = ayuda.textContent;
        let temporizador = null;
        let ultimaBusqueda = '';

        function limpiar() {
            lista.innerHTML = '';
            lista.hidden = true;
        }

        function filaDelMotivo(motivo) {
            const fila = plantilla.content.firstElementChild.cloneNode(true);
            const datos = fila.querySelector('.resultado-paciente-datos');
            datos.textContent = motivo.descripcion;

            if (motivo.yaEsta) {
                datos.insertAdjacentHTML('beforeend', ' <span class="texto-tenue small">· ya está en la consulta</span>');
                fila.querySelector('form').remove();
                return fila;
            }

            fila.querySelector('input[name=codemotivo]').value = motivo.codemotivo;
            return fila;
        }

        function filaParaCrear() {
            const fila = plantillaCrear.content.firstElementChild.cloneNode(true);
            fila.querySelector('.resultado-paciente-datos').textContent =
                motivos.dataset.textoCrear.replace('{texto}', ultimaBusqueda);
            return fila;
        }

        function pintar(datos) {
            lista.innerHTML = '';

            // Crear sólo si lo escrito **no es** un motivo del catálogo: si ya existe uno igual, lo que
            // corresponde es agregarlo, y el servidor tampoco lo duplicaría (el móvil hace lo mismo).
            if (ultimaBusqueda !== '' && !datos.existe) {
                lista.appendChild(filaParaCrear());
            }

            datos.motivos.forEach(function (motivo) {
                lista.appendChild(filaDelMotivo(motivo));
            });

            if (lista.children.length === 0) {
                limpiar();
                ayuda.textContent = ultimaBusqueda === ''
                    ? ayudaInicial
                    : 'Tu catálogo no tiene motivos que coincidan con «' + ultimaBusqueda + '».';
                return;
            }

            lista.hidden = false;

            const cuantos = datos.motivos.length;
            ayuda.textContent = cuantos === 0
                ? 'Ningún motivo de tu catálogo coincide con «' + ultimaBusqueda + '»: puedes crearlo.'
                : cuantos + (cuantos === 1 ? ' motivo' : ' motivos')
                    + (ultimaBusqueda === '' ? ' en tu catálogo.' : ' que coinciden con «' + ultimaBusqueda + '».');
        }

        function buscar() {
            const consulta = texto.value.trim();
            ultimaBusqueda = consulta;

            if (consulta === '') {
                limpiar();
                ayuda.textContent = ayudaInicial;
                return;
            }

            fetch(urlBuscar + '?q=' + encodeURIComponent(consulta), { headers: { 'Accept': 'application/json' } })
                .then(function (respuesta) { return respuesta.json(); })
                .then(function (datos) {
                    // Una respuesta vieja no pisa lo que se escribió después.
                    if (datos.buscar !== texto.value.trim()) return;
                    pintar(datos);
                })
                .catch(function () {
                    limpiar();
                    ayuda.textContent = 'No se pudo buscar en el catálogo: revisa la conexión con el servidor.';
                });
        }

        texto.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, 250);
        });

        document.addEventListener('click', function (evento) {
            if (evento.target !== texto && !caja.contains(evento.target)) {
                limpiar();
            }
        });

        // El botón "Crear un motivo nuevo" y la fila "Crear «…»" del buscador abren el mismo diálogo: la
        // fila le pasa lo escrito, el botón deja el campo vacío.
        function abrirCrear(textoInicial) {
            if (!dialogo) return;

            if (campoDescripcion && textoInicial) {
                campoDescripcion.value = textoInicial;
            }

            limpiar();
            dialogo.showModal();
            if (campoDescripcion) campoDescripcion.focus();
        }

        lista.addEventListener('click', function (evento) {
            if (evento.target.closest('#motivo-crear-boton')) {
                evento.preventDefault();
                abrirCrear(ultimaBusqueda);
            }
        });

        if (botonNuevo) {
            botonNuevo.addEventListener('click', function () { abrirCrear(''); });
        }

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

        const guardado = campo.value;

        function sucio() {
            return campo.value !== guardado;
        }

        function pintarAviso() {
            if (aviso) aviso.hidden = !sucio();
        }

        campo.addEventListener('input', pintarAviso);

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
        const campos = Array.prototype.slice.call(evaluacion.querySelectorAll('input, textarea'));

        // El valor de partida de cada control, para saber si algo cambió. Se toma del `value`/`textContent`
        // con el que el servidor pintó el formulario (que ya trae el `old()` si el POST falló).
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
        /** Los controles de una fila que sigue en la lista: lo que la baja saca del POST y del aviso. */
        const controlesDe = (fila) => Array.prototype.slice.call(
            fila.querySelectorAll('input[type=text], input[type=checkbox], input[type=hidden][name^="filas["]'),
        );
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
        // Las filas que el médico sacó con el botón: su código viaja en `quitar[]` y sus controles **no**
        // viajan (si no, el POST hablaría de una fila que ya no está).
        const quitados = [];
        const controlesQuitados = [];

        function sucio() {
            if (quitados.length > 0) return true;

            return campos.some(function (campo, indice) {
                // Un control de una fila quitada ya no cuenta: su baja ya está en `quitados`.
                if (controlesQuitados.indexOf(campo) >= 0) return false;

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

        // "Quitar" no borra: marca la fila, saca sus controles del formulario y deja su código en
        // `quitar[]`. El segundo botón la restaura tal como estaba.
        antecedentes.querySelectorAll('[data-antecedente-quitar]').forEach(function (boton) {
            const fila = boton.closest('[data-antecedente-fila]');
            const codigo = boton.dataset.antecedenteQuitar;
            const oculto = document.createElement('input');
            oculto.type = 'hidden';
            oculto.name = 'quitar[]';
            oculto.value = codigo;
            const avisoQuitar = fila ? fila.querySelector('.antecedentes-aviso-quitar') : null;
            const restaurar = fila ? fila.querySelector('[data-antecedente-restaurar]') : null;
            const controles = fila ? controlesDe(fila) : [];
            const estabaTildada = fila
                ? Boolean(fila.querySelector('input[type=checkbox][name$="[alerta]"]')?.checked)
                : false;

            boton.addEventListener('click', function () {
                if (quitados.indexOf(codigo) >= 0) return;

                quitados.push(codigo);
                antecedentes.appendChild(oculto);
                // Los controles de la fila se van del formulario: el POST no manda `filas[id]` de una fila
                // que se está quitando (el servidor ya la borra por `quitar[]`).
                controles.forEach(function (control) {
                    control.disabled = true;
                    controlesQuitados.push(control);
                });
                if (fila) fila.classList.add('antecedentes-item-quitado');
                if (avisoQuitar) avisoQuitar.hidden = false;
                boton.hidden = true;
                if (restaurar) restaurar.hidden = false;
                pintarAviso();
            });

            if (restaurar) {
                restaurar.addEventListener('click', function () {
                    const posicion = quitados.indexOf(codigo);
                    if (posicion < 0) return;

                    quitados.splice(posicion, 1);
                    if (oculto.parentNode) oculto.parentNode.removeChild(oculto);
                    controles.forEach(function (control) {
                        control.disabled = false;
                        const enLista = controlesQuitados.indexOf(control);
                        if (enLista >= 0) controlesQuitados.splice(enLista, 1);
                    });
                    if (fila) fila.classList.remove('antecedentes-item-quitado');
                    if (avisoQuitar) avisoQuitar.hidden = true;
                    if (fila) {
                        const casilla = fila.querySelector('input[type=checkbox][name$="[alerta]"]');
                        if (casilla) casilla.checked = estabaTildada;
                    }
                    boton.hidden = false;
                    restaurar.hidden = true;
                    pintarAviso();
                });
            }
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
        const controlesDe = (fila) => Array.prototype.slice.call(
            fila.querySelectorAll('input[type=text][name^="filas["], input[type=hidden][name^="filas["]'),
        );
        const campos = Array.prototype.slice.call(
            diagnosticos.querySelectorAll('input[type=text][name^="filas["], input[type=hidden][name^="filas["]'),
        );

        // El estado de partida de cada control, tal como el servidor pintó el formulario (ya trae el
        // `old()` si el POST falló).
        const guardados = campos.map(function (campo) {
            return campo.value;
        });
        // Las filas que el médico sacó con el botón: su id viaja en `quitar[]` y sus controles **no** viajan
        // (si no, el POST hablaría de una fila que ya no está).
        const quitados = [];
        const controlesQuitados = [];

        function sucio() {
            if (quitados.length > 0) return true;

            return campos.some(function (campo, indice) {
                if (controlesQuitados.indexOf(campo) >= 0) return false;

                return campo.value !== guardados[indice];
            });
        }

        function pintarAviso() {
            if (aviso) aviso.hidden = !sucio();
        }

        campos.forEach(function (campo) {
            campo.addEventListener('input', pintarAviso);
        });

        diagnosticos.querySelectorAll('[data-diagnostico-quitar]').forEach(function (boton) {
            const fila = boton.closest('[data-diagnostico-fila]');
            const id = boton.dataset.diagnosticoQuitar;
            const oculto = document.createElement('input');
            oculto.type = 'hidden';
            oculto.name = 'quitar[]';
            oculto.value = id;
            const avisoQuitar = fila ? fila.querySelector('.diagnosticos-aviso-quitar') : null;
            const restaurar = fila ? fila.querySelector('[data-diagnostico-restaurar]') : null;
            const controles = fila ? controlesDe(fila) : [];

            boton.addEventListener('click', function () {
                if (quitados.indexOf(id) >= 0) return;

                quitados.push(id);
                diagnosticos.appendChild(oculto);
                // Los controles de la fila se van del formulario: el POST no manda `filas[id]` de una fila
                // que se está quitando (el servidor ya la borra por `quitar[]`).
                controles.forEach(function (control) {
                    control.disabled = true;
                    controlesQuitados.push(control);
                });
                if (fila) fila.classList.add('diagnosticos-item-quitado');
                if (avisoQuitar) avisoQuitar.hidden = false;
                boton.hidden = true;
                if (restaurar) restaurar.hidden = false;
                pintarAviso();
            });

            if (restaurar) {
                restaurar.addEventListener('click', function () {
                    const posicion = quitados.indexOf(id);
                    if (posicion < 0) return;

                    quitados.splice(posicion, 1);
                    if (oculto.parentNode) oculto.parentNode.removeChild(oculto);
                    controles.forEach(function (control) {
                        control.disabled = false;
                        const enLista = controlesQuitados.indexOf(control);
                        if (enLista >= 0) controlesQuitados.splice(enLista, 1);
                    });
                    if (fila) fila.classList.remove('diagnosticos-item-quitado');
                    if (avisoQuitar) avisoQuitar.hidden = true;
                    boton.hidden = false;
                    restaurar.hidden = true;
                    pintarAviso();
                });
            }
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

    // El mismo aviso de texto clínico que la enfermedad actual y, además, las plantillas del escritorio:
    // cada botón copia su texto en el campo. `avisoDeTexto` engancha el aviso **antes** de que el script
    // toque el campo, así que el valor de partida es el que pintó el servidor y copiar una plantilla deja el
    // formulario sucio sin ningún estado extra.
    const evolucion = document.querySelector('[data-consulta-evolucion]');

    if (evolucion) {
        avisoDeTexto(evolucion, 'evolucion-sin-guardar');

        // El `<textarea>`, no el primero que aparezca: la sección tiene un solo campo de texto.
        const campo = document.getElementById('evolucion');

        evolucion.querySelectorAll('[data-evolucion-plantilla]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                if (!campo) return;

                // Lo que el doble clic del `dw_84` hace: `mle_evolucion.text = desch`. Se dispara `input`
                // para que el aviso de cambios sin guardar se entere por el mismo camino que si el médico
                // hubiera escrito (el listener de `avisoDeTexto`).
                campo.value = boton.dataset.evolucionPlantilla;
                campo.dispatchEvent(new Event('input', { bubbles: true }));
                campo.focus();
            });
        });
    }

    // ------------------------------------------------------------------- Exámenes e imágenes (WEB-4.7)

    // La única subida de archivo de la consulta: el formulario de la sección del ítem 7. Lo que hace el
    // script es **avisar** —el archivo elegido y todavía sin subir se pierde si se sale—, nada más: el
    // `POST` lo manda el navegador con el `multipart/form-data` del formulario y el archivo lo valida y lo
    // guarda el servidor. El nombre del archivo no se toca acá ni allá: lo pone el servidor.
    const subidaDeImagen = document.querySelector('[data-consulta-archivo]');

    if (subidaDeImagen) {
        const aviso = document.getElementById('examenes-sin-subir');
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
    }

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
})();
