# Fuentes del récipe impreso desde la web (WEB-5.2)

Son las mismas cinco familias libres que el móvil incluye para su PDF (`DoctorisimoMobile/assets/fonts/`),
auto-alojadas aquí para que la hoja se imprima sin depender de una CDN ni de las fuentes del equipo. El
formato del médico (`recipe_formatos.elementos`) guarda el **id** de la familia; `public/css/clinica-recipe.css`
declara un `@font-face` por id con sus cuatro variantes (normal, negrita, itálica y negrita itálica).

| Id (`recipe_formatos`) | Familia | ≈ en el legado | Origen | Licencia |
|---|---|---|---|---|
| `arimo` | Arimo | Arial | Google Fonts (fonts.gstatic.com) | Apache 2.0 |
| `tinos` | Tinos | Times New Roman | Google Fonts | Apache 2.0 |
| `cousine` | Cousine | Courier New | Google Fonts | Apache 2.0 |
| `comic_neue` | Comic Neue | Comic Sans MS | Google Fonts | SIL Open Font License 1.1 |
| `dejavu_sans` | DejaVu Sans | Verdana | npm `dejavu-fonts-ttf@2.37.3` (jsDelivr) | Licencia libre de DejaVu (derivada de Bitstream Vera) |

Todas permiten redistribuirlas dentro de una aplicación.
