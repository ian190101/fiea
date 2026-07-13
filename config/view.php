<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Laravel buscara las vistas Blade en estas rutas. Mantener esto explicito
    | evita fallos al cachear vistas dentro de contenedores de produccion.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Las vistas compiladas viven en storage/framework/views, que el Dockerfile
    | deja escribible para Apache y los comandos artisan de arranque.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views')) ?: storage_path('framework/views')
    ),

];
