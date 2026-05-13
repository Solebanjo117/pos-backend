PRAGMA foreign_keys=OFF;
BEGIN TRANSACTION;
CREATE TABLE "activity_log" ("id" integer primary key autoincrement not null, "log_name" varchar, "description" text not null, "subject_type" varchar, "subject_id" integer, "causer_type" varchar, "causer_id" integer, "properties" text, "created_at" datetime, "updated_at" datetime, "event" varchar, "batch_uuid" varchar);
CREATE TABLE "alternative_products" ("id" integer primary key autoincrement not null, "primary_product_id" integer not null, "alternative_product_id" integer not null, "relationship_type" varchar check ("relationship_type" in ('compatible', 'substitute', 'upgrade')) not null default 'compatible', "created_at" datetime, "updated_at" datetime, foreign key("primary_product_id") references "products"("id") on delete cascade, foreign key("alternative_product_id") references "products"("id") on delete cascade);
CREATE TABLE "attribute_definitions" ("id" integer primary key autoincrement not null, "name" varchar not null, "data_type" varchar check ("data_type" in ('decimal', 'integer', 'string', 'boolean')) not null default 'string', "unit" varchar, "applicable_categories" text, "is_required" tinyint(1) not null default '0', "created_at" datetime, "updated_at" datetime);
CREATE TABLE "brand_sequences" ("id" integer primary key autoincrement not null, "brand_id" integer not null, "next_value" integer not null default '1', "created_at" datetime, "updated_at" datetime, foreign key("brand_id") references "brands"("id") on delete cascade);
CREATE TABLE "brand_supplier" ("id" integer primary key autoincrement not null, "brand_id" integer not null, "supplier_id" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("brand_id") references "brands"("id") on delete cascade, foreign key("supplier_id") references "suppliers"("id") on delete cascade);
CREATE TABLE "brands" ("id" integer primary key autoincrement not null, "name" varchar not null, "description" text, "logo_url" varchar, "status" varchar check ("status" in ('active', 'inactive')) not null default 'active', "created_at" datetime, "updated_at" datetime, "website" varchar, "prefix" varchar);
CREATE TABLE "cache" ("key" varchar not null, "value" text not null, "expiration" integer not null, primary key ("key"));
CREATE TABLE "cache_locks" ("key" varchar not null, "owner" varchar not null, "expiration" integer not null, primary key ("key"));
CREATE TABLE "cash_movements" ("id" integer primary key autoincrement not null, "pos_session_id" integer not null, "user_id" integer not null, "type" varchar check ("type" in ('in', 'out')) not null, "amount" numeric not null, "reason" varchar not null, "notes" text, "created_at" datetime, "updated_at" datetime, foreign key("pos_session_id") references "pos_sessions"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete cascade);
CREATE TABLE "categories" ("id" integer primary key autoincrement not null, "name" varchar not null, "slug" varchar not null, "parent_id" integer, "description" text, "icon" varchar, "priority" integer not null default '0', "created_at" datetime, "updated_at" datetime, "default_attributes" text, "pricing_formula" text, "default_margin_percent" numeric, "applies_itbm" tinyint(1) not null default '0', "itbm_percent" numeric not null default '7', "default_cost" numeric, "min_suggested_price" numeric, foreign key("parent_id") references "categories"("id") on delete cascade);
CREATE TABLE "customer_transactions" (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    customer_id INTEGER NOT NULL,
                    type TEXT CHECK(type IN ('sale', 'payment', 'credit_note', 'adjustment', 'store_credit_used')) NOT NULL,
                    reference_type VARCHAR(100),
                    reference_id INTEGER,
                    amount NUMERIC(12, 2) NOT NULL,
                    balance_before NUMERIC(12, 2) NOT NULL,
                    balance_after NUMERIC(12, 2) NOT NULL,
                    description TEXT,
                    transaction_date DATETIME NOT NULL,
                    created_by INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                    FOREIGN KEY(created_by) REFERENCES users(id)
                );
