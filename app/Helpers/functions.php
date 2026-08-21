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
// The public "Apply Now" form (apply-dg.php) lives at the domain root
// (public_html/), one level above this app's own directory (public_html/mls/)
// -- so it must NOT go through url()/base_url(), which resolve relative to
// wherever this app is mounted. full_url() would wrongly produce
// https://host/mls/apply-dg.php; this always resolves to https://host/apply-dg.php.
function public_site_url(string $path=''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443 ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/' . ltrim($path, '/');
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

/**
 * Sortable <th> for a list view -- click toggles asc/desc on that column,
 * preserving every other query param (search, filters, per-page) via
 * $query (pass $_GET). Used across the Recruitment module's list-view
 * standard; safe to reuse anywhere a plain server-rendered table needs
 * click-to-sort without a JS grid library.
 */
function sortable_th(string $label, string $column, array $query, string $currentSort, string $currentDir): string
{
    $isActive = $currentSort === $column;
    $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $q = array_merge($query, ['sort' => $column, 'dir' => $nextDir]);
    $icon = $isActive ? ($currentDir === 'asc' ? 'mdi-arrow-up' : 'mdi-arrow-down') : 'mdi-unfold-more-horizontal';
    return '<th><a href="?' . e(http_build_query($q)) . '" class="text-dark text-decoration-none d-inline-flex align-items-center gap-1">'
        . e($label) . '<i class="mdi ' . $icon . ' small text-muted"></i></a></th>';
}

/**
 * Previous/page-numbers/Next pagination, preserving every other query
 * param via $query (pass $_GET). Returns '' when there's only one page,
 * so callers can echo it unconditionally.
 */
function pagination_nav(int $page, int $totalPages, array $query): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $link = function (int $targetPage) use ($query): string {
        return '?' . e(http_build_query(array_merge($query, ['page' => $targetPage])));
    };

    $html = '<nav aria-label="Page navigation"><ul class="pagination pagination-sm mb-0">';
    $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . $link(max(1, $page - 1)) . '">Previous</a></li>';
    for ($p = 1; $p <= $totalPages; $p++) {
        $html .= '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . $link($p) . '">' . $p . '</a></li>';
    }
    $html .= '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="' . $link(min($totalPages, $page + 1)) . '">Next</a></li>';
    $html .= '</ul></nav>';

    return $html;
}

/** Bootstrap badge color for an agent_commissions.status value -- one place so every commission view agrees. */
function commission_status_badge(string $status): string
{
    return match ($status) {
        'Fully Earned' => 'success',
        'Forfeited', 'Ineligible' => 'danger',
        'Accruing' => 'info',
        default => 'warning', // Pending
    };
}

function flash_messages(): string
{
    $html = '';
    $success = \App\Core\Session::flash('success');
    $error = \App\Core\Session::flash('error');
    if ($success) {
        $html .= '<div class="js-flash-toast d-none" data-toast-type="success" data-toast-message="' . e($success) . '"></div>';
    }
    if ($error) {
        $html .= '<div class="js-flash-toast d-none" data-toast-type="danger" data-toast-message="' . e($error) . '"></div>';
    }
    return $html;
}
