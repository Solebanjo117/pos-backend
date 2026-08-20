<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$middlewares = $kernel->getMiddlewareGroups()['api'] ?? [];
echo "API MIDDLEWARES:\n";
foreach ($middlewares as $mw) {
    if (is_object($mw)) {
        echo get_class($mw)."\n";
    } else {
        echo $mw."\n";
    }
}
