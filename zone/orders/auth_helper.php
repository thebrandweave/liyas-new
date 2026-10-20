<?php
/**
 * Zone Orders Portal - Helpers
 * (Zone access code login requirement has been removed; portal is directly accessible)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/admin/includes/functions.php';

/**
 * Check if current user is authenticated for Zone Orders portal.
 * Always returns true since access code login has been removed.
 */
function isZoneOrdersAuthenticated(): bool {
    return true;
}

/**
 * Guard to require authentication.
 * No-op since access codes and login requirement have been removed.
 */
function requireZoneOrdersAuth(): void {
    // Direct open access to the dashboard
    return;
}

/**
 * Legacy stub for access code verification.
 * Kept for backward compatibility if called elsewhere.
 */
function verifyZoneAccessCode(PDO $pdo, string $code = ''): array {
    return [
        'valid'     => true,
        'scope'     => 'all',
        'zone_id'   => null,
        'zone_name' => 'All Delivery Zones',
        'zone_slug' => 'all'
    ];
}

/**
 * Assign consistent, visually pleasing badge colors based on zone name/slug
 */
function getZoneColorConfig(string $slug): array {
    $slug = strtolower(trim($slug));
    $palettes = [
        'mangalore'  => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'border' => '#bfdbfe', 'dot' => '#3b82f6'],
        'thokkottu'  => ['bg' => '#faf5ff', 'text' => '#6b21a8', 'border' => '#e9d5ff', 'dot' => '#a855f7'],
        'vitla'      => ['bg' => '#ecfdf5', 'text' => '#047857', 'border' => '#a7f3d0', 'dot' => '#10b981'],
        'surathkal'  => ['bg' => '#fff7ed', 'text' => '#c2410c', 'border' => '#fed7aa', 'dot' => '#f97316'],
        'bantwal'    => ['bg' => '#fdf2f8', 'text' => '#be185d', 'border' => '#fbcfe8', 'dot' => '#ec4899'],
        'moodbidri'  => ['bg' => '#f0fdfa', 'text' => '#0f766e', 'border' => '#99f6e4', 'dot' => '#14b8a6'],
        'all'        => ['bg' => '#f8fafc', 'text' => '#0f172a', 'border' => '#cbd5e1', 'dot' => '#64748b'],
    ];

    if (isset($palettes[$slug])) {
        return $palettes[$slug];
    }

    // Dynamic hash-based fallback palette for newly created zones
    $hash = abs(crc32($slug));
    $colors = [
        ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'border' => '#bfdbfe', 'dot' => '#3b82f6'],
        ['bg' => '#faf5ff', 'text' => '#6b21a8', 'border' => '#e9d5ff', 'dot' => '#a855f7'],
        ['bg' => '#ecfdf5', 'text' => '#047857', 'border' => '#a7f3d0', 'dot' => '#10b981'],
        ['bg' => '#fffbeb', 'text' => '#b45309', 'border' => '#fde68a', 'dot' => '#f59e0b'],
        ['bg' => '#fdf4ff', 'text' => '#86198f', 'border' => '#f5d0fe', 'dot' => '#d946ef'],
        ['bg' => '#f0f9ff', 'text' => '#0369a1', 'border' => '#bae6fd', 'dot' => '#0ea5e9']
    ];
    return $colors[$hash % count($colors)];
}
