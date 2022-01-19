<?php

class ControllerExtensionPaymentOplata extends Controller
{
    private $error = [];
    private $extensionVersion = '2.1.1';

    public function install() {
        $this->load->model('extension/payment/oplata');
        $this->model_extension_payment_oplata->install();
    }

    public function uninstall() {
        $this->load->model('extension/payment/oplata');
        $this->model_extension_payment_oplata->uninstall();
    }

    public function index()
    {
        $this->load->language('extension/payment/oplata');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_oplata', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $errorMessageValues = ["warning", "merchant", "secretkey", "type"];
        foreach ($errorMessageValues as $v)
            $data['error_' . $v] = (isset($this->error[$v])) ? $this->error[$v] : "";

        $data['breadcrumbs'] = $this->getBreadcrumbs();
        $data['extension_version'] = $this->extensionVersion;
        $data['process_payment_types'] = $this->getProcessPaymentTypes();
        $data['style_presets'] = $this->getStylePresets();
        $data['action'] = $this->url->link('extension/payment/oplata', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $formInputs = [
            'payment_oplata_status',
            'payment_oplata_environment',
            'payment_oplata_merchant',
            'payment_oplata_secretkey',
            'payment_oplata_process_payment_type',
            'payment_oplata_type',
            'payment_oplata_geo_zone_id',
            'payment_oplata_sort_order',
            'payment_oplata_order_success_status_id',
            'payment_oplata_order_cancelled_status_id',
            'payment_oplata_order_process_status_id',
            'payment_oplata_order_reverse_status_id',
            'payment_oplata_style_type',
            'payment_oplata_style_preset',
        ];
        foreach ($formInputs as $v)
            $data[$v] = (isset($this->request->post[$v])) ? $this->request->post[$v] : $this->config->get($v);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/oplata', $data));
    }

    public function order()
    {
        $orderID = $this->request->get['order_id'];
        $userToken = $this->request->get['user_token'];

        if ($this->config->get('payment_oplata_status')){
            $this->load->model('extension/payment/oplata');

            if ($fondyOrder = $this->model_extension_payment_oplata->getLastFondyOrder($orderID)){
                $this->load->language('extension/payment/oplata');

                $data['order'] = $fondyOrder;
                $data['order']['formatted_total'] = $fondyOrder['total']/100;
                $data['capture_url'] = html_entity_decode($this->url->link(
                    'extension/payment/oplata/capture',
                    ['order_id' => $orderID, 'user_token' => $userToken]
                ));
                $data['reverse_url'] = html_entity_decode($this->url->link(
                    'extension/payment/oplata/reverse',
                    ['order_id' => $orderID, 'user_token' => $userToken]
                ));
                $data['upd_payment_detail_table_url'] = html_entity_decode($this->url->link(
                    'extension/payment/oplata/order',
                    ['order_id' => $orderID, 'user_token' => $userToken, 'upd' => 1]
                ));

                $view = $this->load->view('extension/payment/oplata_order', $data);

                if (isset($this->request->get['upd'])){
                    echo $view;
                    exit;
                }

                return $view;
            }
        }

        return false;
    }

    public function capture()
    {
        $this->load->model('extension/payment/oplata');
        $this->load->language('extension/payment/oplata');
        $orderID = $this->request->get['order_id'];
        $fondyOrder = $this->model_extension_payment_oplata->getLastFondyOrder($orderID);
        $jsonResponse = [];

        if (!empty($fondyOrder) && $fondyOrder['preauth'] == 'Y' && $fondyOrder['last_tran_type'] != 'capture') {
            $this->load->model('sale/order');
            $order = $this->model_sale_order->getOrder($orderID);
            $captureAmount = round($this->request->post['amount'] * $order['currency_value'] * 100);

            try {
                $this->model_extension_payment_oplata->capture([
                    'order_id' => $fondyOrder['id'],
                    'merchant_id' => $this->config->get('payment_oplata_merchant'),
                    'amount' => $captureAmount,
                    'currency' => $fondyOrder['currency_code']
                ]);

                if ($captureAmount < $fondyOrder['total'])
                    $fondyOrder['total'] = $captureAmount;

                $fondyOrder['last_tran_type'] = 'capture';
                $this->model_extension_payment_oplata->updateFondyOrder($fondyOrder);
                $jsonResponse['success_message'] = $this->language->get('text_success_action');;
            } catch (Exception $e){
                http_response_code(400);
                $jsonResponse['error_message'] = $e->getMessage();
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($jsonResponse));
    }

    public function reverse()
    {
        $this->load->model('extension/payment/oplata');
        $this->load->language('extension/payment/oplata');
        $orderID = $this->request->get['order_id'];
        $fondyOrder = $this->model_extension_payment_oplata->getLastFondyOrder($orderID);
        $jsonResponse = [];

        if (!empty($fondyOrder)){
            $this->load->model('sale/order');
            $order = $this->model_sale_order->getOrder($orderID);
            $refundAmount = round($this->request->post['amount'] * $order['currency_value'] * 100);

            if ($fondyOrder['preauth'] === 'Y' && $fondyOrder['last_tran_type'] == 'purchase')
                $refundAmount = $fondyOrder['total'];

            try {
                $this->model_extension_payment_oplata->reverse([
                    'order_id' => $fondyOrder['id'],
                    'merchant_id' => $this->config->get('payment_oplata_merchant'),
                    'amount' => $refundAmount,
                    'currency' => $fondyOrder['currency_code'],
                ]);

                $fondyOrder['total'] -= $refundAmount;
                $fondyOrder['last_tran_type'] = 'reverse';
                $this->model_extension_payment_oplata->updateFondyOrder($fondyOrder);
                $jsonResponse['success_message'] = $this->language->get('text_success_action');;
            } catch (Exception $e){
                http_response_code(400);
                $jsonResponse['error_message'] = $e->getMessage();
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($jsonResponse));
    }

    /**
     * @return bool
     */
    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/oplata')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post['payment_oplata_merchant']) {
            $this->error['merchant'] = $this->language->get('error_merchant');
        }
        if (!$this->request->post['payment_oplata_secretkey']) {
            $this->error['secretkey'] = $this->language->get('error_secretkey');
        }
        return !$this->error;
    }

    private function getProcessPaymentTypes()
    {
        return [
            'redirect' => $this->language->get('entry_redirect'),
            'built_in_checkout' => $this->language->get('entry_built_in_checkout'),
        ];
    }

    private function getStylePresets()
    {
        return [
            'black' => 'black',
            'vibrant_gold' => 'vibrant gold',
            'vibrant_silver' => 'vibrant silver',
            'euphoric_pink' => 'euphoric pink',
            'solid_black' => 'solid black',
            'silver' => 'silver',
            'black_and_white' => 'black and white',
            'heated_steel' => 'heated steel',
            'nude_pink' => 'nude pink',
            'tropical_gold' => 'tropical gold',
            'navy_shimmer' => 'navy shimmer',
        ];
    }

    /**
     * @return array[]
     */
    private function getBreadcrumbs()
    {
        return [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
                'separator' => false
            ],
            [
                'text' => $this->language->get('text_payment'),
                'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], true),
                'separator' => ' :: '
            ],
            [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/payment/oplata', 'user_token=' . $this->session->data['user_token'], true),
                'separator' => ' :: '
            ],
        ];
    }
}

?>
