# Sprint 4: System ID y Vehiculos — Plan de Implementacion

**Fecha**: 2026-04-10
**Estado**: Pendiente de aprobacion
**Estimado**: ~13 horas de implementacion

---

## Contexto

Sprint 4 se enfoca en crear un sistema de identificacion unico para productos y variantes (System ID), y mejorar la visualizacion de vehiculos. El System ID reemplaza la exposicion del `product.id` en blind mode con un codigo secuencial real. Ademas, cada variante obtiene un codigo prefijado por marca (ej. BOX00000001 para Bosch).

## Tareas

| # | Tarea | Tiempo | Riesgo | Tipo |
|---|-------|--------|--------|------|
| 4.4 | Modelo de vehiculo prominente en compatibilidad | 1.5h | Muy bajo | Frontend |
| 4.3 | Motores organizados con filtros make/model | 3h | Bajo | Frontend |
| 4.1 | Product System ID (00000001) | 3h | Bajo | Backend+Frontend |
| 4.2 | System ID por marca (BOX00000001) | 5.5h | Medio | Backend+Frontend |

**Orden**: 4.4 → 4.3 → 4.1 → 4.2 (quick wins primero, DB pesado al final)

---

## Task 4.4: Modelo de Vehiculo Prominente (#9v/#23)

### Objetivo
Hacer que el modelo de vehiculo sea el elemento visual principal en la tabla de compatibilidad del modal de producto. Agrupar por Make+Model para reducir repeticion.

### Archivo
- `pos-frontend/.../components/Products/ProductDetailModal.vue`

### Cambios

1. **Agregar computed** `groupedVehicles` en script (despues de `vehicleRows`):
   ```typescript
   const groupedVehicles = computed(() => {
       const groups = new Map<string, { make: string; model: string; year_from?: number; year_to?: number; engines: Array<{ code: string; displacement?: string }> }>()
       for (const row of vehicleRows.value) {
           const key = `${row.make}|${row.model}|${row.year_from}-${row.year_to}`
           if (!groups.has(key)) {
               groups.set(key, { make: row.make, model: row.model, year_from: row.year_from, year_to: row.year_to, engines: [] })
           }
           groups.get(key)!.engines.push({ code: row.engine, displacement: row.displacement })
       }
       return Array.from(groups.values())
   })
   ```

2. **Reemplazar tabla** de vehiculos con layout de cards agrupadas:
   - Linea principal **bold grande**: `{Make} {Model}` + badge de anos `{year_from}-{year_to}`
   - Sub-linea muted pequena: motores separados por coma (`5.3L V8 L83, 6.2L V8 L86`)
   - Mantener `<table>` como fallback si hay >15 registros (demasiados para cards)

3. **Mantener** estados de carga, universal, y vacio sin cambios

### Verificaciones
- [ ] Vehiculos agrupados por Make+Model con motores listados debajo
- [ ] Modelo es el elemento visual mas prominente (bold, font grande)
- [ ] Rango de anos visible como badge
- [ ] Productos sin compatibilidad muestran mensaje vacio normal
- [ ] Productos universales muestran mensaje "Universal" normal

---

## Task 4.3: Motores Organizados con Filtros (#5v)

### Objetivo
Agregar dropdowns de Marca y Modelo de vehiculo a la pagina de motores para filtrar la lista.

### Archivos
- `pos-frontend/.../pages/Vehicles/Engines/Index.vue`
- `pos-frontend/.../composables/useVehicleEngines.ts` (verificar params)

### Estado Actual
- Tabla basica con search + paginacion, sin filtros
- Backend **ya soporta** `make_id` y `model_id` como query params en `VehicleEngineController::index()`
- Composables `useVehicleMakes.ts` y `useVehicleModels.ts` **ya existen**

### Cambios

#### 1. `composables/useVehicleEngines.ts`
- Verificar que `fetchEngines()` pasa `make_id` y `model_id` a la API
- Si no los pasa, agregar como params opcionales

#### 2. `pages/Vehicles/Engines/Index.vue`
1. **Importar** composables existentes:
   ```typescript
   import { useVehicleMakes } from '@/composables/useVehicleMakes'
   import { useVehicleModels } from '@/composables/useVehicleModels'
   ```

2. **Agregar state**:
   ```typescript
   const selectedMakeId = ref<number | null>(null)
   const selectedModelId = ref<number | null>(null)
   const { makes, fetchMakes } = useVehicleMakes()
   const { models, fetchModels } = useVehicleModels()
   ```

