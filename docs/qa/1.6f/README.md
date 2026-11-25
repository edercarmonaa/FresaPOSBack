# Evidencia actual — Etapa 1.6f

ETAPA 1.6f: ACEPTADA FUNCIONALMENTE CON OBSERVACIONES. Datos ficticios exclusivamente en `fresapos_test`; diagnósticos operativos de solo lectura. No se publican cookies, tokens, PIN, logs privados, credenciales ni identificadores de cuentas operativas.

[Informe A–AA](../../entrega-1.6f.md) · [Resultados PHPUnit](test-results.json) · [Calidad](quality.json) · [OpenAPI](openapi.json) · [Procedencia](response-provenance.json) · [Concurrencia](concurrency.json) · [Integridad](operative-integrity.json) · [Cinco tablas](history-reconstruction.json). · [Intentos fallidos y reintentos](prior-attempts.json) · [Esquema actual](current-schema.json) · [Arranque de workers](worker-timings.json).

[PDF de resumen](exports/composite/summary.pdf) · [Excel de resumen](exports/composite/summary.xlsx) · [Ticket mixto de80mm](tickets/print-mixed-80mm.pdf).

Los archivos del compuesto comparan el mismo conjunto en Web/API/PDF/XLSX. Los nativos y la repetición de cierre tienen fixtures separados. Cada blob conserva SHA y tamaño en [manifest](artifact-manifest.json). Los contactos son vistas de revisión; los originales PNG se conservan. Los cuatro casos geométricos «before» muestran la reproducción anterior al ajuste; «after» corresponde a la corrección. Algunas capturas iniciales conservan rechazos del primer recorrido del ayudante; la repetición final instrumentada se distingue por prefijo25 y final-pos-network.json.

La vista de XLSX adapta únicamente el render de folios de texto para evitar una limitación de ArtifactTool; no se guardó ningún workbook modificado. Excel de escritorio e impresora física pendientes.

## Capturas por escenario

