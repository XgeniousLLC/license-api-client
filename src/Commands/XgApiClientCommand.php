<?php

namespace Xgenious\XgApiClient\Commands;

use Illuminate\Console\Command;

class XgApiClientCommand extends Command
{
    public $signature = 'xgapiclient';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
