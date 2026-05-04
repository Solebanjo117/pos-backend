## Problema
Permitir en create/edit de producto seleccionar múltiples opciones para un mismo atributo heredado tipo `select`, manteniendo atributos `string` como texto libre.

## Decisiones confirmadas
- Persistencia: guardar múltiples selecciones como JSON array en `product_attributes.value`.
- Comportamiento: aplicar multiselección a todos los atributos de tipo `select`.
- Contrato API: en atributos `select`, `value` se enviará y devolverá como arreglo.
- UI: usar `select multiple` nativo (no chips/autocomplete).
- Formato de despliegue en `/products`: cuando se requiera texto plano, unir multiselección como `Opción 1, Opción 2`.

## Formato de despliegue en `/products`
- Listado (`/products`) y vistas que hoy pintan `value` como texto: para atributos `select` con `value: string[]`, mostrar `value.join(', ')`.
- Detalle/edit: mantener `value` como arreglo para el control `select multiple`; solo transformar a string en componentes de lectura textual.
- Compatibilidad legacy: si llega `value` string en un atributo `select`, mostrarlo tal cual; si llega array vacío, mostrar `—`.

## Enfoque
1. Backend aceptará `attributes.*.value` como string o array según tipo real del atributo heredado.
2. Backend normalizará internamente a JSON string para almacenamiento en `product_attributes.value` y decodificará a array en respuestas.
3. Validación de atributos heredados `select` verificará que **cada opción** seleccionada exista en `default_attributes.*.options`.
4. Frontend create/edit renderizará `select multiple` para atributos heredados `select` y mantendrá input normal para `string`.
5. Frontend enviará arrays para `select` y conservará string para `string`.
6. Pruebas P1 cubrirán create/update válidos e inválidos para multiselección y compatibilidad con datos previos.

## Todos
- Mapear impacto backend en `StoreProductRequest` y `UpdateProductRequest` para permitir `value` array en select.
- Diseñar normalizador único en `ProductService` para convertir valores de atributos `select` a JSON string y preservar `string` como texto.
- Extender validación `validateInheritedAttributeValues` para arrays (no vacío, opciones válidas, sin valores fuera de catálogo).
- Ajustar serialización en `ProductResource` y `ProductDetailResource` para devolver `value` como array en atributos `select`.
- Definir helper de presentación en frontend para `/products` que convierta `string[]` a texto con coma+espacio.
- Evaluar límite/longitud de columna `product_attributes.value`; si existe riesgo de 255 chars, incluir migración segura a `text`.
- Actualizar `Products/Create.vue` para bind `v-model` de `select multiple` a `string[]` en atributos heredados `select`.
- Actualizar `Products/Edit.vue` y `AttributeManager.vue` para editar `select` heredados en modo multiselección.
- Ajustar tipos TS (`types/product.ts`, `useProductAttributes.ts`) para soportar `value: string | string[]` condicionado por tipo.
- Definir compatibilidad legacy: si un `select` viejo tiene value string en DB, parsearlo como array `[value]`.
- Añadir/actualizar tests en `tests/Feature/P1/InheritedAttributeTest.php` para multiselección (create/update + rechazo de opción inválida).
- Ejecutar validación final: `vendor/bin/pint --dirty`, tests P1 objetivo y build frontend.

## Notas
- No se modifica estructura de `categories.default_attributes`; se reutiliza `type` y `options`.
- La comparación de opciones se mantiene exacta (case-sensitive) salvo definición explícita en contra.
- Para evitar pérdidas de datos, el parser de JSON en lectura debe tener fallback seguro para valores no JSON legacy.
