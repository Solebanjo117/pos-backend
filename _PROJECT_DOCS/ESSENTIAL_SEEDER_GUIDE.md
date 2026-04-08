# Essential Seeder Guide

## Propósito

El `EssentialSeeder` fue creado para permitir que la aplicación funcione con el **mínimo absoluto de datos** después de borrar completamente la base de datos.

A diferencia del seeder de demostración completo que incluye múltiples marcas, categorías, proveedores y almacenes, el Essential Seeder crea **solo 1 de cada entidad crítica** necesaria para que la aplicación sea funcional.

## Datos Creados

El EssentialSeeder crea exactamente:

| Entidad | Valor | Razón |
|---------|-------|-------|
| **Brand** | Genérica | FK requerido en product_variants |
| **Category** | General | Necesario para SKU auto-generation |
| **Supplier** | Proveedor General | FK requerido en purchase_orders |
| **Warehouse** | Almacén Principal (WH-MAIN-01) | Necesario para sales, purchases, inventory |
| **User (Admin)** | admin@hermexpos.app | Autenticación y acceso sistema |
| **Payment Methods** | 5 métodos del sistema | Necesarios para procesar transacciones |
| **Settings** | 17+ configuraciones esenciales | Comportamiento de negocio |

### Usuario Predeterminado

```
Email: admin@hermexpos.app
Password: password (❌ CAMBIAR INMEDIATAMENTE EN PRODUCCIÓN)
Role: admin
```

## Cuándo Usar

### Usar EssentialSeeder:

✅ **Ambiente de Producción** - Cuando deployas por primera vez  
✅ **Ambiente de Testing** - Cuando necesitas una DB limpia y funcional  
✅ **Verificación de Dependencias** - Para asegurar que todas las tablas críticas están pobladas  
✅ **Reset de BD** - Cuando borras todo y necesitas empezar de nuevo  

### NO Usar EssentialSeeder:

❌ **Desarrollo Local** - Usa el seeder completo (tienes datos realistas)  
❌ **Testing de Features** - Usa DemoDataSeeder (tienes clientes, productos, ventas)  
❌ **Cuando ya tienes datos** - Solo ejecuta si eres consciente que es idempotente  

## Cómo Ejecutar

### Opción 1: Ejecutar directamente (Recomendado)

```bash
php artisan migrate:fresh
php artisan db:seed --class=EssentialSeeder
```

### Opción 2: Via variable de entorno

```bash
php artisan migrate:fresh
SEED_MODE=essential php artisan db:seed
```

### Opción 3: Auto-detectar ambiente

En producción, `DatabaseSeeder` ejecuta automáticamente `EssentialSeeder`:

```bash
php artisan migrate:fresh
php artisan db:seed  # En producción ejecuta EssentialSeeder automáticamente
```

### Opción 4: Ejecutar seeder completo (con demo data)

```bash
php artisan migrate:fresh
php artisan db:seed  # En desarrollo ejecuta seeder completo
# O explícitamente:
SEED_MODE=demo php artisan db:seed
```

## Verificación

Después de ejecutar, verifica que los datos se crearon correctamente:

### Con artisan tinker:

```bash
php artisan tinker
```

```php
// Verificar brand
Brand::where('name', 'Genérica')->first();
// Resultado: Brand { id: 1, name: "Genérica", ... }

// Verificar category
Category::where('slug', 'general')->first();
// Resultado: Category { id: 1, name: "General", ... }

// Verificar supplier
Supplier::where('name', 'Proveedor General')->first();
// Resultado: Supplier { id: 1, name: "Proveedor General", ... }

// Verificar warehouse
Warehouse::where('code', 'WH-MAIN-01')->first();
// Resultado: Warehouse { id: 1, code: "WH-MAIN-01", ... }

// Verificar admin user
User::where('email', 'admin@hermexpos.app')->first();
// Resultado: User { id: 1, email: "admin@hermexpos.app", role: "admin", ... }

// Verificar payment methods
PaymentMethod::count();
// Resultado: 5

// Verificar settings
Setting::count();
// Resultado: 21
```

### Con consultas SQL:

```sql
-- Verificar cada tabla
SELECT COUNT(*) as brands FROM brands;           -- Debe ser >= 1
SELECT COUNT(*) as categories FROM categories;   -- Debe ser >= 1
SELECT COUNT(*) as suppliers FROM suppliers;     -- Debe ser >= 1
SELECT COUNT(*) as warehouses FROM warehouses;   -- Debe ser >= 1
SELECT COUNT(*) as users FROM users;             -- Debe ser >= 1
SELECT COUNT(*) as methods FROM payment_methods; -- Debe ser 5
SELECT COUNT(*) as settings FROM settings;       -- Debe ser >= 17

-- Verificar user-warehouse relationship
SELECT * FROM user_warehouse 
WHERE user_id = (SELECT id FROM users WHERE email = 'admin@hermexpos.app');
-- Debe retornar 1 fila
```

## Idempotencia

El seeder es **completamente idempotente** - puedes ejecutarlo múltiples veces sin crear duplicados:

```bash
php artisan db:seed --class=EssentialSeeder
php artisan db:seed --class=EssentialSeeder  # Segunda vez, sin duplicados
# Resultado: Mismos IDs, sin cambios en los datos existentes
```

