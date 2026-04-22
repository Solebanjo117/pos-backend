# Plan: Cambios en Producto - Modal, Catalogo, Stock, ITBM

## Context
Se necesitan 8 cambios interrelacionados en el sistema de productos:
- El warning de duplicados usa nombre pero debe usar SKU base
- El stock en la tabla de variantes es readonly pero debe ser editable (con update real al warehouse)
- Los atributos en catalogo/lista no tienen estilo verde
- El modal de detalle tiene varias mejoras pendientes: formato de codigo, simplificar marcas disponibles, mostrar chasis vinculado, reemplazar alternativas con upsales, y habilitar ITBM toggle en vista de venta

---

## Cambio 1: Warning de duplicado basado en SKU base

**Archivos:**
- `pos-frontend/resources/js/pages/Products/Create.vue` (lineas 178-214, 1113-1134)
- `pos-backend/app/Http/Controllers/Api/ProductController.php` (lineas 275-305)

**Que hacer:**
- Frontend `checkDuplicates()`: cambiar para enviar solo `sku` (no `name`) al endpoint
- Backend `checkDuplicate()`: priorizar busqueda por SKU base (partial match), quitar o reducir la busqueda por nombre
- Warning banner: mostrar los productos con SKU similar

---

## Cambio 2: Stock editable en tabla de variantes (create/edit)

**Archivos:**
- `pos-frontend/resources/js/components/Products/VariantManager.vue` (linea ~651 stock readonly, lineas 847-856 inventario inicial)
- `pos-backend/app/Http/Controllers/Api/InventoryController.php` (lineas 93-121 - update endpoint)

**Que hacer:**
- En la tabla de variantes del VariantManager, cambiar el `<td>` de stock de texto readonly a `<input type="number">`
- El valor debe reflejar el stock del warehouse actualmente seleccionado (usar `usePosSettings().currentWarehouseId` o el warehouse context)
- Al cambiar el valor y perder foco (blur) o al guardar, llamar al API para actualizar inventario real: `PUT /api/v1/inventory/{productId}/{warehouseId}` con `{ stock_available, variant_id }`
- Agregar selector de warehouse si no existe, para que el usuario sepa de que warehouse esta viendo/editando stock
- Necesario verificar que el endpoint de inventario soporte variant_id, o crear un endpoint que lo soporte

---

## Cambio 3: Atributos con fondo verde en catalogo y lista

**Archivos:**
- `pos-frontend/resources/js/components/Products/ProductCard.vue` (lineas 115-117 lista, 190-192 grid)
- `pos-frontend/resources/js/pages/Products/Index.vue` (lineas 830-834)

**Que hacer:**
- En ProductCard.vue (ambas vistas grid y list): cambiar el display de atributos para usar badges/tags con fondo verde (`bg-green-100 text-green-800` o similar)
- Mostrar solo los valores del atributo (sin nombres), cada uno como badge individual con fondo verde
- En Products/Index.vue (vista lista tabla): aplicar el mismo estilo de badges verdes con solo valores
- Ambas vistas deben ser consistentes: badges verdes, solo valores

---

## Cambio 4: Formato de codigo en modal - mantener guiones

**Archivos:**
- `pos-frontend/resources/js/components/Products/ProductDetailModal.vue` (lineas 427-428)

**Que hacer:**
- En la linea que muestra el SKU: quitar la llamada a `displayFormat()` (que reemplaza `-` con espacios)
- Mostrar directamente `product.sku_base || product.default_variant?.sku` sin transformar
- Resultado: "45046-59026" en vez de "45046 59026"

---

## Cambio 5: Simplificar "Marcas Disponibles" en modal

**Archivos:**
- `pos-frontend/resources/js/components/Products/ProductDetailModal.vue` (lineas 458-555)

**Que hacer:**
- En la seccion de cada variante, remover:
  - SKU/codigo (linea 479: `displayFormat(variant.sku)`)
  - Descripcion (si aplica)
  - Atributos (lineas 487-489)
