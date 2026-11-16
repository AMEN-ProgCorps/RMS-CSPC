<?php
/**
 * Backward compatibility bridge: forward messify bootstrap requests to chatify bootstrap.
 */
$target = dirname(__DIR__) . '/chatify/bootstrap.php';
if (is_file($target)) {
    require_once $target;
}
