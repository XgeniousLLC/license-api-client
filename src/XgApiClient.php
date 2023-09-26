<?php

namespace Xgenious\XgApiClient;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\File;
use Illuminate\Support\Facades\File as FileHelper;
use GuzzleHttp\Client;

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

        $ip = request()->ip();
        $siteUrl = url('/');

        $has = hash_hmac('sha224',$getItemLicenseKey.$siteUrl,'xgenious');
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        $returnVal = [];

        $url = $this->getBaseApiUrl()."download-latest-version/{$getItemLicenseKey}/{$productUid}?site={$siteUrl}&has={$has}";
        $postFields = [
            "ip" => $ip,
            "api_token" => Config::get("xgapiclient.has_token") 
        ];
        $download_status = $this->chunkedDownload($url,$postFields);

        if ($download_status === 200) {
            Artisan::call('down');

            $returnVal = ['msg' => __('your website is updated to latest version successfully'),"type" => "success"];
            if ($this->systemUpgradeWithLatestVersion()) {
                Artisan::call('up');
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

    public function generalDownload($url,$fields){
        $downloadResponse = Http::connectTimeout(0)
            ->timeout(3000)
            ->withHeaders([
            'accept-encoding' => 'gzip, deflate',
            ])
            ->post($url,$fields);
        $downloadableFile = $downloadResponse->getBody()->getContents();
        $filename = 'update.zip';
        if ($downloadResponse->status() === 200) {
            Storage::put('/update-file/'.$filename, $downloadableFile);
        }
        return $downloadResponse->status();
    }

    public function chunkedDownload($url,$fields){

        $destination = storage_path("app/update-file/update.zip");
        
        Storage::put('/update-file/update.zip', ""); 
        $client = new Client();

        // Open file handle for writing
        $fp = fopen($destination, 'w+');
        
        // Make the POST request
        $response = $client->post($url, [
            'form_params' => $fields,
            'stream' => true,
        ]);
        
        // Get the response body as a stream
        $body = $response->getBody();
        
        // Define the chunk size (e.g., 1 MB)
        $chunkSize = 1024 * 1024;
        
        // Read the stream in chunks and write to the file
        while (!$body->eof()) {
            $chunk = $body->read($chunkSize);
            fwrite($fp, $chunk);
        }
        
        // Close the file handle
        fclose($fp);
        
        return $response->getStatusCode();//status code s failed or success 
    }

    public function getFiles($directory) {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied" errors
        );
    
        foreach ($iterator as $path) {
            if ($path->isFile()) {
                yield $path->getPathname();
            }
        }
    }
    
    public function isDotFile($filename) {
        return substr($filename, 0, 1) === '.';
    }
    
    public function systemUpgradeWithLatestVersion() {
        $getLatestUpdateFile = storage_path('app/update-file/update.zip');
    
        $zipArchive = new \ZipArchive();
        $zipArchive->open($getLatestUpdateFile);
        $filenames = [];
        for ($i = 0; $i < $zipArchive->numFiles; $i++) {
            $stat = $zipArchive->statIndex($i);
            $filenames[] = $stat['name'];
        }
    
        $updatedFileLocation = storage_path('app/update-file');
        $zipExtracted = $zipArchive->extractTo($updatedFileLocation);
        
        if ($zipExtracted) {
            $zipArchive->close();
            
            
            $fileGetLocation = $updatedFileLocation."/update";
            
            // Use generator to fetch files lazily
            foreach ($this->getFiles($fileGetLocation) as $updateFile) {
                // ... the rest of your processing logic for each file
                
                
                    // todo:: remove update-file/update from file path
                    // todo:: remove filename from $updateFile
                    
                    if(!file_exists( $updateFile) ){
                        continue;
                        
                    }
                    
                    $file = new File( $updateFile); 
    
                    $getDirectory = basename(dirname($file->getRealPath()));
                    $getFileName = $file->getFilename();
                    $getFileRepalcePath = str_replace($fileGetLocation . '/',"", $updateFile);
                    
                    
                    if($this->isDotFile($getFileName)){
                        //ignore if it is a .dot file
                         continue;
                    }
                  
    
                    // not to repalce if found these directories
                    $skipDir = ['.fleet', '.idea', '.vscode/', "lang", '.git', 'custom-fonts'];
                    $skipFiles = ['.DS_Store', "dynamic-style.css", "dynamic-script.js",'phpunit',".htaccess",".env"];
                    $rootPathSkipFiles = ['ajax.php','index.php'];
                    $diffPathFolder = ['custom', 'assets', '__rootFiles','phpunit'];
    
    
                    // Ignore directories
                    if (in_array($getDirectory, $skipDir)) {
                        continue;
                    }
    
                    // ignore files
                    if ($file->isFile() && in_array($getFileName, $skipFiles)) {
                        continue;
                    }

                     //ignore .git folder
                     if (str_contains($file->getRealPath(), '.git/')) {
                        continue;
                    }
                    
                    
                    if (ob_get_level()) {
                        ob_end_clean();
                    }

                    
                    
                    //ensuring that the directory is exits if not exits it will create that folder for us
                    if (str_contains($file->getRealPath(), 'custom/')) {
                    
                        $changesLogs = json_decode(FileHelper::get(storage_path('app/update-file/update/change-logs.json')))->custom;
                        foreach ($changesLogs as $changesLog) {
                            // check  change-logs file for which file will update from custom folder;
                            if ($changesLog->filename == $file->getFilename()) {
                                $fromStorage_path = storage_path('../../' . $changesLog->path);
                                FileHelper::ensureDirectoryExists($fromStorage_path);
                                
                                FileHelper::put($fromStorage_path . '/' . $file->getFilename(), $file->getContent());
                            
                            }
                        }
    
                    }
     
    
                    if (str_contains($file->getRealPath(), 'public/') && !str_contains($file->getRealPath(), 'views/') && (!str_contains($file->getRealPath(), 'Modules') && !str_contains($file->getRealPath(), 'plugins'))) {
                        //todo check if the folder name is
                        if ($file->getFilename() !== 'app.js'){
                            FileHelper::ensureDirectoryExists($this->getFilePath($file,$getFileRepalcePath));
                            if (!$file->isDir()){
                                FileHelper::put($this->getFilePath($file,$getFileRepalcePath) . '/' . $getFileName, $file->getContent());
                            }
                        }
                    }
                    
                    if (str_contains($file->getRealPath(), 'Modules/')) {
                        //todo check if the folder name is
                        FileHelper::ensureDirectoryExists($this->getFilePath($file,$getFileRepalcePath));
                        if (!$file->isDir()){
                            FileHelper::put($this->getFilePath($file,$getFileRepalcePath) . '/' . $getFileName, $file->getContent());
                        }
                    }
                    
                    if (str_contains($file->getRealPath(), 'plugins/')) {
                        //todo check if the folder name is
                        FileHelper::ensureDirectoryExists($this->getFilePath($file,$getFileRepalcePath));
                        if (!$file->isDir()){
                            FileHelper::put($this->getFilePath($file,$getFileRepalcePath) . '/' . $getFileName, $file->getContent());
                        }
                    }
    
                    if (str_contains($file->getRealPath(), 'assets/') && (!str_contains($file->getRealPath(), 'views/') && !str_contains($file->getRealPath(), 'Modules/') && !str_contains($file->getRealPath(), 'plugins/'))) {
    
                        //todo check if the folder name is
                        if ($getDirectory === 'page-layout') {
                            //if file not exits in page-layout folder only them put the content
                            FileHelper::ensureDirectoryExists($this->getFilePath($file,$getFileRepalcePath));
                            if (!FileHelper::exists($this->getFilePath($file,$getFileRepalcePath) . '/' . $getFileName)) {
                                if (!$file->isDir()){
                                    FileHelper::put($this->getFilePath($file,$getFileRepalcePath) . '/' . $getFileName, $file->getContent());
                                }
                            }
                        } else {
                            //replace content of all assets folder file
                            FileHelper::ensureDirectoryExists($this->getFilePath($file,$getFileRepalcePath));
                            if (!$file->isDir()){ 
                                FileHelper::put($this->getFilePath($file,$getFileRepalcePath) . '/' . $getFileName, $file->getContent());
                            }
                        } 
    
                    }
    
                    if (str_contains($file->getRealPath(), '__rootFiles/')) {
                        FileHelper::ensureDirectoryExists($this->getFilePath($file,$getFileRepalcePath));
                        if (!$file->isDir()){
                            FileHelper::put($this->getFilePath($file,$getFileRepalcePath) . '/' . $getFileName, $file->getContent());
                        }
                    }
    
                    if (!in_array($getDirectory, $diffPathFolder) && !str_contains($file->getRealPath(), 'Modules/') && !str_contains($file->getRealPath(), 'plugins') && !str_contains($file->getRealPath(), 'assets/')) {
                        //replace all files , those are not custom, assets, __rootFiles , also make sure this is not Modules, plugins Folder

                         // ignore root skip files
                        if ($file->isFile() && in_array($getFileName, $skipFiles)) {
                            continue;
                        }

                        FileHelper::ensureDirectoryExists($this->getFilePath($file,$getFileRepalcePath));
                        if (!$file->isDir()){
                            FileHelper::put($this->getFilePath($file,$getFileRepalcePath) . '/' . $getFileName, $file->getContent());
                        }
                    }
    
                // Since this is inside the loop where each file is fetched lazily, 
                // it won't overload memory with a huge list of file paths. 
            }
        }
        
        Storage::deleteDirectory($updatedFileLocation);
        return true;
    }


    private function replaceFile($file,$getDirectory,$path = '../../'){
        try{
            $file->move(storage_path( $path. $getDirectory));
        }catch(\Exception $e){
            if(str_contains($e->getMessage(),'No such file or directory')){
                if(!file_exists(storage_path($path . $getDirectory)) && is_dir($getDirectory)){
                    @mkdir($structure, 0777, true);
                    $file->move(storage_path('../../' . $getDirectory));
                }
            }
        }
    }

    public function systemDbUpgrade($isTenant,$version){

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
                try{
                    Artisan::call('cache:clear');
                }catch(\Exception $e){}

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
                try {
                    Artisan::call('migrate', ['--force' => true]);
                }catch (\Exception $e){

                }
                try {
                    Artisan::call('db:seed', ['--force' => true]);
                }catch (\Exception $e){

                }
               
                try{
                    Artisan::call('cache:clear');
                }catch(\Exception $e){}

                //todo run a query to get all the tenant then run migrate one by one...
                 Tenant::latest()->chunk(50,function ($tenans){
                    foreach ($tenans as $tenant){
                        try {
                            Config::set("database.connections.mysql.engine","InnoDB");
                            Artisan::call('tenants:migrate', ['--force' => true,'--tenants'=>$tenant->id]);
                        }catch (\Exception $e){
                            //if issue is related to the mysql database engine,
                        }
                    }
                });


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

        $php_version = PHP_VERSION;
        $mysql_version = $this->getMysqlVersionDetails();
        $available_extension = get_loaded_extensions();
        $ip = request()->ip();
        $site_version = get_static_option("site_script_version");


        $req = Http::post($this->getBaseApiUrl()."activate-license/{$licenseCode}/".$envatoUsername,[
            "has" => $has,
            "agent" => $agent,
            "site" => url("/"),
            "ip" => $ip,
            "php_version" => $php_version,
            "mysql_info" => json_encode($mysql_version),
            "php_extensions" => implode(",",$available_extension),
            "site_version" => $site_version,
        ]);
        $result = $req->object();

        //todo verify the data
        $messsage = __("license activate failed, please try after some time, if you sill face issue contact support");
        if ($req->status() === 200){
            if (property_exists($result,"success") && $result->success){
                return [
                    "success" => $result->success,
                    "message" => $result->message,
                    "data" => $result->data
                ];
            }
        }elseif ($req->status() === 422){
            return [
                "success" => false,
                "message" => $result->message,
                "license_key" => $licenseCode
            ];
        }


        return [
            "success" => false,
            "message" => $messsage,
            "license_key" => $licenseCode
        ];

    }

    private function getMysqlVersionDetails(){
        $results = DB::select( DB::raw("select version()") );
        $mysql_version =  $results[0]?->{'version()'};
        $mariadb_version = '';

        if (strpos($mysql_version, 'Maria') !== false) {
            $mariadb_version = $mysql_version;
            $mysql_version = '';
        }
        return [
            "type" => strpos($mysql_version, 'Maria') !== false ? "MariaDB" : "mysql",
            "version" => strpos($mysql_version, 'Maria') !== false ? $mariadb_version : $mysql_version
        ];
    }

    public function checkForUpdate($licenseKey,$getItemVersion){

        $has = hash_hmac('sha224',$licenseKey.$getItemVersion,'xgenious');

        $php_version = PHP_VERSION;
        $mysql_version = $this->getMysqlVersionDetails();
        $available_extension = get_loaded_extensions();
        $ip = request()->ip();
        $site_version = get_static_option("site_script_version");
        $checkUpdateVersion = Http::post($this->getBaseApiUrl()."check-version-update/{$licenseKey}/{$getItemVersion}?has={$has}",[
            "php_version" => $php_version,
            "mysql_info" => json_encode($mysql_version),
            "php_extensions" => implode(",",$available_extension),
            "site_version" => $site_version,
            "ip" => $ip,
            "api_token" => Config::get("xgapiclient.has_token")
        ]);
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
            "message" => property_exists($result,"message") ? $result->message : $messsage
        ];
    }
    public function VerifyLicense($purchaseCode,$email,$envatoUsername){
        $req = Http::post($this->getBaseApiUrl()."verify-license",[
            "purchase_code" => $purchaseCode,
            "email" => $email,
            "client" => $envatoUsername,
            "site" => url("/"),
            "api_token" => Config::get("xgapiclient.has_token")
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

    public function getFilePath($file,$getFileRepalcePath){

        $getDirectory = basename(dirname($file->getRealPath()));
        $dir = "";



        if(str_contains($file->getRealPath(), 'public/') && !str_contains($file->getRealPath(), 'views/') && (!str_contains($file->getRealPath(), 'Modules/') && !str_contains($file->getRealPath(), 'plugins/'))){
            $dir = storage_path('../' . $getFileRepalcePath);
        }
        elseif(str_contains($file->getRealPath(), 'assets/') && !str_contains($file->getRealPath(), 'views/') && (!str_contains($file->getRealPath(), 'Modules') && !str_contains($file->getRealPath(), 'plugins'))){
            $dir = storage_path('../../' . $getFileRepalcePath);
        }elseif(str_contains($file->getRealPath(), '__rootFiles/')){
            $dir = storage_path('../../' . str_replace('__rootFiles/', "", $getFileRepalcePath));
        }else{
            $dir = storage_path('../' . $getFileRepalcePath);
        }


        return str_replace('/'.$file->getFilename(),'',$dir);
    }

} 
