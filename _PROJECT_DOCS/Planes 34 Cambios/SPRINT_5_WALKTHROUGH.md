# Sprint 5: Inventario y Fórmulas
> _Implementación completa según el plan detallado._

## ¿Qué se implementó en este Sprint?

### 1. Proveedor en Entradas de Inventario
- Se añadió el campo opcional `supplier_id` a la tabla `inventory_movements`.
- La vista de creación de Entradas de Inventario (o Inbound en masa) ahora permite seleccionar qué proveedor envió la mercancía.
- La vista de auditoría (Lista de Movimientos) muestra esta nueva columna "Proveedor", facilitando la trazabilidad.

### 2. Restricción de Stock en Cotizaciones
- Se integró un nuevo ajuste global en el sistema: **Requerir stock para cotizar**.
- Este ajuste se configura por el administrador desde **Configuración > Tienda**.
- Al habilitarlo, el backend bloquea automáticamente la creación o actualización de cotizaciones si la suma de unidades de un producto excede la existencia real de inventario (Stock < Cantidad demandada).
- Aplica para la vía tradicional de cotizaciones y para las generadas directamente desde el POS.

### 3. Unidades por Paquete en Compras (OC)
- Se habilitó la conversión de "Paquetes a Unidades" nativamente en el módulo de compras.
- Al crear o editar una Orden de Compra, el comprador especifica tanto "Cantidades/Bultos" como las "Unidades por Paquete" (`units_per_package`).
- Al realizar el ingreso al sistema o parcial ("Recepción de OC"), el sistema calcula matemáticamente y da entrada al stock basándose en el producto real (_Paquetes_ × _Und/Paquete_).
- Esto resuelve el problema de manejar subpaquetamiento que no coincide linealmente 1-a-1 en los catálogos.

### 4. CRUD de Fórmulas Dinámicas de Pricing
- Las fórmulas dinámicas (ej: `ROUNDUP(costo * 1.5)`) que antes eran estáticas a nivel código (hardcoded presets) ahora persisten y viven en Base de Datos.
- En el panel bajo `Administración > Fórmulas de Precio`, el Administrador puede listar, crear, desactivar y eliminar fórmulas en tiempo real.
- Las predeterminadas originales permanecen estáticas con el tag `Predefinida`, protegiéndolas de eliminación, pero dejando desactivarlas o manipular su orden de visualización.
- Se ha incorporado en el frontend un simulador instantáneo de la fórmula, permitiendo evaluar al instante el costo hipotético vs el resultado, apoyando la decisión comercial.
- Componentes unificados (como al crear artículos, marcas) ahora consultan la lista autorizada global en lugar de arrastrar un archivo local estático.

## Verificación de QA Local (Manual)

Se sugiere testear los siguientes flujos para validar:

1. **Órdenes de Compra (OC):**
   - Crear una OC agregando en un ítem `2` empaques con `50` unds c/u, completar la recepción.
   - Ir al Inventario y verificar que las existencias del producto hayan sumado `100` unidades, no `2`.
   
2. **Requerimiento de Stock:**
   - Activar el "Requerir stock para cotizar" en *Configuración > Tienda*. 
   - Intentar guardar una nueva cotización exigiendo 100 unidades de un producto "fuera de stock" (`out_of_stock`). Se debería recibir el candado de validación restrictivo.

3. **Formula Engine:**
   - Ir a *Administración > Fórmulas de Precio*.
   - Crear una fórmula de test: `(costo * 1.5) + ITBM + 5`.
   - Probar el simulador con un Costo Ficticio de $10. Si responde lógicamente, aplicarla temporalmente al editar/crear un subproducto para constatar conectividad de base.
