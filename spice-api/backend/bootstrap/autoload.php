<?php

declare(strict_types=1);

/**
 * Zero-dependency PSR-4 autoloader.
 *
 * The project ships with no third-party packages, so it runs on a bare
 * PHP 8.3 + Apache install. If you later run `composer install`, Composer's
 * autoloader is used instead and this file simply defers to it.
 */
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';

    return;
}

/**
 * A missing database driver is diagnosed HERE, once, for every entry point.
 *
 * Without it PHP throws "could not find driver" from deep inside the PDO
 * constructor, with a stack trace pointing at Database.php — which reads like an
 * application bug and says nothing about the actual cause: this PHP has no
 * pdo_mysql extension loaded.
 *
 * On Windows that is almost always because `php` on the PATH is a standalone
 * download rather than the one bundled with XAMPP, which is already configured.
 * Naming the binary being used is what turns a baffling error into a one-line
 * fix, and it is the reason this check reports which PHP is running.
 */
if (PHP_SAPI === 'cli' && !extension_loaded('pdo_mysql')) {
    $iniPath = php_ini_loaded_file();

    fwrite(STDERR, "\nThis PHP has no MySQL driver, so nothing can reach the database.\n\n");
    fwrite(STDERR, '  PHP binary : ' . PHP_BINARY . "\n");
    fwrite(STDERR, '  php.ini    : ' . ($iniPath === false ? '(NONE LOADED)' : $iniPath) . "\n\n");

    if (DIRECTORY_SEPARATOR === '\\') {
        fwrite(STDERR, "If you have XAMPP, its PHP is already set up. Use it directly:\n");
        fwrite(STDERR, "  C:\\xampp\\php\\php.exe bin\\setup.php\n\n");
        fwrite(STDERR, "The plain `php` command is finding a different installation.\n");
        fwrite(STDERR, "Check which one with:  where php\n");
    } else {
        fwrite(STDERR, "Enable the extension in " . ($iniPath === false ? 'your php.ini' : $iniPath) . ":\n");
        fwrite(STDERR, "  extension=pdo_mysql\n");
    }

    fwrite(STDERR, "\nThen run:  php bin/setup.php\n");

    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = APP_ROOT . '/app/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
