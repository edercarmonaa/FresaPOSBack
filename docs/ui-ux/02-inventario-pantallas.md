# UI-00 — Inventario de pantallas y componentes

Estado: **revisión estática completada; revisión visual parcial**. Inventario del código actual, no de funcionalidades planeadas. Cada archivo Blade se leyó para extraer estructura, formularios, estilos, scripts, rutas, guards y bindings; esto no sustituye inspección visual de todos sus estados.

## Pantallas y familias de rutas

| ID | Ruta | Pantalla/estados | Vista principal | Acceso existente | Evidencia/alcance |
| --- | --- | --- | --- | --- | --- |
| S01 | / | Inicio técnico | resources/views/welcome.blade.php | Público | Actual: Chrome/Edge; sin dashboard ejecutivo |
| S02 | /login | Inicio de sesión | Modules/Auth/resources/views/livewire-login.blade.php | Público; no-store | Actual: Chrome/Edge; sin envío ni credenciales |
| S03 | /account | Mi acceso, PIN, terminal y cambio de empleado | Modules/Auth/resources/views/account.blade.php | auth.access.web; controles propios | Revisión estática; E2E protegido pendiente |
| S04 | /auth/password | Cambio de contraseña | Modules/Auth/resources/views/password.blade.php | auth.access.web | Revisión estática; no enviar formulario |
| S05 | /admin | Administración de sesiones | Modules/Auth/resources/views/admin.blade.php | auth.access.web + users.view | No CRUD completo de usuarios/roles |
| S06 | /admin/catalog/categories | Categorías: lista/alta/edición/estado | Modules/Catalog/resources/views/livewire-categories.blade.php | CatalogAdminAccess; categorías.manage | Estático; confirmaciones y paginación existentes |
| S07 | /admin/catalog/products | Productos: lista/alta/edición/imagen/papelera | Modules/Catalog/resources/views/livewire-products.blade.php | CatalogAdminAccess + capacidades específicas | Estático; subida, restauración y filtros existentes |
| S08 | /admin/sales/reasons | Motivos autorizados | Modules/Sales/resources/views/livewire/reason-administration.blade.php | PosAccess + settings.update | Estático; catálogo de motivos, no configuración general |
| S09 | /pos | Mostrador, mesas, consumo y venta | Modules/Sales/resources/views/livewire/point-of-sale.blade.php | PosAccess + permisos por operación | Estático; no operar base real |
| S10 | /cash | Caja, movimientos, arqueo e historial | Modules/Cash/resources/views/livewire/cash-desk.blade.php | CashAccess + capacidades por operación | Estático; esperado permanece oculto durante conteo |
| S11 | /reports | Resumen operativo/financiero | Modules/Reports/resources/views/index.blade.php | auth.access.web + autorización del servicio | Estático; datos existentes, sin consultas nuevas |
| S12 | /reports/{report} | Nueve variantes de reportes | Modules/Reports/resources/views/index.blade.php | Permisos Reports y módulos propietarios | summary, sales, products, payments, cash-movements, cash-sessions, cash-reconciliations, discounts, corrections |
| X01 | /pos/sales/{id}/receipt | Ticket y preparación de impresión | Modules/Tickets/resources/views/receipt.blade.php | Auth y permisos Tickets | Inventariado; excluido de modificación por alcance |

Hay 12 familias en alcance, contando /reports y su ruta parametrizada por separado, y una familia de tickets excluida. Las nueve variantes comparten plantilla; no son nueve implementaciones independientes. /up y /api/v1/ping son diagnósticos, no pantallas administrativas.

## Todos los archivos Blade propios (38)

Nivel 1 permite solamente presentación y exige preservar directivas; nivel 2 requiere autorización expresa para el archivo mixto; nivel 3 está excluido. **En UI-00 todos permanecen de solo lectura.** Un archivo de nivel 1 no autoriza cambios en permisos, campos, eventos, cálculos ni confirmaciones.

