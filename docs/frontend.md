# Frontend mínimo

Un único layout `resources/views/layouts/app.blade.php` incluye `@tailwindcss/browser@4.1.18` de jsDelivr. `welcome` extiende el layout y no contiene negocio. No hay package.json, Vite, Node ni npm. La versión CDN está fijada completamente, sin alias `@4` que cambie de contenido entre instalaciones.

Livewire 4 se instala con Composer y autodetección de Laravel. `@livewireStyles` / `@livewireScripts` sirven los recursos desde la aplicación, sin CDN JavaScript externo. No es necesario un componente ficticio en la página de arranque. PHPUnit solicita la página y el recurso JS real de Livewire y comprueba sus respuestas HTTP; esto verifica el render del servidor y la entrega del asset, no la ejecución de JavaScript en un navegador.

## Limitaciones aceptadas para esta base

- Internet: una primera visita sin conexión o sin caché no tendrá Tailwind. La disponibilidad del CDN y las políticas de red del local pueden afectar el POS. Esto no proporciona funcionamiento offline de la aplicación.
- La [documentación oficial](https://tailwindcss.com/docs/installation/play-cdn) reserva Play CDN para desarrollo; no recomienda producción. Debe resolverse antes de operar un POS real.
- Rendimiento: descarga y procesamiento/compilación en el navegador, consumo de CPU y posible aparición inicial de contenido sin estilos.
- CSP: habrá que permitir el origen CDN y estudiar los estilos generados dinámicamente, estilos/scripts de Livewire y nonces. No se entrega una CSP permisiva como solución automática. La futura política se prueba con CSP Report-Only antes de bloquear recursos.
- Fijar versión reduce cambios inesperados pero no garantiza disponibilidad ni integridad por sí solo; cualquier adopción de SRI debe comprobarse contra el archivo exacto y contemplar la política CSP.

## Migración futura, fuera de esta entrega

Cuando se autorice: compilar Tailwind en CI/local mediante Vite o binario CLI independiente (opción sin Node en hosting). Definir escaneo de Blade, PHP y futuros módulos; generar CSS estático versionado en public, reemplazar script CDN por enlace al CSS y subir el artefacto construido. Probar clases dinámicas/safelist, tamaño final, Livewire, caché, ausencia de solicitudes CDN y funcionamiento con red interrumpida. Endurecer CSP entonces. No se requiere instalar herramientas de construcción en el hosting.
