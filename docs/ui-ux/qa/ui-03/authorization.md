# Autorizaciones UI-03

26/11/2026. El responsable aceptó UI-02 y autorizó iniciar UI-03 con «adelanyte continua».

La inspección detectó una incompatibilidad de etapa en la última prueba de DesignSystemRenderingTest: exigía que el inicio no cargara el sistema visual. El encargo protegía las pruebas existentes, por lo que se documentó el [punto de control](punto-de-control.md) y un [patch concreto](required-test-adjustment.patch), sin aplicarlo entonces.

Pregunta presentada: «Para integrar UI-03, ¿autorizas actualizar únicamente esa prueba para exigir que cargue los recursos locales, conservando intactas las demás pruebas y agregando comprobaciones de permisos y navegación?»

Respuesta explícita recibida: **«Sí, actualizar solo esa prueba»**.

Se aplicó únicamente esa excepción: la última prueba pasa a comprobar el cargado de recursos locales y ui-root. Las cinco pruebas previas de ese archivo permanecen iguales; el resto de pruebas existentes no se cambia. La cobertura de navegación y layout se agrega en LayoutNavigationTest. El punto de control conserva su estado histórico y no es el estado de cierre.

No se autorizó modificar rutas, servicios, modelos, configuración, contratos, procesos de seguridad/financieros, otras pruebas, dependencias, ramas, commits, push o despliegues. La excepción no se interpreta como permiso general para tales cambios.
