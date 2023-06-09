<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitcad41b250836aee95e32297930a443c8
{
    public static $prefixLengthsPsr4 = array (
        'R' => 
        array (
            'Ripcord\\' => 8,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Ripcord\\' => 
        array (
            0 => __DIR__ . '/..' . '/darkaonline/ripcord/src/Ripcord',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitcad41b250836aee95e32297930a443c8::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitcad41b250836aee95e32297930a443c8::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
