<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_'))
    exit;

require_once _PS_MODULE_DIR_ . 'iyzicocheckoutform/includer.php';
require_once _PS_MODULE_DIR_ . 'iyzicocheckoutform/IyzipayBootstrap.php';

class Iyzicocheckoutform extends PaymentModule {

    protected $_html = '';
    protected $_postErrors = array();
    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;
    public $_prestashop = '_ps';
    public $_ModuleVersion = '1.7.0.4';
    protected $hooks = array(
        'payment',
        'backOfficeHeader',
		'paymentOptions',
        'displayAdminOrder'
    );

    public function __construct() {
        $this->name = 'iyzicocheckoutform';
        $this->tab = 'payments_gateways';
        $this->version = '1.7.0.4';
        $this->author = 'KahveDigital';
        $this->controllers = array('payment', 'validation');
        $this->is_eu_compatible = 1;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('iyzico Checkout Form');
        $this->description = $this->l('iyzico checkout form Internet üzerinden müşterilerinize ödeme yöntemleri sunmanın en hızlı ve en kolay yoludur. Sizi karmaşık Sanal POS başvuru işlemlerinden ve bekleme sürelerinden kurtarır.');
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.7.99.99');
        $this->confirmUninstall = $this->l('Are you sure about removing these details?');
    }

