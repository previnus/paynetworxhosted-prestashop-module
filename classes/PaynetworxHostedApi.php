<?php

/**
 * Paynetworx Hosted Payments API client.
 *
 * Two-step flow:
 *   1. createSession()        — calls the Hosted Payments API to get a session URL
 *                               (uses the Hosted API Key, not Basic Auth)
 *   2. authCaptureWithToken() — charges the token via the standard Payment API
 *                               (uses Basic Auth token user + password)
 */
class PaynetworxHostedApi
{
    // Hosted Payments session endpoints
    const SESSION_URL_TEST = 'https://api.hosted-payments-qa.paynetworx.cloud/v1/';
    const SESSION_URL_LIVE = 'https://api.hosted-payments.paynetworx.cloud/v1/';

    // Standard Payment API endpoints (charge step)
    const PAYMENT_URL_TEST = 'https://api.qa.paynetworx.net/v0/';
    const PAYMENT_URL_LIVE = 'https://api.prod.paynetworx.net/v0/';

    // Hosted form origins for postMessage origin validation in the browser
    const FORM_ORIGIN_TEST = 'https://hosted-payments-qa.paynetworx.cloud';
    const FORM_ORIGIN_LIVE = 'https://hosted-payments.paynetworx.cloud';

    private $hostedApiKey;
    private $paymentUser;
    private $paymentPass;
    private $sessionBaseUrl;
    private $paymentBaseUrl;
    private $formOrigin;

    public function __construct($hostedApiKey, $paymentUser, $paymentPass, $mode = 'test')
    {
        $this->hostedApiKey   = (string) $hostedApiKey;
        $this->paymentUser    = (string) $paymentUser;
        $this->paymentPass    = (string) $paymentPass;
        $this->sessionBaseUrl = ($mode === 'live') ? self::SESSION_URL_LIVE : self::SESSION_URL_TEST;
        $this->paymentBaseUrl = ($mode === 'live') ? self::PAYMENT_URL_LIVE : self::PAYMENT_URL_TEST;
        $this->formOrigin     = ($mode === 'live') ? self::FORM_ORIGIN_LIVE : self::FORM_ORIGIN_TEST;
    }

    public function getFormOrigin()
    {
        return $this->formOrigin;
    }

    /**
     * Step 1 — Create a hosted payment session.
     * Returns the full session object including payment_session_url.
     *
     * @return array  e.g. ['payment_session' => ['payment_session_url' => '...', ...]]
     * @throws Exception on network or JSON error
     */
    public function createSession()
    {
        $payload = json_encode([
            'payment_session' => [
                'payment_session_use' => 'TOKENIZE',
            ],
        ]);

        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . $this->hostedApiKey,
        ];

        $response = $this->sendRequest(
            $this->sessionBaseUrl . 'payments/sessions/create',
            $payload,
            $headers
        );

        if (!isset($response['body']['payment_session']['payment_session_url'])) {
            $error = isset($response['body']['error_type'])
                ? $response['body']['error_type']
                : 'Unknown error';
            throw new Exception('Session creation failed (' . $response['status'] . '): ' . $error);
        }

        return $response['body'];
    }

    /**
     * Step 2 — Charge a tokenized card via the standard Payment API.
     * The token_id comes from the iframe's postMessage response.
     *
     * @param  float  $amount
     * @param  string $currency  ISO 4217 code, e.g. "USD"
     * @param  string $tokenId   token_id from hosted payments iframe
     * @param  array  $billingData Optional billing info for AVS
     * @param  int    $cartId    Used as the OrderNumber reference
     * @return array  ['status' => int, 'body' => array]
     * @throws Exception on network or JSON error
     */
    public function authCaptureWithToken($amount, $currency, $tokenId, $billingData = [], $cartId = 0)
    {
        $orderRef = $cartId > 0 ? 'CART-' . (int) $cartId : 'ORD-' . $this->generateRequestId();

        $payload = [
            'Amount' => [
                'Total'    => round((float) $amount, 2),
                'Currency' => (string) $currency,
            ],
            'PaymentMethod' => [
                'Card' => [
                    'Token' => [
                        'TokenID' => (string) $tokenId,
                    ],
                ],
            ],
            'Attributes' => [
                'EntryMode' => 'manual',
                'ProcessingSpecifiers' => [
                    'InitiatedByECommerce' => true,
                ],
            ],
            'TransactionEntry' => [
                'Device'             => 'NA',
                'DeviceVersion'      => 'NA',
                'Application'        => 'Merchant Website Express',
                'ApplicationVersion' => '1.0',
                'Timestamp'          => gmdate('Y-m-d\TH:i:s') . 'Z',
            ],
            'Detail' => [
                'MerchantData' => [
                    'OrderNumber' => $orderRef,
                    'CustomerID'  => 'Guest',
                ],
            ],
        ];

        if (!empty($billingData)) {
            $address = [];
            foreach (['Name', 'Line1', 'Line2', 'City', 'State', 'PostalCode', 'Country', 'Phone', 'Email'] as $field) {
                if (!empty($billingData[$field])) {
                    $address[$field] = (string) $billingData[$field];
                }
            }
            if ($address) {
                $payload['PaymentMethod']['Card']['BillingAddress'] = $address;
            }
        }

        $body = json_encode($payload);

        if ($body === false) {
            throw new Exception('Failed to encode charge payload: ' . json_last_error_msg());
        }

        $requestId = $this->generateRequestId();
        $headers   = [
            'Content-Type: application/json',
            'Request-ID: ' . $requestId,
            'Authorization: Basic ' . base64_encode($this->paymentUser . ':' . $this->paymentPass),
        ];

        return $this->sendRequest(
            $this->paymentBaseUrl . 'transaction/authcapture',
            $body,
            $headers
        );
    }

    private function sendRequest($url, $body, array $headers)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Network error: ' . $error);
        }

        curl_close($ch);

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Unexpected non-JSON response from gateway.');
        }

        return [
            'status' => $httpCode,
            'body'   => $decoded,
        ];
    }

    /**
     * Generates a 27-character CSPRNG request ID using rejection sampling to eliminate
     * modulo bias (62 chars does not divide 256 evenly — biased bytes are discarded).
     */
    private function generateRequestId()
    {
        $chars  = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max    = 256 - (256 % 62); // 248
        $result = '';
        while (strlen($result) < 27) {
            $byte = ord(random_bytes(1));
            if ($byte < $max) {
                $result .= $chars[$byte % 62];
            }
        }
        return $result;
    }
}