3. **Watchers**:
   - Al cambiar `selectedMakeId`: cargar modelos de esa marca, resetear `selectedModelId`, recargar engines
   - Al cambiar `selectedModelId`: recargar engines con filtro

4. **Template** — agregar fila de filtros arriba del search:
   ```html
   <div class="grid grid-cols-3 gap-4 mb-4">
     <select v-model="selectedMakeId">Marca vehiculo</select>
     <select v-model="selectedModelId" :disabled="!selectedMakeId">Modelo</select>
     <button @click="resetFilters">Limpiar filtros</button>
   </div>
   ```

5. **Agregar columnas** "Marca Vehiculo" y "Modelo" a la tabla existente

6. **onMounted**: llamar `fetchMakes()`

### Verificaciones
- [ ] Dropdown de Marca carga todas las marcas de vehiculos
- [ ] Seleccionar marca → Dropdown de Modelo se llena con modelos de esa marca
- [ ] Seleccionar modelo → Tabla muestra solo motores de ese modelo
- [ ] "Limpiar filtros" → Tabla muestra todos los motores
- [ ] Busqueda de texto + filtros funcionan combinados
- [ ] Columnas Marca y Modelo visibles en la tabla

---

## Task 4.1: Product System ID (#27)

### Objetivo
Agregar un `system_id` auto-incremental a la tabla `products` con formato de 8 digitos zero-padded (00000001). Backfill productos existentes.

### Archivos Backend
- `database/migrations/2026_04_10_000001_add_system_id_to_products_table.php` (NUEVO)
- `database/migrations/2026_04_10_000002_create_system_sequences_table.php` (NUEVO)
- `app/Models/Product.php`
- `app/Services/ProductService.php`
- `app/Http/Resources/ProductResource.php`
- `app/Http/Resources/ProductDetailResource.php`

### Archivos Frontend
- `pos-frontend/.../components/Products/ProductDetailModal.vue`

### Cambios Backend

#### Migracion 1: `add_system_id_to_products_table`
```php
public function up(): void
{
    Schema::table('products', function (Blueprint $table) {
        $table->string('system_id', 10)->nullable()->after('id');
    });

    // Backfill existing products
    $products = DB::table('products')->orderBy('id')->pluck('id');
    foreach ($products as $index => $id) {
        DB::table('products')->where('id', $id)->update([
            'system_id' => str_pad($index + 1, 8, '0', STR_PAD_LEFT),
        ]);
    }

    // Now make NOT NULL and add unique index
    Schema::table('products', function (Blueprint $table) {
        $table->string('system_id', 10)->nullable(false)->unique()->change();
    });
}
```

#### Migracion 2: `create_system_sequences_table`
```php
public function up(): void
{
    Schema::create('system_sequences', function (Blueprint $table) {
        $table->id();
        $table->string('key', 50)->unique();
        $table->bigInteger('next_value')->default(1);
        $table->timestamps();
    });

    // Seed with current product count + 1
    $nextVal = DB::table('products')->count() + 1;
    DB::table('system_sequences')->insert([
        'key' => 'product_system_id',
        'next_value' => $nextVal,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
```

#### `Product.php` (linea 17-30)
- Agregar `'system_id'` a `$fillable` array

#### `ProductService.php` — metodo `createProduct()` (linea 48-59)
Despues de `Product::create(...)`:
```php
$product->system_id = $this->generateProductSystemId();
$product->save();
```

Nuevo metodo privado:
```php
private function generateProductSystemId(): string
{
    $row = DB::table('system_sequences')
        ->where('key', 'product_system_id')
        ->lockForUpdate()
        ->first();

    $nextVal = $row->next_value;
    DB::table('system_sequences')
        ->where('key', 'product_system_id')
        ->update(['next_value' => $nextVal + 1, 'updated_at' => now()]);

    return str_pad($nextVal, 8, '0', STR_PAD_LEFT);
}
```

#### `ProductResource.php` (linea ~20)
Agregar: `'system_id' => $this->system_id,`

#### `ProductDetailResource.php` (linea ~20)
Agregar: `'system_id' => $this->system_id,`

### Cambios Frontend

#### `ProductDetailModal.vue`
- Header blind mode (linea ~283): `#{{ String(product.id).padStart(8, '0') }}` → `{{ product.system_id || '#' + String(product.id).padStart(8, '0') }}`
- Body blind mode (linea ~370): mismo cambio

