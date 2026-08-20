<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== USUARIOS EN LA BASE DE DATOS ===\n\n";

$users = DB::table('users')->select('id', 'name', 'email', 'role', 'is_active')->get();

echo 'Total de usuarios: '.$users->count()."\n\n";

if ($users->count() === 0) {
    echo "❌ NO HAY USUARIOS EN LA BASE DE DATOS\n";
    echo "Debes crear usuarios para poder iniciar sesión.\n\n";
    exit(1);
}

foreach ($users as $user) {
    $status = $user->is_active ? '✅ ACTIVO' : '❌ INACTIVO';
    echo sprintf("ID: %d | Email: %-30s | Nombre: %-20s | Rol: %-10s | %s\n",
        $user->id,
        $user->email,
        $user->name,
        $user->role,
        $status
    );
}

echo "\n";
