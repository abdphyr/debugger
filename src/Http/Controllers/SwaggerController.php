<?php


namespace Abd\Debugger\Http\Controllers;

class SwaggerController
{
    public function doc()
    {
        return view('debugger::swagger');
    }
}