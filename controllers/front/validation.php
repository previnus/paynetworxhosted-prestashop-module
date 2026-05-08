<?php

/**
 * Validation Controller.
 *
 * Receives the token_id posted by the payment form after the hosted iframe
 * tokenizes the card, then charges it via the standard Payment API.
 * Raw card data (PAN / CVC / expiry) never reaches this server.
 */
class PaynetworxhostedValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$this->module->active
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        // Verify this payment method is available for the current cart
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === 'paynetworxhosted') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $this->errors[] = $this->module->l('This payment method is not available.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
            return;
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        // Idempotency: if an order already exists for this cart, redirect to confirmation
        $existingOrderId = (int) Order::getOrderByCartId((int) $cart->id);
        if ($existingOrderId > 0) {
            Tools::redirect(
                'index.php?controller=order-confirmation'
                . '&id_cart='   . (int) $cart->id
                . '&id_module=' . (int) $this->module->id
                . '&id_order='  . $existingOrderId
                . '&key='       . $customer->secure_key
            );
            return;
        }

        // Double-submit / replay protection
        $nonce        = Tools::getValue('paynetworx_nonce');
        $sessionNonce = $this->context->cookie->paynetworxhosted_nonce;
        unset($this->context->cookie->paynetworxhosted_nonce);

        if (empty($nonce) || !hash_equals((string) $sessionNonce, (string) $nonce)) {
            $this->errors[] = $this->module->l('Invalid payment session. Please try again.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=4');
            return;
        }

        // The token_id is the only card-related data that touches this server
        $tokenId = trim(Tools::getValue('pnx_token_id'));

        if (empty($tokenId)) {
            $this->errors[] = $this->module->l('Payment could not be completed. Please try again.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=4');
            return;
        }

        // Basic format check — Paynetworx token IDs are alphanumeric strings up to 128 chars
        if (!preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $tokenId)) {
            $this->errors[] = $this->module->l('Invalid payment token. Please try again.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=4');
            return;
        }

        $currency = $this->context->currency;
        $total    = round((float) $cart->getOrderTotal(true, Cart::BOTH), 2);

        $billingData = $this->buildBillingData($cart, $customer);

        // Claim the cart slot — UNIQUE KEY on id_cart prevents concurrent double-charges
        $inserted = Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'paynetworxhosted_transactions`
             (`id_cart`, `amount`, `currency`, `status`, `created_at`)
             VALUES (' . (int) $cart->id . ', ' . (float) $total . ', \''
            . pSQL($currency->iso_code) . '\', \'pending\', NOW())'
        );

        if (!$inserted || Db::getInstance()->Affected_Rows() === 0) {
            $this->errors[] = $this->module->l('A payment for this order is already being processed. Please wait.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=4');
            return;
        }

        require_once _PS_MODULE_DIR_ . 'paynetworxhosted/classes/PaynetworxHostedApi.php';

        $api = new PaynetworxHostedApi(
            trim(Configuration::get('PAYNETWORXHOSTED_HOSTED_API_KEY')),
            trim(Configuration::get('PAYNETWORXHOSTED_TOKEN_USER')),
            trim(Configuration::get('PAYNETWORXHOSTED_TOKEN_PASS')),
            Configuration::get('PAYNETWORXHOSTED_MODE')
        );

        try {
            PrestaShopLogger::addLog(
                'Paynetworx Hosted: charge attempt cart=' . (int) $cart->id
                    . ' amount=' . $total . ' ' . $currency->iso_code
                    . ' token=' . substr($tokenId, 0, 8) . '...',
                1, null, 'Cart', (int) $cart->id, true
            );

            $result = $api->authCaptureWithToken($total, $currency->iso_code, $tokenId, $billingData, (int) $cart->id);

            PrestaShopLogger::addLog(
                'Paynetworx Hosted: gateway response cart=' . (int) $cart->id
                    . ' http=' . $result['status']
                    . ' approved=' . (isset($result['body']['Approved']) ? var_export($result['body']['Approved'], true) : 'null'),
                1, null, 'Cart', (int) $cart->id, true
            );

            if ($result['status'] >= 200 && $result['status'] < 300
                && isset($result['body']['Approved'])
                && $result['body']['Approved'] === true
            ) {
                $transactionId = isset($result['body']['TransactionID']) ? (string) $result['body']['TransactionID'] : '';
                $authCode      = isset($result['body']['AuthCode'])      ? (string) $result['body']['AuthCode']      : '';

                // Record the successful charge before creating the PS order
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'paynetworxhosted_transactions`
                     SET `transaction_id` = \'' . pSQL($transactionId) . '\',
                         `auth_code`      = \'' . pSQL($authCode) . '\',
                         `status`         = \'charged\'
                     WHERE `id_cart` = ' . (int) $cart->id
                );

                try {
                    $this->module->validateOrder(
                        (int) $cart->id,
                        (int) Configuration::get('PS_OS_PAYMENT'),
                        $total,
                        $this->module->displayName,
                        'TransactionID: ' . $transactionId . ' | AuthCode: ' . $authCode,
                        ['transaction_id' => $transactionId],
                        (int) $currency->id,
                        false,
                        $customer->secure_key
                    );

                    Db::getInstance()->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'paynetworxhosted_transactions`
                         SET `id_order` = ' . (int) $this->module->currentOrder . ',
                             `status`   = \'complete\'
                         WHERE `id_cart` = ' . (int) $cart->id
                    );

                    Tools::redirect(
                        'index.php?controller=order-confirmation'
                        . '&id_cart='   . (int) $cart->id
                        . '&id_module=' . (int) $this->module->id
                        . '&id_order='  . (int) $this->module->currentOrder
                        . '&key='       . $customer->secure_key
                    );
                } catch (Exception $e) {
                    // Charge succeeded but PS order creation failed — needs manual reconciliation
                    PrestaShopLogger::addLog(
                        'CRITICAL — Paynetworx Hosted: charge approved (TransactionID=' . $transactionId
                        . ') but validateOrder() failed — MANUAL REVIEW REQUIRED: ' . $e->getMessage(),
                        4, null, 'Cart', (int) $cart->id, true
                    );
                    $this->errors[] = $this->module->l(
                        'Your payment was received but order creation failed. Please contact support with reference: ' . $transactionId,
                        'validation'
                    );
                    $this->redirectWithNotifications('index.php?controller=order&step=4');
                }
            } else {
                $detail = $this->extractGatewayMessage($result['body'] ?? []);
                if ($detail) {
                    PrestaShopLogger::addLog(
                        'Paynetworx Hosted: decline detail cart=' . (int) $cart->id . ' — ' . $detail,
                        2, null, 'Cart', (int) $cart->id, true
                    );
                }

                // Release the lock so the customer can retry
                Db::getInstance()->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'paynetworxhosted_transactions`
                     WHERE `id_cart` = ' . (int) $cart->id . ' AND `status` = \'pending\''
                );

                $this->errors[] = $this->module->l('Your payment was declined. Please try a different card.', 'validation');
                $this->redirectWithNotifications('index.php?controller=order&step=4');
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Paynetworx Hosted: exception cart=' . (int) $cart->id . ' — ' . $e->getMessage(),
                3, null, 'Cart', (int) $cart->id, true
            );

            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'paynetworxhosted_transactions`
                 WHERE `id_cart` = ' . (int) $cart->id . ' AND `status` = \'pending\''
            );

            $this->errors[] = $this->module->l('A payment error occurred. Please try again or contact support.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=4');
        }
    }

    private function buildBillingData(Cart $cart, Customer $customer)
    {
        $address = new Address($cart->id_address_invoice);
        $country = new Country($address->id_country);

        $data = [
            'Name'       => trim($address->firstname . ' ' . $address->lastname),
            'Line1'      => $address->address1,
            'City'       => $address->city,
            'PostalCode' => $address->postcode,
            'Country'    => $country->iso_code,
            'Email'      => $customer->email,
        ];

        if ($address->id_state) {
            $state = new State((int) $address->id_state);
            $data['State'] = $state->iso_code;
        }

        if (!empty($address->address2)) {
            $data['Line2'] = $address->address2;
        }

        $phone = !empty($address->phone) ? $address->phone : $address->phone_mobile;
        if ($phone) {
            $data['Phone'] = $phone;
        }

        return $data;
    }

    private function extractGatewayMessage(array $body)
    {
        foreach (['ResponseText', 'Error', 'Message'] as $key) {
            if (!empty($body[$key])) {
                return (string) $body[$key];
            }
        }
        return '';
    }
}
