<?php
/**
 * Carga manualmente la librería Firebase JWT (sin Composer)
 * Incluye los archivos principales si no están cargados.
 * ¡Asegúrate de ajustar la ruta si mueves el loader!
 */
function eventosapp_include_firebase_jwt() {
    if (!class_exists('Firebase\JWT\JWT')) {
        $jwt_lib_dir = __DIR__ . '/firebase-jwt/';
        // Carga primero la interface
        require_once $jwt_lib_dir . 'JWTExceptionWithPayloadInterface.php';
        // Luego las excepciones (todas dependen de la interface)
        require_once $jwt_lib_dir . 'BeforeValidException.php';
        require_once $jwt_lib_dir . 'ExpiredException.php';
        require_once $jwt_lib_dir . 'SignatureInvalidException.php';
        // Clases principales
        require_once $jwt_lib_dir . 'JWT.php';
        require_once $jwt_lib_dir . 'Key.php';
        // Si usas JWK y CachedKeySet:
        if (file_exists($jwt_lib_dir . 'JWK.php')) require_once $jwt_lib_dir . 'JWK.php';
        if (file_exists($jwt_lib_dir . 'CachedKeySet.php')) require_once $jwt_lib_dir . 'CachedKeySet.php';
    }
}


