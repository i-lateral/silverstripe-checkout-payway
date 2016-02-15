<?php

class PayWayHandler extends PaymentHandler {
    
    /**
     * URL of the PayWay gateway
     * 
     * @string
     * @config
     */
    private static $gateway_url = 'https://payway.stgeorge.com.au/';
    
    /**
     * Port to use for the PayWay gateway
     * 
     * @string
     * @config
     */
    private static $gateway_port = 443;
    
    /**
     * URL of the PayWay gateway
     * 
     * @string
     * @config
     */
    private static $certs_file = 'checkout-payway/certs/cacerts.crt';
    

    public function index($request) {
        
        $this->extend("onBeforeIndex");
        
        $site = SiteConfig::current_site_config();
        $order = $this->getOrderData();
        $cart = ShoppingCart::get();
        $key = $this->payment_gateway->ConfigKey;
        
        $merchant_id = (Director::isDev()) ? "TEST" : $this->payment_gateway->MerchantID;
        
        $callback_url = Controller::join_links(
            Director::absoluteBaseURL(),
            Payment_Controller::config()->url_segment,
            "callback",
            $this->payment_gateway->ID
        );

        $return_url = Controller::join_links(
            Director::absoluteBaseURL(),
            Payment_Controller::config()->url_segment,
            'complete',
            $order->OrderNumber
        );

        $back_url = Controller::join_links(
            Director::absoluteBaseURL(),
            Checkout_Controller::config()->url_segment,
            "finish"
        );
        
        $payment_details = array(
            'username' => $this->payment_gateway->Username,
            'password' => $this->payment_gateway->Password,
            'biller_code' => $this->payment_gateway->BillerCode,
            'merchant_id' => $merchant_id,
            'receipt_address' => $order->Email,
            'payment_amount' => number_format($cart->TotalCost,2),
            'payment_reference' => $order->OrderNumber,
            'payment_reference_minimum_length' => 10,
            'payment_reference_maximum_length' => 20,
            'payment_reference_text' => _t("PayWay.PaymentReferenceText", "Order Number"),
            'return_link_url' => $return_url,
            'reply_link_url' => $callback_url,
            'reply_link_email' => $this->payment_gateway->PaymentReplyEmail,
            'reply_link_post_type' => 'extended'
        );

        foreach($cart->getItems() as $item) {
            $payment_details[$item->Title] = $item->Quantity . ',' . number_format($item->Price,2);
        }
        
        if(!Checkout::config()->simple_checkout) {
            $payment_details[$order->PostageType] = number_format($cart->PostageCost, 2);
        }
        
        // Add tax (if needed) else just total
        if($cart->TaxCost) {
            $payment_details[_t("PayWay.Tax", 'Tax')] = number_format($cart->TaxCost, 2);
        }
        
        // If we cannot get payway's token, generate a friendly error
        try {
            $token = $this->get_token($payment_details);
        } catch (Exception $e) {
            error_log("Exception caught: " . $e->getMessage());
            
            $content = "<p>";
            $content = _t("PayWay.UnableToPayContent", "Please return to the previous page and try again");
            $content = "</p>";
            $content = '<p><a href="' . $back_url . '" class="btn">Back</a></p>';
            
            $this->customise(array(
                "Title" => _t("PayWay.UnableToPay", "Unable to take payment"),
                "MetaTitle" => _t("PayWay.UnableToPay", "Unable to take payment"),
                "Content" => $content
            ));
            
            return $this->renderWith(array("Page"));
        }
        
        $hand_off_url = Controller::join_links(
            $this->config()->gateway_url,
            "MakePayment"
        );
        
        $hand_off_url .= "?biller_code=" . $this->payment_gateway->BillerCode;
        $hand_off_url .= "&token=" . urlencode($token);
        
        $this->extend('onAfterIndex');
        
        return $this->redirect($hand_off_url);
    }

    /**
     * Process the callback data from the payment provider
     */
    public function callback($request) {
        
        $this->extend("onBeforeCallback");
        
        $data = $this->request->postVars();
        $status = "error";
        $order_id = 0;
        $payment_id = 0;
        $gateway_data = array();
        
        if(
            isset($data) &&
            array_key_exists('cd_supplier_business', $data) &&
            array_key_exists('cd_summary', $data) &&
            array_key_exists('cd_response', $data) &&
            array_key_exists('payment_reference', $data) &&
            array_key_exists('tx_response', $data) &&
            array_key_exists('no_receipt', $data)
        ) {
            
            // Are our credentials correct
            if($data['cd_supplier_business'] != $this->payment_gateway->Username)
                return $this->httpError(500);
            
            // Check Payment status
            switch($data['cd_summary']) {
                case '0':
                    $status = "paid";
                    break;
                case '1':
                    $status = "failed";
                    break;
                case '3':
                    $status = "failed";
                    break;
            }
            
            $order_id = $data['payment_reference'];
            
            $payment_id = $data['no_receipt'];
            
            $gateway_data = array(
                "ResponseCode" => $data["cd_response"],
                "ResponseSummary" => $data["cd_summary"],
                "ResponseDesc" => $data["tx_response"],
                "RecieptNo" => $data["no_receipt"]
            );
            
        } else
            return $this->httpError(500);
        
        $payment_data = ArrayData::array_to_object(array(
            "OrderID" => $order_id,
            "PaymentProvider" => "PayWay",
            "PaymentID" => $payment_id,
            "Status" => $status,
            "GatewayData" => $data
        ));
        
        $this->setPaymentData($payment_data);
        
        $this->extend('onAfterCallback');
        
        return;
    }
    
    /**
     * Get a payment token from Payway to secure our payment
     * 
     * @return String
     */
    private function get_token($parameters) {
        $certs_file = Controller::join_links(
            BASE_PATH,
            $this->config()->certs_file
        );
        
        $payway_url = $this->config()->gateway_url;
        $port = $this->config()->gateway_port;

        $ch = curl_init(Controller::join_links($payway_url, "RequestToken"));

        if ( $port != 443 ) curl_setopt($ch, CURLOPT_PORT, $port );

        curl_setopt($ch, CURLOPT_FAILONERROR, true );
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true );
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
  
        // Set timeout options
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30 );
        curl_setopt($ch, CURLOPT_TIMEOUT, 30 );
  
        // Set references to certificate files
        curl_setopt($ch, CURLOPT_CAINFO, $certs_file );
  
        // Check the existence of a common name in the SSL peer's certificate
        // and also verify that it matches the hostname provided
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2 );   
  
        // Verify the certificate of the SSL peer
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true );
        
        // Build the parameters string to pass to PayWay
        $param_string = '';
        $init = true;
        
        foreach($parameters as $key => $value) {
            if($init)
                $init = false; 
            else
                $param_string .= '&';
                
            $param_string .= urlencode($key) . '=' . urlencode($value);
        }
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $param_string);

        // Make the request
        $response_text = curl_exec($ch);

        // Check the response for errors
        $error_no = curl_errno($ch);
        
        if($error_no != 0 ) {
            $errno = curl_errno($ch);
            $errstr = curl_error($ch);
            throw new Exception("cURL error: [$errno] $errstr");
        }

        curl_close($ch);

        // Split the response into parameters
        $response_params = array();
        
        foreach(explode("&", $response_text) as $response_item ) {
            list($key, $value) = explode("=", $response_item, 2);
            $response_params[$key] = $value;
        }

        if(array_key_exists('error', $response_params))
            throw new Exception("Error getting token: " . $response_params['error']);
        else
            return $response_params['token'];
    }

}
