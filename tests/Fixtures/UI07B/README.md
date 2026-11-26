# Fixtures de UI-07B

Las dos vistas Blade conservan la pantalla anterior a UI-07B. No se editan para hacer pasar las pruebas. `FixtureData` aporta valores ficticios y estados deterministas para comparar atributos, textos, orden, confirmaciones y bloqueo de fieldsets; no consulta SQL.

La revisión de navegador rellena los valores asociados a `wire:model` únicamente dentro del HTML exportado. No hidrata Livewire, no inicia sesión y no ejecuta acciones de negocio. El nombre contiene texto con etiquetas para verificar su escape. Los avisos loading/offline se ocultan en reposo y se muestran explícitamente en las capturas de mensajes.