- Mantener solo:
  - **Marca**: `variant.brand?.name` (prominente)
  - **Precio**: el precio actual (lineas 502-524)
  - **Cantidad/Stock**: el stock disponible (linea 529)
- Simplificar la tarjeta de variante para un layout mas limpio con solo estos 3 datos

---

## Cambio 6: Mostrar chasis vinculado en vehiculos compatibles

**Archivos:**
- `pos-frontend/resources/js/components/Products/ProductDetailModal.vue` (lineas 209-244, 586-604)

**Que hacer:**
- En `fetchVehicleCompatibility()`: extraer tambien datos de chasis del response (`c.chassis?.code`, `c.chassis?.name`)
- Agregar `chassis` al tipo VehicleRow y al mapeo
- En `groupedVehicles` computed: incluir chasis en el agrupamiento o como dato adicional
- En el template (lineas 586-604): mostrar el chasis vinculado junto a cada grupo de vehiculos
  - Formato: "Chasis: P10 (Serie P10)" o similar debajo de los motores
- El API ya devuelve chasis con eager load (`chassis.model.make`), solo falta mapearlo en frontend

---

## Cambio 7: Reemplazar "Alternativas y Ref. Cruzadas" con "Upsales"

**Archivos:**
- `pos-frontend/resources/js/components/Products/ProductDetailModal.vue` (lineas 632-685)

**Que hacer:**
- Cambiar el titulo de "Alternativas y Ref. Cruzadas" a "Upsales"
- Filtrar los datos para mostrar solo relaciones de tipo: `compatible`, `substitute`, `upgrade` (excluir `cross_reference` y `same_category`)
- Actualizar los labels de relacion: quitar "Ref. Cruzada" como opcion visible
- Mantener la funcionalidad de click para ver producto alternativo

---

## Cambio 8: ITBM toggle en vista de venta (modo normal del modal)

**Archivos:**
- `pos-frontend/resources/js/components/Products/ProductDetailModal.vue` (lineas 370-385, 504-524)

**Que hacer:**
- Mover el toggle ITBM fuera de la condicion `v-if="blindMode"` para que aparezca tambien en modo normal (venta)
- Agregar estado separado para ITBM en modo venta: `includeITBMSale` (con su propio localStorage key)
- En modo normal: aplicar ITBM al precio mostrado si el toggle esta activo
  - Usar `priceWithTax()` sobre `base_price` cuando `includeITBMSale` es true
- En modo cliente (blind): mantener comportamiento actual con `includeITBM`
- Adaptar vista cliente para mostrar `cost_total` (costo total incluyendo tax de costo) que viene de los cambios en la tabla de variantes

---

## Cambio 9: Duplicado - SKU base vacio y variantes

**Archivos:**
- `pos-frontend/resources/js/pages/Products/Create.vue` (funcion de duplicado)

**Que hacer:**
- Al duplicar un producto, NO auto-generar el SKU base — dejarlo vacio para que el usuario lo llene manualmente
- Las variantes actualmente no se duplican porque se copia el SKU del producto original, causando conflicto de unicidad
- Al dejar el SKU base vacio, el usuario ingresa uno nuevo y las variantes se pueden generar correctamente con el nuevo prefijo
- Codigo alterno: NO se debe copiar al duplicar (comportamiento correcto, cada producto tiene sus propios codigos alternos)

---

## Cambio 11: Upsale - No auto-generar, dejar manual

**Archivos:**
- `pos-frontend/resources/js/pages/Products/Create.vue` o componente de relaciones de producto
- `pos-backend/app/Http/Controllers/Api/ProductController.php` (si aplica en store/update)

**Que hacer:**
- NO auto-generar relaciones de upsale al crear/duplicar producto
- Se esta agregando mal de forma automatica
- Dejar la gestion de upsales como proceso manual (el usuario las agrega manualmente desde el modal o edicion)

---

