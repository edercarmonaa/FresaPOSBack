# Catálogo real del Design System UI-02

**Pendiente de aceptación.** Abrir [http://127.0.0.1:8092](http://127.0.0.1:8092). Usa los ocho componentes Blade reales y los CSS/JS de public. No es una ruta Laravel ni una pantalla operativa.

PowerShell, desde la raíz, para regenerar y servir:

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-02/render-catalog.php
.\.tools\php\php.exe -S 127.0.0.1:8092 -t docs/ui-ux/catalogo/ui-02 docs/ui-ux/qa/ui-02/router.php
```

El exportador arranca el contenedor para renderizar Blade; no ejecuta consultas de negocio, migraciones ni cambios de configuración. El servidor posterior solo lee una lista cerrada de archivos estáticos: HTML/CSS/JS del catálogo y recursos UI de public. Las demás rutas y los métodos distintos de GET reciben 404. No expone la raíz del repositorio. Los recursos no se cargan aún en el layout funcional de 8080.

Cambiar Apariencia, recorrer controles con Tab, activar un botón de muestra y cambiar densidad de filas. Los botones solo muestran explicaciones; no hay guardado ni procesamiento. Los estados son variantes visuales declaradas como muestras. El ejemplo numérico es tipográfico, sin datos reales ni cálculos. Las credenciales, sesión y permisos no forman parte del catálogo.

`catalog.blade.php` es la fuente; `index.html` es su exportación. Al modificar un componente debe regenerarse el HTML antes de revisar. `catalog.css` y `catalog.js` contienen únicamente la distribución e interacciones de esta documentación, no los futuros layouts del producto. No requieren compilación o paquetes nuevos.

[Diseño](../../05-design-system.md) · [Tokens](../../06-design-tokens.md) · [Componentes](../../07-componentes.md) · [QA](../../qa/ui-02/README.md).
