<?php

return [
    'releases' => [
        [
            'version' => '34.8.0',
            'date' => '2026-04-28',
            'title' => 'Cierre de abril - Catalogo, ventas y navegacion',
            'category' => 'operations',
            'changes' => [
                'Se agrego soporte para imagenes de categorias y herencia visual en productos.',
                'Se reforzo el flujo de productos con estados, archivado, restauracion y atributos heredados.',
                'Se ajustaron ordenes y cotizaciones para preservar advertencias de stock y mejorar el contrato de respuesta.',
                'Se agrego un flyout de submenu para usar el sidebar compactado sin perder acceso a las opciones internas.',
            ],
        ],
        [
            'version' => '34.7.0',
            'date' => '2026-04-26',
            'title' => 'Importadores mas claros',
            'category' => 'imports',
            'changes' => [
                'Se simplificaron plantillas de importacion para productos, categorias, marcas, proveedores y vehiculos.',
                'Se agregaron requests dedicados para preview y ejecucion de importaciones.',
                'Se mejoro la validacion compartida del importador base para detectar errores antes de confirmar.',
                'Se ajusto la experiencia del importador universal para mostrar mensajes mas utiles al usuario.',
            ],
        ],
        [
            'version' => '34.6.0',
            'date' => '2026-04-25',
            'title' => 'Productos con metadata diferida y categorias extra',
            'category' => 'catalog',
            'changes' => [
                'Se agrego soporte para categorias extra en productos sin reemplazar la categoria principal.',
                'Se incorporo guardado diferido de atributos, referencias OEM, alternativas y compatibilidad vehicular.',
                'Se habilito regeneracion de nombre y descripcion de producto en modo borrador.',
                'Se reforzaron pruebas de persistencia, duplicado e importacion de productos.',
            ],
        ],
        [
            'version' => '34.5.0',
            'date' => '2026-04-10',
            'title' => 'Sprint 5 - Inventario y compras',
            'category' => 'operations',
            'changes' => [
                'Se agregaron proveedores en entradas de inventario y ordenes de compra.',
                'Se habilito CRUD de formulas de precio para configuracion administrativa.',
                'Se ajusto recepcion de ordenes de compra para trabajar con unidades por empaque.',
                'Se incorporo configuracion require_stock_for_quotes en settings.',
            ],
        ],
        [
            'version' => '34.4.0',
            'date' => '2026-04-10',
            'title' => 'Sprint 4 - System ID y vehiculos',
            'category' => 'catalog',
            'changes' => [
                'Se agrego system_id secuencial en productos y variantes.',
                'Se habilito prefix autogenerado para marcas.',
                'Se expusieron system_id y prefix en recursos API.',
                'Se mejoro organizacion de motores y compatibilidad vehicular.',
            ],
        ],
        [
            'version' => '34.3.0',
            'date' => '2026-04-09',
            'title' => 'Sprint 3 - UI UX y busqueda',
            'category' => 'pos',
            'changes' => [
                'Se reemplazo busqueda modal por busqueda inline en POS.',
                'Se simplificaron tablas removiendo indicadores inline de descuento e impuesto.',
                'Se actualizaron vistas de detalle para mejor lectura de OEM y compatibilidad.',
                'Se unifico interaccion de teclado para busqueda y seleccion de productos.',
            ],
        ],
        [
            'version' => '34.2.0',
            'date' => '2026-04-09',
            'title' => 'Sprint 2 - Variantes y pricing',
            'category' => 'pricing',
            'changes' => [
                'Se estabilizo evaluacion de formulas con parentesis anidados.',
                'Se corrigio precision de division por cero en formulas.',
                'Se corrigio doble aplicacion de ITBM en calculo de precio.',
                'Se reforzaron filtros multiples de categorias y marcas en productos.',
            ],
        ],
        [
            'version' => '34.1.0',
            'date' => '2026-04-09',
            'title' => 'Sprint 1 - Quick wins y limpieza SKU',
            'category' => 'stability',
            'changes' => [
                'Se removieron mensajes y flujos confusos de SKU autogenerado en UI.',
                'Se permitio crear productos sin variante por defecto.',
                'Se corrigio comportamiento de Enter en formularios clave.',
                'Se reforzaron validaciones para evitar inventario negativo.',
            ],
        ],
    ],
];