### Verificaciones
- [ ] `php artisan migrate` exitoso
- [ ] Productos existentes tienen system_id backfilled (00000001, 00000002...)
- [ ] Crear producto nuevo → obtiene siguiente system_id secuencial
- [ ] Blind mode muestra system_id real en lugar de DB id padded
- [ ] API response incluye campo `system_id`

---

## Task 4.2: System ID por Marca (#31/#33)

### Objetivo
Cada variante de producto obtiene un System ID prefijado por marca. Formato: `{PREFIJO}{00000001}` (ej. BOX00000001 para Bosch, DEN00000001 para Denso).

### Archivos Backend
- `database/migrations/2026_04_10_000003_add_prefix_to_brands_table.php` (NUEVO)
- `database/migrations/2026_04_10_000004_create_brand_sequences_table.php` (NUEVO)
- `database/migrations/2026_04_10_000005_add_system_id_to_product_variants_table.php` (NUEVO)
- `app/Models/Brand.php`
- `app/Models/ProductVariant.php`
- `app/Services/ProductService.php`
- `app/Http/Resources/ProductVariantResource.php`
- `app/Http/Controllers/Api/BrandController.php`

### Archivos Frontend
- `pos-frontend/.../components/Products/ProductDetailModal.vue`
- `pos-frontend/.../pages/Brands/Create.vue`
- `pos-frontend/.../pages/Brands/Edit.vue`

### Cambios Backend

#### Migracion 3: `add_prefix_to_brands_table`
```php
public function up(): void
{
    Schema::table('brands', function (Blueprint $table) {
        $table->string('prefix', 5)->nullable()->after('name');
    });

    // Backfill: first 3 uppercase letters of name
    $brands = DB::table('brands')->get(['id', 'name']);
    foreach ($brands as $brand) {
        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $brand->name), 0, 3));
        DB::table('brands')->where('id', $brand->id)->update(['prefix' => $prefix]);
    }

    Schema::table('brands', function (Blueprint $table) {
        $table->unique('prefix');
    });
}
```

#### Migracion 4: `create_brand_sequences_table`
```php
public function up(): void
{
    Schema::create('brand_sequences', function (Blueprint $table) {
        $table->id();
        $table->foreignId('brand_id')->unique()->constrained()->cascadeOnDelete();
        $table->bigInteger('next_value')->default(1);
        $table->timestamps();
    });

    // Seed: one row per existing brand
    $brands = DB::table('brands')->pluck('id');
    foreach ($brands as $brandId) {
        DB::table('brand_sequences')->insert([
            'brand_id' => $brandId,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
```

#### Migracion 5: `add_system_id_to_product_variants_table`
```php
public function up(): void
{
    Schema::table('product_variants', function (Blueprint $table) {
        $table->string('system_id', 20)->nullable()->after('id');
    });

    // Backfill: for each brand, assign sequential IDs
    $brands = DB::table('brands')->get(['id', 'prefix']);
    foreach ($brands as $brand) {
        $variants = DB::table('product_variants')
            ->where('brand_id', $brand->id)
            ->orderBy('id')
            ->pluck('id');

        foreach ($variants as $index => $variantId) {
            $systemId = $brand->prefix . str_pad($index + 1, 8, '0', STR_PAD_LEFT);
            DB::table('product_variants')->where('id', $variantId)->update(['system_id' => $systemId]);
        }

        // Update sequence counter
        DB::table('brand_sequences')->where('brand_id', $brand->id)->update([
            'next_value' => $variants->count() + 1,
            'updated_at' => now(),
        ]);
    }

    Schema::table('product_variants', function (Blueprint $table) {
        $table->unique('system_id');
    });
}
```

#### `Brand.php`
- Agregar `'prefix'` a `$fillable`
- Agregar boot method:
```php
protected static function booted(): void
{
    static::creating(function (Brand $brand) {
        if (empty($brand->prefix)) {
            $brand->prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $brand->name), 0, 3));
        }
    });

    static::created(function (Brand $brand) {
        DB::table('brand_sequences')->insertOrIgnore([
            'brand_id' => $brand->id,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}
```

#### `ProductVariant.php`
- Agregar `'system_id'` a `$fillable`

#### `ProductService.php` — metodo `createVariant()` (linea 137-148)
Despues de crear variante:
```php
if (!empty($data['brand_id'])) {
    $variant->system_id = $this->generateVariantSystemId($data['brand_id']);
    $variant->save();
}
```

