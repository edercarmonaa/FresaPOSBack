# Fixtures de Caja · UI-07C

Las cuatro vistas Blade conservan las versiones anteriores a esta subetapa. Las comparaciones no cambian esas copias para acomodar resultados. `FixtureData` crea estados ficticios en memoria, incluidos permisos, apertura, movimientos pendientes/resueltos y cierres con diferencia firmada.

`source()` prepara el render aislado: sustituye los dos hijos Livewire por marcadores y retira el bloque `@script` operativo, que requiere contexto Livewire real. Una prueba independiente compara ese bloque y las etiquetas originales de los hijos byte por byte contra las vistas actuales. Los estados de Schema se simulan sin SQL; la integración SQL comprueba la composición real.

Las fixtures de navegador insertan las vistas reales de los hijos solamente donde quedan sus marcadores después de evaluar las condiciones del padre. Así, el estado de conteo no contiene el panel de movimientos ni el fondo inicial. El historial mostrado pertenece a una sesión anterior y está identificado como dato ficticio.

Los valores de `wire:model` se rellenan solo en el HTML de QA, sin hidratación, sesión ni acciones financieras. Los importes se entregan como cadenas, sin cálculos ni redondeo de presentación. Las etiquetas incluidas en nombres, conceptos y observaciones permiten comprobar el escape de contenido.
