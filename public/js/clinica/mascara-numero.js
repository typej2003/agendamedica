/*
 * La máscara de los números que se teclean (O-08): la talla, el peso, las circunferencias, las frecuencias y
 * la tensión arterial de la evaluación física.
 *
 * Es la misma idea que `mascara-fecha.js` (O-18), que es el patrón de la casa: **la máscara es de la
 * pantalla**; el dato que viaja y se guarda conserva su formato. Acá ese formato es el de la base —la talla
 * `1.62`, la tensión `120/80`— y la conversión de la coma al punto la hace el servidor en un solo lugar
 * (`App\Support\NumeroDeFormulario`), no este archivo: el campo manda lo que el médico ve.
 *
 * Se declara en la vista con `data-mascara` y no necesita ninguna librería. Hay tres formas:
 *
 *  - `decimal` — dígitos y una coma decimal, con `data-enteros` (máximo de la parte entera) y `data-decimales`
 *    (máximo de la decimal). La talla en metros lleva **un** entero, así que la coma se pone sola al llegar a
 *    su máximo: teclear `162` deja `1,62`; `2` deja `2` y después `00` lo completa a `2,00`.
 *  - `entero` — sólo dígitos (las frecuencias cardiaca y respiratoria), con `data-enteros` de tope.
 *  - `tension` — la máscara `###/###` del escritorio (`w_consulta_principal.srw:9045`): tres dígitos, la
 *    barra y dos o tres más. Teclear `12080` deja `120/80`; una sistólica de dos dígitos se teclea con su
 *    barra (`90/60`) o se pega entera.
 *
 * Lo que comparte con la máscara de fecha, porque es lo que se verificó que tiene que funcionar:
 *
 *  - **Se llena mientras se escribe**, de adelante hacia atrás, y lo que no entra en la máscara se descarta en
 *    el momento (no se "corrige" a otro valor: eso inventaría un dato clínico).
 *  - **Pegar** un valor entero (`1.62`, `1,62`, `120/80`, `120-80`) lo normaliza; pegar algo que no es un
 *    número no rompe el campo.
 *  - **Borrar** (Retroceso o Suprimir) saca el último dígito y, cuando no queda ninguno, deja el campo
 *    **vacío** —nunca un `0,` colgando—. Con todo el campo seleccionado (`Ctrl+A` + borrar) lo vacía entero.
 *  - Cada cambio dispara `input`, que es lo que escuchan el aviso de cambios sin guardar, el autoguardado
 *    (`consulta.js` y `consulta-barra.js`) y el recálculo del IMC: para ellos esto es escribir, como
 *    cualquier otra tecla.
 *  - Un valor guardado que **no entra** en la máscara (un dato viejo de la base con más decimales, o la
 *    tensión `120-80` del legado) se **respeta tal cual** hasta que el médico toque el campo: reformatearlo
 *    a ciegas lo cambiaría sin que nadie lo haya pedido.
 */