| Archivo | Nivel | Formularios | Tablas | Bloques CSS/JS | Bindings wire |
| --- | --- | --- | --- | --- | --- |
| `Modules/Auth/resources/views/account.blade.php` | 1 | 5 | 0 | 0/0 | — |
| `Modules/Auth/resources/views/admin.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Auth/resources/views/livewire-login.blade.php` | 1 | 1 | 0 | 0/0 | Sí |
| `Modules/Auth/resources/views/livewire-sessions.blade.php` | 1 | 1 | 1 | 0/0 | Sí |
| `Modules/Auth/resources/views/login.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Auth/resources/views/password.blade.php` | 1 | 2 | 0 | 0/0 | — |
| `Modules/Cash/resources/views/desk.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Cash/resources/views/livewire/cash-desk.blade.php` | 2 | 1 | 0 | 1/1 | Sí |
| `Modules/Cash/resources/views/livewire/closing-panel.blade.php` | 1 | 2 | 0 | 1/0 | Sí |
| `Modules/Cash/resources/views/livewire/movements-panel.blade.php` | 1 | 1 | 1 | 1/0 | Sí |
| `Modules/Cash/resources/views/navigation.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Catalog/resources/views/categories.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Catalog/resources/views/field-error.blade.php` | 2 | 0 | 0 | 0/0 | — |
| `Modules/Catalog/resources/views/livewire-categories.blade.php` | 1 | 1 | 1 | 0/0 | Sí |
| `Modules/Catalog/resources/views/livewire-products.blade.php` | 2 | 1 | 1 | 0/0 | Sí |
| `Modules/Catalog/resources/views/navigation.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Catalog/resources/views/pagination.blade.php` | 1 | 0 | 0 | 0/0 | Sí |
| `Modules/Catalog/resources/views/products.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Reports/resources/views/error.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Reports/resources/views/index.blade.php` | 2 | 2 | 1 | 1/1 | — |
| `Modules/Reports/resources/views/navigation.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Reports/resources/views/pdf.blade.php` | 3 | 0 | 2 | 1/0 | — |
| `Modules/Sales/resources/views/livewire/checkout-panel.blade.php` | 1 | 1 | 0 | 0/0 | Sí |
| `Modules/Sales/resources/views/livewire/correction-panel.blade.php` | 2 | 1 | 0 | 1/0 | Sí |
| `Modules/Sales/resources/views/livewire/discount-panel.blade.php` | 1 | 1 | 0 | 0/0 | Sí |
| `Modules/Sales/resources/views/livewire/point-of-sale.blade.php` | 2 | 3 | 0 | 0/1 | Sí |
| `Modules/Sales/resources/views/livewire/reason-administration.blade.php` | 1 | 1 | 0 | 1/0 | Sí |
| `Modules/Sales/resources/views/livewire/sale-actions-panel.blade.php` | 1 | 1 | 0 | 0/0 | Sí |
| `Modules/Sales/resources/views/navigation.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Sales/resources/views/partials/pagination.blade.php` | 1 | 0 | 0 | 0/0 | Sí |
| `Modules/Sales/resources/views/partials/styles.blade.php` | 1 | 0 | 0 | 1/0 | — |
| `Modules/Sales/resources/views/pos.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Sales/resources/views/reasons-navigation.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Sales/resources/views/reasons.blade.php` | 1 | 0 | 0 | 0/0 | — |
| `Modules/Tickets/resources/views/error.blade.php` | 3 | 0 | 0 | 0/0 | — |
| `Modules/Tickets/resources/views/receipt.blade.php` | 3 | 1 | 0 | 1/0 | — |
| `resources/views/layouts/app.blade.php` | 2 | 0 | 0 | 0/1 | — |
| `resources/views/welcome.blade.php` | 1 | 0 | 0 | 0/0 | — |

## Clases Livewire mixtas (14)

Estas clases contienen estado, validación, llamadas a servicios y controles de seguridad. Nivel 2 para cualquier intervención propuesta; sus porciones funcionales siguen prohibidas. Preferir modernizar sus vistas sin editar estas clases. Los nombres de métodos siguientes constituyen un inventario de puntos de interacción, no autorización para cambiarlos.

