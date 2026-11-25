// Ejecutar en una página POS de QA con nombres largos y productos sin imagen.
// Solo inspecciona DOM y geometría; no modifica estado ni envía solicitudes.
(() => {
    const cards = [...document.querySelectorAll('.pos-product')];
    const failures = [];
    let fallbacks = 0;
    for (const [index, card] of cards.entries()) {
        const fallback = card.querySelector('.pos-product-placeholder');
        if (!fallback) continue;
        fallbacks++;
        const text = fallback.querySelector('span');
        const heading = card.querySelector('h3');
        const box = fallback.getBoundingClientRect();
        const label = text.getBoundingClientRect();
        const title = heading.getBoundingClientRect();
        if (label.top < box.top - 1 || label.bottom > box.bottom + 1
            || label.left < box.left - 1 || label.right > box.right + 1
            || label.bottom > title.top + 1) {
            failures.push({index, reason: 'fallback_text_overlaps_card_body'});
        }
        if (!fallback.getAttribute('aria-label')?.includes(heading.textContent.trim())) {
            failures.push({index, reason: 'missing_full_accessible_name'});
        }
    }
    return {viewport: [innerWidth, innerHeight], cards: cards.length, fallbacks,
        failures, passed: cards.length > 0 && fallbacks > 0 && failures.length === 0};
})()
