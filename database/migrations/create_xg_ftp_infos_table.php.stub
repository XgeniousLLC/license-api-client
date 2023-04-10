<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('xg_ftp_infos', function (Blueprint $table) {
            $table->id();

            $table->string('item_version');
            $table->string('item_license_key');
            $table->string('item_license_status');
            $table->string('item_license_msg');

            $table->timestamps();
        });
    }
    public function down(){
        Schema::dropIfExists('xg_ftp_infos');
    }
};
