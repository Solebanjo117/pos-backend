<?php

$lines = file('storage/logs/laravel.log');
$errors = [];
$currentError = [];
foreach ($lines as $line) {
    if (strpos($line, 'local.ERROR:') !== false) {
        if (! empty($currentError)) {
            $errors[] = $currentError;
        }
        $currentError = [$line];
    } elseif (! empty($currentError)) {
        $currentError[] = $line;
    }
}
if (! empty($currentError)) {
    $errors[] = $currentError;
}

// Search for the last 5 errors that contain sales_orders, exception, etc.
$recent = array_slice($errors, -10);
foreach ($recent as $err) {
    $text = implode('', $err);
    if (strpos($text, 'Route [login] not defined') !== false) {
        continue;
    }
    echo substr($text, 0, 800)."\n----------------\n";
}
