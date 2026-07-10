<?php
use App\Core\Security;

function base_url(): string {
    $script = str_replace('\\','/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/');
    if (str_ends_with($dir, '/public')) $dir = substr($dir, 0, -7);
    return $dir ?: '';
}
function url(string $path=''): string { return base_url() . '/' . ltrim($path, '/'); }
function asset(string $path): string { return url('/public/' . ltrim($path, '/')); }
function e(?string $v): string { return Security::e($v); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.Security::csrfToken().'">'; }

/**
 * Generate a short, effectively-unique reference/document number.
 * e.g. generate_reference('BRW') => "BRW-260709-9F3A2B"
 */
function generate_reference(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function old(string $key, array $old = [], string $default = ''): string
{
    return e($old[$key] ?? $default);
}

function format_money(mixed $amount): string
{
    return number_format((float) $amount, 2);
}

function flash_messages(): string
{
    $html = '';
    $success = \App\Core\Session::flash('success');
    $error = \App\Core\Session::flash('error');
    if ($success) {
        $html .= '<div class="alert alert-success alert-dismissible fade show" role="alert">' . e($success) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    if ($error) {
        $html .= '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . e($error) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    return $html;
}
