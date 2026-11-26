/* Interacciones documentales: no forman parte de los flujos del producto. */
document.querySelectorAll('[data-demo]').forEach(button => button.addEventListener('click', () => {
    const notice = document.getElementById('catalog-notice');
    notice.textContent = button.dataset.demo;
    notice.hidden = false;
}));
document.getElementById('density').addEventListener('change', event => {
    document.documentElement.dataset.uiDensity = event.target.value;
});
