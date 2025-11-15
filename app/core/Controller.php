<?php
// File: app/core/Controller.php

class Controller
{
    protected function view($view, $data = [], $layout = 'layouts/header.php')
    {
        extract($data);
        $baseViewPath = __DIR__ . '/../views/';

        // header
        require $baseViewPath . 'layouts/header.php';

        // main
        require $baseViewPath . $view . '.php';

        // footer
        require $baseViewPath . 'layouts/footer.php';
    }

    protected function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }
}
