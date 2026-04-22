# Plan: Importador de Vehículos V2 — Formato Simplificado

**Fecha**: 2026-04-21  
**Estado**: Aprobado — proceder con implementación

---

## 1. Formato de Entrada

El Excel del usuario tiene este formato plano:

| MAKE   | MODEL | YEAR      | CODE | ENGINE | VIN                          |
|--------|-------|-----------|------|--------|------------------------------|
| TOYOTA | YARIS | 2000-2005 | P10  | 1NZFE  | MR2BT9F3101124478 MTM        |

### Mapeo a entidades del sistema

| Columna Excel | Entidad destino | Campo |
|---------------|-----------------|-------|
| `MAKE`     | `VehicleMake`   | `name` — resolve or create |
| `MODEL`    | `VehicleModel`  | `name` — resolve or create bajo el make |
| `YEAR`     | `VehicleModel`  | `year_from` / `year_to` — parsear rango `2000-2005` |
| `CODE`     | `VehicleChassis` | `code` — resolve or create, vincular al model |
| `ENGINE`   | `VehicleEngine`  | `engine_code` — resolve or create bajo el model |
| `VIN`      | `VehicleVin`     | `vin` — campo de texto libre, se guarda tal cual (string completo) |

### Parsing especial

- **YEAR** `"2000-2005"` → `year_from = 2000`, `year_to = 2005`. Si solo `"2005"` → `year_from = year_to = 2005`.
- **CODE** → Se guarda como `VehicleChassis.code` (ej: P10), se vincula al modelo via pivot `vehicle_chassis_model`.
- **ENGINE** → Se guarda como `VehicleEngine.engine_code`, vinculado al model via `model_id`.
- **VIN** → Campo de texto libre opcional. Se guarda el string completo tal cual en `VehicleVin.vin`.

---

## 2. Cambios Requeridos

### 2.1 Backend — `app/Imports/VehicleImport.php`

**Reescritura completa** para el nuevo formato de 6 columnas:

| Método | Cambio |
|--------|--------|
| `getColumns()` | 6 columnas: make, model, year, code, engine, vin |
| `getExampleRow()` | Fila de ejemplo con formato real |
| `mapHeader()` | Mapear headers en inglés y español |
| `validateRow()` | Validar make y model requeridos, año como rango válido |
| `processRow()` | Crear jerarquía completa: Make → Model → Chassis → Engine → VIN |

**processRow() pseudocódigo:**
```php
public function processRow(array $row): mixed
{
    // 1. Resolve/Create Make
    $makeId = resolveOrCreate(VehicleMake, 'name', $row['make']);

    // 2. Parse year range
    [$yearFrom, $yearTo] = parseYearRange($row['year']);

    // 3. Resolve/Create Model (under make, matching name + year overlap)
    $model = findOrCreateModel($makeId, $row['model'], $yearFrom, $yearTo);

    // 4. Resolve/Create Chassis (if CODE provided)
    if ($row['code']) {
        $chassis = findOrCreateChassis($row['code'], $model->id, $yearFrom, $yearTo);
        $model->chassis()->syncWithoutDetaching([$chassis->id]);
    }

    // 5. Resolve/Create Engine (if ENGINE provided)
    $engine = null;
    if ($row['engine']) {
        $engine = findOrCreateEngine($row['engine'], $model->id);
    }

    // 6. Create VIN(s) (if VIN provided)
    if ($row['vin']) {
        createVin($row['vin'], $model->id, $engine?->id);
    }

    return $model;
}
```

### 2.2 Backend — `app/Exports/VehicleImportTemplate.php`

Actualizar `headings()` y `array()` a las 6 columnas del nuevo formato.

### 2.3 Frontend — `resources/js/pages/Vehicles/Import.vue`

Actualizar `importConfig.columns` para reflejar las 6 nuevas columnas:

```typescript
columns: [
    { key: 'make',   label: 'Marca (MAKE)',  type: 'searchable', required: true, searchableEndpoint: '/vehicle-makes/all' },
    { key: 'model',  label: 'Modelo (MODEL)',type: 'text',       required: true },
    { key: 'year',   label: 'Año (YEAR)',    type: 'text',       required: true, placeholder: '2000-2005' },
    { key: 'code',   label: 'Código (CODE)', type: 'text',       placeholder: 'P10' },
    { key: 'engine', label: 'Motor (ENGINE)',type: 'text',       placeholder: '1NZFE' },
    { key: 'vin',    label: 'VIN',           type: 'text',       placeholder: 'MR2BT...' },
]
```

---

## 3. Detalles de Implementación

### Parsing de YEAR range
```php
private function parseYearRange(?string $yearStr): array
{
    if (!$yearStr || trim($yearStr) === '') return [null, null];
    $yearStr = trim($yearStr);

    // "2000-2005" or "2000 - 2005"
    if (preg_match('/^(\d{4})\s*[-–]\s*(\d{4})$/', $yearStr, $matches)) {
        return [(int)$matches[1], (int)$matches[2]];
    }

    // Single year "2005"
    if (is_numeric($yearStr)) {
        $y = (int)$yearStr;
        return [$y, $y];
    }

    return [null, null];
}
```

### Resolve/Create Engine
```php
// VehicleEngine is resolved by engine_code under the model
$engine = VehicleEngine::where('model_id', $model->id)
    ->whereRaw('LOWER(engine_code) = ?', [mb_strtolower($engineCode)])
    ->first();

if (!$engine) {
    $engine = VehicleEngine::create([
        'model_id' => $model->id,
        'engine_code' => strtoupper(trim($engineCode)),
        'fuel_type' => 'N/A',
        'aspiration' => 'N/A',
    ]);
}
```

### Create VIN
```php
// VIN is stored as-is, linked to model and optionally engine
VehicleVin::firstOrCreate(
    ['vin' => strtoupper(trim($vinStr)), 'model_id' => $model->id],
    ['engine_id' => $engine?->id]
);
```

---

## 4. Archivos Afectados

| Archivo | Acción | Repo |
|---------|--------|------|
| `app/Imports/VehicleImport.php` | REWRITE | backend |
| `app/Exports/VehicleImportTemplate.php` | MODIFY | backend |
| `resources/js/pages/Vehicles/Import.vue` | MODIFY | frontend |

---

## 5. Verificación

1. Descargar plantilla → 6 columnas (MAKE, MODEL, YEAR, CODE, ENGINE, VIN)
2. Subir Excel con datos de ejemplo → preview correcto
3. Importar → VehicleMake creado (TOYOTA), VehicleModel creado (YARIS, 2000-2005), VehicleChassis creado (P10), VehicleEngine creado (1NZFE), VehicleVin creado
4. Importar misma fila de nuevo → sin duplicados (idempotente)
