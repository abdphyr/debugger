<?php

namespace Abd\Debugger\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class DebugActionAttr
{
    public ?string $url = null;
    public ?string $group = null;
    public ?string $method = null;
    public array $queryparams = [];
    public array $urlparams = [];
    public array $headers = [];
    public array $config = [];
    public array $typedParameters = [];

    public function __construct(
        $url = null,
        $method = null,
        $urlparams = [],
        $queryparams = [],
        $headers = [],
        $config = [],
        $group = null,
        ...$args
    ) {
        $this->group = $group;
        $this->url = $url;
        $this->method = $method;
        $this->urlparams = $urlparams;
        $this->headers = $headers;
        $this->queryparams = $queryparams;
        $this->config = $config;
        $this->typedParameters = $args;
    }

    public function getUrl()
    {
        return $this->getConfigItem('url', $this->url);
    }

    public function getGroup()
    {
        return $this->getConfigItem('group', $this->group);
    }

    public function getMethod()
    {
        return $this->getConfigItem('method', $this->method);
    }

    public function getUrlparams()
    {
        return $this->getConfigItem('urlparams', $this->urlparams);
    }

    public function getHeaders()
    {
        return $this->getConfigItem('headers', $this->headers);
    }

    public function getQueryparams()
    {
        return $this->getConfigItem('queryparams', $this->queryparams);
    }

    public function getConfigItem($key, $default = null)
    {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    public function getQueryParameter($key, $default = null)
    {
        return isset($this->getQueryparams()[$key]) ? $this->getQueryparams()[$key] : $default;
    }

    public function getUrlParameter($key, $default = null)
    {
        return isset($this->getUrlparams()[$key]) ? $this->getUrlparams()[$key] : $default;
    }

    public function getTypedParameter($key, $default = [])
    {
        return isset($this->typedParameters[$key]) ? $this->typedParameters[$key] : $default;
    }
}
