# Nuevos Requerimientos POS - 2026-03-30

## Resumen
Requerimientos extraídos de sesión de revisión de UX/UI del módulo de cotizaciones del POS.

---

## R41: Mostrar número de cotización activa en header
**Timestamp:** 0:10  
**Prioridad:** Alta  
**Módulo:** POS - Cotizaciones

### Descripción
Al crear o editar una cotización en el POS, el número de cotización debe mostrarse en el header/encabezado de la pantalla para que el usuario sepa en todo momento qué cotización está trabajando.

### Comportamiento esperado
- Mostrar "Cotización #XXXX" en el header mientras se edita
- Debe ser visible y prominente
- Debe actualizarse cuando se crea una nueva cotización

---

## R42: Modal de búsqueda siempre debe mostrar opciones
**Timestamp:** 2:06  
**Prioridad:** Alta  
**Módulo:** POS - Búsqueda de productos

### Descripción
Actualmente, cuando hay un solo resultado de búsqueda, el sistema redirige directamente al producto sin mostrar el modal de selección. Esto debe cambiarse para que **siempre** muestre el modal con los resultados.

### Comportamiento actual (problemático)
- Usuario busca "alternador"
- Si hay un solo resultado, va directo al detalle del producto
- Usuario no puede ver si hay otras opciones o refinar búsqueda

### Comportamiento esperado
- Usuario busca "alternador"
- Modal siempre aparece mostrando resultados (incluso si es uno solo)
- Usuario puede ver el/los resultado(s) y seleccionar explícitamente

---

## R43: Editar búsqueda dentro del modal
**Timestamp:** 1:43  
**Prioridad:** Media  
**Módulo:** POS - Modal de búsqueda

### Descripción
El usuario debe poder editar el texto de búsqueda dentro del modal sin tener que cerrarlo y volver a abrir la búsqueda.

### Comportamiento actual (problemático)
- Usuario escribe "F" para abrir modal
- Quiere buscar "terminal" pero ya tiene "F" en el buscador
- Debe cerrar modal, limpiar, escribir "terminal" y volver a abrir

### Comportamiento esperado
- Usuario escribe "F" para abrir modal
- Puede editar directamente el texto dentro del modal
- Modal actualiza resultados en tiempo real mientras edita

---

## R44: Search bar + ordenamiento en carrito del POS
**Timestamp:** 3:03  
**Prioridad:** Alta  
**Módulo:** POS - Carrito de cotización

### Descripción
El carrito de la cotización debe tener capacidades de búsqueda y ordenamiento de los productos ya añadidos.

### Funcionalidades requeridas
1. **Search bar:** Buscar dentro de los productos del carrito actual
2. **Ordenamiento:** Poder ordenar por:
   - Nombre de producto
   - Código
   - Cantidad
   - Precio unitario
   - Precio total (opcional)

### Comportamiento esperado
- Barra de búsqueda en la parte superior del carrito
- Controles de ordenamiento (dropdown o headers clicables)
- Búsqueda filtra en tiempo real
- Ordenamiento reorganiza la lista visualmente

---

## R45: Filtros laterales por atributos
**Timestamp:** 5:23  
**Prioridad:** Media  
**Módulo:** POS - Búsqueda de productos

### Descripción
Implementar panel de filtros laterales en la búsqueda de productos, similar a e-commerce modernos.

### Funcionalidades requeridas
- **Filtro por Marca:** Lista de marcas disponibles (checkboxes)
- **Filtro por Categoría:** Lista de categorías (checkboxes)
- **Filtros por atributos dinámicos:** Basados en los atributos configurados por categoría
  - Ej: "Tamaño de pantalla" (slider o checkboxes)
  - Ej: "Voltaje", "Amperaje", etc.

### UI/UX Esperada
- Panel lateral izquierdo o derecho
- Filtros colapsables por sección
- Mostrar filtros aplicados con opción de remover
- Actualización de resultados en tiempo real al aplicar/quitar filtros

### Referencia
Usuario menciona ejemplos similares a filtros de Amazon/MercadoLibre con:
- Checkboxes para opciones múltiples
- Sliders para rangos numéricos (si aplica)
- Chips/tags mostrando filtros activos

---

## Bugs/Issues identificados

### BUG-001: Búsqueda no responde a entrada vacía o espacios
**Timestamp:** 0:35 - 1:11  
**Severidad:** Alta

#### Descripción
La búsqueda no muestra resultados cuando:
- Se presiona Enter sin texto
- Se escribe solo un espacio
- En algunos casos con ciertas letras no hay respuesta

#### Reproducción
1. Ir a cotizaciones
2. Intentar buscar producto
3. Presionar Enter sin escribir nada
4. No aparece modal ni resultados

#### Investigar
- ¿Validación en frontend bloqueando búsquedas vacías?
- ¿Backend requiere mínimo de caracteres?
- ¿Por qué algunas letras no funcionan?

---

### UX-001: No hay confirmación al salir sin guardar cotización
**Timestamp:** 4:45 - 4:58  
**Severidad:** Media

#### Descripción
Al salir de una cotización sin guardar, se pierde toda la información sin confirmación.

#### Comportamiento esperado
- Modal de confirmación: "¿Está seguro de salir sin guardar?"
- Opciones: "Guardar y salir", "Salir sin guardar", "Cancelar"
- O considerar auto-guardado en borrador

---

## Notas de implementación

### Prioridad sugerida
1. **R42** + **BUG-001**: Arreglar flujo de búsqueda (crítico para UX)
2. **R41**: Header con número de cotización (rápido de implementar)
3. **R44**: Search + sort en carrito (mejora productividad)
4. **R43**: Edición en modal (mejora UX)
5. **R45**: Filtros laterales (feature complejo, mayor desarrollo)
6. **UX-001**: Confirmación al salir (prevención de pérdida de datos)

### Consideraciones técnicas
- **Frontend:** Vue.js (verificar en investigación)
- **Backend:** Laravel API
- **Filtros dinámicos:** Requiere estructura de atributos por producto
- **Ordenamiento:** Puede hacerse client-side si dataset pequeño

---

## Próximos pasos
1. Investigar código actual del POS (rutas, componentes, controladores)
2. Identificar archivos involucrados
3. Crear plan de implementación detallado
4. Estimar esfuerzo por requerimiento
