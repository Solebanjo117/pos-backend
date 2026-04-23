# Plan: Cambios Rápidos - Duplicar Producto y Tooltips

## 1. Backend: Agregar "(Copia)" al nombre al duplicar producto

**Archivo:** `C:\Users\JUANANTONIOCASTILLAO\Herd\pos-backend\app\Services\ProductService.php`
**Línea:** ~771

Cambiar:
```php
'name' => $source->name,
```
Por:
```php
'name' => $source->name . ' (Copia)',
```

Esto corrige el test `ProductDuplicateTest.php:291` que espera `'Terminal Derecho (Copia)'`.

---

## 2. Frontend: Agregar tooltips en lista de productos

**Archivo:** `C:\Users\JUANANTONIOCASTILLAO\Herd\pos-frontend\resources\js\pages\Products\Index.vue`

### 2a. Columna SKU (línea ~712-719)
Agregar `title="SKU base del producto"` al div del SKU:

```vue
<td :style="getColumnStyle('sku')" class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900 dark:text-white">
    <div title="SKU base del producto">{{ product.sku_base || product.default_variant?.sku }}</div>
    ...
</td>
```

### 2b. Columna Stock (línea ~744-748)
Agregar `title` al `<td>` de stock:

```vue
<td
    :style="getColumnStyle('stock')"
    title="Stock total en todos los almacenes"
    class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-white">
    {{ product.total_stock ?? 'N/A' }}
</td>
```

---

## Verificación

1. Correr test: `php artisan test tests/Feature/P1/ProductDuplicateTest.php`
2. Verificar tooltips en `/products` pasando el mouse sobre SKU y Stock
