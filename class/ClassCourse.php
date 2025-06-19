<?php
Class ClassCourse {

    public $nias_user_name;

    public $nias_user_certificate;

    public $nias_user_id;

    public $nias_product_id;

    public $nias_order_id;


    public function nias_setup_data($nias_user_name , $nias_user_id , $nias_user_certificate , $nias_product_id , $nias_order_id){

        $this->UserName                     = $nias_user_name;
        $this->UserId                       = $nias_user_id;
        $this->UserCertificate              = $nias_user_certificate;
        $this->ProductId                    = $nias_product_id;
        $this->order_id                     = $nias_order_id;
        
        
        
        }




}

