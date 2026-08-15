<?php

namespace app\controller;

use app\BaseController;

final class C2c extends BaseController
{
    public function index()
    {
        return view('c2c/index', ['initialOrderNo' => '']);
    }

    public function order(string $order)
    {
        return view('c2c/index', ['initialOrderNo' => strtoupper(trim($order))]);
    }
}
