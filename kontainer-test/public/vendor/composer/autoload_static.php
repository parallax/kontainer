<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit6a29aa676020703204ab5fd542979d11
{
    public static $prefixLengthsPsr4 = array (
        'R' => 
        array (
            'Rollbar\\' => 8,
            'Rivsen\\Demo\\' => 12,
        ),
        'P' => 
        array (
            'Psr\\Log\\' => 8,
            'Predis\\' => 7,
        ),
        'M' => 
        array (
            'Monolog\\' => 8,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Rollbar\\' => 
        array (
            0 => __DIR__ . '/..' . '/rollbar/rollbar/src',
        ),
        'Rivsen\\Demo\\' => 
        array (
            0 => __DIR__ . '/..' . '/rivsen/hello-world/src',
        ),
        'Psr\\Log\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/log/Psr/Log',
        ),
        'Predis\\' => 
        array (
            0 => __DIR__ . '/..' . '/predis/predis/src',
        ),
        'Monolog\\' => 
        array (
            0 => __DIR__ . '/..' . '/monolog/monolog/src/Monolog',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit6a29aa676020703204ab5fd542979d11::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit6a29aa676020703204ab5fd542979d11::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
