document.addEventListener('DOMContentLoaded', function () {
    // Formulario de Búsqueda Rápida de Médicos
    const searchForm = document.getElementById('quickSearchForm');
    if (searchForm) {
        searchForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const specialty = document.getElementById('searchSpecialty').value;
            const location = document.getElementById('searchLocation').value;

            if (!specialty && !location) {
                alert('Por favor seleccione al menos un criterio de búsqueda.');
                return;
            }

            console.log(`Buscando especialista: ${specialty} en la ubicación: ${location}`);
            // Aquí puedes redirigir a tu ruta de Laravel de búsqueda:
            // window.location.href = `/doctores?especialidad=${specialty}&ubicacion=${location}`;
        });
    }

    // Efecto de cambio de sombra en la barra de navegación al hacer scroll
    // Esa barra sólo existe en el sitio público: en el panel no está y el listener tiraba un error en cada scroll.
    const navbar = document.querySelector('.navbar-gineco');
    if (navbar) window.addEventListener('scroll', function () {
        if (window.scrollY > 20) {
            navbar.classList.add('shadow-sm');
        } else {
            navbar.classList.remove('shadow-sm');
        }
    });
});