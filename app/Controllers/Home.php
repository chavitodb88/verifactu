<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {

        $isTest = $test ?? (strtolower((string) env('verifactu.isTest')) !== 'false');
        $sendReal = $testReal ?? (strtolower((string) env('verifactu.sendReal')) === '1');
        return view('welcome_message', ['test' => $isTest, 'sendReal' => $sendReal]);
    }
}
