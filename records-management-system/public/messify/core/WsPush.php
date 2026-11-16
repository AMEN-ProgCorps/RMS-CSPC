<?php
/**
 * Backward compatibility bridge: forward messify WsPush class to chatify WsPush.
 */
$target = dirname(__DIR__, 2) . '/chatify/core/WsPush.php';
if (is_file($target)) {
    require_once $target;
}
