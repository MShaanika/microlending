<?php
namespace App\Core;

class Controller
{
    protected function view(string $view, array $data = []): void { View::render($view, $data); }
    protected function redirect(string $path): void { header('Location: ' . url($path)); exit; }
}