| Archivo | Líneas | Métodos públicos |
| --- | --- | --- |
| `Modules/Auth/app/Http/Livewire/Login.php` | 37 | login, render |
| `Modules/Auth/app/Http/Livewire/Sessions.php` | 69 | consult, revoke, revokeAll, revokeOthers, render |
| `Modules/Cash/app/Http/Livewire/CashClosingPanel.php` | 249 | mount, beginClosing, confirmClosing, queryClosing, showClosing, filterHistory, changePage, render |
| `Modules/Cash/app/Http/Livewire/CashDesk.php` | 107 | beginBlindCount, closingConfirmed, mount, refreshState, openSession, render |
| `Modules/Cash/app/Http/Livewire/CashMovementsPanel.php` | 151 | mount, resolveMovement, prepareMovement, recordMovement, changePage, render |
| `Modules/Catalog/app/Http/Livewire/CatalogScreen.php` | 92 | updated, gotoPage, closeForm |
| `Modules/Catalog/app/Http/Livewire/Categories.php` | 87 | create, edit, save, toggle, remove, render |
| `Modules/Catalog/app/Http/Livewire/Products.php` | 226 | _startUpload, _finishUpload, updatedImage, clearUpload, removeImage, closeForm, create, edit, save, toggle, remove, restore, render |
| `Modules/Sales/app/Http/Livewire/CheckoutPanel.php` | 268 | refreshTicket, mount, openCheckout, addPayment, removePayment, updated, calculate, confirmCheckout, queryCheckout, render |
| `Modules/Sales/app/Http/Livewire/CorrectionPanel.php` | 339 | mount, prepare, addPayment, removePayment, calculate, updated, submit, queryOperation, goPage, updatedSearch, render |
| `Modules/Sales/app/Http/Livewire/DiscountPanel.php` | 223 | mount, refreshTicket, prepare, calculate, confirm, queryOperation, updated, render |
| `Modules/Sales/app/Http/Livewire/PointOfSale.php` | 567 | mount, updated, selectMode, selectCounterState, clearSale, selectSale, selectTable, refreshSale, checkoutFinished, resolveIntent, startSale, selectCategory, clearSearch, gotoPage, chooseProduct, addProduct, editItem, adjustQuantity, saveQuantity, saveItemNote, saveSaleNote, pauseSale, resumeSale, render |
| `Modules/Sales/app/Http/Livewire/ReasonAdministration.php` | 204 | mount, create, edit, changeStatus, save, queryOperation, render, updated |
| `Modules/Sales/app/Http/Livewire/SaleActionsPanel.php` | 250 | refreshTicket, mount, prepareRemoval, prepareCancellation, reasonPage, confirmOperation, queryOperation, render |

## Estados y componentes que requieren regresión futura

- Auth: acceso, errores, contraseña obligatoria, PIN, terminal, cambio de empleado, sesiones activas/expiradas/revocadas y confirmaciones.
- Catalog: filtros, orden, tamaño de página, vacío, formulario abierto/cerrado, validación, producto eliminado/restaurado, carga y vista previa de imagen.
- Sales: mostrador/mesas, caja ausente, ventas abiertas/pausadas/pagadas, panel móvil, intención pendiente, conflicto, permisos revocados, error de red y sesión expirada.
- Cobro: pago simple/mixto, campos dinámicos, revisión, confirmación, recibo y descuento total. No cambiar importe, redondeo o cálculo.
- Operaciones autorizadas: PIN/contraseña, motivos, retirada/cancelación, descuento, corrección separada y cobro adicional. Mantener todos los límites y confirmaciones.
- Cash: sin aprovisionar, cerrada/abierta, apertura, entradas/salidas, pendientes, conteo ciego, resultado e historial. No revelar esperado anticipadamente.
- Reports: nueve tipos, filtros/fechas/orden/paginación, serie e indicadores reales, vacío y errores. PDF/XLSX y sus plantillas/generadores excluidos.
- Errores: Reports tiene error propio; errores generales dependen del framework y del manejo saneado de Core/Auth. No hay catálogo propio completo de páginas 403/404/419/500.

## Funcionalidades sin pantalla dedicada comprobada

No se encontró CRUD Web completo de usuarios/roles/permisos, inventario, devoluciones/reembolsos, notificaciones, auditoría, dispositivos, configuración general ni dashboard ejecutivo independiente. La existencia de módulos, permisos, contratos o API no acredita una pantalla. Las mesas se operan en POS; no se encontró mantenimiento Web independiente de mesas. No crear esas interfaces para rellenar el rediseño.

## Clasificación de todo el repositorio

[CSV por archivo seguido](qa/ui-00/file-classification.csv), [inventario técnico JSON](qa/ui-00/inventory.json), [rutas y middleware](qa/ui-00/routes.json). Modelos, servicios, repositorios, DTOs, contratos, migraciones, consultas, rutas y pruebas funcionales existentes: nivel 3. Composer/lock/configuración de QA: nivel 2 y sin cambios. Documentación histórica y estándares quedan preservados.
