<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Hash;

echo "=== CREANDO BASE DE DATOS ===\n\n";

// Get or create first warehouse
$warehouse = Warehouse::first();
if (!$warehouse) {
    echo "Creando almacén predeterminado...\n";
    $warehouse = Warehouse::create([
        'name' => 'Almacén Principal',
        'code' => 'ALM-001',
        'address' => 'Calle Principal 123',
        'city' => 'San Salvador',
        'country' => 'El Salvador',
        'is_active' => true,
    ]);
    echo "✅ Almacén creado.\n\n";
}

echo "=== CREANDO USUARIOS DE PRUEBA ===\n\n";

$users = [
    [
        'name' => 'Admin User',
        'email' => 'admin@hermexpos.app',
        'password' => 'password123',
        'role' => 'admin',
        'is_active' => true,
    ],
    [
        'name' => 'Manager User',
        'email' => 'manager@hermexpos.app',
        'password' => 'password123',
        'role' => 'manager',
        'is_active' => true,
    ],
    [
        'name' => 'Cashier User',
        'email' => 'cashier@hermexpos.app',
        'password' => 'password123',
        'role' => 'cashier',
        'is_active' => true,
    ],
];

foreach ($users as $userData) {
    // Check if already exists
    $existing = User::where('email', $userData['email'])->first();
    if ($existing) {
        echo "⚠️  Usuario ya existe: {$userData['email']}\n";
        continue;
    }

    $user = User::create([
        'name' => $userData['name'],
        'email' => $userData['email'],
        'password' => Hash::make($userData['password']),
        'role' => $userData['role'],
        'is_active' => $userData['is_active'],
        'current_warehouse_id' => $warehouse->id,
        'email_verified_at' => now(),
    ]);

    // Assign warehouse
    $user->warehouses()->attach($warehouse->id);

    echo "✅ Usuario creado: {$userData['email']} (rol: {$userData['role']})\n";
}

echo "\n=== PUEDES INICIAR SESIÓN CON ===\n\n";
echo "📧 Email: admin@hermexpos.app\n";
echo "🔑 Contraseña: password123\n";
echo "👤 Rol: admin (acceso completo)\n\n";

echo "O también:\n";
echo "📧 Email: cashier@hermexpos.app\n";
echo "🔑 Contraseña: password123\n";
echo "👤 Rol: cashier (acceso limitado)\n\n";