Internamente usa:
- `Brand::firstOrCreate()` - No crea duplicados por nombre
- `Category::firstOrCreate()` - No crea duplicados por slug
- `Supplier::firstOrCreate()` - No crea duplicados por nombre
- `Warehouse::firstOrCreate()` - No crea duplicados por código
- `User::firstOrCreate()` - No crea duplicados por email

## Después del Seeding

### Pasos recomendados:

1. **Cambiar contraseña admin**
   ```bash
   php artisan tinker
   $user = User::find(1);
   $user->password = bcrypt('your-secure-password');
   $user->save();
   ```

2. **Actualizar información de la tienda**
   - Edita `store_name`, `store_address`, `store_tax_id`, etc. desde la API
   - O via `php artisan tinker`:
     ```php
     Setting::set('store_name', 'Mi Tienda', 'string', 'store');
     ```

3. **Agregar más usuarios** (vendedores, cajeros, etc.)
   - Usa la API: `POST /api/v1/users`
   - O via seeder personalizado

4. **Crear categorías adicionales**
   - Usa la API o crea un seeder personalizado
   - Ejemplo: Sistema Eléctrico, Motor, Frenos, etc.

5. **Agregar marcas adicionales**
   - Usa la API: `POST /api/v1/brands`
   - O copia datos del `BrandSeeder` si es negocio automotriz

6. **Agregar proveedores**
   - Usa la API: `POST /api/v1/suppliers`
   - O copia datos del `SupplierSeeder`

7. **Crear tu primer producto**
   - Usa la API: `POST /api/v1/products`
   - Sistema usará brand "Genérica", category "General" por defecto

## Diferencias: EssentialSeeder vs Seeders de Demo

| Aspecto | EssentialSeeder | Demo Complete |
|---------|-----------------|----------------|
| **Marcas** | 1 (Genérica) | 8 (Bosch, Brembo, NGK, etc.) |
| **Categorías** | 1 (General) | ~30 (árbol jerárquico automotriz) |
| **Proveedores** | 1 (Proveedor General) | 5 (AutoZone, Parts Direct, etc.) |
| **Almacenes** | 1 (WH-MAIN-01) | 4 (CDMX, MTY, GDL, Satélite) |
| **Usuarios** | 1 admin | 3 usuarios demo |
| **Métodos Pago** | 5 (sistema) | 5 (sistema) |
| **Productos** | 0 | ~50+ (con inventario demo) |
| **Ventas** | 0 | ~20+ (con pagos) |
| **Clientes** | 0 | 10+ |
| **Tiempo ejecución** | ~100ms | ~5s |

## Troubleshooting

### Error: "Class App\Models\Brand not found"

**Solución:** Verifica que el modelo existe:
```bash
ls app/Models/Brand.php
```

### Error: "SQLSTATE[HY000]: General error"

**Solución:** Verifica que las migraciones se corrieron:
```bash
php artisan migrate:fresh
```

### Duplicados al ejecutar

**Solución:** El seeder es idempotente, pero verifica que no haya error a mitad de ejecución:
```bash
# Revisa los logs
tail -f storage/logs/laravel.log
```

### User sin warehouse_id después de seeding

**Solución:** Verifica que la relación pivot fue creada:
```bash
php artisan tinker
User::find(1)->warehouses;  # Debe retornar [1]
User::find(1)->current_warehouse_id;  # Debe ser 1
```

## Archivo de Configuración

Para cambiar los valores predeterminados del EssentialSeeder, edita:

```
database/seeders/EssentialSeeder.php
```

Ejemplos de cambio:

**Cambiar nombre de brand:**
```php
['name' => 'Mi Marca']
```

**Cambiar nombre de warehouse:**
```php
['name' => 'Almacén Central', 'code' => 'WH-CENTRAL-01']
```

**Cambiar email admin:**
```php
['email' => 'admin@miempresa.com']
```

**Cambiar password admin:**
```php
['password' => bcrypt('mi-contraseña-segura')]
```

Después de cambios, ejecuta:
```bash
php artisan db:seed --class=EssentialSeeder
```

## Notas de Seguridad

⚠️ **NUNCA** usar en producción:
- Email: `admin@hermexpos.app` (cambiar a tu email)
- Password: `password` (usar contraseña fuerte)
- Información de tienda genérica (actualizar con datos reales)

✅ **SIEMPRE** en producción:
- Cambiar credenciales admin
- Actualizar información de tienda
- Configurar encriptación de datos sensibles
- Verificar permisos de usuarios
- Hacer backup antes de cambios

## Relacionado

- [EssentialSeeder.php](../database/seeders/EssentialSeeder.php)
- [DefaultSettingsSeeder.php](../database/seeders/DefaultSettingsSeeder.php)
- [DatabaseSeeder.php](../database/seeders/DatabaseSeeder.php)
- [BrandSeeder.php](../database/seeders/BrandSeeder.php) - Seeder de demo con 8 marcas
- [CategorySeeder.php](../database/seeders/CategorySeeder.php) - Seeder de demo con ~30 categorías
- [SupplierSeeder.php](../database/seeders/SupplierSeeder.php) - Seeder de demo con 5 proveedores
