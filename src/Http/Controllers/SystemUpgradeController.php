<?php

namespace Xgenious\XgApiClient\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Artisan;


class SystemUpgradeController extends Controller
{
    public function checkSystemUpdate()
    {
        $licenseKay = getXgFtpInfoFieldValue('item_license_key');
        $getItemVersion = getXgFtpInfoFieldValue('item_version');

        $has = hash_hmac('sha224',$licenseKay.$getItemVersion,'xgenious');

        $checkUpdateVersion = Http::post(Config::get('xgapiclient.base_api_url')."/check-version-update/{$licenseKay}/{$getItemVersion}?has={$has}");

        $result = $checkUpdateVersion->json();
        //dd($result);

        $clientVersion = $result['client_v'] ?? null;
        $latestVersion = $result['latest_v'] ?? null;
        $productName = $result['product'] ?? null;
        $productUid = $result['product_uid'] ?? null;
        $releaseDate =  $result['release_date'] ?? null;
        $changelog =  $result['changelog'] ?? null;
        $phpVersionReq =  $result['php_version'] ?? null;
        $mysqlVersionReq =  $result['mysql_version'] ?? null;
        $extensions =  $result['extension'] ?? null;
        $isTenant =  $result['is_tenant'] ?? null;
        $daysDiff = Carbon::parse($releaseDate)->diffInDays();
        $msg = $result['message'] ?? null;

        return view('XgApiClient::check-update', compact(
                'clientVersion',
                'latestVersion',
                'productName',
                'productUid',
                'changelog',
                'phpVersionReq',
                'mysqlVersionReq',
                'extensions',
                'isTenant',
                'daysDiff',
                'msg'
            )
        );
    }

    public function updateDownloadLatestVersion($productUid, $isTenant)
    {
        $getItemLicenseKey = getXgFtpInfoFieldValue('item_license_key');
        $siteUrl = url('/');

        $has = hash_hmac('sha224',$getItemLicenseKey.$siteUrl,'xgenious');

        $downloadResponse = Http::post(Config::get('xgapiclient.base_api_url')."/download-latest-version/{$getItemLicenseKey}/{$productUid}?site={$siteUrl}&has={$has}");

        $downloadableFile = $downloadResponse->getBody()->getContents();
        $filename = 'update.zip';

        if ($downloadResponse->ok()) {
            Storage::put('/update-file/'.$filename, $downloadableFile);
            if ($this->systemUpgradeWithLatestVersion()) {
                $this->systemDbUpgrade($isTenant);
            }
            return redirect()->back()->with(['msg' => 'System Successfully Upgraded!!! :)' , 'type' => 'success']);
        }
    }

    public function systemUpgradeWithLatestVersion()
    {
        $getLatestUpdateFile = storage_path('app/update-file/update.zip');

        $zipArchive = new \ZipArchive();

        $updatedFileLocation = "update-file/update";

        $zipExtracted = $zipArchive->extractTo(storage_path('app/update-file/'));

        if ($zipExtracted) {
            $zipArchive->close();
            //delete zip after extracted
            unlink(storage_path('app/update-file/update.zip'));

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
                    return response()->json('Alert...your update version folder have .git, please contact your author!!');
                }
                if (str_contains($getDirectory, 'custom/')) {
                    $changesLogs = json_decode(Storage::get($updatedFileLocation. '/change-logs.json'))->custom;
                    foreach($changesLogs as $changesLog) {
                        // check  change-logs file for which file will update from custom folder;
                        if ($changesLog->filename == $file->getFilename()) {
                            if (!in_array($file->getFilename(),$skipFiles)){
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
                if (str_contains($getDirectory, 'Modules/')) {
                    //check change-logs file for which module will update;
                    $modules = json_decode(Storage::get($updatedFileLocation. '/change-logs.json'))->modules;
                    if (!in_array($file->getFilename(),$skipFiles)){
                        foreach ($modules as $module) {
                            if (str_contains($getDirectory, 'Modules/'.$module)) {
                                $file->move(storage_path('../' . $getDirectory));
                            }
                        }
                    }
                }
                elseif (($getDirectory !== 'change-logs.json') && !$cacheDirExist && $getDirectory !== 'custom/') {
                    if (!in_array($file->getFilename(),$skipFiles)){
                        $file->move(storage_path('../' . $getDirectory));
                    }
                }

            }
        }

        Storage::deleteDirectory($updatedFileLocation);

        return true;
    }

    public function systemDbUpgrade($isTenant)
    {
        if ($isTenant === 0) {
            try {
                XGsetEnvValue(['APP_ENV' => 'local']);
                Artisan::call('migrate', ['--force' => true]);
                Artisan::call('db:seed', ['--force' => true]);
                Artisan::call('cache:clear');
                XGsetEnvValue(['APP_ENV' => 'production']);

                return true;
            } catch (\Exception $e) {
                return false;
            }

        } elseif ($isTenant === 1) {
            try {
                XGsetEnvValue(['APP_ENV' => 'local']);
                Artisan::call('cache:clear');
                Artisan::call('migrate', ['--force' => true]);
                Artisan::call('db:seed', ['--force' => true]);
                Artisan::call('cache:clear');
                //tenant database migrate
                Artisan::call('tenants:migrate', ['--force' => true]);
                XGsetEnvValue(['APP_ENV' => 'production']);

                return true;
            } catch (\Exception $e) {
                return false;
            }
        }

    }

}
