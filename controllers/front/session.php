<?php

/**
 * Session Controller — AJAX endpoint.
 *
 * Called by the payment form JavaScript on page load.
 * Creates a Paynetworx Hosted Payments session server-side (keeps the
 * Hosted API Key out of the browser) and returns the session URL for
 * the iframe src.
 */
class PaynetworxhostedSessionModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->module->active || !$this->module->isConfigured()) {
            http_response_code(503);
            echo json_encode(['error' => 'Payment method unavailable.']);
            exit;
        }

        $cart = $this->context->cart;

        if (!$cart || $cart->id_customer == 0) {
            http_response_code(403);
            echo json_encode(['error' => 'No active cart.']);
            exit;
        }

        require_once _PS_MODULE_DIR_ . 'paynetworxhosted/classes/PaynetworxHostedApi.php';

        $api = new PaynetworxHostedApi(
            trim(Configuration::get('PAYNETWORXHOSTED_HOSTED_API_KEY')),
            trim(Configuration::get('PAYNETWORXHOSTED_TOKEN_USER')),
            trim(Configuration::get('PAYNETWORXHOSTED_TOKEN_PASS')),
            Configuration::get('PAYNETWORXHOSTED_MODE')
        );

        try {
            $session = $api->createSession();

            echo json_encode([
                'session_url' => $session['payment_session']['payment_session_url'],
                'expires_at'  => isset($session['payment_session']['expires_at'])
                    ? $session['payment_session']['expires_at']
                    : null,
            ]);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Paynetworx Hosted: session creation failed — ' . $e->getMessage(),
                3, null, 'Cart', (int) $cart->id, true
            );
            http_response_code(502);
            echo json_encode(['error' => 'Could not initialize payment session. Please try again.']);
        }

        exit;
    }
}
