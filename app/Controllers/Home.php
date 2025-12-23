<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        $cfg = config('Verifactu');

        $isTest = $cfg->isTest;
        $sendReal = $cfg->sendReal === 1;
        return view('welcome_message', ['test' => $isTest, 'sendReal' => $sendReal, 'cfg' => $cfg]);
    }
}
