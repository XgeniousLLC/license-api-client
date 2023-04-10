<?php

namespace Xgenious\XgApiClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class ActivationController extends Controller
{
    public function licenseActivation()
    {
        return view('XgApiClient::license-activation');

    }

    public function licenseActivationUpdate(Request $request)
    {
        $data = $this->validate($request, [
            'product_activation_key' => 'required',
            'client' => 'required',
        ]);

        $siteUrl = url('/');
        $agent = $request->header('User-Agent');

        // todo:: first we need build api url
        // todo:: url should be look like this activate-license/{key}/{client}
        $has = hash_hmac('sha224',$data['product_activation_key'].$data['client'].$siteUrl,'xgenious');

        $response = Http::post(Config::get('xgapiclient.base_api_url')."/activate-license/{$data['product_activation_key']}/{$data['client']}?site={$siteUrl}&agent={$agent}&has={$has}");

        $result = $response->json();

        $msg = __('your server could not able to connect with xgenious license server, please contact support');
        $msg = $result['message'] ?? $msg;
        $type = 'danger';

        if($response->ok() && $result){
            $licenseStatus = ($result['data']['validity'] === 1) ? 'licensed' : 'not licensed';
            $msg = $result['message'];
            $type = 'licensed' == $licenseStatus ? 'success' : 'danger';

            if ($result['message'] === 'License is Activated') {
                DB::table('xg_ftp_infos')->updateOrInsert([
                    'item_license_key' => $data['product_activation_key'],
                    'item_license_status' => $licenseStatus,
                    'item_license_msg' => $result['message'],
                    'item_version' => $result['0']['version']
                ], ['id' => 1]);

            }
        }


        return redirect()->back()->with(['msg' => $msg , 'type' => $type]);
    }

}
