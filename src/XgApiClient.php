<?php

namespace Xgenious\XgApiClient;

use Illuminate\Support\Facades\Http;

class XgApiClient
{
    public function VerifyLicense($purchaseCode,$email,$envatoUsername){

        $req = Http::post($this->getBaseApiUrl()."verify-license",[
            "purchase_code" => $purchaseCode,
            "email" => $email,
            "client" => $envatoUsername,
            "site" => url("/")
        ]);
        //todo verify the data
        $messsage = __("purhcase code verify failed, make sure you purhcase code is valid");
        if ($req->status() === 200){
            $result = $req->object();
            if (property_exists($result,"success") && $result->success){
                return [
                    "success" => $result->success,
                    "message" => $result->message,
                    "data" => $result->data
                ];
            }
        }

        return [
          "success" => false,
          "message" => $messsage,
          "data" => null
        ];

    }

    private function getBaseApiUrl()
    {
        return "https://license.xgenious.com/api/";
    }

}
