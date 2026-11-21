<?php
/**
 * Autoloader for the Caravel Pay integration kit (CaravelPay\Kit\…). Requires the Caravel Pay PHP SDK
 * (CaravelPay\…) to be loadable too. If another copy is already loaded, that copy is used.
 */
if (!class_exists('CaravelPay\\Kit\\Gateway', false)) {
    spl_autoload_register(static function ($class) {
        $prefix = 'CaravelPay\\Kit\\';
        if (0 !== strncmp($class, $prefix, strlen($prefix))) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
