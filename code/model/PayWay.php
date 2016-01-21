<?php

class PayWay extends PaymentMethod
{
    
    public static $handler = "PayWayHandler";

    public $Title = 'PayWay';
    
    public $Icon = '';

    private static $db = array(
        "EncryptionKey" => "Varchar(255)",
        "BillerCode" => "Varchar(99)",
        "MerchantID" => "Varchar(99)",
        "Username" => "Varchar(99)",
        "Password" => "Varchar(99)",
        "PaymentReplyEmail" => "Varchar(99)"
    );

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        if ($this->ID) {
            $fields->addFieldsToTab(
                "Root.Main",
                array(
                    TextField::create('EncryptionKey'),
                    TextField::create('BillerCode'),
                    TextField::create('MerchantID'),
                    TextField::create('Username'),
                    TextField::create('Password'),
                    TextField::create('PaymentReplyEmail', "Email used for failed payment replies")
                )
            );
        }

        return $fields;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        $this->CallBackSlug = (!$this->CallBackSlug) ? 'PayWay' : $this->CallBackSlug;

        $this->Summary = (!$this->Summary) ? "Pay with credit/debit card securely via PayWay" : $this->Summary;
    }
}
