<?php
/**
 * PHPUnit bootstrap. Runs without WordPress.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_readable($autoload)) {
    require_once $autoload;
} else {
    // Fallback PSR-4 autoloader so the suite also runs from a PHPUnit phar.
    spl_autoload_register(
        static function (string $class): void {
            $map = [
                'Saizul\\CodRiskGuard\\Tests\\' => __DIR__ . '/',
                'Saizul\\CodRiskGuard\\WP\\'    => dirname(__DIR__) . '/includes/',
                'Saizul\\CodRiskGuard\\'        => dirname(__DIR__) . '/src/',
            ];

            foreach ($map as $prefix => $dir) {
                if (0 === strpos($class, $prefix)) {
                    $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_readable($file)) {
                        require_once $file;
                    }

                    return;
                }
            }
        }
    );
}

require_once __DIR__ . '/Stubs/wordpress.php';
