# Single Warehouse Mode Configuration

This POS system supports a **single warehouse mode** that simplifies the checkout process by automatically assigning all sales to a predefined default warehouse.

## Configuration

Add the following to your `.env` file:

```env
# Enable single warehouse mode
POS_SINGLE_WAREHOUSE_MODE=true

# Set the default warehouse ID
POS_DEFAULT_WAREHOUSE_ID=1
```

## How It Works

### When Enabled (`POS_SINGLE_WAREHOUSE_MODE=true`)
- Cashiers don't need to select a warehouse for each product
- All cart items automatically use the warehouse specified in `POS_DEFAULT_WAREHOUSE_ID`
- Inventory is deducted from the  default warehouse when sales are confirmed
- UI is simpler and faster for single-location businesses

### When Disabled (`POS_SINGLE_WAREHOUSE_MODE=false`)
- System operates in multi-warehouse mode
- Cashiers can select warehouse for each product (if UI supports it)
- Useful for businesses with multiple locations

## Finding Your Warehouse ID

To find your warehouse ID, check the `warehouses` table in your database:

```sql
SELECT id, name, code FROM warehouses;
```

The `id` column contains the warehouse ID to use in `POS_DEFAULT_WAREHOUSE_ID`.

## Testing

1. **Set configuration in `.env`:**
   ```env
   POS_SINGLE_WAREHOUSE_MODE=true
   POS_DEFAULT_WAREHOUSE_ID=1
   ```

2. **Clear config cache (if using cache):**
   ```bash
   php artisan config:clear
   ```

3. **Test POS:**
   - Navigate to `/pos`
   - Add products to cart
   - Process a sale
   - Verify order items have correct `warehouse_id`
   - Check inventory was deducted from correct warehouse

## API Response

The frontend fetches POS settings from `/api/v1/pos/settings`:

```json
{
  "single_warehouse_mode": true,
  "default_warehouse_id": 1,
  "default_warehouse": {
    "id": 1,
    "name": "Almacén Principal",
    "code": "ALM-01"
  }
}
```

## Files Modified

### Backend
- `config/pos.php` - Configuration file
- `app/Http/Controllers/Api/PosController.php` - API controller
- `routes/api_sales_orders.php` - Added route for settings endpoint
- `app/Services/SalesOrderService.php` - Auto-confirm paid orders

### Frontend
- `services/api/pos.ts` - POS API service
- `composables/usePosSettings.ts` - Settings composable
- `composables/usePOS.ts` - Integrated auto-warehouse assignment
- `pages/pos/Index.vue` - Updated product selection logic
