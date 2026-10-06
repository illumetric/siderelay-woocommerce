<?php

declare(strict_types=1);

namespace SideRelay\WooCommerce;

use SideRelay\Client;

final class Plugin
{
    public function register(): void
    {
        add_action('wp_footer', $this->blocksHandoff(...));
        add_action('woocommerce_blocks_loaded', $this->registerBlocksSchema(...));
        add_action('woocommerce_store_api_checkout_update_order_from_request', $this->saveBlocksChoice(...), 10, 2);
        add_action('admin_menu', $this->menu(...));
        add_action('woocommerce_checkout_after_customer_details', $this->checkoutField(...));
        add_action('woocommerce_checkout_create_order', $this->saveChoice(...), 10, 2);
        add_action('woocommerce_payment_complete', $this->enqueue(...));
        add_action('woocommerce_order_status_processing', $this->enqueue(...));
        add_action('woocommerce_order_status_completed', $this->enqueue(...));
        add_filter('woocommerce_order_actions', static function (array $actions): array {
            $actions['siderelay_retry'] = 'Retry SideRelay tracking';
            return $actions;
        });
        add_action('woocommerce_order_action_siderelay_retry', function (object $order): void {
            $this->enqueue((int) $order->get_id());
        });
        add_action('siderelay_send_purchase', $this->send(...), 10, 2);
        add_action('woocommerce_thankyou', $this->browserPurchase(...));
    }