| Escenario |1440|1024|768|390|
| --- | --- | --- | --- | --- |
| 01-login | [1440](screens/01-login-1440.png) | [1024](screens/01-login-1024.png) | [768](screens/01-login-768.png) | [390](screens/01-login-390.png) |
| 01-pos-ticket | [1440](screens/01-pos-ticket-1440.png) | [1024](screens/01-pos-ticket-1024.png) | [768](screens/01-pos-ticket-768.png) | [390](screens/01-pos-ticket-390.png) |
| 01-pos-ticket-action | [1440](screens/01-pos-ticket-action-1440.png) | [1024](screens/01-pos-ticket-action-1024.png) | [768](screens/01-pos-ticket-action-768.png) | [390](screens/01-pos-ticket-action-390.png) |
| 01-summary | [1440](screens/01-summary-1440.png) | [1024](screens/01-summary-1024.png) | [768](screens/01-summary-768.png) | [390](screens/01-summary-390.png) |
| 01-summary-results | [1440](screens/01-summary-results-1440.png) | [1024](screens/01-summary-results-1024.png) | [768](screens/01-summary-results-768.png) | [390](screens/01-summary-results-390.png) |
| 02-cash-prepared | [1440](screens/02-cash-prepared-1440.png) | [1024](screens/02-cash-prepared-1024.png) | [768](screens/02-cash-prepared-768.png) | [390](screens/02-cash-prepared-390.png) |
| 02-cash-preview | [1440](screens/02-cash-preview-1440.png) | [1024](screens/02-cash-preview-1024.png) | [768](screens/02-cash-preview-768.png) | [390](screens/02-cash-preview-390.png) |
| 02-catalog | [1440](screens/02-catalog-1440.png) | [1024](screens/02-catalog-1024.png) | [768](screens/02-catalog-768.png) | [390](screens/02-catalog-390.png) |
| 02-sales | [1440](screens/02-sales-1440.png) | [1024](screens/02-sales-1024.png) | [768](screens/02-sales-768.png) | [390](screens/02-sales-390.png) |
| 02-sales-results | [1440](screens/02-sales-results-1440.png) | [1024](screens/02-sales-results-1024.png) | [768](screens/02-sales-results-768.png) | [390](screens/02-sales-results-390.png) |
| 03-categories | [1440](screens/03-categories-1440.png) | [1024](screens/03-categories-1024.png) | [768](screens/03-categories-768.png) | [390](screens/03-categories-390.png) |
| 03-mixed-prepared | [1440](screens/03-mixed-prepared-1440.png) | [1024](screens/03-mixed-prepared-1024.png) | [768](screens/03-mixed-prepared-768.png) | [390](screens/03-mixed-prepared-390.png) |
| 03-mixed-preview | [1440](screens/03-mixed-preview-1440.png) | [1024](screens/03-mixed-preview-1024.png) | [768](screens/03-mixed-preview-768.png) | [390](screens/03-mixed-preview-390.png) |
| 03-products | [1440](screens/03-products-1440.png) | [1024](screens/03-products-1024.png) | [768](screens/03-products-768.png) | [390](screens/03-products-390.png) |
| 03-products-results | [1440](screens/03-products-results-1440.png) | [1024](screens/03-products-results-1024.png) | [768](screens/03-products-results-768.png) | [390](screens/03-products-results-390.png) |
| 04-fixed-prepared | [1440](screens/04-fixed-prepared-1440.png) | [1024](screens/04-fixed-prepared-1024.png) | [768](screens/04-fixed-prepared-768.png) | [390](screens/04-fixed-prepared-390.png) |
| 04-fixed-preview | [1440](screens/04-fixed-preview-1440.png) | [1024](screens/04-fixed-preview-1024.png) | [768](screens/04-fixed-preview-768.png) | [390](screens/04-fixed-preview-390.png) |
| 04-payments | [1440](screens/04-payments-1440.png) | [1024](screens/04-payments-1024.png) | [768](screens/04-payments-768.png) | [390](screens/04-payments-390.png) |
| 04-payments-results | [1440](screens/04-payments-results-1440.png) | [1024](screens/04-payments-results-1024.png) | [768](screens/04-payments-results-768.png) | [390](screens/04-payments-results-390.png) |
| 04-reasons-empty | [1440](screens/04-reasons-empty-1440.png) | [1024](screens/04-reasons-empty-1024.png) | [768](screens/04-reasons-empty-768.png) | [390](screens/04-reasons-empty-390.png) |
| 05-cash | [1440](screens/05-cash-1440.png) | [1024](screens/05-cash-1024.png) | [768](screens/05-cash-768.png) | [390](screens/05-cash-390.png) |
| 05-cash-movements | [1440](screens/05-cash-movements-1440.png) | [1024](screens/05-cash-movements-1024.png) | [768](screens/05-cash-movements-768.png) | [390](screens/05-cash-movements-390.png) |
| 05-cash-movements-results | [1440](screens/05-cash-movements-results-1440.png) | [1024](screens/05-cash-movements-results-1024.png) | [768](screens/05-cash-movements-results-768.png) | [390](screens/05-cash-movements-results-390.png) |
| 05-percent-prepared | [1440](screens/05-percent-prepared-1440.png) | [1024](screens/05-percent-prepared-1024.png) | [768](screens/05-percent-prepared-768.png) | [390](screens/05-percent-prepared-390.png) |
| 05-percent-preview | [1440](screens/05-percent-preview-1440.png) | [1024](screens/05-percent-preview-1024.png) | [768](screens/05-percent-preview-768.png) | [390](screens/05-percent-preview-390.png) |
| 06-cash-sessions | [1440](screens/06-cash-sessions-1440.png) | [1024](screens/06-cash-sessions-1024.png) | [768](screens/06-cash-sessions-768.png) | [390](screens/06-cash-sessions-390.png) |
| 06-cash-sessions-results | [1440](screens/06-cash-sessions-results-1440.png) | [1024](screens/06-cash-sessions-results-1024.png) | [768](screens/06-cash-sessions-results-768.png) | [390](screens/06-cash-sessions-results-390.png) |
| 06-pos-open | [1440](screens/06-pos-open-1440.png) | [1024](screens/06-pos-open-1024.png) | [768](screens/06-pos-open-768.png) | [390](screens/06-pos-open-390.png) |
| 06-zero_percent-prepared | [1440](screens/06-zero_percent-prepared-1440.png) | [1024](screens/06-zero_percent-prepared-1024.png) | [768](screens/06-zero_percent-prepared-768.png) | [390](screens/06-zero_percent-prepared-390.png) |
| 06-zero_percent-preview | [1440](screens/06-zero_percent-preview-1440.png) | [1024](screens/06-zero_percent-preview-1024.png) | [768](screens/06-zero_percent-preview-768.png) | [390](screens/06-zero_percent-preview-390.png) |
| 07-cash-reconciliations | [1440](screens/07-cash-reconciliations-1440.png) | [1024](screens/07-cash-reconciliations-1024.png) | [768](screens/07-cash-reconciliations-768.png) | [390](screens/07-cash-reconciliations-390.png) |
| 07-cash-reconciliations-results | [1440](screens/07-cash-reconciliations-results-1440.png) | [1024](screens/07-cash-reconciliations-results-1024.png) | [768](screens/07-cash-reconciliations-results-768.png) | [390](screens/07-cash-reconciliations-results-390.png) |
| 07-discount-controls | [1440](screens/07-discount-controls-1440.png) | [1024](screens/07-discount-controls-1024.png) | [768](screens/07-discount-controls-768.png) | [390](screens/07-discount-controls-390.png) |
| 07-zero_fixed-prepared | [1440](screens/07-zero_fixed-prepared-1440.png) | [1024](screens/07-zero_fixed-prepared-1024.png) | [768](screens/07-zero_fixed-prepared-768.png) | [390](screens/07-zero_fixed-prepared-390.png) |
| 07-zero_fixed-preview | [1440](screens/07-zero_fixed-preview-1440.png) | [1024](screens/07-zero_fixed-preview-1024.png) | [768](screens/07-zero_fixed-preview-768.png) | [390](screens/07-zero_fixed-preview-390.png) |
| 08-active-correction | [1440](screens/08-active-correction-1440.png) | [1024](screens/08-active-correction-1024.png) | [768](screens/08-active-correction-768.png) | [390](screens/08-active-correction-390.png) |
| 08-corrected-prepared | [1440](screens/08-corrected-prepared-1440.png) | [1024](screens/08-corrected-prepared-1024.png) | [768](screens/08-corrected-prepared-768.png) | [390](screens/08-corrected-prepared-390.png) |
| 08-corrected-preview | [1440](screens/08-corrected-preview-1440.png) | [1024](screens/08-corrected-preview-1024.png) | [768](screens/08-corrected-preview-768.png) | [390](screens/08-corrected-preview-390.png) |
| 08-discounts | [1440](screens/08-discounts-1440.png) | [1024](screens/08-discounts-1024.png) | [768](screens/08-discounts-768.png) | [390](screens/08-discounts-390.png) |
| 08-discounts-results | [1440](screens/08-discounts-results-1440.png) | [1024](screens/08-discounts-results-1024.png) | [768](screens/08-discounts-results-768.png) | [390](screens/08-discounts-results-390.png) |
| 09-corrections | [1440](screens/09-corrections-1440.png) | [1024](screens/09-corrections-1024.png) | [768](screens/09-corrections-768.png) | [390](screens/09-corrections-390.png) |
| 09-corrections-results | [1440](screens/09-corrections-results-1440.png) | [1024](screens/09-corrections-results-1024.png) | [768](screens/09-corrections-results-768.png) | [390](screens/09-corrections-results-390.png) |
| 09-long-prepared | [1440](screens/09-long-prepared-1440.png) | [1024](screens/09-long-prepared-1024.png) | [768](screens/09-long-prepared-768.png) | [390](screens/09-long-prepared-390.png) |
| 09-long-preview | [1440](screens/09-long-preview-1440.png) | [1024](screens/09-long-preview-1024.png) | [768](screens/09-long-preview-768.png) | [390](screens/09-long-preview-390.png) |
| 09-paid-corrected | [1440](screens/09-paid-corrected-1440.png) | [1024](screens/09-paid-corrected-1024.png) | [768](screens/09-paid-corrected-768.png) | [390](screens/09-paid-corrected-390.png) |
| 10-card-prepared | [1440](screens/10-card-prepared-1440.png) | [1024](screens/10-card-prepared-1024.png) | [768](screens/10-card-prepared-768.png) | [390](screens/10-card-prepared-390.png) |
| 10-card-preview | [1440](screens/10-card-preview-1440.png) | [1024](screens/10-card-preview-1024.png) | [768](screens/10-card-preview-768.png) | [390](screens/10-card-preview-390.png) |
| 10-discount-validation | [1440](screens/10-discount-validation-1440.png) | [1024](screens/10-discount-validation-1024.png) | [768](screens/10-discount-validation-768.png) | [390](screens/10-discount-validation-390.png) |
| 10-paging | [1440](screens/10-paging-1440.png) | [1024](screens/10-paging-1024.png) | [768](screens/10-paging-768.png) | [390](screens/10-paging-390.png) |
| 11-discount-double-click | [1440](screens/11-discount-double-click-1440.png) | [1024](screens/11-discount-double-click-1024.png) | [768](screens/11-discount-double-click-768.png) | [390](screens/11-discount-double-click-390.png) |
| 11-empty | [1440](screens/11-empty-1440.png) | [1024](screens/11-empty-1024.png) | [768](screens/11-empty-768.png) | [390](screens/11-empty-390.png) |
| 11-transfer-prepared | [1440](screens/11-transfer-prepared-1440.png) | [1024](screens/11-transfer-prepared-1024.png) | [768](screens/11-transfer-prepared-768.png) | [390](screens/11-transfer-prepared-390.png) |
| 11-transfer-preview | [1440](screens/11-transfer-preview-1440.png) | [1024](screens/11-transfer-preview-1024.png) | [768](screens/11-transfer-preview-768.png) | [390](screens/11-transfer-preview-390.png) |
| 12-discount-response-recovered | [1440](screens/12-discount-response-recovered-1440.png) | [1024](screens/12-discount-response-recovered-1024.png) | [768](screens/12-discount-response-recovered-768.png) | [390](screens/12-discount-response-recovered-390.png) |
| 12-invalid | [1440](screens/12-invalid-1440.png) | [1024](screens/12-invalid-1024.png) | [768](screens/12-invalid-768.png) | [390](screens/12-invalid-390.png) |
| 12-other-prepared | [1440](screens/12-other-prepared-1440.png) | [1024](screens/12-other-prepared-1024.png) | [768](screens/12-other-prepared-768.png) | [390](screens/12-other-prepared-390.png) |
| 12-other-preview | [1440](screens/12-other-preview-1440.png) | [1024](screens/12-other-preview-1024.png) | [768](screens/12-other-preview-768.png) | [390](screens/12-other-preview-390.png) |
| 13-discount-removed | [1440](screens/13-discount-removed-1440.png) | [1024](screens/13-discount-removed-1024.png) | [768](screens/13-discount-removed-768.png) | [390](screens/13-discount-removed-390.png) |
| 13-filtered | [1440](screens/13-filtered-1440.png) | [1024](screens/13-filtered-1024.png) | [768](screens/13-filtered-768.png) | [390](screens/13-filtered-390.png) |
| 13-long-ticket-bottom | [1440](screens/13-long-ticket-bottom-1440.png) | [1024](screens/13-long-ticket-bottom-1024.png) | [768](screens/13-long-ticket-bottom-768.png) | [390](screens/13-long-ticket-bottom-390.png) |
| 14-blocked-active | [1440](screens/14-blocked-active-1440.png) | [1024](screens/14-blocked-active-1024.png) | [768](screens/14-blocked-active-768.png) | [390](screens/14-blocked-active-390.png) |
| 14-blocked-open | [1440](screens/14-blocked-open-1440.png) | [1024](screens/14-blocked-open-1024.png) | [768](screens/14-blocked-open-768.png) | [390](screens/14-blocked-open-390.png) |
| 14-blocked-paused | [1440](screens/14-blocked-paused-1440.png) | [1024](screens/14-blocked-paused-1024.png) | [768](screens/14-blocked-paused-768.png) | [390](screens/14-blocked-paused-390.png) |
| 14-correction-open | [1440](screens/14-correction-open-1440.png) | [1024](screens/14-correction-open-1024.png) | [768](screens/14-correction-open-768.png) | [390](screens/14-correction-open-390.png) |
| 14-forbidden | [1440](screens/14-forbidden-1440.png) | [1024](screens/14-forbidden-1024.png) | [768](screens/14-forbidden-768.png) | [390](screens/14-forbidden-390.png) |
| 15-closed | [1440](screens/15-closed-1440.png) | [1024](screens/15-closed-1024.png) | [768](screens/15-closed-768.png) | [390](screens/15-closed-390.png) |
| 15-correction-draft | [1440](screens/15-correction-draft-1440.png) | [1024](screens/15-correction-draft-1024.png) | [768](screens/15-correction-draft-768.png) | [390](screens/15-correction-draft-390.png) |
| 15-keyboard | [1440](screens/15-keyboard-1440.png) | [1024](screens/15-keyboard-1024.png) | [768](screens/15-keyboard-768.png) | [390](screens/15-keyboard-390.png) |
| 16-correction-review | [1440](screens/16-correction-review-1440.png) | [1024](screens/16-correction-review-1024.png) | [768](screens/16-correction-review-768.png) | [390](screens/16-correction-review-390.png) |
| 16-two-sessions | [1440](screens/16-two-sessions-1440.png) | [1024](screens/16-two-sessions-1024.png) | [768](screens/16-two-sessions-768.png) | [390](screens/16-two-sessions-390.png) |
| 16-waiter-view-only | [1440](screens/16-waiter-view-only-1440.png) | [1024](screens/16-waiter-view-only-1024.png) | [768](screens/16-waiter-view-only-768.png) | [390](screens/16-waiter-view-only-390.png) |
| 17-correction-confirmed | [1440](screens/17-correction-confirmed-1440.png) | [1024](screens/17-correction-confirmed-1024.png) | [768](screens/17-correction-confirmed-768.png) | [390](screens/17-correction-confirmed-390.png) |
| 17-pin-reprint | [1440](screens/17-pin-reprint-1440.png) | [1024](screens/17-pin-reprint-1024.png) | [768](screens/17-pin-reprint-768.png) | [390](screens/17-pin-reprint-390.png) |
| 18-corrected-receipt | [1440](screens/18-corrected-receipt-1440.png) | [1024](screens/18-corrected-receipt-1024.png) | [768](screens/18-corrected-receipt-768.png) | [390](screens/18-corrected-receipt-390.png) |
| 19-empty-reason-cancel | [1440](screens/19-empty-reason-cancel-1440.png) | [1024](screens/19-empty-reason-cancel-1024.png) | [768](screens/19-empty-reason-cancel-768.png) | [390](screens/19-empty-reason-cancel-390.png) |
| 19-empty-reason-remove | [1440](screens/19-empty-reason-remove-1440.png) | [1024](screens/19-empty-reason-remove-1024.png) | [768](screens/19-empty-reason-remove-768.png) | [390](screens/19-empty-reason-remove-390.png) |
| 20-reasons-created | [1440](screens/20-reasons-created-1440.png) | [1024](screens/20-reasons-created-1024.png) | [768](screens/20-reasons-created-768.png) | [390](screens/20-reasons-created-390.png) |
| 21-line-removed | [1440](screens/21-line-removed-1440.png) | [1024](screens/21-line-removed-1024.png) | [768](screens/21-line-removed-768.png) | [390](screens/21-line-removed-390.png) |
| 22-sale-cancelled | [1440](screens/22-sale-cancelled-1440.png) | [1024](screens/22-sale-cancelled-1024.png) | [768](screens/22-sale-cancelled-768.png) | [390](screens/22-sale-cancelled-390.png) |
| 23-product-layout-after | [1440](screens/23-product-layout-after-1440.png) | [1024](screens/23-product-layout-after-1024.png) | [768](screens/23-product-layout-after-768.png) | [390](screens/23-product-layout-after-390.png) |
| 23-product-layout-before | [1440](screens/23-product-layout-before-1440.png) | [1024](screens/23-product-layout-before-1024.png) | [768](screens/23-product-layout-before-768.png) | [390](screens/23-product-layout-before-390.png) |
| 24-native-cross-origin-csrf-419.png | [1440](screens/24-native-cross-origin-csrf-419.png) | — | — | — |
| 25-final-pos-clean | [1440](screens/25-final-pos-clean-1440.png) | [1024](screens/25-final-pos-clean-1024.png) | [768](screens/25-final-pos-clean-768.png) | [390](screens/25-final-pos-clean-390.png) |
| 25-final-ticket-action | [1440](screens/25-final-ticket-action-1440.png) | [1024](screens/25-final-ticket-action-1024.png) | [768](screens/25-final-ticket-action-768.png) | [390](screens/25-final-ticket-action-390.png) |
| 26-final-closed | [1440](screens/26-final-closed-1440.png) | [1024](screens/26-final-closed-1024.png) | [768](screens/26-final-closed-768.png) | [390](screens/26-final-closed-390.png) |
| 27-final-sessions | [1440](screens/27-final-sessions-1440.png) | [1024](screens/27-final-sessions-1024.png) | [768](screens/27-final-sessions-768.png) | [390](screens/27-final-sessions-390.png) |

## Contactos de revisión

[contact-01.png](rendered/ui/contact-01.png)
[contact-02.png](rendered/ui/contact-02.png)
[contact-03.png](rendered/ui/contact-03.png)
[contact-04.png](rendered/ui/contact-04.png)
[contact-05.png](rendered/ui/contact-05.png)
[contact-06.png](rendered/ui/contact-06.png)
[contact-07.png](rendered/ui/contact-07.png)
[contact-08.png](rendered/ui/contact-08.png)
[contact-09.png](rendered/ui/contact-09.png)
[contact-10.png](rendered/ui/contact-10.png)
[contact-11.png](rendered/ui/contact-11.png)
[contact-12.png](rendered/ui/contact-12.png)
[contact-13.png](rendered/ui/contact-13.png)
[contact-14.png](rendered/ui/contact-14.png)
[contact-15.png](rendered/ui/contact-15.png)
