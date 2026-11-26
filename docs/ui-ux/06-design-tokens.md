# Tokens visuales — UI 1.0

Fuente de verdad: [tokens.css](../../public/css/ui/tokens.css). Todos se declaran dentro de `.ui-root`; los semánticos oscuros usan `[data-ui-theme=dark]`. [Paleta aceptada](04-propuesta-visual.md).

| Nivel | Ejemplos | Responsabilidad |
|---|---|---|
| Primitivos | `--ui-blue-600`, `--ui-space-4`, `--ui-radius-md` | Valores de identidad y escala |
| Semánticos | `--ui-primary`, `--ui-surface`, `--ui-text`, `--ui-error` | Significado, independiente del tema |
| Componentes | `--ui-button-bg`, `--ui-field-border`, `--ui-card-radius` | Decisiones del componente |

## Paleta semántica

| Token (prefijo --ui-) | Claro | Oscuro |
|---|---|---|
| bg | #F3F5F9 | #0D1421 |
| surface / surface-soft | #FFFFFF / #F8FAFC | #151F30 / #1B283B |
| text / muted | #142137 / #526178 | #EDF2FA / #B1BFD2 |
| primary / on-primary | #1D4ED8 / #FFFFFF | #A7C6FF / #102448 |
| primary-hover | #1E40AF | #C4D9FF |
| selected / selected-text | #EAF1FF / #1E40AF | #233B63 / #BCD4FF |
| control-border / focus | #7F8FA5 / #2563EB | #657B97 / #B6D1FF |
| success / success-bg | #166534 / #E9F7EF | #9BE3B5 / #15382B |
| warning / warning-bg | #854D0E / #FFF5DF | #F2CF83 / #3F321A |
| error / error-bg | #B42338 / #FFF0F1 | #FFBAC8 / #41252E |
| info / info-bg | #1E40AF / #EAF1FF | #BCD4FF / #233B63 |

`line` es separador decorativo; los campos usan `control-border`, medido a mínimo 3:1 respecto a surface. Los estados deshabilitados tienen tokens propios; su contraste no se presenta como contraste de un control activo. Los 40 pares medidos figuran en QA, sin afirmar una auditoría total de colores o estados posibles.

## Escalas

| Grupo | Valores implementados |
|---|---|
| Espaciado space-1/2/3/4/5/6/8/10 | 4 / 8 / 12 / 16 / 20 / 24 / 32 / 40 px |
| Radios sm / md / full | 7 / 12 / 999 px |
| Tipografía xs / sm / md / lg / xl / 2xl / 3xl | .857 / .929 / 1 / 1.143 / 1.429 / 1.714 / 2.143 rem |
| Base / interlineado | 14 px / 1.5 |
| Pesos normal / medium / strong | 400 / 550 / 650 |
| Control mínimo | 44 px |
| Relleno vertical de fila compact / standard / comfortable | 10 / 16 / 22 px |
| Transición | 160 ms; 0 ms con movimiento reducido |
| Capas base / sticky / overlay / dialog / notice | 0 / 20 / 40 / 60 / 80 |

Los niveles de superposición son reserva visual; esta entrega no sustituye modales ni implementa overlays funcionales. Inter tiene respaldo del sistema y números tabulares mediante `ui-money`/`ui-numeric`. `ui-money` conserva un importe indivisible: en muestras con varios importes cada uno debe ir en su propio span para permitir reflujo entre ellos. No calcula ni reformatea valores.

## Breakpoints y consumo

Componentes: compacto hasta 767 px; desde 768 px mantiene presentación de escritorio cuando hay espacio. Catálogo documental: columnas simples hasta 767 px y cabecera adaptada hasta 1023 px. La matriz de verificación incorpora 1024, 1366, 1920 y 2560 px. Las variables CSS no se utilizan como condiciones de `@media`; los límites literales se mantienen documentados. Los layouts de producto definirán su distribución en UI-03, sin anticiparla aquí.

Para cambiar una acción, consumir el token del componente; para cambiar un significado en ambos temas, modificar el semántico y volver a medir sus pares. No aplicar colores de estado sin texto, ni usar el azul corporativo como sustituto de cada rol oscuro. Mantener los estilos visuales separados de importes, permisos y estados transaccionales.
