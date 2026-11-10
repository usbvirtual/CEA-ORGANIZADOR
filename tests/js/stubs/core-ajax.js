// Sustituto de 'core/ajax' para node:test (ver tests/js/resolver.mjs).
// Las pruebas reales inyectan su transporte con api.setTransport().
export default {
    call: () => {
        throw new Error('core/ajax no está disponible en las pruebas; usa api.setTransport().');
    },
};
