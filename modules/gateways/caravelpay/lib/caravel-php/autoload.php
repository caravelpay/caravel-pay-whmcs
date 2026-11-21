<?php
/**
 * Autoloader for copies of the Caravel Pay PHP SDK used without Composer (WHMCS, FOSSBilling and
 * other platforms): require this file once. With Composer, use vendor/autoload.php instead.
 *
 * If another copy of the SDK is already loaded in the same PHP process, that copy is used.
 */
if (!class_exists('CaravelPay\\Client', false)) {
    spl_autoload_register(static function ($class) {
        $prefix = 'CaravelPay\\';
        if (0 !== strncmp($class, $prefix, strlen($prefix))) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
