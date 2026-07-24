<?php
use App\Core\Security;

function base_url(): string {
    $script = str_replace('\\','/', $_SERVER['SCRIPT_NAME'] ?? '');
    // dirname() on Windows returns '\' (not '/') for a root-level script
    // like '/index.php' -- normalize before rtrim or that stray backslash
    // survives into every url()/asset() call as a protocol-relative "\/..."
    // href, which browsers resolve as if "public" were a hostname.
    $dir = str_replace('\\', '/', dirname($script));
    $dir = rtrim($dir, '/');
    if (str_ends_with($dir, '/public')) $dir = substr($dir, 0, -7);
    return $dir ?: '';
}
function url(string $path=''): string { return base_url() . '/' . ltrim($path, '/'); }
function asset(string $path): string { return url('/public/' . ltrim($path, '/')); }
// url() is deliberately host-relative -- correct for href/redirect use inside
// a browser, but a link sent externally (SMS, email) needs the scheme and
// host too, or it renders as a bare, unclickable path like "/mls/portal/login".
function full_url(string $path=''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443 ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . url($path);
}
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

/**
 * Same as format_money() but renders negatives in parentheses, e.g.
 * (332.10) instead of -332.10 -- the convention accounting reports (GL
 * running balance) use for an overdrawn/credit balance.
 */
function format_balance(mixed $amount): string
{
    $amount = (float) $amount;
    return $amount < 0 ? '(' . number_format(abs($amount), 2) . ')' : number_format($amount, 2);
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
