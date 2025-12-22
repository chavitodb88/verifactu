<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {

        $isTest = $test ?? (strtolower((string) env('verifactu.isTest')) !== 'false');
        return view('welcome_message', ['test' => $isTest]);
    }
}
