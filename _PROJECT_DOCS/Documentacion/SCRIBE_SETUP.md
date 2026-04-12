# Laravel Scribe - Configuración para Documentación de API

Este documento describe cómo configurar Laravel Scribe para auto-generar la documentación de API.

## ¿Qué es Scribe?

Scribe es una herramienta que auto-genera documentación de API a partir de anotaciones PHPDoc en los controladores de Laravel. Genera:
- HTML interactivo
- Postman collection
- OpenAPI specification

## Instalación

```bash
cd ../pos-backend

# 1. Instalar Scribe
composer require --dev knuckleswtf/scribe

# 2. Publicar configuración
php artisan vendor:publish --tag=scribe-config

# 3. Publicar vistas (opcional)
php artisan vendor:publish --tag=scribe-views
```

## Configuración

Editar `config/scribe.php`:

```php
<?php

return [
    /**
     * Generate API documentation
     */
    'type' => 'laravel', // or 'lumen', 'dingo'

    /**
     * Información básica de la API
     */
    'title' => 'HermeX POS API',
    'description' => 'API REST del Sistema de Punto de Venta HermeX',
    'version' => 'v1.1',
    'base_url' => 'http://localhost:8000/api',
    'scheme' => 'http',
    'host' => 'localhost:8000',

    /**
     * Salida de documentación
     */
    'output_path' => 'storage/docs',

    /**
     * Postman collection
     */
    'postman' => [
        'enabled' => true,
    ],

    /**
     * OpenAPI spec
     */
    'openapi' => [
        'enabled' => true,
    ],

    /**
     * Ejemplos automáticos
     */
    'examples' => [
        'database_seed' => 'DatabaseSeeder', // No. of records
        'models_sample_size' => 3,
    ],

    /**
     * Estrategia de autenticación
     */
    'auth' => [
        'default' => 'bearer', // o 'basic', 'cookie', etc
        'strategies' => [
            'bearer' => [
                'name' => 'Bearer Token',
                'header_name' => 'Authorization',
                'header_value' => 'Bearer XXXXXXXX',
            ],
        ],
    ],

    /**
     * Rutas a documentar
     */
    'routes' => [
        [
            'match' => [
                'domains' => ['*'],
                'prefixes' => ['api/*'],
                'versions' => ['v1'],
            ],
            'include' => [],
            'exclude' => [
                'health',
                'status',
            ],
            'apply' => [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ],
        ],
    ],

    /**
     * Idioma de documentación
     */
    'language' => 'en', // o 'es'

    /**
     * Temas
     */
    'theme' => 'default', // o 'dark'

    /**
     * Ubicación de operaciones personalizadas
     */
    'operations' => [
        'enabled' => true,
        'location' => 'docs/operations',
    ],
];
```

## Cómo Anotar Controladores

### Ejemplo Básico

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * @group Productos
 *
 * APIs para gestionar el catálogo de productos
 */
class ProductController extends Controller
{
    /**
     * Listar productos
     *
     * Obtiene una lista paginada de productos con filtros opcionales.
     * Soporta búsqueda, filtrado por categoría, marca y disponibilidad.
     *
     * @queryParam search string Búsqueda por nombre, SKU o código de barras. Example: filtro
     * @queryParam category_id int Filtrar por categoría. Example: 5
     * @queryParam brand_id int Filtrar por marca. Example: 3
     * @queryParam skip_unavailable boolean Omitir productos sin stock. Example: true
     * @queryParam sort_by string Campo para ordenar (name, price, stock). Example: price
     * @queryParam per_page int Registros por página. Example: 20
     *
     * @authenticated
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Filtro de Aire",
     *       "sku": "FA-001",
     *       "price": 25.50,
     *       "stock": 100
     *     }
     *   ],
     *   "meta": {
     *     "total": 150,
     *     "per_page": 20,
     *     "current_page": 1
     *   }
     * }
     *
     * @response 401 {
     *   "success": false,
     *   "message": "No autenticado"
     * }
     */
    public function index(Request $request)
    {
        // Implementation...
    }

    /**
     * Crear producto
     *
     * Crea un nuevo producto en el catálogo.
     * Permite definir precios, variantes, atributos, compatibilidad y imágenes.
     *
     * @bodyParam name string required Nombre del producto. Example: Filtro de Aire
     * @bodyParam sku string required Código SKU único. Example: FA-001
     * @bodyParam description string Descripción detallada. Example: Filtro de aire de alta calidad
     * @bodyParam category_id int required ID de categoría. Example: 5
     * @bodyParam brand_id int required ID de marca. Example: 3
     * @bodyParam price decimal required Precio base. Example: 25.50
     * @bodyParam cost decimal Costo de compra. Example: 15.00
     * @bodyParam stock int Stock inicial. Example: 100
     *
     * @authenticated
     * @response 201 {
     *   "success": true,
     *   "data": {
     *     "id": 999,
     *     "name": "Filtro de Aire",
     *     "sku": "FA-001",
     *     "price": 25.50,
     *     "created_at": "2026-04-08T10:30:00Z"
     *   }
     * }
     *
     * @response 422 {
     *   "success": false,
     *   "errors": {
     *     "sku": ["El SKU ya existe"]
     *   }
     * }
     */
    public function store(Request $request)
    {
        // Implementation...
    }

