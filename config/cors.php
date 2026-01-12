<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],
    
    'allowed_methods' => ['*'],
    
    'allowed_origins' => [
        'https://sis-academia-front.vercel.app',
        'http://localhost:5173', // para desarrollo local
    ], // Para desarrollo, usar '*'
    
    'allowed_origins_patterns' => [],
    
    'allowed_headers' => ['*'],
    
    'exposed_headers' => [],
    
    'max_age' => 0,
    
    'supports_credentials' => false,  // Si usas '*', debe ser false
];

