# Evidencias locales — 1.5d.2b

17 de noviembre de 2026. Chrome 157 real mediante CDP en modo headless, PHP/Livewire y SQL Server Express reales. Se usó exclusivamente fresapos_test, usuarios ficticios y servidor QA aislado; no se registraron movimientos operativos. Tras revisar, se cerraron los procesos QA y se limpiaron sus tablas: cero restantes.

23 PNG, con cuatro resoluciones: 1440×900, 1024×768, 768×1024 y 390×844. Familias closed, form, history y read-only cubren los cuatro tamaños. Las demás capturas documentan doble clic, pérdida de respuesta, recuperación, saldo insuficiente, foco de teclado, carga y mesero sin acceso. Se inspeccionaron visualmente [formulario de escritorio](form-1440.png), [historial móvil](history-390.png) y [lectura en tableta](read-only-768.png).

- [Reporte Chrome](visual-report.json): sin errores JavaScript, desbordamiento horizontal ni botones visibles menores de 44 px en las capturas.
- [Carga](loading-evidence.json): indicador pendiente y dos controles deshabilitados bajo latencia inducida.
- [SQL del fixture](sql-evidence.json): 100.0000 + 25.5000 − 5.1250 = 120.3750; dos movimientos, dos auditorías, cero ventas/pagos. Doble clic y recuperación no duplicaron registros.
- [OpenAPI](finance-1.5d2b-openapi-final.json): 51 operaciones y 255 respuestas reales contrastadas, cero errores/advertencias.
- [Integridad operativa](finance-1.5d2b-final-integrity-report.json): veinte tablas previas y diez archivos protegidos intactos, migraciones originales conservadas.
- [HTTP operativo](finance-1.5d2b-health.json): disponibilidad y acceso protegido después de migrar.
- Nueve archivos finance-1.5d2b-race-*.json: procesos HTTP y SPID SQL independientes; entradas/salidas simultáneas, saldo insuficiente, transición de sesión, replay/conflicto, rollback, respuesta perdida y sesión SQL terminada después del commit. Todos sobre fresapos_test.

La respuesta descartada por Chrome ocurrió después de la respuesta del servidor; no prueba un corte físico de red. La carrera connection_after_commit sí termina la conexión SQL del worker de prueba y comprueba reconexión/replay. La transición closed pertenece únicamente al fixture, sin habilitar cierre productivo.

El motor de automatización Windows no inició en dos intentos; Chrome permitió completar la revisión. Los scripts y perfiles privados permanecen en .tools, ignorado por Git. No se incluyen tokens, credenciales operativas, contenido del respaldo ni rutas privadas del servidor.
