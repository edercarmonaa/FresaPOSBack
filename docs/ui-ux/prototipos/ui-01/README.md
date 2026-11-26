# Prototipo separado UI-01

**Pendiente de aceptación.** Maqueta estática sin Laravel, SQL, autenticación, API ni operaciones reales. Está deliberadamente identificada en todas las vistas.

Abrir [http://127.0.0.1:8091](http://127.0.0.1:8091). Elegir Reportes, Productos, Punto de venta, Acceso o Componentes en el selector superior. Cambiar Claro/Oscuro/Automático; el icono de ajustes abre densidad, texto y movimiento. Probar menú colapsado, pestañas, vistas previas, Escape y resumen móvil del POS.

Si el servidor no está disponible, desde la raíz del repositorio, en PowerShell:

```powershell
.\.tools\php\php.exe -S 127.0.0.1:8091 -t docs/ui-ux/prototipos/ui-01
```

Este comando sirve exclusivamente la carpeta documental. La aplicación operativa sigue en el puerto 8080. No requiere Node, compilación, Docker o dependencias nuevas. También puede servirse mediante PHP 8.3 local disponible en PATH.

No ingresar credenciales: los campos de acceso son de solo lectura. Productos y precios son ficticios y estáticos. Las métricas de Reportes están vacías para evitar confundirlas con datos reales. Guardar, confirmar y procesar pagos no están implementados. Las búsquedas y selecciones solo demuestran apariencia.

Archivos: `index.html`, `prototype.css`, `prototype.js`, fuente local y licencia en `assets/fonts/`. Código fuente de la aplicación intacto. La barra de identificación, los selectores de pantallas y la guía pertenecen a la revisión y no al futuro producto.

Ver [propuesta](../../04-propuesta-visual.md), [informe](../../entregas/UI-01.md) y [QA](../../qa/ui-01/README.md). Aceptar este prototipo no significa que esté integrado ni que se haya validado toda la aplicación con esta apariencia.
