<?php

namespace Abd\Debugger\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class DebugControllerAttr
{
    public function __construct(protected string $prefix) {}
}
