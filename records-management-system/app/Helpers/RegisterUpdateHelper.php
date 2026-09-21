<?php

namespace App\Helpers;

if (!class_exists(\App\Helpers\RegisterUpdateHelper::class, false)) {
    $bootstrap = __DIR__ . '/../../resources/views/pages/dcs/logic/bootstrap.blade.php';
    if (file_exists($bootstrap)) {
        require_once $bootstrap;
    }
}