CREATE TABLE "customers" ("id" integer primary key autoincrement not null, "code" varchar not null, "type" varchar check ("type" in ('individual', 'business')) not null default 'individual', "name" varchar not null, "email" varchar, "phone" varchar, "tax_id" varchar, "tax_name" varchar, "address" text, "city" varchar, "state" varchar, "country" varchar not null default 'México', "postal_code" varchar, "credit_limit" numeric not null default '0', "credit_days" integer not null default '0', "current_balance" numeric not null default '0', "contact_person" varchar, "contact_phone" varchar, "notes" text, "status" varchar check ("status" in ('active', 'inactive', 'blocked')) not null default 'active', "created_at" datetime, "updated_at" datetime, "deleted_at" datetime);
CREATE TABLE "failed_jobs" ("id" integer primary key autoincrement not null, "uuid" varchar not null, "connection" text not null, "queue" text not null, "payload" text not null, "exception" text not null, "failed_at" datetime not null default CURRENT_TIMESTAMP);
CREATE TABLE "inventory" ("id" integer primary key autoincrement not null, "product_id" integer not null, "warehouse_id" integer not null, "stock_available" integer not null default '0', "stock_reserved" integer not null default '0', "stock_on_order" integer not null default '0', "reorder_point" integer not null default '0', "target_level" integer not null default '0', "created_at" datetime, "updated_at" datetime, foreign key("product_id") references "products"("id") on delete cascade, foreign key("warehouse_id") references "warehouses"("id") on delete cascade);
CREATE TABLE "inventory_movements" ("id" integer primary key autoincrement not null, "movement_type" varchar not null, "product_id" integer not null, "warehouse_id" integer not null, "destination_warehouse_id" integer, "quantity" integer not null, "reference_type" varchar, "reference_id" integer, "notes" text, "user_id" integer, "created_at" datetime, "updated_at" datetime, "product_batch_id" integer, "variant_id" integer, "reason" varchar, "supplier_id" integer, foreign key("product_batch_id") references product_batches("id") on delete set null on update no action, foreign key("product_id") references products("id") on delete cascade on update no action, foreign key("warehouse_id") references warehouses("id") on delete cascade on update no action, foreign key("destination_warehouse_id") references warehouses("id") on delete cascade on update no action, foreign key("user_id") references users("id") on delete set null on update no action, foreign key("variant_id") references product_variants("id") on delete set null on update no action, foreign key("supplier_id") references "suppliers"("id") on delete set null);
CREATE TABLE "inventory_transfer_items" ("id" integer primary key autoincrement not null, "inventory_transfer_id" integer not null, "product_id" integer not null, "variant_id" integer, "quantity" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("inventory_transfer_id") references "inventory_transfers"("id") on delete cascade, foreign key("product_id") references "products"("id") on delete restrict, foreign key("variant_id") references "product_variants"("id") on delete set null);
CREATE TABLE "inventory_transfers" ("id" integer primary key autoincrement not null, "reference" varchar not null, "from_warehouse_id" integer not null, "to_warehouse_id" integer not null, "status" varchar check ("status" in ('draft', 'in_transit', 'completed', 'cancelled')) not null default 'draft', "created_by" integer not null, "received_by" integer, "notes" text, "dispatched_at" datetime, "received_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("from_warehouse_id") references "warehouses"("id") on delete restrict, foreign key("to_warehouse_id") references "warehouses"("id") on delete restrict, foreign key("created_by") references "users"("id") on delete restrict, foreign key("received_by") references "users"("id") on delete set null);
CREATE TABLE "job_batches" ("id" varchar not null, "name" varchar not null, "total_jobs" integer not null, "pending_jobs" integer not null, "failed_jobs" integer not null, "failed_job_ids" text not null, "options" text, "cancelled_at" integer, "created_at" integer not null, "finished_at" integer, primary key ("id"));
CREATE TABLE "jobs" ("id" integer primary key autoincrement not null, "queue" varchar not null, "payload" text not null, "attempts" integer not null, "reserved_at" integer, "available_at" integer not null, "created_at" integer not null);
CREATE TABLE "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);
CREATE TABLE "oem_references" ("id" integer primary key autoincrement not null, "product_id" integer not null, "oem_code" varchar not null, "manufacturer" varchar, "created_at" datetime, "updated_at" datetime, foreign key("product_id") references "products"("id") on delete cascade);
CREATE TABLE "password_reset_tokens" ("email" varchar not null, "token" varchar not null, "created_at" datetime, primary key ("email"));
CREATE TABLE "payment_methods" ("id" integer primary key autoincrement not null, "name" varchar not null, "code" varchar not null, "is_active" tinyint(1) not null default '1', "is_system" tinyint(1) not null default '0', "requires_reference" tinyint(1) not null default '0', "requires_cash_count" tinyint(1) not null default '0', "sort_order" integer not null default '0', "created_at" datetime, "updated_at" datetime);
CREATE TABLE "personal_access_tokens" ("id" integer primary key autoincrement not null, "tokenable_type" varchar not null, "tokenable_id" integer not null, "name" text not null, "token" varchar not null, "abilities" text, "last_used_at" datetime, "expires_at" datetime, "created_at" datetime, "updated_at" datetime);
CREATE TABLE "pos_sessions" ("id" integer primary key autoincrement not null, "user_id" integer not null, "opened_at" datetime, "closed_at" datetime, "opening_amount" numeric not null default ('0'), "closing_amount" numeric, "expected_amount" numeric, "status" varchar not null default ('open'), "notes" text, "created_at" datetime, "updated_at" datetime, "closing_details" text, "warehouse_id" integer, foreign key("user_id") references users("id") on delete cascade on update no action, foreign key("warehouse_id") references "warehouses"("id") on delete set null);
CREATE TABLE "pricing_formulas" ("id" integer primary key autoincrement not null, "name" varchar not null, "formula" varchar not null, "description" text, "is_preset" tinyint(1) not null default '0', "is_active" tinyint(1) not null default '1', "sort_order" integer not null default '0', "created_at" datetime, "updated_at" datetime);
CREATE TABLE "product_attributes" ("id" integer primary key autoincrement not null, "product_id" integer not null, "name" varchar not null, "value" varchar not null, "unit" varchar, "created_at" datetime, "updated_at" datetime, "variant_id" integer, foreign key("product_id") references products("id") on delete cascade on update no action, foreign key("variant_id") references "product_variants"("id") on delete cascade);
CREATE TABLE "product_batches" ("id" integer primary key autoincrement not null, "product_id" integer not null, "batch_number" varchar not null, "manufacturing_date" date, "expiry_date" date, "cost" numeric not null default '0', "supplier_id" integer, "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, "notes" text, foreign key("product_id") references "products"("id") on delete cascade, foreign key("supplier_id") references "suppliers"("id") on delete set null);
CREATE TABLE "product_extra_categories" ("id" integer primary key autoincrement not null, "product_id" integer not null, "category_id" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("product_id") references "products"("id") on delete cascade, foreign key("category_id") references "categories"("id") on delete cascade);
CREATE TABLE "product_inventory" ("id" integer primary key autoincrement not null, "product_id" integer, "warehouse_id" integer not null, "quantity_available" integer not null default ('0'), "quantity_reserved" integer not null default ('0'), "reorder_point" integer not null default ('10'), "reorder_quantity" integer not null default ('50'), "location_in_warehouse" varchar, "last_restocked_at" datetime, "created_at" datetime, "updated_at" datetime, "variant_id" integer, foreign key("warehouse_id") references warehouses("id") on delete cascade on update no action, foreign key("product_id") references products("id") on delete cascade on update no action, foreign key("variant_id") references "product_variants"("id") on delete cascade);
CREATE TABLE "product_lines" ("id" integer primary key autoincrement not null, "brand_id" integer not null, "name" varchar not null, "description" text, "priority" integer not null default '0', "created_at" datetime, "updated_at" datetime, foreign key("brand_id") references "brands"("id") on delete cascade);
CREATE TABLE "product_media" ("id" integer primary key autoincrement not null, "product_id" integer not null, "media_type" varchar check ("media_type" in ('image', 'pdf', 'video')) not null default 'image', "category" varchar check ("category" in ('technical_sheet', 'diagram', 'manual', 'certificate', 'photo')) not null default 'photo', "file_path" varchar not null, "original_filename" varchar not null, "file_size" integer not null default '0', "display_order" integer not null default '0', "created_at" datetime, "updated_at" datetime, foreign key("product_id") references "products"("id") on delete cascade);
CREATE TABLE "product_pricing" ("id" integer primary key autoincrement not null, "product_id" integer, "base_price" numeric not null default ('0'), "msrp" numeric not null default ('0'), "cost" numeric not null default ('0'), "margin_percentage" numeric not null default ('0'), "tax_rate" numeric not null default ('0'), "max_discount_percentage" numeric not null default ('0'), "created_at" datetime, "updated_at" datetime, "variant_id" integer, "tax_type" varchar not null default 'standard', "pricing_formula" varchar, "cost_tax_rate" numeric, "sale_tax_rate" numeric, "cost_base" numeric, "cost_miscellaneous" numeric not null default '0', foreign key("product_id") references products("id") on delete cascade on update no action, foreign key("variant_id") references "product_variants"("id") on delete cascade);
CREATE TABLE "product_suppliers" ("id" integer primary key autoincrement not null, "product_id" integer, "supplier_id" integer not null, "supplier_cost" numeric not null default ('0'), "lead_time_days" integer not null default ('0'), "minimum_order_quantity" integer not null default ('1'), "is_preferred" tinyint(1) not null default ('0'), "created_at" datetime, "updated_at" datetime, "variant_id" integer, foreign key("supplier_id") references suppliers("id") on delete cascade on update no action, foreign key("product_id") references products("id") on delete cascade on update no action, foreign key("variant_id") references "product_variants"("id") on delete cascade);
CREATE TABLE "product_variants" ("id" integer primary key autoincrement not null, "product_id" integer not null, "brand_id" integer not null, "sku" varchar not null, "upc" varchar, "status" varchar not null default ('active'), "is_default" tinyint(1) not null default ('0'), "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, "system_id" varchar, foreign key("brand_id") references brands("id") on delete restrict on update no action, foreign key("product_id") references products("id") on delete cascade on update no action);
CREATE TABLE "product_vehicle_compatibility" ("id" integer primary key autoincrement not null, "product_id" integer not null, "engine_id" integer, "notes" text, "created_at" datetime, "updated_at" datetime, "chassis_id" integer, "all_engines" tinyint(1) not null default '0', "all_chassis" tinyint(1) not null default '0', foreign key("product_id") references products("id") on delete cascade on update no action, foreign key("engine_id") references vehicle_engines("id") on delete cascade on update no action, foreign key("chassis_id") references "vehicle_chassis"("id") on delete cascade);
CREATE TABLE "product_vins" ("id" integer primary key autoincrement not null, "product_id" integer not null, "vin" varchar not null, "notes" varchar, "created_at" datetime, "updated_at" datetime, foreign key("product_id") references products("id") on delete cascade on update no action);
CREATE TABLE "products" ("id" integer primary key autoincrement not null, "sku_base" varchar not null, "product_line_id" integer, "name" varchar not null, "description" text, "status" varchar not null default ('active'), "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, "batch_tracking" tinyint(1) not null default ('0'), "category_id" integer, "image_url" varchar, "is_universal" tinyint(1) not null default ('0'), "alternate_code" varchar, "pricing_formula" varchar, "engine_id" integer, "system_id" varchar not null, foreign key("engine_id") references vehicle_engines("id") on delete set null on update no action, foreign key("product_line_id") references product_lines("id") on delete set null on update no action, foreign key("category_id") references categories("id") on delete set null on update no action);
CREATE TABLE "purchase_order_items" ("id" integer primary key autoincrement not null, "purchase_order_id" integer not null, "product_id" integer not null, "quantity_ordered" integer not null, "quantity_received" integer not null default ('0'), "unit_cost" numeric not null, "subtotal" numeric not null, "notes" text, "created_at" datetime, "updated_at" datetime, "variant_id" integer, "supplier_id" integer, "status" varchar not null default 'pending', "units_per_package" integer not null default '1', "pre_stock_applied_qty" integer not null default '0', foreign key("variant_id") references product_variants("id") on delete restrict on update no action, foreign key("purchase_order_id") references purchase_orders("id") on delete cascade on update no action, foreign key("product_id") references products("id") on delete restrict on update no action, foreign key("supplier_id") references "suppliers"("id") on delete restrict);
CREATE TABLE "purchase_order_payments" ("id" integer primary key autoincrement not null, "purchase_order_id" integer not null, "user_id" integer, "amount" numeric not null, "method" varchar not null default 'cash', "reference_number" varchar, "notes" text, "paid_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("purchase_order_id") references "purchase_orders"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete set null);
CREATE TABLE "purchase_orders" ("id" integer primary key autoincrement not null, "po_number" varchar not null, "supplier_id" integer, "warehouse_id" integer not null, "user_id" integer, "status" varchar not null default ('draft'), "order_date" date not null, "expected_date" date, "received_date" date, "subtotal" numeric not null default ('0'), "tax_amount" numeric not null default ('0'), "shipping_cost" numeric not null default ('0'), "total" numeric not null default ('0'), "notes" text, "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, "payment_status" varchar not null default ('pending'), foreign key("warehouse_id") references warehouses("id") on delete restrict on update no action, foreign key("user_id") references users("id") on delete set null on update no action, foreign key("supplier_id") references "suppliers"("id") on delete restrict);
CREATE TABLE "sales_order_items" ("id" integer primary key autoincrement not null, "sales_order_id" integer not null, "product_id" integer not null, "warehouse_id" integer not null, "quantity" integer not null, "unit_price" numeric not null, "discount_percent" numeric not null default ('0'), "discount_amount" numeric not null default ('0'), "tax_percent" numeric not null default ('0'), "tax_amount" numeric not null default ('0'), "subtotal" numeric not null, "total" numeric not null, "created_at" datetime, "updated_at" datetime, "variant_id" integer, "unit_cost" numeric not null default ('0'), "seller_id" integer, "notes" text, "is_custom_line" tinyint(1) not null default '0', "custom_description" varchar, foreign key("variant_id") references product_variants("id") on delete restrict on update no action, foreign key("sales_order_id") references sales_orders("id") on delete cascade on update no action, foreign key("product_id") references products("id") on delete no action on update no action, foreign key("warehouse_id") references warehouses("id") on delete no action on update no action, foreign key("seller_id") references "users"("id") on delete set null);
CREATE TABLE "sales_order_payments" (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    sales_order_id INTEGER NOT NULL,
                    amount NUMERIC(12, 2) NOT NULL,
                    method TEXT NOT NULL,
                    notes TEXT,
                    paid_at DATETIME NOT NULL,
                    created_by INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY(sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
                    FOREIGN KEY(created_by) REFERENCES users(id)
                );
CREATE TABLE "sales_orders" ("id" integer primary key autoincrement not null, "order_number" varchar not null, "customer_id" integer, "user_id" integer not null, "date" date not null, "status" varchar not null default ('draft'), "subtotal" numeric not null default ('0'), "tax_amount" numeric not null default ('0'), "discount_amount" numeric not null default ('0'), "shipping_cost" numeric not null default ('0'), "total" numeric not null default ('0'), "payment_status" varchar not null default ('pending'), "payment_method" varchar, "notes" text, "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, "pos_session_id" integer, "audit_logs" text, "internal_notes" text, "sent_to_warehouse_at" datetime, "prepared_at" datetime, "prepared_by" integer, foreign key("pos_session_id") references pos_sessions("id") on delete set null on update no action, foreign key("customer_id") references customers("id") on delete set null on update no action, foreign key("prepared_by") references "users"("id") on delete set null);
CREATE TABLE "sales_quote_items" ("id" integer primary key autoincrement not null, "sales_quote_id" integer not null, "product_id" integer not null, "variant_id" integer, "warehouse_id" integer not null, "quantity" integer not null, "unit_price" numeric not null, "discount_percent" numeric not null default '0', "discount_amount" numeric not null default '0', "tax_percent" numeric not null default '0', "tax_amount" numeric not null default '0', "subtotal" numeric not null, "total" numeric not null, "created_at" datetime, "updated_at" datetime, "notes" text, "is_custom_line" tinyint(1) not null default '0', "custom_description" varchar, foreign key("sales_quote_id") references "sales_quotes"("id") on delete cascade, foreign key("product_id") references "products"("id"), foreign key("warehouse_id") references "warehouses"("id"));
CREATE TABLE "sales_quotes" ("id" integer primary key autoincrement not null, "quote_number" varchar not null, "customer_id" integer, "user_id" integer not null, "warehouse_id" integer, "date" date not null, "valid_until" date, "status" varchar not null default ('draft'), "subtotal" numeric not null default ('0'), "tax_amount" numeric not null default ('0'), "discount_amount" numeric not null default ('0'), "total" numeric not null default ('0'), "converted_order_id" integer, "notes" text, "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, "pos_session_id" integer, "internal_notes" text, "sent_to_cashier_at" datetime, foreign key("converted_order_id") references sales_orders("id") on delete set null on update no action, foreign key("warehouse_id") references warehouses("id") on delete set null on update no action, foreign key("user_id") references users("id") on delete no action on update no action, foreign key("customer_id") references customers("id") on delete set null on update no action, foreign key("pos_session_id") references "pos_sessions"("id") on delete set null);
CREATE TABLE "sales_return_items" ("id" integer primary key autoincrement not null, "sales_return_id" integer not null, "sales_order_item_id" integer not null, "product_id" integer not null, "variant_id" integer, "quantity" integer not null, "unit_price" numeric not null, "subtotal" numeric not null, "created_at" datetime, "updated_at" datetime, "user_id" integer, "seller_id" integer, foreign key("user_id") references users("id") on delete set null on update no action, foreign key("sales_return_id") references sales_returns("id") on delete cascade on update no action, foreign key("sales_order_item_id") references sales_order_items("id") on delete cascade on update no action, foreign key("product_id") references products("id") on delete no action on update no action, foreign key("variant_id") references product_variants("id") on delete set null on update no action, foreign key("seller_id") references "users"("id") on delete set null);
CREATE TABLE "sales_returns" ("id" integer primary key autoincrement not null, "return_number" varchar not null, "sales_order_id" integer not null, "warehouse_id" integer not null, "customer_id" integer, "user_id" integer not null, "status" varchar check ("status" in ('pending', 'approved', 'rejected')) not null default 'pending', "reason" varchar, "total_amount" numeric not null default '0', "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, "refund_method" varchar not null default 'balance', "refund_notes" text, foreign key("sales_order_id") references "sales_orders"("id") on delete cascade, foreign key("warehouse_id") references "warehouses"("id"), foreign key("customer_id") references "customers"("id") on delete set null, foreign key("user_id") references "users"("id"));
CREATE TABLE "sessions" ("id" varchar not null, "user_id" integer, "ip_address" varchar, "user_agent" text, "payload" text not null, "last_activity" integer not null, primary key ("id"));
CREATE TABLE "settings" ("id" integer primary key autoincrement not null, "key" varchar not null, "value" text, "type" varchar not null default 'string', "group" varchar not null default 'general', "description" text, "created_at" datetime, "updated_at" datetime);
CREATE TABLE "suppliers" ("id" integer primary key autoincrement not null, "name" varchar not null, "contact_name" varchar, "email" varchar, "phone" varchar, "address" text, "payment_terms" varchar, "status" varchar check ("status" in ('active', 'inactive')) not null default 'active', "created_at" datetime, "updated_at" datetime, "city" varchar, "country" varchar not null default 'Panamá', "lead_time_days" integer, "notes" text, "deleted_at" datetime);
CREATE TABLE "supply_list_items" ("id" integer primary key autoincrement not null, "product_id" integer not null, "variant_id" integer, "supplier_id" integer, "added_by" integer not null, "quantity_needed" numeric not null default '1', "notes" text, "created_at" datetime, "updated_at" datetime, "unit_cost" numeric default '0', "tax_rate" numeric default '7', "sale_price" numeric default '0', "stock_added_qty" integer not null default '0', foreign key("product_id") references "products"("id") on delete cascade, foreign key("variant_id") references "product_variants"("id") on delete set null, foreign key("supplier_id") references "suppliers"("id") on delete set null, foreign key("added_by") references "users"("id"));
CREATE TABLE "system_sequences" ("id" integer primary key autoincrement not null, "key" varchar not null, "next_value" integer not null default '1', "created_at" datetime, "updated_at" datetime);
CREATE TABLE "user_warehouse" ("id" integer primary key autoincrement not null, "user_id" integer not null, "warehouse_id" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, foreign key("warehouse_id") references "warehouses"("id") on delete cascade);
CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "created_at" datetime, "updated_at" datetime, "two_factor_secret" text, "two_factor_recovery_codes" text, "two_factor_confirmed_at" datetime, "current_warehouse_id" integer, "role" varchar not null default 'cashier', "is_active" tinyint(1) not null default '1', "username" varchar, foreign key("current_warehouse_id") references "warehouses"("id") on delete set null);
CREATE TABLE "vehicle_chassis" ("id" integer primary key autoincrement not null, "code" varchar not null, "name" varchar, "model_id" integer, "year_from" varchar, "year_to" varchar, "notes" text, "created_at" datetime, "updated_at" datetime, foreign key("model_id") references "vehicle_models"("id") on delete set null);
CREATE TABLE "vehicle_chassis_model" ("id" integer primary key autoincrement not null, "chassis_id" integer not null, "model_id" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("chassis_id") references "vehicle_chassis"("id") on delete cascade, foreign key("model_id") references "vehicle_models"("id") on delete cascade);
CREATE TABLE "vehicle_engine_model" ("id" integer primary key autoincrement not null, "engine_id" integer not null, "model_id" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("engine_id") references "vehicle_engines"("id") on delete cascade, foreign key("model_id") references "vehicle_models"("id") on delete cascade);
CREATE TABLE "vehicle_engines" ("id" integer primary key autoincrement not null, "model_id" integer, "engine_code" varchar not null, "displacement" varchar, "fuel_type" varchar, "created_at" datetime, "updated_at" datetime, "aspiration" varchar, foreign key("model_id") references "vehicle_models"("id") on delete set null);
CREATE TABLE "vehicle_makes" ("id" integer primary key autoincrement not null, "name" varchar not null, "created_at" datetime, "updated_at" datetime);
CREATE TABLE "vehicle_models" ("id" integer primary key autoincrement not null, "make_id" integer not null, "name" varchar not null, "year_from" integer not null, "year_to" integer not null, "created_at" datetime, "updated_at" datetime, "generation" varchar, foreign key("make_id") references "vehicle_makes"("id") on delete cascade);
CREATE TABLE "vehicle_vins" (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    engine_id INTEGER NULL,
                    model_id INTEGER NULL,
                    vin VARCHAR(40) NOT NULL,
                    notes VARCHAR(255) NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    FOREIGN KEY (engine_id) REFERENCES vehicle_engines(id) ON DELETE CASCADE,
                    FOREIGN KEY (model_id) REFERENCES vehicle_models(id) ON DELETE CASCADE
                );
CREATE TABLE "warehouse_batches" ("id" integer primary key autoincrement not null, "warehouse_id" integer not null, "product_batch_id" integer not null, "quantity" numeric not null default '0', "quantity_reserved" numeric not null default '0', "created_at" datetime, "updated_at" datetime, foreign key("warehouse_id") references "warehouses"("id") on delete cascade, foreign key("product_batch_id") references "product_batches"("id") on delete cascade);
CREATE TABLE "warehouses" ("id" integer primary key autoincrement not null, "code" varchar not null, "name" varchar not null, "address" text, "is_active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime, "status" varchar not null default 'active', "location" text, "capacity" integer, "notes" text);
CREATE TABLE "work_order_items" ("id" integer primary key autoincrement not null, "work_order_id" integer not null, "product_id" integer not null, "variant_id" integer, "warehouse_id" integer not null, "quantity" integer not null default '1', "unit_price" numeric not null default '0', "notes" varchar, "created_at" datetime, "updated_at" datetime, foreign key("work_order_id") references "work_orders"("id") on delete cascade, foreign key("product_id") references "products"("id"), foreign key("variant_id") references "product_variants"("id") on delete set null, foreign key("warehouse_id") references "warehouses"("id"));
CREATE TABLE "work_orders" ("id" integer primary key autoincrement not null, "folio" varchar, "customer_id" integer, "warehouse_id" integer not null, "created_by" integer not null, "assigned_to" integer, "status" varchar check ("status" in ('pending', 'in_progress', 'done', 'delivered', 'cancelled')) not null default 'pending', "vehicle_description" varchar, "notes" text, "labour_cost" numeric not null default '0', "estimated_delivery_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("customer_id") references "customers"("id") on delete set null, foreign key("warehouse_id") references "warehouses"("id"), foreign key("created_by") references "users"("id"), foreign key("assigned_to") references "users"("id") on delete set null);
CREATE INDEX "activity_log_log_name_index" on "activity_log" ("log_name");
CREATE INDEX "alternative_products_alternative_product_id_index" on "alternative_products" ("alternative_product_id");
CREATE INDEX "alternative_products_primary_product_id_index" on "alternative_products" ("primary_product_id");
CREATE UNIQUE INDEX "alternative_products_unique" on "alternative_products" ("primary_product_id", "alternative_product_id");
CREATE INDEX "attribute_definitions_data_type_index" on "attribute_definitions" ("data_type");
CREATE UNIQUE INDEX "brand_sequences_brand_id_unique" on "brand_sequences" ("brand_id");
CREATE UNIQUE INDEX "brand_supplier_unique" on "brand_supplier" ("brand_id", "supplier_id");
CREATE UNIQUE INDEX "brands_name_unique" on "brands" ("name");
CREATE UNIQUE INDEX "brands_prefix_unique" on "brands" ("prefix");
CREATE INDEX "brands_status_index" on "brands" ("status");
CREATE INDEX "cache_expiration_index" on "cache" ("expiration");
CREATE INDEX "cache_locks_expiration_index" on "cache_locks" ("expiration");
CREATE INDEX "categories_parent_id_priority_index" on "categories" ("parent_id", "priority");
CREATE UNIQUE INDEX "categories_slug_unique" on "categories" ("slug");
CREATE INDEX "causer" on "activity_log" ("causer_type", "causer_id");
CREATE INDEX customer_transactions_customer_id_index ON customer_transactions(customer_id);
CREATE INDEX customer_transactions_reference_type_reference_id_index ON customer_transactions(reference_type, reference_id);
CREATE INDEX customer_transactions_transaction_date_index ON customer_transactions(transaction_date);
CREATE INDEX customer_transactions_type_index ON customer_transactions(type);
CREATE INDEX "customers_code_index" on "customers" ("code");
CREATE UNIQUE INDEX "customers_code_unique" on "customers" ("code");
CREATE INDEX "customers_email_index" on "customers" ("email");
CREATE INDEX "customers_status_index" on "customers" ("status");
CREATE INDEX "customers_type_index" on "customers" ("type");
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs" ("uuid");
CREATE INDEX "inventory_movements_created_at_index" on "inventory_movements" ("created_at");
CREATE INDEX "inventory_movements_movement_type_index" on "inventory_movements" ("movement_type");
CREATE INDEX "inventory_movements_product_id_index" on "inventory_movements" ("product_id");
CREATE INDEX "inventory_movements_reference_type_reference_id_index" on "inventory_movements" ("reference_type", "reference_id");
CREATE INDEX "inventory_movements_supplier_id_index" on "inventory_movements" ("supplier_id");
CREATE INDEX "inventory_movements_variant_id_index" on "inventory_movements" ("variant_id");
CREATE INDEX "inventory_movements_warehouse_id_index" on "inventory_movements" ("warehouse_id");
CREATE UNIQUE INDEX "inventory_transfers_reference_unique" on "inventory_transfers" ("reference");
CREATE INDEX "inventory_warehouse_id_index" on "inventory" ("warehouse_id");
CREATE INDEX "jobs_queue_index" on "jobs" ("queue");
CREATE INDEX "oem_references_oem_code_index" on "oem_references" ("oem_code");
CREATE INDEX "oem_references_product_id_index" on "oem_references" ("product_id");
CREATE UNIQUE INDEX "payment_methods_code_unique" on "payment_methods" ("code");
CREATE INDEX "personal_access_tokens_expires_at_index" on "personal_access_tokens" ("expires_at");
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens" ("token");
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens" ("tokenable_type", "tokenable_id");
CREATE INDEX "product_attributes_name_index" on "product_attributes" ("name");
CREATE INDEX "product_attributes_name_value_index" on "product_attributes" ("name", "value");
CREATE INDEX "product_attributes_value_index" on "product_attributes" ("value");
CREATE INDEX "product_attributes_variant_id_index" on "product_attributes" ("variant_id");
CREATE UNIQUE INDEX "product_batches_product_id_batch_number_unique" on "product_batches" ("product_id", "batch_number");
CREATE UNIQUE INDEX "product_engine_unique" on "product_vehicle_compatibility" ("product_id", "engine_id");
CREATE INDEX "product_extra_categories_category_id_index" on "product_extra_categories" ("category_id");
CREATE UNIQUE INDEX "product_extra_categories_unique" on "product_extra_categories" ("product_id", "category_id");
CREATE INDEX "product_inventory_product_id_index" on "product_inventory" ("product_id");
CREATE UNIQUE INDEX "product_inventory_product_id_warehouse_id_variant_id_unique" on "product_inventory" ("product_id", "warehouse_id", "variant_id");
CREATE INDEX "product_inventory_quantity_available_reorder_point_index" on "product_inventory" ("quantity_available", "reorder_point");
CREATE INDEX "product_inventory_variant_id_index" on "product_inventory" ("variant_id");
CREATE INDEX "product_inventory_warehouse_id_index" on "product_inventory" ("warehouse_id");
CREATE INDEX "product_lines_brand_id_priority_index" on "product_lines" ("brand_id", "priority");
CREATE INDEX "product_media_product_id_category_index" on "product_media" ("product_id", "category");
CREATE INDEX "product_media_product_id_media_type_index" on "product_media" ("product_id", "media_type");
CREATE INDEX "product_pricing_base_price_index" on "product_pricing" ("base_price");
CREATE INDEX "product_pricing_product_id_index" on "product_pricing" ("product_id");
CREATE UNIQUE INDEX "product_pricing_product_variant_unique" on "product_pricing" ("product_id", "variant_id");
CREATE INDEX "product_pricing_variant_id_index" on "product_pricing" ("variant_id");
CREATE UNIQUE INDEX "product_supplier_unique" on "product_suppliers" ("product_id", "supplier_id");
CREATE INDEX "product_suppliers_product_id_is_preferred_index" on "product_suppliers" ("product_id", "is_preferred");
CREATE INDEX "product_suppliers_variant_id_index" on "product_suppliers" ("variant_id");
CREATE UNIQUE INDEX "product_variants_product_id_brand_id_unique" on "product_variants" ("product_id", "brand_id");
CREATE INDEX "product_variants_product_id_is_default_index" on "product_variants" ("product_id", "is_default");
CREATE INDEX "product_variants_sku_index" on "product_variants" ("sku");
CREATE UNIQUE INDEX "product_variants_system_id_unique" on "product_variants" ("system_id");
CREATE INDEX "product_variants_upc_index" on "product_variants" ("upc");
CREATE INDEX "product_vehicle_compat_idx" on "product_vehicle_compatibility" ("product_id", "engine_id", "chassis_id");
CREATE INDEX "product_vehicle_compatibility_engine_id_index" on "product_vehicle_compatibility" ("engine_id");
CREATE INDEX "product_vehicle_compatibility_product_id_index" on "product_vehicle_compatibility" ("product_id");
CREATE UNIQUE INDEX "product_vins_product_id_vin_unique" on "product_vins" ("product_id", "vin");
CREATE INDEX "product_vins_vin_index" on "product_vins" ("vin");
CREATE UNIQUE INDEX "product_warehouse_unique" on "inventory" ("product_id", "warehouse_id");
CREATE INDEX "products_category_id_index" on "products" ("category_id");
CREATE INDEX "products_status_index" on "products" ("status");
CREATE UNIQUE INDEX "products_system_id_unique" on "products" ("system_id");
CREATE INDEX "purchase_order_items_product_id_index" on "purchase_order_items" ("product_id");
CREATE INDEX "purchase_order_items_purchase_order_id_index" on "purchase_order_items" ("purchase_order_id");
CREATE INDEX "purchase_order_items_status_index" on "purchase_order_items" ("status");
CREATE INDEX "purchase_order_items_supplier_id_index" on "purchase_order_items" ("supplier_id");
CREATE INDEX "purchase_order_items_variant_id_index" on "purchase_order_items" ("variant_id");
CREATE INDEX "purchase_orders_order_date_index" on "purchase_orders" ("order_date");
CREATE UNIQUE INDEX "purchase_orders_po_number_unique" on "purchase_orders" ("po_number");
CREATE INDEX "purchase_orders_status_index" on "purchase_orders" ("status");
CREATE INDEX "purchase_orders_supplier_id_index" on "purchase_orders" ("supplier_id");
CREATE INDEX "sales_order_items_product_id_index" on "sales_order_items" ("product_id");
CREATE INDEX "sales_order_items_sales_order_id_index" on "sales_order_items" ("sales_order_id");
CREATE INDEX "sales_order_items_variant_id_index" on "sales_order_items" ("variant_id");
CREATE INDEX "sales_order_items_warehouse_id_index" on "sales_order_items" ("warehouse_id");
CREATE INDEX sales_order_payments_method_index ON sales_order_payments(method);
CREATE INDEX sales_order_payments_paid_at_index ON sales_order_payments(paid_at);
CREATE INDEX sales_order_payments_sales_order_id_index ON sales_order_payments(sales_order_id);
CREATE INDEX "sales_orders_created_at_index" on "sales_orders" ("created_at");
CREATE INDEX "sales_orders_customer_id_index" on "sales_orders" ("customer_id");
CREATE INDEX "sales_orders_date_index" on "sales_orders" ("date");
CREATE INDEX "sales_orders_order_number_index" on "sales_orders" ("order_number");
CREATE UNIQUE INDEX "sales_orders_order_number_unique" on "sales_orders" ("order_number");
CREATE INDEX "sales_orders_status_index" on "sales_orders" ("status");
CREATE INDEX "sales_quote_items_product_id_index" on "sales_quote_items" ("product_id");
CREATE INDEX "sales_quote_items_sales_quote_id_index" on "sales_quote_items" ("sales_quote_id");
CREATE INDEX "sales_quotes_customer_id_index" on "sales_quotes" ("customer_id");
CREATE INDEX "sales_quotes_date_index" on "sales_quotes" ("date");
CREATE INDEX "sales_quotes_quote_number_index" on "sales_quotes" ("quote_number");
CREATE UNIQUE INDEX "sales_quotes_quote_number_unique" on "sales_quotes" ("quote_number");
CREATE INDEX "sales_quotes_status_index" on "sales_quotes" ("status");
CREATE UNIQUE INDEX "sales_returns_return_number_unique" on "sales_returns" ("return_number");
CREATE INDEX "sales_returns_sales_order_id_status_index" on "sales_returns" ("sales_order_id", "status");
CREATE INDEX "sessions_last_activity_index" on "sessions" ("last_activity");
CREATE INDEX "sessions_user_id_index" on "sessions" ("user_id");
CREATE UNIQUE INDEX "settings_key_unique" on "settings" ("key");
CREATE INDEX "subject" on "activity_log" ("subject_type", "subject_id");
CREATE UNIQUE INDEX "suppliers_name_unique" on "suppliers" ("name");
CREATE INDEX "suppliers_status_index" on "suppliers" ("status");
CREATE UNIQUE INDEX "system_sequences_key_unique" on "system_sequences" ("key");
CREATE UNIQUE INDEX "user_warehouse_user_id_warehouse_id_unique" on "user_warehouse" ("user_id", "warehouse_id");
CREATE UNIQUE INDEX "users_email_unique" on "users" ("email");
CREATE UNIQUE INDEX "users_username_unique" on "users" ("username");
CREATE UNIQUE INDEX "vehicle_chassis_model_chassis_id_model_id_unique" on "vehicle_chassis_model" ("chassis_id", "model_id");
CREATE INDEX "vehicle_chassis_model_id_code_index" on "vehicle_chassis" ("model_id", "code");
CREATE INDEX "vehicle_chassis_model_model_id_index" on "vehicle_chassis_model" ("model_id");
CREATE UNIQUE INDEX "vehicle_engine_model_engine_id_model_id_unique" on "vehicle_engine_model" ("engine_id", "model_id");
CREATE INDEX "vehicle_engine_model_model_id_index" on "vehicle_engine_model" ("model_id");
CREATE UNIQUE INDEX "vehicle_engines_model_id_engine_code_unique" on "vehicle_engines" ("model_id", "engine_code");
CREATE UNIQUE INDEX "vehicle_makes_name_unique" on "vehicle_makes" ("name");
CREATE INDEX "vehicle_models_make_id_name_index" on "vehicle_models" ("make_id", "name");
CREATE INDEX vehicle_vins_engine_id_index ON vehicle_vins(engine_id);
CREATE INDEX vehicle_vins_model_id_index ON vehicle_vins(model_id);
CREATE UNIQUE INDEX vehicle_vins_vin_unique ON vehicle_vins(vin);
CREATE UNIQUE INDEX "warehouse_batches_warehouse_id_product_batch_id_unique" on "warehouse_batches" ("warehouse_id", "product_batch_id");
CREATE UNIQUE INDEX "warehouses_code_unique" on "warehouses" ("code");
CREATE INDEX "warehouses_is_active_index" on "warehouses" ("is_active");
CREATE UNIQUE INDEX "work_orders_folio_unique" on "work_orders" ("folio");
COMMIT;
