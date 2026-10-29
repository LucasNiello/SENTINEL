<?php

// O Artisan pode transmitir as variáveis locais em $_SERVER, que o Dotenv
// consulta antes de getenv(). Aplicar a configuração PHPUnit nos três locais.
foreach (simplexml_load_file(dirname(__DIR__).'/phpunit.xml')->php->env as $setting) {
    $name = (string) $setting['name'];
    $value = (string) $setting['value'];
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}

// Não carregar o cache local (que pode apontar para MySQL) no processo PHPUnit.
foreach (['CONFIG', 'ROUTES', 'EVENTS'] as $kind) {
    $name = 'APP_'.$kind.'_CACHE';
    $cache = sys_get_temp_dir().'/sentinel-phpunit-'.strtolower($kind).'-'.getmypid().'.php';
    putenv($name.'='.$cache);
    $_ENV[$name] = $_SERVER[$name] = $cache;
}

require dirname(__DIR__).'/vendor/autoload.php';