    public function menu(): void
    {
        add_submenu_page('woocommerce', 'SideRelay', 'SideRelay', 'manage_woocommerce', 'siderelay', $this->settings(...));
    }
    private function key(): string
    {
        return hash('sha256', wp_salt('auth'), true);
    }
    private function token(): ?string
    {
        $saved = get_option('siderelay_token');
        if (!is_string($saved)) {
            return null;
        }
        $bytes = base64_decode($saved, true);
        if (!$bytes || strlen($bytes) < 29) {
            return null;
        }
        $token = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'siderelay-woocommerce');
        return $token === false ? null : $token;
    }
    public function settings(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $notice = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('siderelay_settings');
            if (isset($_POST['disconnect'])) {
                delete_option('siderelay_token');
                $notice = 'Disconnected.';
            } elseif (isset($_POST['token'])) {
                $token = trim(wp_unslash($_POST['token']));
                if (preg_match('/^sr_token_(test|live)_[a-f0-9]{24}\.[A-Za-z0-9_-]{43}$/D', $token)) {
                    $nonce = random_bytes(12);
                    $tag = '';
                    $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, 'siderelay-woocommerce');
                    update_option('siderelay_token', base64_encode($nonce . $tag . $ciphertext), false);
                    $notice = 'Connected. Your token is saved and hidden.';
                } else {
                    $notice = 'Check the connection token and try again.';
                }
            }
        }
        echo '<div class="wrap"><h1>SideRelay</h1><p>Connect your shop to confirm purchases in SideRelay. Create a backend connection in SideRelay, then paste its token below.</p>';
        if ($notice) {
            echo '<p role="status">' . esc_html($notice) . '</p>';
        }
        echo '<p>' . ($this->token() ? 'Connected · token hidden' : 'Not connected') . '</p><form method="post">';
        wp_nonce_field('siderelay_settings');
        echo '<label>Connection token <input type="password" name="token" autocomplete="new-password" placeholder="Paste a new token" size="60"></label><p><button class="button button-primary" type="submit">Connect or replace token</button> <button class="button" name="disconnect" value="1">Disconnect</button></p></form><p>Uses the existing SideRelay website loader for privacy choices. Uses classic checkout or WooCommerce Checkout Blocks. Missing choices stay unknown.</p></div>';
    }
    public function checkoutField(): void
    {
        echo '<input type="hidden" name="siderelay_context" id="siderelay-context"><script>(function(){function sync(){var el=document.getElementById("siderelay-context");if(el&&window.SideRelay&&SideRelay.getBackendContext)el.value=JSON.stringify(SideRelay.getBackendContext("checkout"));}sync();window.addEventListener("siderelay:configured",sync);window.addEventListener("siderelay:consent",sync);})();</script>';
    }
    public function registerBlocksSchema(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace' => 'siderelay',
            'schema_callback' => static fn () => ['context' => ['type' => 'string', 'readonly' => false, 'maxLength' => 8192]],
            'schema_type' => ARRAY_A
        ]);
    }
    public function blocksHandoff(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }
        echo '<script>(function(){function sync(){if(!window.SideRelay||!SideRelay.getBackendContext||!window.wp||!wp.data||!window.wc||!wc.wcBlocksData)return;try{wp.data.dispatch(wc.wcBlocksData.checkoutStore).setExtensionData("siderelay",{context:JSON.stringify(SideRelay.getBackendContext("checkout"))});}catch(e){}}sync();window.addEventListener("siderelay:configured",sync);window.addEventListener("siderelay:consent",sync);window.addEventListener("load",sync);})();</script>';
    }
    public function saveBlocksChoice(object $order, object $request): void
    {
        $raw = $request['extensions']['siderelay']['context'] ?? '';
        if (!is_string($raw) || strlen($raw) > 8192) {
            return;
        }
        $context = json_decode($raw, true);
        if (!is_array($context)) {
            return;
        }
        if ($request->get_header('sec-gpc') === '1') {
            $context['consent']['global_privacy_control'] = true;
        }
        $order->update_meta_data('_siderelay_context', self::permissionContext($context));
    }
    public function saveChoice(object $order, array $data): void
    {
        $raw = $_POST['siderelay_context'] ?? '';
        if (!is_string($raw) || strlen($raw) > 8192) {
            return;
        }
        $context = json_decode(wp_unslash($raw), true);
        if (is_array($context) && ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') {
            $context['consent']['global_privacy_control'] = true;
        }
        if (is_array($context)) {
            $order->update_meta_data('_siderelay_context', self::permissionContext($context));
        }
    }
    public static function permissionContext(array $input): array
    {
        $consent = ['analytics' => 'unknown', 'advertising' => 'unknown', 'personalization' => 'unknown', 'source' => 'custom'];
        foreach (['analytics', 'advertising', 'personalization'] as $purpose) {
            if (in_array($input['consent'][$purpose] ?? '', ['granted', 'denied'], true)) {
                $consent[$purpose] = $input['consent'][$purpose];
            }
        }
        foreach (['global_privacy_control', 'sale_sharing_opt_out', 'targeted_advertising_opt_out'] as $flag) {
            $consent[$flag] = ($input['consent'][$flag] ?? false) === true;
        }
        if ($consent['global_privacy_control'] || $consent['sale_sharing_opt_out'] || $consent['targeted_advertising_opt_out']) {
            $consent['advertising'] = $consent['personalization'] = 'denied';
        }
        $identity = [];
        foreach ($input['identity'] ?? [] as $key => $value) {
            if (($consent['advertising'] === 'granted' || ($consent['analytics'] === 'granted' && in_array($key, ['anonymous_id', 'session_id', 'ga_client_id'], true))) && is_string($value) && strlen($value) <= 512 && in_array($key, ['anonymous_id','session_id','ga_client_id','fbp','fbc','gclid','gbraid','wbraid','ttclid','msclkid','sc_click_id','li_fat_id','epik','rdt_cid'], true)) {
                $identity[$key] = $value;
            }
        }
        return ['consent' => $consent, 'identity' => $identity];
    }
    public function enqueue(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$this->token() || !$order || !$order->is_paid() || $order->get_meta('_siderelay_received')) {
            return;
        }
        try {
            if (!as_has_scheduled_action('siderelay_send_purchase', [$orderId, 1], 'siderelay')) {
                as_enqueue_async_action('siderelay_send_purchase', [$orderId, 1], 'siderelay');
            }
        } catch (\Throwable) {
            $order->update_meta_data('_siderelay_status', 'retry');
            $order->save();
        }
    }
    public static function event(object $order, string $site): array
    {
        $context = self::permissionContext((array) $order->get_meta('_siderelay_context'));
        $identity = $context['identity'];
        if ($context['consent']['advertising'] === 'granted') {
            $identity['email_sha256'] = Client::hashIdentity($order->get_billing_email());
            if ($order->get_billing_phone()) {
                $identity['phone_sha256'] = Client::hashIdentity($order->get_billing_phone(), 'phone');
            }
        }
        return ['event_id' => 'purchase:woo:' . substr(hash('sha256', $site), 0, 12) . ':' . $order->get_id(), 'occurred_at' => gmdate('c', $order->get_date_paid()->getTimestamp()), 'consent' => $context['consent'], 'identity' => $identity, 'properties' => ['transaction_id' => (string) $order->get_id(), 'value' => (float) $order->get_total(), 'currency' => $order->get_currency()]];
    }
    public function send(int $orderId, int $attempt = 1): void
    {
        $order = wc_get_order($orderId);
        $token = $this->token();
        if (!$token || !$order || !$order->is_paid() || $order->get_meta('_siderelay_received')) {
            return;
        }
        try {
            $result = (new Client($token))->purchase(self::event($order, home_url()));
        } catch (\Throwable) {
            $result = ['status' => 'configuration'];
        }
        $order->update_meta_data('_siderelay_status', $result['status']);
        if ($result['status'] === 'received') {
            $order->update_meta_data('_siderelay_received', true);
        } elseif ($result['status'] === 'retry' && $attempt < 10) {
            as_schedule_single_action(time() + min(3600, 60 * 2 ** ($attempt - 1)), 'siderelay_send_purchase', [$orderId, $attempt + 1], 'siderelay');
        }
        $order->save();
    }
    public function browserPurchase(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order || !$order->is_paid() || !isset($_GET['key']) || !hash_equals($order->get_order_key(), wc_clean(wp_unslash($_GET['key'])))) {
            return;
        }
        $event = self::event($order, home_url());
        $event['event_name'] = 'purchase';
        unset($event['consent'], $event['identity']);
        echo '<script>(function(){var event=' . wp_json_encode($event, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';function send(){if(window.SideRelay)SideRelay.track(event);}if(window.SideRelay)send();else window.addEventListener("siderelay:configured",send,{once:true});})();</script>';
    }
}
