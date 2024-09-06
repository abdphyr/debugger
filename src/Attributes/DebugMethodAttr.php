<?php

namespace Abd\Debugger\Attributes;


#[\Attribute(\Attribute::TARGET_METHOD)]
class DebugMethodAttr
{
    public function __construct(public $method = 'GET') {}
}
