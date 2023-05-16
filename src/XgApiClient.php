<?php

namespace Xgenious\XgApiClient;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\File;

class XgApiClient
{

    public  function extensionCheck($name)
    {
        if (!extension_loaded($name)) {
                $response = false;
            } else {
            $response = true;
        }
        return $response;
    }
    public function downloadAndRunUpdateProcess($productUid, $isTenant,$getItemLicenseKey,$version){


        $siteUrl = url('/');
        $has = hash_hmac('sha224',$getItemLicenseKey.$siteUrl,'xgenious');
        $downloadResponse = Http::connectTimeout(0)->timeout(1200)->post($this->getBaseApiUrl()."download-latest-version/{$getItemLicenseKey}/{$productUid}?site={$siteUrl}&has={$has}");
        $downloadableFile = $downloadResponse->getBody()->getContents();
        $filename = 'update.zip';
        $returnVal = [];
        if ($downloadResponse->status() === 200) {
            Storage::put('/update-file/'.$filename, $downloadableFile);
            Artisan::call('down');

            $returnVal = ['msg' => __('System update failed'),"type" => "danger"];
            if ($this->systemUpgradeWithLatestVersion()) {
                if (!$this->systemDbUpgrade($isTenant,$version)){
                    $returnVal ['msg'] = __('Database Upgrade and Migration failed');
                    $returnVal ['type'] = "success";
                    return $returnVal;
                }
            }
            Artisan::call('up');
            return $returnVal;
        }
        return false;
    }

    private  function  systemUpgradeWithLatestVersion(){
        $getLatestUpdateFile = storage_path('app/update-file/update.zip');

        $zipArchive = new \ZipArchive();
        $zipArchive->open($getLatestUpdateFile);
        $filenames= [];
        if(!empty($zipArchive)){
            for($i = 0; $i < $zipArchive->numFiles; $i++ ){
                $stat = $zipArchive->statIndex( $i );
                // file's name
                $filenames[] = $stat['name'];
            }
        }

        $updatedFileLocation = "update-file/update";

        $zipExtracted = $zipArchive->extractTo(storage_path('app/update-file/'));

        if ($zipExtracted) {
            $zipArchive->close();
            //delete zip after extracted
            @unlink(storage_path('app/update-file/update.zip'));

            $updateFiles = Storage::allFiles($updatedFileLocation);

            foreach($updateFiles as $updateFile) {
                // todo:: first we need to get file name
                // todo:: remove update-file/update from file path
                // todo:: remove filename from $updateFile

                $file = new File(storage_path("app/" . $updateFile));

                $getDirectory = str_replace($updatedFileLocation . '/',"", $updateFile);
                $getDirectory = ($getDirectory == 'change-logs.json') ? $getDirectory : chop($getDirectory, $file->getFilename());

                // not to move if found these directories
                $skipDir = ['.fleet/', '.idea/', '.vscode/'];
                $skipFiles = ['.DS_Store'];
                $cacheDirExist = in_array($getDirectory, $skipDir) && (str_contains($getDirectory, '.fleet/') || str_contains($getDirectory, '.idea') || str_contains($getDirectory, '.vscode'));

                if (str_contains($getDirectory, '.git/')) {
                    //dd('Git'.$getDirectory);
                    //return response()->json('Alert...your update version folder have .git, please contact your author!!');
                }
                if (str_contains($getDirectory, 'custom/')) {
                    $changesLogs = json_decode(Storage::get($updatedFileLocation. '/change-logs.json'))->custom;
                    foreach($changesLogs as $changesLog) {
                        // check  change-logs file for which file will update from custom folder;
                        if ($changesLog->filename == $file->getFilename()) {
                            if (!in_array($file->getFilename(),$skipFiles)){
                                //todo add filter into it...
                                $file->move(storage_path('../'));
                            }
                        }
                    }
                }
                if (str_contains($getDirectory, 'assets/') && (!str_contains($getDirectory, 'Modules/') && !str_contains($getDirectory, 'plugins'))) {
                    //dd($getDirectory, $file->move(storage_path('../../' . $getDirectory)));
                    if (!in_array($file->getFilename(),$skipFiles)){
                        $file->move(storage_path('../../' . $getDirectory));
                    }
                }


                // if (str_contains($getDirectory, 'Modules/')) {
                //     //check change-logs file for which module will update;
                //     $modules = json_decode(Storage::get($updatedFileLocation. '/change-logs.json'))->modules;
                //     if (!in_array($file->getFilename(),$skipFiles)){
                //         foreach ($modules as $module) {
                //             if (str_contains($getDirectory, 'Modules/'.$module)) {
                //                 $file->move(storage_path('../' . $getDirectory));
                //             }
                //         }
                //     }
                // }
                //did not found any use case
                // elseif (($getDirectory !== 'change-logs.json') && !$cacheDirExist && $getDirectory !== 'custom/') {
                //     if (!in_array($file->getFilename(),$skipFiles)){
                //         $file->move(storage_path('../' . $getDirectory));
                //     }
                // }

            }
        }

        Storage::deleteDirectory($updatedFileLocation);

        return true;
    }
    private function systemDbUpgrade($isTenant,$version){

        if ($isTenant == 0) {
            try {
                setEnvValue(['APP_ENV' => 'local']);
                try {
                    Artisan::call('migrate', ['--force' => true]);
                }catch (\Exception $e){

                }
                try {
                    Artisan::call('db:seed', ['--force' => true]);
                }catch (\Exception $e){

                }
                Artisan::call('cache:clear');
                setEnvValue(['APP_ENV' => 'production']);

                try {
                    update_static_option("site_script_version",trim($version,"vV-"));
                }catch (\Exception $e){}
                
                return true;
            } catch (\Exception $e) {
                return false;
            }

        } elseif ($isTenant == 1) {
            try {
                setEnvValue(['APP_ENV' => 'local']);
                Artisan::call('cache:clear');
                try {
                    Artisan::call('migrate', ['--force' => true]);
                }catch (\Exception $e){

                }
                try {
                    Artisan::call('db:seed', ['--force' => true]);
                }catch (\Exception $e){

                }
                Artisan::call('cache:clear');
                //tenant database migrate
                try {
                    Artisan::call('tenants:migrate', ['--force' => true]);
                }catch (\Exception $e){

                }
                try {
                    update_static_option_central('get_script_version',trim($version,"vV-"));
                }catch (\Exception $e){}

                setEnvValue(['APP_ENV' => 'production']);

                return true;
            } catch (\Exception $e) {
                return false;
            }
        }

    }

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
                "product_uid" => $result->product_uid ?? "",
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