    /**
     * Obtener producto
     *
     * @urlParam id int required ID del producto. Example: 1
     *
     * @authenticated
     * @response 200 {...}
     * @response 404 {"success": false, "message": "Producto no encontrado"}
     */
    public function show(Product $product)
    {
        // Implementation...
    }

    /**
     * Actualizar producto
     *
     * @urlParam id int required ID del producto. Example: 1
     * @bodyParam name string Nombre del producto.
     * @bodyParam price decimal Precio base.
     * @bodyParam stock int Stock.
     *
     * @authenticated
     * @response 200 {...}
     * @response 404 {"success": false, "message": "Producto no encontrado"}
     */
    public function update(Request $request, Product $product)
    {
        // Implementation...
    }

    /**
     * Eliminar producto
     *
     * @urlParam id int required ID del producto. Example: 1
     *
     * @authenticated
     * @response 204
     * @response 404 {"success": false, "message": "Producto no encontrado"}
     */
    public function destroy(Product $product)
    {
        // Implementation...
    }
}
```

## Etiquetas Disponibles

### Información de Endpoint
- `@group GroupName` - Agrupar endpoints relacionados
- `@authenticated` - Requiere autenticación
- `@apiResource` - Laravel resource controller
- `@hideFromAPIDocumentation` - Excluir del docs

### Parámetros
- `@queryParam name type Descripción. Example: valor`
- `@bodyParam name type Descripción. Example: valor`
- `@urlParam name type Descripción. Example: valor`
- `@headerParam name Descripción. Example: valor`

### Respuestas
- `@response status {...contenido...}` - Ejemplo de response
- `@response 200` - Sin contenido (solo status)

### Metadata
- `@deprecated` - Marcar como deprecado
- `@todo Description` - Tareas pendientes

## Generar Documentación

```bash
# Generar toda la documentación
php artisan scribe:generate

# Servir documentación en desarrollo
php artisan serve:docs

# Ir a http://localhost:8000/docs
```

## Salida Generada

Se crea en `storage/docs/`:

```
storage/docs/
├── index.html              # Documentación HTML
├── postman-collection.json # Postman collection
├── openapi.yaml            # OpenAPI spec
└── screenshots/            # Capturas automáticas (si se configura)
```

## Integración con VitePress

Después de generar, copiar la documentación a la carpeta de documentación:

```bash
# Desde la raíz del proyecto
cp storage/docs/openapi.yaml _PROJECT_DOCS/Documentacion/docs/manual-tecnico/api/
cp storage/docs/postman-collection.json _PROJECT_DOCS/Documentacion/public/
```

## Mejores Prácticas

### ✅ Hacer
- Anotar todos los endpoints públicos
- Incluir ejemplos realistas
- Documentar todos los parámetros
- Explicar qué hace cada endpoint
- Incluir ejemplos de respuestas de error

### ❌ No Hacer
- Dejar endpoints sin documentar
- Usar ejemplos genéricos o "ejemplo"
- Omitir casos de error común
- Documentación desincronizada del código
- Cambiar endpoint sin actualizar docs

## Validación

Para verificar que la documentación es correcta:

```bash
# Generar y ver en navegador
php artisan scribe:generate
open http://localhost:8000/docs

# Verificar que Postman collection es válida
postman validate postman-collection.json

# Verificar OpenAPI spec
npm install -g swagger-cli
swagger-cli validate openapi.yaml
```

## Automatización

Agregar a `.github/workflows/docs.yml`:

```yaml
name: Regenerar Documentación API

on:
  push:
    branches: [main, develop]
    paths:
      - 'app/Http/Controllers/Api/**'
      - '.github/workflows/docs.yml'

jobs:
  regenerate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: php-actions/composer@v6
      - name: Generar documentación
        run: php artisan scribe:generate
      - name: Commit cambios
        run: |
          git config user.email "docs@hermexpos.app"
          git config user.name "Docs Bot"
          git add storage/docs/
          git commit -m "docs: regenerar API docs" || true
          git push
```

---

**Referencia:** https://scribe.readthedocs.io/  
**Actualizado:** 2026-04-08