Nuevo metodo privado:
```php
private function generateVariantSystemId(int $brandId): string
{
    $brand = Brand::find($brandId);
    $prefix = $brand->prefix ?? strtoupper(substr($brand->name, 0, 3));

    $seq = DB::table('brand_sequences')
        ->where('brand_id', $brandId)
        ->lockForUpdate()
        ->first();

    if (!$seq) {
        DB::table('brand_sequences')->insert([
            'brand_id' => $brandId,
            'next_value' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $prefix . str_pad(1, 8, '0', STR_PAD_LEFT);
    }

    $nextVal = $seq->next_value;
    DB::table('brand_sequences')
        ->where('brand_id', $brandId)
        ->update(['next_value' => $nextVal + 1, 'updated_at' => now()]);

    return $prefix . str_pad($nextVal, 8, '0', STR_PAD_LEFT);
}
```

#### `ProductVariantResource.php` (linea ~15)
Agregar: `'system_id' => $this->system_id,`

Tambien en el bloque `brand` agregar prefix:
```php
'brand' => [
    'id' => $this->brand?->id,
    'name' => $this->brand?->name,
    'logo_url' => $this->brand?->logo_url ?? null,
    'prefix' => $this->brand?->prefix ?? null,
],
```

#### `BrandController.php`
- En `store()` validation: agregar `'prefix' => 'nullable|string|max:5|unique:brands,prefix|regex:/^[A-Z]+$/'`
- En `update()` validation: agregar `'prefix' => 'nullable|string|max:5|unique:brands,prefix,' . $brand->id . '|regex:/^[A-Z]+$/'`

### Cambios Frontend

#### `ProductDetailModal.vue` — display de variantes
En blind mode (~linea 417):
```html
<span v-else class="font-bold ...">{{ variant.system_id || variant.brand?.name || 'Sin marca' }}</span>
```

#### `Brands/Create.vue`
Agregar campo despues de "Nombre":
```html
<div>
    <label>Prefijo (System ID)</label>
    <input v-model="form.prefix" maxlength="5" placeholder="BOX"
           class="uppercase" />
    <p class="text-xs text-gray-500">2-5 letras mayusculas para codigos de sistema (ej. BOX para Bosch)</p>
</div>
```

#### `Brands/Edit.vue`
Mismo campo que Create.vue.

### Verificaciones
- [ ] `php artisan migrate` — 3 migraciones exitosas
- [ ] Marcas existentes tienen prefix (ej. Bosch → BOX)
- [ ] Variantes existentes tienen system_id (ej. BOX00000001)
- [ ] Crear variante nueva para Bosch → obtiene BOX0000000N (siguiente)
- [ ] Crear variante para Denso → obtiene DEN00000001
- [ ] Blind mode muestra system_id de variante
- [ ] Crear/editar marca permite cambiar prefix
- [ ] Dos marcas no pueden tener el mismo prefix

---

## Checklist General

### Backend
- [ ] `php artisan migrate` — 5 migraciones exitosas sin errores
- [ ] `php artisan test` — sin regresiones
- [ ] Backfill correcto: todos los productos y variantes existentes tienen system_id
- [ ] Secuencias correctas: nuevos productos/variantes obtienen el siguiente ID
- [ ] Concurrencia: lockForUpdate previene IDs duplicados

### Frontend
- [ ] Blind mode: producto muestra system_id (ej. 00000001)
- [ ] Blind mode: variante muestra system_id con prefijo (ej. BOX00000001)
- [ ] Vehiculos: modelo prominente, agrupados por Make+Model
- [ ] Engines: filtros de marca/modelo funcionan
- [ ] Brands: campo prefix en create/edit
- [ ] Sin errores en consola

### Flujo de integracion
1. Crear marca "TestBrand" con prefix "TST" → se crea con BOX-sequence
2. Crear producto → obtiene system_id 00000XXX
3. Agregar variante con marca "TestBrand" → obtiene TST00000001
4. Ver producto en blind mode → muestra system_id y variant.system_id
5. Ver producto con compatibilidad vehicular → modelo prominente y agrupado
6. Ir a Engines → filtrar por marca Toyota → solo motores Toyota

### Estrategia de Commits
1. `feat: make vehicle model prominent in product compatibility display`
2. `feat: add make/model filters to engine listing page`
3. `feat: implement product system ID with auto-increment sequence`
4. `feat: implement brand-prefixed system ID for product variants`
