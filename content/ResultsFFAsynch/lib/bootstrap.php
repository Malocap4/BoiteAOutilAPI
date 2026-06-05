<?php
spl_autoload_register(function($class){
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) require $file;
});
