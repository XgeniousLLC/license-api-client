<?php

namespace Xgenious\XgApiClient;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class XgApiClient
{

    public function activeLicense($licenseCode,$envatoUsername){

        $siteUrl = url('/');
        $agent = request()->header('User-Agent');
        // todo:: first we need build api url
        // todo:: url should be look like this activate-license/{key}/{client}
        $has = hash_hmac('sha224',$licenseCode.$envatoUsername.$siteUrl,'xgenious');

        $req = Http::post($this->getBaseApiUrl()."activate-license/{$licenseCode}/".$envatoUsername,[
            "has" => $has,
            "agent" => $agent,
            "site" => url("/")
        ]);

        //todo verify the data
        $messsage = __("license activate failed, please try after some time, if you sill face issue contact support");
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
            "license_key" => $licenseCode
        ];

    }

    public function checkForUpdate($licenseKey,$getItemVersion){
        $has = hash_hmac('sha224',$licenseKey.$getItemVersion,'xgenious');
        $checkUpdateVersion = Http::post($this->getBaseApiUrl()."check-version-update/{$licenseKey}/{$getItemVersion}?has={$has}");
        $result = $checkUpdateVersion->object();
        $messsage = __("something went wrong please try after sometime, if you still face the issue, please contact support");
        if (property_exists($result,'success') && $result->success){
            $data = [
                "message" => $result->message ?? __("something went wrong, please try after sometime, if you still faca the issue contact support"),
                "client_version" => $result->client_v ?? "",
                "latest_version" => $result->latest_v ?? "",
                "product" => $result->product ?? "",
                "changelog" => $result->changelog ?? "",
                "php_version" => $result->php_version ?? "",
                "mysql_version" => $result->mysql_version ?? "",
                "extension" => $result->extension ?? "",
                "is_tenant" => $result->is_tenant ?? 0,
                "release_date" => property_exists($result,"release_date") ? Carbon::parse($result->release_date)->diffForHumans() : "",
            ];


            return [
                "success" => $result->success,
                "message" => $result->message,
                "data" => $data
            ];
        }

        return [
            "success" => false,
            "message" => $messsage
        ];
    }
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
