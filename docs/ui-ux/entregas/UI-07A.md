# Entrega UI-07A · Catálogo

## 1. Identificación

UI-07A, 27/11/2026. **Pendiente de aceptación**. UI-06 aceptada al responder «continua» a la autorización de UI-07 comenzando por Catálogo.

## 2. Objetivo

Integrar los componentes compartidos en productos y categorías preservando íntegramente contratos y acciones existentes.

## 3. Alcance

Dos páginas y sus vistas Livewire, paginación y mensajes de campo. Presentación, pruebas nuevas y documentación. Formularios en línea y confirmaciones originales, sin nuevos modales o flujos.

## 4. Estado inicial

UI-06 disponible pero sin commit. Catálogo usaba controles y clases anteriores. Línea base Feature + Architecture: 312 / 1.287; Pint, Larastan y Composer pasan. No se ejecutó integración SQL antes de cambios. [Resultados](../qa/ui-07a/quality.json).

## 5. Estado Git

HEAD `be38e26`, main. Se preservan modificaciones y archivos no versionados de UI-06: huellas de 3.436 archivos al inicio. Sin staging, commits, pushes, ramas o despliegue. [Integridad](../qa/ui-07a/source-integrity.json).

## 6. Archivos creados

`public/css/ui/catalog.css`; `tests/Feature/CatalogPresentationTest.php`; `tests/Integration/CatalogPresentationHttpTest.php`; seis fixtures previas, fábrica y README en `tests/Fixtures/UI07A/`; [guía](../catalogo-ui07a.md), informe, QA y evidencias exclusivos.

## 7. Archivos modificados

Seis vistas de `Modules/Catalog/resources/views`: products, categories, livewire-products, livewire-categories, pagination y field-error. `docs/ui-ux/ESTADO.md` registra aprobación y estado. No cambian el layout, componentes UI-06 ni archivos funcionales de backend.

## 8. Justificación

Reutilizar controles, filtros, tabla y paginación; uniformar jerarquía, errores y acciones en ambos temas. Mantener mensajes nativos para conservar roles y anuncios. El precio no se recalcula ni cambia de tipo.

## 9. Evidencias visuales

155 PNG. [Antes](../evidencias/ui-07a/before-products-edit-1366.png), [edición final](../evidencias/ui-07a/review-chrome-products-edit-light-1366x768.png), [categorías oscuras móviles](../evidencias/ui-07a/review-chrome-categories-edit-dark-390x844.png). Fixtures ficticias explícitas, sin sesión ni operaciones. [Matriz](../qa/ui-07a/responsive.csv).

## 10. Resultados de pruebas

Baseline 312 / 1.287, 22,169 s, 90 MB. Final Feature + Architecture 319 / 1.349, 29,266 s, 90 MB, cero fallos/errores/omitidas. Integración final 49 / 858, 150,645 s, 82 MB, pasa. Nueva comparación 7 / 62 incluida. Pint, Larastan, Composer y plataforma: código cero. Primer intento SQL: 48 / 839, un fallo corregido conservando una clase. [Comandos y todos los intentos](../qa/ui-07a/README.md).

## 11. Accesibilidad

Labels, IDs, errores y regiones vivas preservados; headers con scope, caption y región de tabla enfocables; checkbox nativo con etiqueta de 44 px. Diecisiete checks principales, 36 de edición y siete adicionales pasan. Veintidós pares de contraste cumplen: mínimo texto 5,86:1 y no textual 3,29:1. No se certifica WCAG integral.

## 12. Responsive

108 casos en Chrome/Edge, claro/oscuro, siete referencias Chrome y dos Edge; 36 revisiones finales de edición. Sin desbordamientos de página o imágenes rotas, columnas conservadas con scroll local. Fuente raíz duplicada a 320 px pasa; no equivale a zoom nativo.

## 13. Rendimiento

CSS nuevo de 4.723 bytes sin comprimir; JS UI-06 reutilizado sin modificar. LCP local de fixture n=5, mediana antes/después 148/144 ms; rangos 128–160/128–176; CLS 0. No se infiere mejora causal o rendimiento de consultas reales. Sin paquetes o fuentes nuevos.

## 14. Integridad

Siete archivos iniciales cambian. Los 174 archivos previos de pruebas, diez protegidos y UI-06 se conservan, salvo el índice evolutivo. Backend, rutas, permisos, validaciones, precios, almacenamiento y API intactos. Pruebas SQL solo en fresapos_test, guardada; tras finalizar, cero tablas. No se verificaron hashes de filas operativas.

## 15. Defectos preexistentes

La línea base seleccionada no registra fallos. La entrega conserva dependencias y límites ambientales anteriores. Las incidencias de integración introducidas se corrigieron; no se atribuyen a defectos previos.

## 16. Incidencias

Compilación de @disabled en componentes corregida con bindings equivalentes; parser de etiquetas ajustado; ruta PHPUnit errónea sin ejecución; comparaciones de blancos/booleanos normalizadas. Clase de scroll restaurada tras un fallo de prueba existente. Contraste transitorio corregido localmente y borde de error restablecido frente al puente CSS. Logs iniciales, fallos y repeticiones conservados. Sin modificaciones de pruebas previas.

## 17. Riesgos

Regresión de campos, permisos y acciones mitigada mediante comparación de 60 pares y HTTP/Livewire real en SQL de pruebas. La revisión visual estática no valida hidratación ni recorrido autenticado completo del navegador. No se garantiza ausencia absoluta de defectos.

## 18. Limitaciones

Fixtures con bindings rellenados ficticiamente y loaders ocultos en reposo, sin SQL o Livewire operativo. SVG QA no es un formato nuevo admitido de subida. Solo Chrome/Edge headless. Cada regla final de borde fue revisada en los estados afectados; el resto conserva estilos de la matriz principal.

## 19. No ejecutado

Suite global de otras áreas no repetida por alcance. E2E autenticado en navegador, lectores de pantalla, Firefox/Safari, tacto real, zoom nativo, axe/Lighthouse/INP, Azure SQL y cPanel pendientes. No se afirman resultados históricos como actuales.

## 20. Criterios de aceptación

Presentación de productos/categorías integrada; permisos, formularios, imágenes, papelera y errores verificados donde se compararon y probaron; regresión y calidad pasan; responsive, contraste y documentación entregados. Aceptación del responsable y pendientes de entorno expresos.

## 21. Recomendaciones

Revisar las rutas reales de Catálogo con una cuenta autorizada. Aceptar o solicitar ajustes de UI-07A. Proponer UI-07B para Motivos autorizados de ventas, manteniendo su lógica y autorizaciones.

## 22. Propuesta de estado final

**Pendiente de aceptación de UI-07A.** Siguiente módulo no iniciado. [Índice](../ESTADO.md).
