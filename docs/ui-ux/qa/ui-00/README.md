# UI-00 — Evidencias técnicas

Inventario y clasificación por responsabilidad, rutas, versiones, calidad, integridad, trazabilidad, condiciones y mediciones. Las credenciales, tokens, inputs, filas operativas y cuerpos de sesión no se recopilan. Los diez protegidos solo se publican como resultado booleano de comparación; los SHA privados permanecen en .tools/ui00-initial.json.

- [Inventario JSON](inventory.json) y [clasificación completa CSV](file-classification.csv).
- [Rutas actuales](routes.json), [versiones](versions.json), [calidad](quality.json), [integridad de repositorio](repository-integrity.json).
- [Trazabilidad completa](traceability.csv), [mediciones](performance-summary.json), [resumen visual](visual-summary.json), [condiciones de equipo](machine.json), [incidencias](tooling-incidents.json).
- [Informe y resultados](../../03-linea-base-funcional.md). JUnit/log privados: .tools/ui00-baseline-junit.xml y .tools/ui00-baseline.log. El resumen phpunit-summary.json se crea al terminar; no usar un documento provisional como resultado final.
- [Código documental del capturador](browser-audit.cjs.txt), [sonda de accesibilidad](accessibility-probe.cjs.txt). Son auxiliares de auditoría separados del producto, no scripts de build ni rutas de aplicación. Se preservan para poder revisar el procedimiento; requieren el Playwright ya existente y navegadores del sistema.

## Reproducción en el entorno actual

Desde PowerShell en raíz, ejecutar secuencialmente con los lanzadores existentes: composer test, composer lint, composer analyse, composer validate --strict y composer check-platform-reqs. Nunca iniciar dos runners SQL contra fresapos_test; respetar TestDatabaseGuard. Para capturas, copiar los dos archivos .txt documentales a .tools con nombres ui00-browser.cjs y ui00-accessibility.cjs y usar el ejecutable Node de QA ya disponible. Resolver rutas locales de herramientas antes de ejecutar; no instalar paquetes ni navegadores automáticamente. Los scripts referencian el Playwright privado de .tools/node_modules y localhost:8080, bloquean métodos distintos de GET y usan contextos anónimos. No incorporar herramientas privadas al hosting.

Los nombres de salida de capturas son estables: para una nueva auditoría se debe cambiar primero el directorio de evidencia para preservar esta línea base; no ejecutarlos sobre estos archivos históricos sin una decisión deliberada. La primera sonda de accesibilidad esperaba load y falló a 30 s; la versión documental refleja el segundo intento DOM/estilos que pasó, con el mismo límite. No es un reintento oculto.

## Manifiesto

manifest.json registra SHA-256 de todos los archivos de docs/ui-ux salvo el propio manifiesto. Es una comprobación de integridad de artefactos, no una firma de aceptación. La aceptación sigue pendiente del responsable.

[Resultado real de la suite terminada](phpunit-summary.json).
