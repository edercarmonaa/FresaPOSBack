# UI-00 — Matriz de trazabilidad

Los identificadores R remiten a los apartados del encargo; UI00-S al inventario de pantallas. Ningún cambio visual funcional se implementó. La matriz detallada incluye componente, riesgo, prueba, evidencia, observaciones y estado en [traceability.csv](qa/ui-00/traceability.csv). Las etapas futuras son propuestas sin autorización.

| Requisito | Etapa | Pantalla/ámbito | Ruta | Archivo | Resultado | Estado |
| --- | --- | --- | --- | --- | --- | --- |
| UI00-S01 | UI-00 | Inicio técnico | / | `resources/views/welcome.blade.php` | Actual: Chrome/Edge; sin dashboard ejecutivo | Estático completo; visual parcial |
| UI00-S02 | UI-00 | Inicio de sesión | /login | `Modules/Auth/resources/views/livewire-login.blade.php` | Actual: Chrome/Edge; sin envío ni credenciales | Estático completo; visual parcial |
| UI00-S03 | UI-00 | Mi acceso, PIN, terminal y cambio de empleado | /account | `Modules/Auth/resources/views/account.blade.php` | Revisión estática; E2E protegido pendiente | Estático completo; visual parcial |
| UI00-S04 | UI-00 | Cambio de contraseña | /auth/password | `Modules/Auth/resources/views/password.blade.php` | Revisión estática; no enviar formulario | Estático completo; visual parcial |
| UI00-S05 | UI-00 | Administración de sesiones | /admin | `Modules/Auth/resources/views/admin.blade.php` | No CRUD completo de usuarios/roles | Estático completo; visual parcial |
| UI00-S06 | UI-00 | Categorías: lista/alta/edición/estado | /admin/catalog/categories | `Modules/Catalog/resources/views/livewire-categories.blade.php` | Estático; confirmaciones y paginación existentes | Estático completo; visual parcial |
| UI00-S07 | UI-00 | Productos: lista/alta/edición/imagen/papelera | /admin/catalog/products | `Modules/Catalog/resources/views/livewire-products.blade.php` | Estático; subida, restauración y filtros existentes | Estático completo; visual parcial |
| UI00-S08 | UI-00 | Motivos autorizados | /admin/sales/reasons | `Modules/Sales/resources/views/livewire/reason-administration.blade.php` | Estático; catálogo de motivos, no configuración general | Estático completo; visual parcial |
| UI00-S09 | UI-00 | Mostrador, mesas, consumo y venta | /pos | `Modules/Sales/resources/views/livewire/point-of-sale.blade.php` | Estático; no operar base real | Estático completo; visual parcial |
| UI00-S10 | UI-00 | Caja, movimientos, arqueo e historial | /cash | `Modules/Cash/resources/views/livewire/cash-desk.blade.php` | Estático; esperado permanece oculto durante conteo | Estático completo; visual parcial |
| UI00-S11 | UI-00 | Resumen operativo/financiero | /reports | `Modules/Reports/resources/views/index.blade.php` | Estático; datos existentes, sin consultas nuevas | Estático completo; visual parcial |
| UI00-S12 | UI-00 | Nueve variantes de reportes | /reports/{report} | `Modules/Reports/resources/views/index.blade.php` | summary, sales, products, payments, cash-movements, cash-sessions, cash-reconciliations, discounts, corrections | Estático completo; visual parcial |
| UI00-X01 | UI-00 | Ticket y preparación de impresión | /pos/sales/{id}/receipt | `Modules/Tickets/resources/views/receipt.blade.php` | Inventariado; excluido de modificación por alcance | Excluido de edición |
| R04 | UI-00 | Auditoría técnica | — | `docs/ui-ux/` | Documentado | Pendiente de aceptación |
| R05 | UI-00 | Preservación Git | — | `docs/ui-ux/` | Verificado por SHA | Pendiente de aceptación |
| R06 | UI-00 | Clasificación | — | `docs/ui-ux/` | 2539 archivos | Pendiente de aceptación |
| R20 | UI-00 | Responsive inicial | — | `docs/ui-ux/` | Parcial: inicio/login | Pendiente de aceptación |
| R21 | UI-00 | Accesibilidad inicial | — | `docs/ui-ux/` | Parcial: login | Pendiente de aceptación |
| R25 | UI-00 | Rendimiento inicial | — | `docs/ui-ux/` | 5 muestras laboratorio | Pendiente de aceptación |
| R30 | UI-00 | Línea base | — | `docs/ui-ux/` | Ejecutada | Pendiente de aceptación |
| R32 | UI-00 | Documentación | — | `docs/ui-ux/` | Creada | Pendiente de aceptación |
| R33 | UI-00 | Trazabilidad | — | `docs/ui-ux/` | Documentada | Pendiente de aceptación |
| R37 | UI-00 | Puerta de aprobación | — | `docs/ui-ux/` | Pendiente de decisión | Pendiente de aceptación |
| R07-R08 | UI-01 | Identidad y dirección visual | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R09 | UI-02 | Design System | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R10-R22 | UI-03 | Tres temas y preferencias | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R11 | UI-02 | Tipografía/iconos | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R12-R13 | UI-03 | Layouts y navegación | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R14 | UI-05 | Resumen con datos existentes | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R15 | UI-08 | POS operativo | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R16-R19 | UI-06 | Tablas, formularios, paneles y mensajes | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R23 | UI-08 | Presentación de controles críticos | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R24 | UI-07 | Módulos existentes | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R26-R27 | UI-01 a UI-11 | Calidad visual y mantenimiento | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
| R31-R34-R36 | UI-11 | Aceptación final e informe | Solo rutas existentes | `Por definir por etapa` | Propuesto | No iniciado; requiere aprobación |