    public function install() {
        if (!parent::install() || !$this->registerHook('paymentOptions') || !$this->registerHook('displayAdminOrder') || !$this->registerHook('displayPaymentEU') || !$this->registerHook('paymentReturn'))
            return false;

        if (!Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'iyzico_order_form` (
                       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                        `order_id` int(11) unsigned NOT NULL,
                        `transaction_id` varchar(255) NOT NULL,
                        `installment_fee` double NOT NULL,
                        `installment_amount` double NOT NULL,
                        `installment_no` int(11) unsigned NOT NULL,
                        `installment_brand` varchar(100) NOT NULL,
                        `response_data` text NOT NULL,
                        `created` datetime DEFAULT NULL,
                        `processing_time` varchar(255) NOT NULL,
                         PRIMARY KEY (`id`)
                    ) ENGINE= ' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8'))
            return false;


        if (!Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'iyzico_cart_save` (
						`card_save_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                        `customer_id` int(11) unsigned NOT NULL,
                        `api_key` varchar(255) ,
                        `card_key` varchar(155),
                         PRIMARY KEY (`card_save_id`)
                    ) ENGINE= ' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8'))
            return false;


        if (!Db::getInstance()->Execute('ALTER TABLE `' . _DB_PREFIX_ . 'iyzico_order_form` CHANGE `installment_fee` `installment_fee` DOUBLE NOT NULL;'))
            return false;

        //iyzico cart detai table for refund
        if (!Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'iyzico_cart_detail` (
                        `id_iyzico_cart_detail` int(11) NOT NULL AUTO_INCREMENT,
                        `order_id` int(11) NOT NULL,
                        `item_id` int(11) NOT NULL,
                        `payment_transaction_id` int(11) NOT NULL,
                        `paid_price` double NOT NULL,
                        `total_refunded_amount` double NOT NULL,
                        `created` datetime NOT NULL,
                        `updated` datetime NOT NULL,
                        PRIMARY KEY (`id_iyzico_cart_detail`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'))
            return false;

        $tableName = _DB_PREFIX_ . 'iyzico_cart_detail';
        $result = Db::getInstance()->ExecuteS("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '{$tableName}' AND COLUMN_NAME = 'currency';");
        if (empty($result)) {
            if (!Db::getInstance()->Execute("ALTER TABLE `" . _DB_PREFIX_ . "iyzico_cart_detail` ADD `currency` VARCHAR(50) NOT NULL DEFAULT 'TRY' AFTER `paid_price`;"))
                return false;
        }


        if (!Db::getInstance()->Execute("CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "iyzico_api_log` (
                        `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                        `order_id` int(11) NOT NULL,
                        `item_id` int(11) NOT NULL,
                        `transaction_status` varchar(255) NOT NULL,
                        `api_request` text NOT NULL,
                        `api_response` text NOT NULL,
                        `request_type` enum('payment_form_initialization','order_success','order_cancel','order_refund','post_callback','get_auth') NOT NULL,
                        `note` text NOT NULL,
                        `created` datetime NOT NULL,
                        `updated` datetime NOT NULL,
                        PRIMARY KEY (`id`)
                        ) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8"))
            return false;

        return true;
    }

    public function uninstall() {
        if (parent::uninstall()) {
            Configuration::deleteByName('IYZICO_FORM_LIVE_API_ID');
            Configuration::deleteByName('IYZICO_FORM_LIVE_SECRET');
            foreach ($this->hooks as $hook) {
                if (!$this->unregisterHook($hook))
                    return false;
            }
        }
        return true;
    }

    protected function _postValidation() {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('IYZICO_FORM_LIVE_API_ID') || !Tools::getValue('IYZICO_FORM_LIVE_SECRET') || !Tools::getValue('IYZICO_FORM_LIVE_API_ID') || !Tools::getValue('IYZICO_FORM_LIVE_SECRET')) {
                $this->_postErrors[] = $this->l('Account keys are required.');
            }
        }
    }

    protected function _postProcess() {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('IYZICO_FORM_LIVE_API_ID', Tools::getValue('IYZICO_FORM_LIVE_API_ID'));
            Configuration::updateValue('IYZICO_FORM_LIVE_SECRET', Tools::getValue('IYZICO_FORM_LIVE_SECRET'));
            Configuration::updateValue('IYZICO_FORM_CLASS', Tools::getValue('IYZICO_FORM_CLASS'));
        }
        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
    }

    protected function _displayIyzicoInfo() {
        $version = $this->_ModuleVersion;
        $psver = _PS_VERSION_;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://iyzico.kahvedigital.com/version');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "psversion=$psver&iyzico=$version&type=prestashop");
        $response = curl_exec($ch);
        $response = json_decode($response, true);
        curl_close($ch);
        $this->context->smarty->assign('version', $response);

        $test = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $this->context->smarty->assign('link', $test);
        if ($version == $response['iyzico_version']) {
            if (isset($_POST['updated_iyzico'])) {

                $version_updatable = $_POST['updated_iyzico'];

                function recurse_copy($src, $dst) {
                    $dir = opendir($src);
                    @mkdir($dst);
                    while (false !== ( $file = readdir($dir))) {
                        if (( $file != '.' ) && ( $file != '..' )) {
                            if (is_dir($src . '/' . $file)) {
                                recurse_copy($src . '/' . $file, $dst . '/' . $file);
                            } else {
                                copy($src . '/' . $file, $dst . '/' . $file);
                            }
                        }
                    }
                    closedir($dir);
                }

                function rrmdir($dir) {
                    if (is_dir($dir)) {
                        $objects = scandir($dir);
                        foreach ($objects as $object) {
                            if ($object != "." && $object != "..") {
                                if (filetype($dir . "/" . $object) == "dir")
                                    rrmdir($dir . "/" . $object);
                                else
                                    unlink($dir . "/" . $object);
                            }
                        }
                        reset($objects);
                        rmdir($dir);
                    }
                }

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'http://iyzico.kahvedigital.com/update');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, "new_version=$version_updatable");
                $response = curl_exec($ch);
                $response = json_decode($response, true);
                curl_close($ch);
                $serveryol = $_SERVER['DOCUMENT_ROOT'];
                $ch = curl_init();
                $source = $response['file_dest'];
                curl_setopt($ch, CURLOPT_URL, $source);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $data = curl_exec($ch);
                curl_close($ch);
                $foldername = $response['version_name'];
                $fullfoldername = $serveryol . '/' . $foldername;
                if (!file_exists($fullfoldername)) {
                    mkdir($fullfoldername);
                }
                $unzipfilename = 'iyzicoupdated.zip';
                $file = fopen($fullfoldername . '/' . $unzipfilename, "w+");
                fputs($file, $data);
                fclose($file);
                $path = pathinfo(realpath($fullfoldername . '/' . $unzipfilename), PATHINFO_DIRNAME);
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive;
                    $res = $zip->open($fullfoldername . '/' . $unzipfilename);
                    if ($res === TRUE) {
                        $zip->extractTo($path);
                        $zip->close();
                        $zip_name_folder = $response['zip_name_folder'];
                        recurse_copy($fullfoldername . '/' . $zip_name_folder, _PS_MODULE_DIR_ . '/' . $zip_name_folder);
                        rrmdir($fullfoldername);
                    }
                }
            }
        }

        return $this->display(__FILE__, 'infos.tpl');
    }

    public function getContent() {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors))
                $this->_postProcess();
            else
                foreach ($this->_postErrors as $err)
                    $this->_html .= $this->displayError($err);
        } else
            $this->_html .= '<br />';

        $this->_html .= $this->_displayIyzicoInfo();
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPaymentOptions($params) {
        try {

            $currency_query = 'SELECT * FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency`= "' . $params['cookie']->id_currency . '"';
            $currency = Db::getInstance()->ExecuteS($currency_query);
            $cart_id = $this->context->cookie->id_cart;
            $product_ids_discount = array();
            $productsIds = array();
            $product_id_contain_discount = array();
            $iso_code = $this->context->language->iso_code;
            $erorr_msg = ($iso_code == "tr") ? 'Girdiğiniz kur değeri sistem tarafından desteklenmemektedir. Lütfen kur değerinin TL, USD, EUR, GBP veya IRR olduğundan emin olunuz.' : 'The current exchange rate you entered is not supported by the system. Please use TRY, USD, EUR, GBP, IRR exchange rate.';
            $error_terms = ($iso_code == "tr") ? 'Şartlar ve koşulları kabul etmeniz gerekir.' : 'You must accept terms and conditions.';
            $this->smarty->assign(array(
                'error_terms' => $error_terms,
            ));
   

            IyzipayBootstrap::init();
            $options = new \Iyzipay\Options();
            $options->setApiKey(Configuration::get('IYZICO_FORM_LIVE_API_ID'));
            $options->setSecretKey(Configuration::get('IYZICO_FORM_LIVE_SECRET'));
            $options->setBaseUrl("https://api.iyzipay.com");
            $form_class = Configuration::get('IYZICO_FORM_CLASS');

            $locale = ($iso_code == "tr") ? Iyzipay\Model\Locale::TR : Iyzipay\Model\Locale::EN;


            $query = 'SELECT * FROM `' . _DB_PREFIX_ . 'address` WHERE `id_customer`= "' . $params['cookie']->id_customer . '"';
            $guest_user_detail = Db::getInstance()->ExecuteS($query);
            $country_query = 'SELECT * FROM `' . _DB_PREFIX_ . 'country_lang` WHERE `id_country`= "' . $guest_user_detail[0]['id_country'] . '"';
            $guest_country = Db::getInstance()->ExecuteS($country_query);

            $products = $params['cart']->getProducts();
            $billing_detail = new Address((int) ($params['cart']->id_address_invoice));
            $shipping_detail = new Address((int) ($params['cart']->id_address_delivery));
            $order_amount = (double) number_format($params['cart']->getOrderTotal(true, Cart::BOTH), 2, '.', '');
            $product_sub_total = number_format($params['cart']->getOrderTotal(true, Cart::ONLY_PRODUCTS), 2, '.', '');
            $shipping_price = number_format($params['cart']->getOrderTotal(true, Cart::ONLY_SHIPPING), 2, '.', '');


            $first_name = !empty($params['cookie']->customer_firstname) ? $params['cookie']->customer_firstname : 'NOT PROVIDED';
            $last_name = !empty($params['cookie']->customer_lastname) ? $params['cookie']->customer_lastname : 'NOT PROVIDED';
            $email = !empty($params['cookie']->email) ? $params['cookie']->email : 'NOT PROVIDED';
            $last_login = !empty($guest_user_detail[0]['date_add']) ? $guest_user_detail[0]['date_add'] : 'NOT PROVIDED';
            $registration_date = !empty($guest_user_detail[0]['date_upd']) ? $guest_user_detail[0]['date_upd'] : 'NOT PROVIDED';
            $phone_mobile = !empty($guest_user_detail[0]['phone_mobile']) ? $guest_user_detail[0]['phone_mobile'] : 'NOT PROVIDED';
            $city = !empty($guest_user_detail[0]['city']) ? $guest_user_detail[0]['city'] : 'NOT PROVIDED';
            $country = !empty($guest_country[0]['name']) ? $guest_country[0]['name'] : 'NOT PROVIDED';
            $postcode = !empty($guest_user_detail[0]['postcode']) ? $guest_user_detail[0]['postcode'] : 'NOT PROVIDED';

            $billing_date_add = !empty($billing_detail->date_add) ? $billing_detail->date_add : 'NOT PROVIDED';
            $billing_date_upd = !empty($billing_detail->date_upd) ? $billing_detail->date_upd : 'NOT PROVIDED';
            $billing_phone_mobile = !empty($billing_detail->phone_mobile) ? $billing_detail->phone_mobile : 'NOT PROVIDED';
            $billing_city = !empty($billing_detail->city) ? $billing_detail->city : 'NOT PROVIDED';
            $billing_country = !empty($billing_detail->country) ? $billing_detail->country : 'NOT PROVIDED';
            $billing_postcode = !empty($billing_detail->postcode) ? $billing_detail->postcode : 'NOT PROVIDED';
            $billing_firstname = !empty($billing_detail->firstname) ? $billing_detail->firstname : 'NOT PROVIDED';
            $billing_lastname = !empty($billing_detail->lastname) ? $billing_detail->lastname : 'NOT PROVIDED';

            $shipping_firstname = !empty($shipping_detail->firstname) ? $shipping_detail->firstname : 'NOT PROVIDED';
            $shipping_lastname = !empty($shipping_detail->lastname) ? $shipping_detail->lastname : 'NOT PROVIDED';
            $shipping_city = !empty($shipping_detail->city) ? $shipping_detail->city : 'NOT PROVIDED';
            $shipping_country = !empty($shipping_detail->country) ? $shipping_detail->country : 'NOT PROVIDED';
            $shipping_postcode = !empty($shipping_detail->postcode) ? $shipping_detail->postcode : 'NOT PROVIDED';


            $request = new \Iyzipay\Request\CreateCheckoutFormInitializeRequest();
            $request->setLocale($locale);
            $request->setConversationId(uniqid() . $this->_prestashop);
            $request->setPaidPrice(number_format($order_amount, 2, '.', ''));
            $request->setCurrency($currency[0]['iso_code']);
            $request->setBasketId($params['cookie']->id_cart);
            $request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::PRODUCT);
            $request->setCallbackUrl((Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'index.php?module_action=result&fc=module&module=iyzicocheckoutform&controller=result');
            $request->setPaymentSource('PRESTASHOP-' . _PS_VERSION_ . "-" . $this->_ModuleVersion);


            $buyer = new \Iyzipay\Model\Buyer();
            $buyer->setId($params['cookie']->id_customer);
            $buyer->setName($first_name);
            $buyer->setSurname($last_name);
            $buyer->setGsmNumber($phone_mobile);
            $buyer->setEmail($email);
            $buyer->setIdentityNumber($params['cookie']->id_customer . uniqid());
            $buyer->setIp((string) Tools::getRemoteAddr());

            $billing_address = new \Iyzipay\Model\Address();
            $billing_address->setContactName($first_name . ' ' . $last_name);

            $shipping_address = new \Iyzipay\Model\Address();
            $credit_card = ($iso_code == "tr") ? "Kredi Kartı" : "Credit Card";
            $module_dir = __PS_BASE_URI__;
            if ($params['cookie']->is_guest == 1) {
                $buyer->setLastLoginDate($last_login);
                $buyer->setRegistrationDate($registration_date);
                $buyer->setRegistrationAddress($guest_user_detail[0]['address1'] . ' ' . $guest_user_detail[0]['address2']);
                $buyer->setGsmNumber($phone_mobile);
                $buyer->setCity($city);
                $buyer->setCountry($country);
                $buyer->setZipCode($postcode);

                $billing_address->setCity($city);
                $billing_address->setCountry($country);
                $billing_address->setAddress($guest_user_detail[0]['address1'] . ' ' . $guest_user_detail[0]['address2']);
                $billing_address->setZipCode($postcode);

                $shipping_address->setContactName($first_name . ' ' . $last_name);
                $shipping_address->setCity($city);
                $shipping_address->setCountry($country);
                $shipping_address->setAddress($guest_user_detail[0]['address1'] . ' ' . $guest_user_detail[0]['address2']);
                $shipping_address->setZipCode($postcode);
            } else {

                $buyer->setLastLoginDate($billing_date_add);
                $buyer->setRegistrationDate($billing_date_upd);
                $buyer->setRegistrationAddress($billing_detail->address1 . ' ' . $billing_detail->address2);
                $buyer->setGsmNumber($billing_phone_mobile);
                $buyer->setCity($billing_city);
                $buyer->setCountry($billing_country);
                $buyer->setZipCode($billing_postcode);

                $billing_address->setContactName($billing_firstname . ' ' . $billing_lastname);
                $billing_address->setCity($billing_city);
                $billing_address->setCountry($billing_country);
                $billing_address->setAddress($billing_detail->address1 . ' ' . $billing_detail->address2);
                $billing_address->setZipCode($billing_postcode);

                $shipping_address->setContactName($shipping_firstname . ' ' . $shipping_lastname);
                $shipping_address->setCity($shipping_city);
                $shipping_address->setCountry($shipping_country);
                $shipping_address->setAddress($shipping_detail->address1 . ' ' . $shipping_detail->address2);
                $shipping_address->setZipCode($shipping_postcode);
            }

            foreach ($products as $product) {
                $productsIds[] = $product['id_product'];
            }

            $sql_prod_discount = "SELECT reduction_product, reduction_amount, reduction_percent FROM " . _DB_PREFIX_ . "cart_rule WHERE reduction_product > 0 AND reduction_product IN (" . implode(',', $productsIds) . ")";
            $prod_ids_cart_rule = Db::getInstance()->ExecuteS($sql_prod_discount);

            if (!empty($prod_ids_cart_rule)) {
                foreach ($prod_ids_cart_rule as $key => $value) {
                    $product_id_contain_discount[$value['reduction_product']] = array(
                        'reduction_amount' => $value['reduction_product'],
                        'reduction_percent' => $value['reduction_product']
                    );
                }
            }

            if ($cart_id) {
                $product_ids_discount = $this->_productDiscountArr($cart_id, $product_id_contain_discount);
            }
            $cart_discount_price = 0;
            $product_ids_array = array_keys($product_ids_discount);

            if (!empty($product_ids_discount['order_specific_discount'])) {
                foreach ($product_ids_discount['order_specific_discount'] as $key => $value) {
                    if ($value['discount_type'] == 'percent') {
                        $cart_discount_price += ($product_sub_total * $value['amount']) / 100;
                    } else {
                        $cart_discount_price += $value['amount'];
                    }
                }
            }

            $total_discount = 0;
            $remaining_discount = 0;
            $shipping_price_per_product = 0;
            $cart_total = 0;
            $items = array();
            foreach ($products as $product) {
                $discount = 0;
                $discount_price = 0;
                $product_price = ($product['price_wt'] * $product['cart_quantity']);

                $category = !empty($product['category']) ? $product['category'] : 'NOT PROVIDED';

                if (in_array($product['id_product'], $product_ids_array)) {
                    if ($product_ids_discount[$product['id_product']]['discount_type'] == 'percent') {
                        $discount_price = ( $product_price * (double) $product_ids_discount[$product['id_product']]['amount']) / 100;
                        $product_price = $product_price - $discount_price;
                    } else {
                        $discount_price = $product_ids_discount[$product['id_product']]['amount'];
                        $product_price = $product_price - $discount_price;
                    }
                }

                $discount = $cart_discount_price * ($product_price / $product_sub_total);
                $discount = number_format($discount, 2);
                $product_price -= $discount;

                if ($shipping_price > 0) {
                    if ($product_price < 0) {
                        $prod_price = 0;
                    } else {
                        $prod_price = $product_price;
                    }
                    $shipping_price_per_product = (($product['price_wt'] * $product['cart_quantity']) / $product_sub_total) * $shipping_price;
                    $shipping_price_per_product = number_format($shipping_price_per_product, 2);
                    $product_price = $prod_price + $shipping_price_per_product;
                }

                if ($product_price > 0) {
                    $item = new \Iyzipay\Model\BasketItem();
                    $item->setId($product['id_product']);
                    $item->setName($product['name']);
                    $item->setCategory1($category);
                    $product_type = $product['is_virtual'] ? \Iyzipay\Model\BasketItemType::VIRTUAL : \Iyzipay\Model\BasketItemType::PHYSICAL;
                    $item->setItemType($product_type);

                    $item->setPrice(number_format($product_price, 2, '.', ''));
                    $cart_total += number_format($product_price, 2, '.', '');
                    $items[] = $item;
                } else {
                    $remaining_discount += abs($product_price);
                }
                $total_discount += $discount;
            }

            $discount_remain = $cart_discount_price - $total_discount;
            $total_price_final = 0;
            if ($discount_remain > 0) {
                foreach ($items as $key => $item) {
                    $product_price = $item->getPrice();
                    $discount = $discount_remain * ($product_price / $cart_total);
                    $product_price -= $discount;
                    if ($product_price > 0) {
                        $item->setPrice(number_format($product_price, 2));
                        $total_price_final += number_format($product_price, 2);
                    } else {
                        unset($items[$key]);
                    }
                }
            } else {
                $total_price_final = $cart_total;
            }

            if ($total_price_final < $order_amount) {
                $diff_price = (double) $order_amount - (double) $total_price_final;
                $diff_price = (double) number_format($diff_price, 2);
                $item_count = count($items);
                $last_item_index = $item_count - 1;
                $prod_price = $items[$last_item_index]->getPrice();
                $product_price = $prod_price + $diff_price;
                $items[$last_item_index]->setPrice($product_price);
                $total_price_final = $total_price_final + $diff_price;
            }

            if ($total_price_final > $order_amount) {
                $diff_price = (double) $total_price_final - (double) $order_amount;
                $diff_price = (double) number_format($diff_price, 2);
                $item_count = count($items);
                $last_item_index = $item_count - 1;
                $prod_price = $items[$last_item_index]->getPrice();
                $product_price = $prod_price - $diff_price;
                $total_price_final = $total_price_final - $diff_price;

                if ($product_price <= 0) {
                    unset($items[$last_item_index]);
                } else {
                    $items[$last_item_index]->setPrice($product_price);
                }
            }

            if (!empty($items)) {
                $request->setPrice($total_price_final);
                $request->setBuyer($buyer);
                $request->setBillingAddress($billing_address);
                $request->setShippingAddress($shipping_address);
                $request->setBasketItems($items);
                Db::getInstance()->insert("iyzico_api_log", array(
                    'id' => Tools::getValue('id'),
                    'order_id' => (int) $params['cookie']->id_cart,
                    'item_id' => 0,
                    'transaction_status' => '',
                    'api_request' => pSQL($request->toJsonString()),
                    'api_response' => '',
                    'request_type' => 'payment_form_initialization',
                    'note' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'updated' => date('Y-m-d H:i:s'),
                ));

                $last_insert_id = Db::getInstance()->Insert_ID();

                if (isset($params['cookie']->id_customer)) {
                    if ($params['cookie']->is_guest !== 1) {
                        $cardcustomer = 'SELECT * FROM `' . _DB_PREFIX_ . 'iyzico_cart_save` WHERE `customer_id`= "' . $params['cookie']->id_customer . '"';
                        if ($row = Db::getInstance()->getRow($cardcustomer)) {
                            if (!(strlen($row['card_key']) == 0) || ($row['card_key'] !== '0') || ($row['card_key'] !== 'null')) {
                                if ($row['api_key'] == Configuration::get('IYZICO_FORM_LIVE_API_ID')) {
                                    $request->setCardUserKey($row['card_key']);
                                }
                            }
                        }
                    }
                }

                $response = \Iyzipay\Model\CheckoutFormInitialize::create($request, $options);

                $update_array = array(
                    'transaction_status' => 'success',
                    'api_response' => pSQL($response->getRawResult()),
                    'updated' => date('Y-m-d H:i:s'),
                );

                Db::getInstance()->update('iyzico_api_log', $update_array, 'id = ' . (int) $last_insert_id);

                if ($response->getStatus() == 'failure') {
                    $this->error = $response->getErrorMessage();
                    $this->context->smarty->assign('error', $this->error);
                    $this->context->smarty->assign('credit_card', $credit_card);
                    $this->response = '';
                }
                if ($response->getStatus() == 'success') {
                    $this->response = $response->getCheckoutFormContent();
                    $this->context->smarty->assign('response', $this->response);
                    $this->context->smarty->assign('form_class', $form_class);
                    $this->context->smarty->assign('credit_card', $credit_card);
                    $this->context->smarty->assign('module_dir', $module_dir);
                    $this->error = '';
                }
                $this->smarty->assign(array(
                    'credit_card' => $credit_card,
                    'this_path' => $this->_path,
                    'this_path_bw' => $this->_path,
                    'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/',
                ));

                $logo = Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png');
                $newOption = new PaymentOption();
                $newOption->setCallToActionText($this->trans('Kredi Kartı İle Öde', array(), 'Modules.Iyzicocheckoutform'))
                        ->setAdditionalInformation($this->fetch('module:iyzicocheckoutform/views/templates/hook/payment.tpl'));
						
                $newOption->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))->setLogo($logo);
				  $newOption->setModuleName('iyzicocheckoutform', array(), 'Modules.Iyzicocheckoutform');
                $payment_options = [
                    $newOption,
                ];

                return $payment_options;
            } else {
                $this->context->cookie->zero_total = true;
                $this->smarty->assign('success_url', Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://' . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'index.php?module_action=payment&fc=module&module=iyzicocheckoutform&controller=result');

                return $this->display(__FILE__, 'no_payment.tpl');
            }
        } catch (Exception $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
        }
    }

    private function _productDiscountArr($cart_id, $product_id_contain_discount) {
        $product_ids_discount = array();
        $cart_product_discounted_ids = array_keys($product_id_contain_discount);
        $cart_rule_query = 'SELECT cr.id_cart,cr.id_cart_rule, crpg.id_product_rule_group  FROM ' . _DB_PREFIX_ . 'cart_cart_rule cr '
                . 'LEFT JOIN ' . _DB_PREFIX_ . 'cart_rule_product_rule_group crpg ON cr.id_cart_rule = crpg.id_cart_rule'
                . '  WHERE  cr.id_cart = ' . (int) $cart_id;
        $cart_rule_result = Db::getInstance()->ExecuteS($cart_rule_query);

        if (!empty($cart_rule_result)) {
            foreach ($cart_rule_result as $key => $value) {
                $id_product_rule_group = $value['id_product_rule_group'];
                $is_product_specific_discount = !empty($id_product_rule_group) ? true : false;
                $id_cart_rule = $value['id_cart_rule'];

                $reduction_amount = $this->_findCartRulePrice($id_cart_rule, $is_product_specific_discount);
                $discount_amount = 0;
                $discount_type = '';
                if (!empty($reduction_amount['reduction_amount']) && (float) $reduction_amount['reduction_amount'] > 0) {
                    $discount_amount = $reduction_amount['reduction_amount'];
                    $discount_type = 'amount';
                } else if (!empty($reduction_amount['reduction_percent'])) {
                    $discount_amount = $reduction_amount['reduction_percent'];
                    $discount_type = 'percent';
                }

                if (!empty($reduction_amount) && $is_product_specific_discount && !empty($cart_product_discounted_ids)) {
                    $product_ids = $this->_getProductRules($id_product_rule_group);
                    foreach ($product_ids as $row) {
                        if (in_array($row['id_item'], $cart_product_discounted_ids)) {
                            $product_ids_discount[$row['id_item']] = array('amount' => $discount_amount, 'discount_type' => $discount_type);
                        }
                    }
                } else {
                    $product_ids_discount['order_specific_discount'][] = array('amount' => $discount_amount, 'discount_type' => $discount_type);
                }
            }
        }
        return $product_ids_discount;
    }

    private function _getProductRules($id_product_rule_group) {
        $results = Db::getInstance()->executeS('
		SELECT *
		FROM ' . _DB_PREFIX_ . 'cart_rule_product_rule pr
		LEFT JOIN ' . _DB_PREFIX_ . 'cart_rule_product_rule_value prv ON pr.id_product_rule = prv.id_product_rule
		WHERE pr.id_product_rule_group = ' . (int) $id_product_rule_group);
        return $results;
    }

    private function _findCartRulePrice($id_cart_rule, $is_product_specific_discount) {
        if ($is_product_specific_discount) {
            $sql = 'SELECT reduction_amount, reduction_percent FROM ' . _DB_PREFIX_ . 'cart_rule WHERE reduction_product > 0 AND id_cart_rule = ' . (int) $id_cart_rule . ' LIMIT 0,1';
        } else {
            $sql = 'SELECT reduction_amount, reduction_percent FROM ' . _DB_PREFIX_ . 'cart_rule WHERE  id_cart_rule = ' . (int) $id_cart_rule . ' LIMIT 0,1';
        }

        $results = Db::getInstance()->executeS($sql);
        if (!empty($results)) {
            return $results[0];
        }
        return $results;
    }

    public function checkCurrency($cart) {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module))
            foreach ($currencies_module as $currency_module)
                if ($currency_order->id == $currency_module['id_currency'])
                    return true;
        return false;
    }

    public function renderForm() {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('IYZICO LIVE API ID'),
                        'name' => 'IYZICO_FORM_LIVE_API_ID',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('IYZICO LIVE SECRET'),
                        'name' => 'IYZICO_FORM_LIVE_SECRET',
                        'required' => true
                    ),
                    array(
                        'type' => 'radio',
                        'label' => $this->l('Form Class'),
                        'name' => 'IYZICO_FORM_CLASS',
                        'values' => array(
                            array(
                                'value' => 'popup',
                                'label' => $this->l('Popup')
                            ),
                            array(
                                'value' => 'responsive',
                                'label' => $this->l('Responsive')
                            ),
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->id = (int) Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );
        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues() {

        return array(
            'IYZICO_FORM_LIVE_API_ID' => Tools::getValue('IYZICO_FORM_LIVE_API_ID', Configuration::get('IYZICO_FORM_LIVE_API_ID')),
            'IYZICO_FORM_LIVE_SECRET' => Tools::getValue('IYZICO_FORM_LIVE_SECRET', Configuration::get('IYZICO_FORM_LIVE_SECRET')),
            'IYZICO_FORM_CLASS' => Tools::getValue('IYZICO_FORM_CLASS', Configuration::get('IYZICO_FORM_CLASS')),
        );
    }

    public function hookDisplayAdminOrder($params) {
        $order_detail = array();
        $iyzico_installment_data = IyzicocheckoutformOrder::getByPsOrderId($params['id_order']);
        $order_amount = number_format($params['cart']->getOrderTotal(true, Cart::BOTH), 2, '.', '');
        $this->smarty->assign('iyzico_installment_data', $iyzico_installment_data);
        $iso_code = $this->context->language->iso_code;

        $order_state_query = 'SELECT * FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order`= "' . $params['id_order'] . '"';
        $order_state = Db::getInstance()->ExecuteS($order_state_query);

        $query = 'SELECT * FROM `' . _DB_PREFIX_ . 'iyzico_cart_detail` WHERE `order_id`= "' . $params['id_order'] . '" AND total_refunded_amount > 0';
        $refund_exist = Db::getInstance()->ExecuteS($query);
        
        if (date('Y-m-d', strtotime($iyzico_installment_data['created'])) == date('Y-m-d')) {
            if (empty($refund_exist)) {
                if ($order_state[0]['current_state'] != _PS_OS_CANCELED_) {
                    $iyzico_order_state_query = 'SELECT * FROM `' . _DB_PREFIX_ . 'iyzico_order_form` WHERE `order_id`= "' . $params['id_order'] . '"';
                    $iyzico_order_state = Db::getInstance()->ExecuteS($iyzico_order_state_query);

                    $this->smarty->assign(array(
                        'id_employee' => $params['cookie']->id_employee,
                        'lang_code' => $iso_code,
                        'transaction_id' => $iyzico_installment_data['transaction_id'],
                        'currency' => Tools::displayPrice($iyzico_order_state[0]['installment_amount'], $params['currency']->sign, false)
                    ));
                }
            }
        }

        if ($order_state[0]['current_state'] != _PS_OS_CANCELED_) {

            $query = 'SELECT distinct pl.id_product,pl.name,ic.* FROM ' . _DB_PREFIX_ . 'iyzico_cart_detail ic LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON ic.item_id = pl.id_product WHERE ic.order_id = ' . $params['id_order'] . ' AND ic.paid_price != ic.total_refunded_amount';
            $order_detail = Db::getInstance()->ExecuteS($query);

            $this->smarty->assign('order_detail', $order_detail);
        }

        $log_query = 'SELECT distinct pl.id_product,pl.name,al.* FROM ' . _DB_PREFIX_ . 'iyzico_api_log al LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON al.item_id = pl.id_product WHERE al.order_id = ' . $params['id_order'] . ' AND al.request_type IN ("order_cancel","order_refund")';
        $order_history = Db::getInstance()->ExecuteS($log_query);
        $refund_url = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'modules/iyzicocheckoutform/refund.php';

        $form_action = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'modules/iyzicocheckoutform/cancel.php';

        $error_msg = !empty(Tools::getValue('error')) ? Tools::getValue('error') : '';

        $this->smarty->assign(array(
            'refund_url' => $refund_url,
            'lang_code' => $iso_code,
            'order_history' => $order_history,
            'ip' => (string) Tools::getRemoteAddr(),
            'form_action' => $form_action,
            'error' => $error_msg

        ));
        return $this->display(__FILE__, 'order_detail.tpl');
    }


}
