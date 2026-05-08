<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Paynetworx Hosted Payments module.
 *
 * Card data is collected entirely inside a Paynetworx-hosted iframe.
 * The server never sees PAN, CVC, or expiry — only a one-time token.
 * This removes the store from PCI DSS SAQ D scope.
 *
 * Prerequisites:
 *  - Paynetworx Hosted Payments API Key (separate from Basic Auth token)
 *  - Hosted Payments QA environment activated by Paynetworx support
 *  - Existing Access Token User + Password (for the charge step)
 */
class Paynetworxhosted extends PaymentModule
{
    public function __construct()
    {
        $this->name    = 'paynetworxhosted';
        $this->tab     = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author  = 'ArcPro Media Inc.';
        $this->author_uri = 'https://www.arcpromedia.com';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '9.99.99',
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Paynetworx (Hosted)');
        $this->description = $this->l('PCI-compliant card payments via Paynetworx hosted iframe.');
        $this->confirmUninstall = $this->l('Are you sure? Saved credentials will be deleted.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->installDb()
            && Configuration::updateValue('PAYNETWORXHOSTED_MODE', 'test')
            && Configuration::updateValue('PAYNETWORXHOSTED_HOSTED_API_KEY', '')
            && Configuration::updateValue('PAYNETWORXHOSTED_TOKEN_USER', '')
            && Configuration::updateValue('PAYNETWORXHOSTED_TOKEN_PASS', '');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('PAYNETWORXHOSTED_MODE')
            && Configuration::deleteByName('PAYNETWORXHOSTED_HOSTED_API_KEY')
            && Configuration::deleteByName('PAYNETWORXHOSTED_TOKEN_USER')
            && Configuration::deleteByName('PAYNETWORXHOSTED_TOKEN_PASS')
            && Db::getInstance()->execute(
                'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'paynetworxhosted_transactions`'
            );
    }

    private function installDb()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paynetworxhosted_transactions` (
                `id_transaction` INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `id_cart`        INT UNSIGNED     NOT NULL,
                `id_order`       INT UNSIGNED     NOT NULL DEFAULT 0,
                `transaction_id` VARCHAR(64)      NOT NULL DEFAULT \'\',
                `auth_code`      VARCHAR(32)      NOT NULL DEFAULT \'\',
                `amount`         DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
                `currency`       VARCHAR(3)       NOT NULL DEFAULT \'\',
                `status`         VARCHAR(16)      NOT NULL DEFAULT \'pending\',
                `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_transaction`),
                UNIQUE KEY `uq_cart` (`id_cart`),
                KEY `idx_order` (`id_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
    }

    public function isConfigured()
    {
        return Configuration::get('PAYNETWORXHOSTED_HOSTED_API_KEY')
            && Configuration::get('PAYNETWORXHOSTED_TOKEN_USER')
            && Configuration::get('PAYNETWORXHOSTED_TOKEN_PASS');
    }

    public function hookHeader()
    {
        // Only load on checkout pages
        $page = isset($this->context->controller->php_self) ? $this->context->controller->php_self : '';
        if (!in_array($page, ['order', 'order-opc', 'checkout'], true)) {
            return;
        }
        $this->context->controller->addCSS($this->_path . 'views/css/paynetworxhosted.css', 'all');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPaynetworxHostedConfig')) {
            $hostedKey = trim(Tools::getValue('PAYNETWORXHOSTED_HOSTED_API_KEY'));
            $user      = trim(Tools::getValue('PAYNETWORXHOSTED_TOKEN_USER'));
            $pass      = trim(Tools::getValue('PAYNETWORXHOSTED_TOKEN_PASS'));
            $mode      = Tools::getValue('PAYNETWORXHOSTED_MODE') === 'live' ? 'live' : 'test';

            if (empty($hostedKey) || empty($user)) {
                $output .= $this->displayError(
                    $this->l('Hosted API Key and Access Token User are required.')
                );
            } else {
                Configuration::updateValue('PAYNETWORXHOSTED_HOSTED_API_KEY', $hostedKey);
                Configuration::updateValue('PAYNETWORXHOSTED_TOKEN_USER', $user);
                Configuration::updateValue('PAYNETWORXHOSTED_MODE', $mode);

                // Only overwrite password if a new one was submitted
                if (!empty($pass)) {
                    Configuration::updateValue('PAYNETWORXHOSTED_TOKEN_PASS', $pass);
                }

                $output .= $this->displayConfirmation($this->l('Settings saved successfully.'));
            }
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table        = $this->table;
        $helper->module       = $this;
        $helper->default_form_language    = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier    = $this->identifier;
        $helper->submit_action = 'submitPaynetworxHostedConfig';
        $helper->currentIndex  = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'PAYNETWORXHOSTED_MODE'           => Configuration::get('PAYNETWORXHOSTED_MODE', 'test'),
                'PAYNETWORXHOSTED_HOSTED_API_KEY' => Configuration::get('PAYNETWORXHOSTED_HOSTED_API_KEY'),
                'PAYNETWORXHOSTED_TOKEN_USER'     => Configuration::get('PAYNETWORXHOSTED_TOKEN_USER'),
                'PAYNETWORXHOSTED_TOKEN_PASS'     => '',
            ],
            'languages'   => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->l('Paynetworx Hosted Payments — Configuration'),
                    'icon'  => 'icon-key',
                ],
                'description' => $this->l(
                    'Hosted Payments requires two sets of credentials: '
                    . '(1) a Hosted API Key for iframe session creation, '
                    . 'and (2) the standard Access Token credentials for charging the token. '
                    . 'Contact Paynetworx support to obtain the Hosted API Key and activate your QA environment.'
                ),
                'input' => [
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Environment'),
                        'name'    => 'PAYNETWORXHOSTED_MODE',
                        'required' => true,
                        'options' => [
                            'query' => [
                                ['id' => 'test', 'name' => $this->l('Test / Sandbox')],
                                ['id' => 'live', 'name' => $this->l('Live / Production')],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('Hosted Payments API Key'),
                        'name'     => 'PAYNETWORXHOSTED_HOSTED_API_KEY',
                        'required' => true,
                        'desc'     => $this->l('Provided by Paynetworx specifically for Hosted Payments. Different from the Access Token credentials below.'),
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('Access Token User'),
                        'name'     => 'PAYNETWORXHOSTED_TOKEN_USER',
                        'required' => true,
                        'desc'     => $this->l('Your standard Paynetworx API token user (used to charge the token after card capture).'),
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->l('Access Token Password'),
                        'name'     => 'PAYNETWORXHOSTED_TOKEN_PASS',
                        'required' => true,
                        'desc'     => $this->l('Leave blank to keep the current password unchanged.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ]]);
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->isConfigured()) {
            return [];
        }

        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText($this->l('Pay by Credit / Debit Card'))
               ->setForm($this->generatePaymentForm());

        return [$option];
    }

    protected function generatePaymentForm()
    {
        // One-time nonce — consumed by the validation controller to block replay
        $nonce = bin2hex(random_bytes(16));
        $this->context->cookie->paynetworxhosted_nonce = $nonce;

        $mode = Configuration::get('PAYNETWORXHOSTED_MODE', 'test');

        $this->context->smarty->assign([
            'session_ajax_url'  => $this->context->link->getModuleLink($this->name, 'session', [], true),
            'validation_url'    => $this->context->link->getModuleLink($this->name, 'validation', [], true),
            'pnx_form_origin'   => ($mode === 'live')
                ? 'https://hosted-payments.paynetworx.cloud'
                : 'https://hosted-payments-qa.paynetworx.cloud',
            'paynetworx_nonce'  => $nonce,
        ]);

        return $this->display(__FILE__, 'views/templates/front/payment_form.tpl');
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $order = isset($params['order']) ? $params['order'] : null;

        $this->context->smarty->assign([
            'order_reference' => $order ? $order->reference : '',
        ]);

        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }
}
