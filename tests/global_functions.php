<?php

namespace Xgenious\XgApiClient;

function request() {
    return new class {
        public function ip() {
            return '127.0.0.1';
        }
    };
}