<?php
require __DIR__ . '/bootstrap.php';
try {
    $res = $sync->run();
    echo '[' . date('c') . '] OK ' . json_encode($res, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] ERROR ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
