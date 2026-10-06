<?php

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Plugin.php';
use SideRelay\WooCommerce\Plugin;

function check(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}
$context = Plugin::permissionContext(['consent' => ['advertising' => 'granted', 'global_privacy_control' => true], 'identity' => ['email' => 'buyer@example.com', 'gclid' => 'click']]);
check($context['consent']['advertising'] === 'denied' && $context['identity'] === [], 'GPC removes matching before storage');
check(Plugin::permissionContext([])['consent']['advertising'] === 'unknown', 'Missing choice stays unknown');
$order = new class () {
    public function get_meta(string $key): array
    {
        return ['consent' => ['advertising' => 'granted'],'identity' => ['gclid' => 'click']];
    }
    public function get_billing_email(): string
    {
        return 'Buyer@example.com';
    }
    public function get_billing_phone(): string
    {
        return '+46 123 456 789';
    }
    public function get_id(): int
    {
        return 123;
    }
    public function get_date_paid(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-06T12:00:00Z');
    }
    public function get_total(): string
    {
        return '49.90';
    }
    public function get_currency(): string
    {
        return 'EUR';
    }
};
$a = Plugin::event($order, 'https://example.com');
$b = Plugin::event($order, 'https://example.com');
check($a === $b, 'Retries preserve event IDs and paid times');
check($a['event_id'] !== Plugin::event($order, 'https://other.example.com')['event_id'], 'Shop isolation');
check($a['identity']['email_sha256'] === hash('sha256', 'buyer@example.com'), 'Hash supported billing identity');
check($a['properties']['value'] === 49.90 && $a['properties']['transaction_id'] === '123', 'Purchase mapping');
echo "WooCommerce adapter checks passed\n";