## Cambio 12: Eliminar campo SKU variante del submenu de productos

**Archivos:**
- `pos-frontend/resources/js/pages/Products/Index.vue` (tabla/submenu desplegable)

**Que hacer:**
- En la tabla de productos (`/products`), en el submenu desplegable (expandable row) de cada producto, eliminar el field de "SKU variante"
- No es necesario, esta de mas

---

## Cambio 13: Atributos default de categoria si se dejan vacios

**Archivos:**
- `pos-backend/app/Http/Controllers/Api/ProductController.php` o `app/Services/ProductService.php`
- `pos-frontend/resources/js/pages/Products/Create.vue` (logica de guardado)

**Que hacer:**
- Si al crear un producto se dejan los atributos vacios, asignar automaticamente los atributos default definidos en la categoria
- Verificar que la categoria tenga atributos default configurados y usarlos como fallback
- Esto aplica tanto en creacion como posiblemente en duplicado

---

## Cambio 14: Relacion many-to-many Proveedor/Marca

**Archivos:**
- `pos-backend/database/migrations/` (nueva migracion para tabla pivot `brand_supplier`)
- `pos-backend/app/Models/Supplier.php` (relacion belongsToMany brands)
- `pos-backend/app/Models/Brand.php` (relacion belongsToMany suppliers)
- `pos-backend/app/Http/Controllers/Api/SupplierController.php` o nuevo controller
- `pos-frontend/` (componente de gestion proveedor/marca)

**Que hacer:**
- Crear relacion many-to-many entre Proveedor (`suppliers`) y Marca (`brands`)
- Seguir el mismo patron que el modulo de carro/motor (VehicleMake/VehicleEngine):
  - Se crea la marca primero (independiente)
  - Luego se escoge/asigna el proveedor a la marca (o viceversa, la marca al proveedor)
- Crear tabla pivot `brand_supplier` con `brand_id` y `supplier_id`
- UI: similar al modulo de vehiculos - seleccionar marcas existentes para asignar al proveedor

---

## Orden de implementacion recomendado

1. **Cambio 4** - Formato codigo (trivial, 1 linea)
2. **Cambio 12** - Eliminar SKU variante del submenu (trivial, frontend)
3. **Cambio 3** - Atributos verdes (frontend solo)
4. **Cambio 5** - Simplificar marcas disponibles (frontend solo)
5. **Cambio 9** - Duplicado: SKU base vacio + variantes (frontend)
6. **Cambio 11** - Upsale: no auto-generar (frontend + backend)
8. **Cambio 7** - Upsales en vez de alternativas (frontend solo)
9. **Cambio 13** - Atributos default de categoria (backend + frontend)
10. **Cambio 1** - Warning duplicado por SKU (frontend + backend)
11. **Cambio 6** - Chasis en vehiculos compatibles (frontend)
12. **Cambio 8** - ITBM toggle en modo venta (frontend)
13. **Cambio 14** - Relacion many-to-many proveedor/marca (backend + frontend, nuevo modulo)
14. **Cambio 2** - Stock editable (frontend + backend, mas complejo)

---

## Verificacion

1. Navegar a `/products/create` y verificar:
   - Al escribir un SKU base similar a uno existente, aparece warning
   - La tabla de variantes muestra inputs editables de stock por warehouse
2. Navegar a `/products` y verificar:
   - Vista catalogo: atributos como badges verdes (solo valores)
   - Vista lista: atributos como badges verdes (solo valores)
3. Abrir modal de detalle de producto y verificar:
   - Codigo muestra guiones (ej: "45046-59026")
   - Marcas disponibles: solo marca, precio, cantidad
   - Vehiculos compatibles: muestra chasis vinculado
   - Seccion "Upsales" en vez de "Alternativas y Ref. Cruzadas"
   - Toggle ITBM visible en modo normal (venta) y en modo cliente
4. Ejecutar tests: `php artisan test tests/Feature/P1/ --stop-on-failure`