(function () {
    'use strict';

    var SELECTOR = '[data-mascara="decimal"], [data-mascara="entero"], [data-mascara="tension"]';

    /** Un tope del atributo `data-*`, con su valor por omisión. */
    function tope(campo, atributo, porDefecto) {
        var valor = parseInt(campo.dataset[atributo], 10);

        return isFinite(valor) && valor > 0 ? valor : porDefecto;
    }

    /**
     * La forma del campo: cuántos grupos de dígitos tiene, qué entra en cada uno y con qué se separan.
     *
     * `autoavance` es la regla para el separador: cuando el primer grupo **no puede crecer más** (la talla
     * tiene un solo entero, la tensión tres) el separador se pone solo. En el peso y las circunferencias el
     * primer grupo admite de uno a tres enteros y ahí la coma no es adivinable: la pone el médico (o llega
     * al pegar `70,5`).
     */
    function forma(campo) {
        var tipo = campo.dataset.mascara;

        if (tipo === 'tension') {
            var porGrupo = tope(campo, 'enteros', 3);

            return { tipo: tipo, grupos: [porGrupo, porGrupo], separadores: /[\/\-]/, separador: '/', autoavance: true };
        }

        if (tipo === 'entero') {
            return { tipo: tipo, grupos: [tope(campo, 'enteros', 3)], separadores: null, separador: null, autoavance: false };
        }

        var enteros = tope(campo, 'enteros', 3);

        return {
            tipo: 'decimal',
            grupos: [enteros, tope(campo, 'decimales', 2)],
            separadores: /[.,]/,
            separador: ',',
            autoavance: enteros === 1,
        };
    }

    function vacio(f) {
        return { grupos: f.grupos.map(function () { return ''; }), actual: 0 };
    }

    /** Lo que muestra el campo: los grupos con dígitos, unidos por el separador. Un grupo vacío del final no se
     *  dibuja, así que nunca queda un `1,` colgando (ni un valor que el servidor no pueda leer como número). */
    function dibujar(f, estado) {
        var partes = [];

        for (var i = 0; i < estado.grupos.length; i++) {
            if (estado.grupos[i] === '') {
                break;
            }
            partes.push(estado.grupos[i]);
        }

        return partes.join(f.separador || '');
    }

    /** Agrega un dígito en el grupo activo (o en el siguiente, si el separador es automático). */
    function agregar(f, estado, digito) {
        if (!/^\d$/.test(digito)) {
            return false;
        }

        var i = estado.actual;

        while (i < estado.grupos.length) {
            if (estado.grupos[i].length < f.grupos[i]) {
                estado.grupos[i] += digito;
                estado.actual = i;
                return true;
            }

            // El grupo está lleno: sólo se pasa al siguiente si el separador se pone solo.
            if (!f.autoavance) {
                return false;
            }

            i++;
        }

        return false;
    }

    /** Reparte un texto de dígitos por los grupos, con las mismas reglas que el teclado. */
    function distribuir(f, texto) {
        var estado = vacio(f);

        for (var i = 0; i < texto.length; i++) {
            if (!agregar(f, estado, texto[i])) {
                return null;
            }
        }

        return estado;
    }

    /**
     * El estado que se lee de un texto: el valor de la base (`1.62`), lo que se acaba de teclear (`1,62`) o lo
     * que se pegó (`120/80`, `120-80`). `null` si no entra en la máscara (y entonces se respeta tal cual).
     */
    function leer(f, valor) {
        var texto = String(valor == null ? '' : valor).trim();

        if (texto === '') {
            return vacio(f);
        }

        if (f.separadores !== null) {
            var partes = texto.split(f.separadores);

            if (partes.length === 2 && /^\d+$/.test(partes[0]) && /^\d+$/.test(partes[1])
                && partes[0].length <= f.grupos[0] && partes[1].length <= f.grupos[1]) {
                return { grupos: [partes[0], partes[1]], actual: 1 };
            }
        }

        // Un número entero —el `.0` que deja una columna `double` en la base también—: se distribuye como si se
        // hubiera tecleado dígito a dígito.
        var entero = texto.match(/^(\d+)(?:[.,]0+)?$/);

        if (entero && (f.separadores === null || entero[1].length <= f.grupos[0])) {
            return distribuir(f, entero[1]);
        }

        // Sin separador en el texto pero con él en la máscara: los dígitos se reparten (pegar `12080`).
        if (f.separadores !== null && /^\d+$/.test(texto)) {
            return distribuir(f, texto);
        }

        return null;
    }

    document.querySelectorAll(SELECTOR).forEach(function (campo) {
        var f = forma(campo);

        // El último valor que pintó el script: sirve para distinguir sus propios `input` de los de afuera
        // (un autocompletar, pegar desde el menú del navegador, una prueba automática).
        var pintado = campo.value;
        var estado = leer(f, pintado);

        // Lo que no entra en la máscara se muestra como vino: no se reformatea un dato viejo de la base.
        if (estado !== null) {
            var dibujado = dibujar(f, estado);

            if (dibujado !== campo.value) {
                campo.value = dibujado;
                pintado = campo.value;
            }
        }

        function aplicar() {
            campo.value = dibujar(f, estado);
            pintado = campo.value;
            try {
                campo.setSelectionRange(campo.value.length, campo.value.length);
            } catch (e) { /* un campo sin selección (nunca pasa en un input de texto) */ }
            // El aviso de cambios sin guardar, el autoguardado y el IMC escuchan `input`.
            campo.dispatchEvent(new Event('input', { bubbles: true }));
        }

        /** Todo el campo seleccionado (Ctrl+A y borrar) se vacía entero. */
        function todoSeleccionado() {
            return campo.value !== ''
                && campo.selectionStart === 0
                && campo.selectionEnd === campo.value.length;
        }

        // Un cambio que no pasó por el teclado: se resincroniza lo que el script tiene en memoria. Si no entra
        // en la máscara, el campo queda «ajeno» hasta que el médico lo edite.
        campo.addEventListener('input', function () {
            if (campo.value === pintado) {
                return;
            }

            estado = leer(f, campo.value);
            pintado = campo.value;

            if (estado !== null) {
                var dibujado = dibujar(f, estado);

                if (dibujado !== campo.value) {
                    campo.value = dibujado;
                    pintado = campo.value;
                }
            }
        });

        campo.addEventListener('keydown', function (evento) {
            if (evento.ctrlKey || evento.metaKey || evento.altKey || evento.isComposing) {
                return;
            }

            if (evento.key === 'Backspace' || evento.key === 'Delete') {
                // El valor lo arma este script: el navegador no puede borrar un carácter por su cuenta (la coma o
                // la barra volverían a entrar en el siguiente dibujo).
                evento.preventDefault();

                if (todoSeleccionado() || estado === null) {
                    estado = vacio(f);
                    aplicar();
                    return;
                }

                var i = estado.actual;

                while (i >= 0) {
                    if (estado.grupos[i] !== '') {
                        estado.grupos[i] = estado.grupos[i].slice(0, -1);
                        estado.actual = i;
                        aplicar();
                        return;
                    }

                    i--;
                }

                // No quedaba ningún dígito: el campo ya estaba vacío.
                return;
            }

            // La coma, el punto o la barra: el médico adelanta el separador (la tensión `90/60`).
            if (f.separador !== null && (evento.key === ',' || evento.key === '.' || evento.key === '/' || evento.key === '-')) {
                evento.preventDefault();

                if (estado !== null && estado.grupos[0] !== '' && estado.grupos.length > 1) {
                    estado.actual = 1;
                }

                return;
            }

            if (evento.key && evento.key.length === 1) {
                evento.preventDefault();

                if (estado === null) {
                    estado = vacio(f);
                }

                if (agregar(f, estado, evento.key)) {
                    aplicar();
                }
            }
        });

        campo.addEventListener('paste', function (evento) {
            evento.preventDefault();

            var portapapeles = (evento.clipboardData || window.clipboardData);
            var pegado = leer(f, portapapeles ? portapapeles.getData('text') : '');

            if (pegado !== null) {
                estado = pegado;
                aplicar();
            }
        });
    });
})();
