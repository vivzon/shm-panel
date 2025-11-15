<?php
// File: public/index.php

require_once __DIR__ . '/../app/core/Auth.php';

$basePath = dirname(__DIR__);
$configPath = $basePath . '/config/config.php';

if (!file_exists($configPath)) {
    // Not installed yet
    header('Location: /shm-panel/install/index.php');
    exit;
}

$config = require $configPath;

$url = $_GET['url'] ?? '';
$url = trim($url, '/');
$segments = explode('/', $url);

$controllerName = ucfirst(strtolower($segments[0] ?? 'auth')) . 'Controller';
$methodName     = $segments[1] ?? 'login';
$param1         = $segments[2] ?? null;

// Default routes
if ($segments[0] === '' || $segments[0] === 'auth') {
    $controllerName = 'AuthController';
    $methodName = $segments[1] ?? 'login';
}

// Map simple routes
if ($segments[0] === 'admin') {
    $controllerName = 'AdminController';
    $methodName = $segments[1] ?? 'dashboard';
}

if ($segments[0] === 'servers') {
    $controllerName = 'ServerController';
    $methodName = $segments[1] ?? 'index';
    $param1 = $segments[2] ?? null;
}

// Load controller
$controllerFile = __DIR__ . '/../app/controllers/' . $controllerName . '.php';

if (!file_exists($controllerFile)) {
    http_response_code(404);
    echo "404 Not Found";
    exit;
}

require_once $controllerFile;

$controller = new $controllerName();

if (!method_exists($controller, $methodName)) {
    http_response_code(404);
    echo "404 Not Found";
    exit;
}

if ($param1 !== null) {
    $controller->{$methodName}($param1);
} else {
    $controller->{$methodName}();
}
