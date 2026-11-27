# QA UI-08A

Ejecutar desde PowerShell en la ra?z. No se instalan dependencias.

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-08a/export-views.php
```

El exportador deshabilita todas las conexiones SQL en memoria. Produce 32 documentos ficticios de antes/despu?s, sin sesi?n operativa. Los hijos financieros se sustituyen por marcadores ?nicamente en estas fixtures. El bundle instalado de Livewire ejecuta Alpine real, sin snapshots ni hidrataci?n de componentes. El script operativo que necesita `$wire` no se ejecuta aqu?; se comprueba sin cambios y por regresi?n SQL.

El servidor PHP de QA usa el router de esta carpeta en 127.0.0.1:8101. Solo permite GET a archivos expl?citos. No crea rutas Laravel ni admite acciones financieras. El navegador de QA bloquea POST y destinos externos, salvo el recurso Tailwind ya utilizado por el layout.

```powershell
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-08a/browser-check.cjs
```

La reproducci?n requiere Playwright ya disponible en `.tools/node_modules` y Chrome/Edge locales. Node es herramienta de QA de esta m?quina, no requisito de ejecuci?n o hosting.

[Resultado del navegador](../../evidencias/ui-08a/browser-results.json), [calidad](quality.json), [integridad](source-integrity.json), [manifiesto](manifest.json), [verificaci?n de entrega](delivery-check.json). Los logs y JUnit de la ejecuci?n se conservan en esta carpeta. El manifiesto excluye el ?ndice vivo ESTADO.md y sus propios archivos de sellado.

Limitaciones: las fixtures no validan hidrataci?n Livewire ni una sesi?n autenticada completa. Las pruebas de integraci?n s? montan componentes reales y usan exclusivamente fresapos_test con salvaguarda. No se afirma certificaci?n WCAG integral.
