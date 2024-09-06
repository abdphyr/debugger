<?php

namespace Abd\Debugger\Console\Commands;

use Abd\Debugger\Attributes\DebugControllerAttr;
use Abd\Debugger\Attributes\DebugActionAttr;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Str;
use Symfony\Component\VarDumper\VarDumper;

class AbdApiDoc extends Command
{
    const OBJ = "object";
    const NUM = "integer";
    const ARR = "array";
    const STR = "string";
    const BOO = "boolean";

    protected $signature = "abd:api:doc {--folder= : Folder which generated files are saved} {--group= : Main file which urls are saved} {--debug}";
    protected $description = 'Command description';

    protected string $folder = "swagger";
    protected string $group = "root";

    public function handle()
    {
        if ($folder = $this->option('folder')) {
            $this->folder = public_path($folder);
        } else {
            $this->folder = public_path($this->folder);
        }
        if (!file_exists($this->folder)) {
            mkdir($this->folder, 0777, true);
        }
        $this->writeAssets();

        if ($group = $this->option('group')) {
            $this->group = ($this->folder . '/' . $group . '.json');
        } else {
            $this->group = ($this->folder . '/' . $this->group . '.json');
        }
        if (!file_exists($this->group)) {
            copy(__DIR__ . '/../../../resources/templates/template.json', $this->group);
        }
        $this->resolve();
    }

    protected function writeAssets()
    {
        if (!file_exists($this->folder . '/swagger.css')) {
            $from = __DIR__ . '/../../../resources/css/swagger.css';
            $to = $this->folder . '/swagger.css';
            copy($from, $to);
        }

        if (!file_exists($this->folder . '/swagger.js')) {
            $from = __DIR__ . '/../../../resources/js/swagger.js';
            $to = $this->folder . '/swagger.js';
            copy($from, $to);
        }
    }


    protected function writeToGroupFile($group)
    {
        $group = $this->folder . '/' . $group . '.json';
        if (!file_exists($group)) {
            file_put_contents($group, file_get_contents(__DIR__ . '/doc/template.json'));
        }
        return $group;
    }

    protected function resolve()
    {
        $namespace = 'App\Http\\Controllers';
        $path = app_path('Http/Controllers');
        $filter = function ($filename) {
            if (! str_starts_with($filename, 'Controller')) {
                return true;
            }
        };
        $controllers = $this->getFiles(path: $path, namespace: $namespace, filter: $filter);
        foreach ($controllers as $controller) {
            $classname = pathinfo($controller, PATHINFO_FILENAME);
            $object = app()->make($classname);
            $reflectionController = new ReflectionClass($classname);
            if (! ($debugControllerAttr = $this->isDebugController($reflectionController))) {
                // VarDumper::dump($reflectionController);
                continue;
            }
            $reflectionMethods = $reflectionController->getMethods();
            foreach ($reflectionMethods as $reflectionMethod) {
                if (! ($debugActionAttr = $this->isDocAction($reflectionMethod))) {
                    // VarDumper::dump($reflectionMethod);
                    continue;
                }
                $request = null;
                $requestInstance = null;
                $requestPartials = [
                    'query' => [],
                    'request' => [],
                    'attributes' => [],
                    'cookies' => [],
                    'files' => [],
                    'server' => [],
                    'content' => null
                ];
                $response = new Response();
                /** @var DebugActionAttr */
                $debugActionAttrObj = $debugActionAttr->newInstance();
                if (! $debugActionAttrObj->getUrl()) {
                    $this->comment($reflectionMethod->getFileName() . ':' . $reflectionMethod->getStartLine());
                    $this->comment('Http URL is required on ' . $reflectionController->getName() . "::" . $reflectionMethod->getName() . '()');
                    $this->comment("Example #[DebugActionAttr(url: '/admin/resource')] or #[DebugActionAttr(config: ['url' => '/admin/resource'])]");
                    exit;
                }
                if (! $debugActionAttrObj->getMethod()) {
                    $this->comment($reflectionMethod->getFileName() . ':' . $reflectionMethod->getStartLine());
                    $this->comment('Http METHOD is required on ' . $reflectionController->getName() . "::" . $reflectionMethod->getName() . '()');
                    $this->comment("Example #[DebugActionAttr(method: 'POST')] or #[DebugActionAttr(config: ['method' => 'POST'])]");
                    exit;
                }
                $HTTP_METHOD = $this->getHttpMethod($reflectionMethod, $debugActionAttrObj);
                $parameters = $reflectionMethod->getParameters();
                $passedParameters = [];

                foreach ($parameters as $parameter) {
                    $parameterName = $parameter->getName();
                    if ($parameterType = $parameter->getType()) {
                        if ($parameterType->isBuiltin()) {
                            if ($p = $debugActionAttrObj->getUrlParameter($parameterName)) {
                                $passedParameters[$parameterName] = $p;
                            } else {
                                $type = $parameterType->getName();
                                $this->comment($reflectionMethod->getFileName() . ':' . $reflectionMethod->getStartLine());
                                $this->comment("Argument \"$$parameterName\" is not provided of " . $reflectionController->getName() . "::" . $reflectionMethod->getName() . "($type $$parameterName )");
                                $this->comment("Example #[DebugActionAttr(urlparams: ['$parameterName' => ($type)'value'])] or #[DebugActionAttr(config: ['urlparams' => ['$parameterName' => ($type)'value']])]");
                                exit;
                            }
                        } else {
                            $parameterTypeName = $parameterType->getName();
                            try {
                                $implements = class_implements($parameterTypeName);
                                if (in_array(\Illuminate\Contracts\Validation\ValidatesWhenResolved::class, $implements)) {
                                    $data = $debugActionAttrObj->getTypedParameter($parameterName);
                                    $data['server'] = $this->server($debugActionAttrObj);
                                    $data['query'] = isset($data['query']) ? array_merge($data['query'], $debugActionAttrObj->getQueryparams()) : $debugActionAttrObj->getQueryparams();
                                    foreach ($data as $key => $value) {
                                        $requestPartials[$key] = array_merge($requestPartials[$key], $data[$key]);
                                    }
                                    /** @var \Illuminate\Foundation\Http\FormRequest */
                                    $instance = new $parameterTypeName(...$data);
                                    $requestInstance = $instance;
                                    try {
                                        $instance->setContainer($this->laravel);
                                        $instance->setRedirector(redirect());
                                        $instance->validateResolved();
                                    } catch (\Illuminate\Validation\ValidationException $th) {
                                        $message = $th->validator->errors()->first();
                                        $errors = $th->validator->errors()->toArray();
                                        $response = new JsonResponse(data: compact('message', 'errors'), status: 422);
                                    } catch (\Illuminate\Http\Exceptions\HttpResponseException $th) {
                                        $response = $th->getResponse();
                                    } catch (\Throwable $th) {
                                        $this->comment($th->getMessage());
                                        exit;
                                    }
                                } else if (\Illuminate\Http\Request::class == $parameterTypeName) {
                                    $data = $debugActionAttrObj->getTypedParameter($parameterName);
                                    $data['server'] = $this->server($debugActionAttrObj);
                                    $data['query'] = isset($data['query']) ? array_merge($data['query'], $debugActionAttrObj->getQueryparams()) : $debugActionAttrObj->getQueryparams();
                                    foreach ($data as $key => $value) {
                                        $requestPartials[$key] = array_merge($requestPartials[$key], $data[$key]);
                                    }
                                    $instance = new $parameterTypeName(...$data);
                                    $requestInstance = $instance;
                                } else {
                                    $instance = app()->make($parameterTypeName, ...$debugActionAttrObj->getTypedParameter($parameterName));
                                }
                            } catch (\Throwable $th) {
                                $this->comment($th->getMessage());
                                exit;
                            }
                            $passedParameters[$parameterName] = $instance;
                        }
                    } else {
                        if ($p = $debugActionAttrObj->getUrlParameter($parameterName)) {
                            $passedParameters[$parameterName] = $p;
                        } else {
                            $this->comment($reflectionMethod->getFileName() . ':' . $reflectionMethod->getStartLine());
                            $this->comment("Argument \"$$parameterName\" is not provided of " . $reflectionController->getName() . "::" . $reflectionMethod->getName() . "($$parameterName)");
                            $this->comment("Example #[DebugActionAttr(urlparams: ['$parameterName' => 'value'])] or #[DebugActionAttr(config: ['urlparams' => ['$parameterName' => 'value']])]");
                            exit;
                        }
                    }
                }
                if (! $requestInstance) {
                    $requestPartials['server'] = $this->server($debugActionAttrObj);
                    $requestPartials['query'] = $debugActionAttrObj->getQueryparams();
                }

                $request = new Request(...$requestPartials);

                app()->instance('request', $request);

                if ($this->option('debug')) {
                    $this->info(blue($reflectionController->getName()) . white('::') . yellow($reflectionMethod->getName() . '()'));
                    try {   
                        $response = $reflectionMethod->invoke($object, ...$passedParameters);
                        if ($response instanceof \Illuminate\Contracts\Support\Responsable) {
                            $response = $response->toResponse($request ?? request());
                        } else if (is_array($response)) {
                            $response = new JsonResponse(data: $response);
                        } else if (is_string($response) || is_numeric($response) || is_bool($response) || is_null($response)) {
                            $response = new Response($response);
                        } else if ($response instanceof \Illuminate\Support\Collection) {
                            $response = new JsonResponse(data: $response);
                        }
                        $this->info(green('Ok: ') . red($response->getContent()));
                    } catch (\Throwable $th) {
                        $message = $th->getMessage();
                        $file = $th->getFile();
                        $line = $th->getLine();
                        $this->info(white('Error: ') . red($message));
                        $this->info(white('Location: ') . yellow($file . ':' . $line));
                    }
                    $this->newLine(2);
                    continue;
                }

                if (! in_array($response->getStatusCode(), [422, 500])) {
                    try {
                        $response = $reflectionMethod->invoke($object, ...$passedParameters);
                    } catch (\Throwable $th) {
                        $message = $th->getMessage();
                        $file = $th->getFile();
                        $line = $th->getLine();
                        $this->comment($message . ' on ' . $file . ':' . $line);
                        exit;
                    }
                    if ($response instanceof \Illuminate\Contracts\Support\Responsable) {
                        $response = $response->toResponse($request ?? request());
                    } else if (is_array($response)) {
                        $response = new JsonResponse(data: $response);
                    } else if (is_string($response) || is_numeric($response) || is_bool($response) || is_null($response)) {
                        $response = new Response($response);
                    } else if ($response instanceof \Illuminate\Support\Collection) {
                        $response = new JsonResponse(data: $response);
                    }
                }
                $action = $reflectionMethod->getName();
                $fileName = $this->generateFilenameForAction($action);
                $folderName = $this->folderNameForController($reflectionController->getName());
                $endpoint = $debugActionAttrObj->getUrl() ?? "/$folderName";
                $group = $debugActionAttrObj->getGroup() ? $this->writeToGroupFile($debugActionAttrObj->getGroup()) : $this->group;

                $folder = $this->folder . '/' . $folderName;
                if (!file_exists($folder)) {
                    mkdir($folder, 0777, true);
                }
                $actionFilePath = $folder . '/' . $fileName;
                $this->changeGroupFile($group, $endpoint, $folderName, $fileName);
                $this->writeToDocFile($actionFilePath, $request, $response, $debugActionAttrObj);
                $this->info($actionFilePath);
            }
        }
    }

    public function server(DebugActionAttr $debugActionAttrObj)
    {
        if ($headers = $debugActionAttrObj->getHeaders()) {
            foreach ($headers as $key => $value) {
                $_SERVER["HTTP_" . strtoupper($key)] = $value;
            }
        }
        return $_SERVER;
    }

    public function writeToDocFile($actionFilePath, $request, $response, $debugActionAttrObj)
    {
        $data = json_decode($response->getContent(), true);
        $method = strtolower($debugActionAttrObj->getMethod());
        $status = $response->getStatusCode();
        $document = [];
        $docJson = $this->convertValuesToDocFormat($request, $response, $debugActionAttrObj);
        if (file_exists($actionFilePath) && $filedata = json_decode(file_get_contents($actionFilePath), true)) {
            foreach ($filedata as $key => $value) {
                if ($key == $method) {
                    $responses = $filedata[$method]['responses'];
                    $responses["$status"] = $docJson['responses']["$status"];
                    $docJson['responses'] = $responses;
                    $document[$method] = $docJson;
                } else {
                    $document[$key] = $value;
                }
            }
            if (!isset($document[$method])) {
                $document[$method] = $docJson;
            }
        } else {
            $document[$method] = $docJson;
        }
        $data = Str::remove('\\', json_encode($document));
        file_put_contents($actionFilePath, $data, JSON_PRETTY_PRINT);
    }

    public function generateFilenameForAction($action)
    {
        $basePathActions = ['index', 'store'];
        $singlePathActions = ['show', 'update', 'destroy'];
        $filename = '';
        if (in_array($action, $basePathActions)) {
            $filename = 'get_list_store';
        } else if (in_array($action, $singlePathActions)) {
            $filename = 'get_one_update_delete';
        } else {
            $filename = $action;
        }
        return $filename . '.json';
    }

    public function changeGroupFile($group, $endpoint, $folderName, $fileName)
    {
        try {
            $baseDocValue = json_decode(file_get_contents($group), true);
        } catch (\Throwable $th) {
            dd($group);
        }
        $endpoint = Str::startsWith($endpoint, '/') ? $endpoint : "/$endpoint";
        if (!isset($baseDocValue['paths'][$endpoint])) {
            $baseDocValue['paths'][$endpoint] = [
                '$ref' => $folderName . '/' . $fileName
            ];
            file_put_contents($group, Str::remove('\\', json_encode($baseDocValue)), JSON_PRETTY_PRINT);
        }
    }

    protected function folderNameForController($controllerName)
    {
        $explodedName = explode('\\', $controllerName);
        $realName = end($explodedName);
        $realName = Str::replace('Controller', '', $realName);
        $realName = Str::snake($realName);
        return $realName;
    }

    protected function getHttpMethod(ReflectionMethod $reflectionMethod, DebugActionAttr $debugActionAttrObj)
    {
        if ($debugActionAttrObj->getMethod()) {
            return $_SERVER['REQUEST_METHOD'] = $debugActionAttrObj->getMethod();
        }
        return $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function isDocAction(ReflectionMethod $reflectionMethod)
    {
        if ($attributes = $reflectionMethod->getAttributes(DebugActionAttr::class)) {
            return $attributes[0];
        }
    }

    protected function isDebugController(ReflectionClass $reflectionController)
    {
        if ($attributes = $reflectionController->getAttributes(DebugControllerAttr::class)) {
            return $attributes[0];
        }
    }

    function getFiles($path = null, $namespace = null, $filter = null)
    {
        $items = scandir($path);
        $results = [];
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            if (is_dir("$path/$item")) {
                $dirResults = $this->fileFinder(path: "$path/$item", namespace: ($namespace ? "$namespace\\$item" : $item), filter: $filter);
                $results = array_merge($results, $dirResults);
            } else {
                if ($filter && $filter instanceof \Closure) {
                    if ($filter($item)) {
                        $results[] = $namespace ? "$namespace\\$item" : $item;
                    }
                } else {
                    $results[] = $namespace ? "$namespace\\$item" : $item;
                }
            }
        }
        return $results;
    }

    /**
     * @param Request|null $request
     * @param Response|JsonResponse $response
     * @param DebugActionAttr $debugActionAttrObj
     */
    protected function convertValuesToDocFormat($request, $response, $debugActionAttrObj)
    {
        $body = [];
        // $body['tags'] = getterValue($route, 'tags');
        // $body['description'] = getterValue($route, 'description');
        $body['parameters'] = [];
        if ($urlparams = $debugActionAttrObj->getUrlparams()) {
            foreach ($urlparams as $key => $value) {
                $body['parameters'][] = [
                    'name' => $key,
                    'in' => 'path',
                    'required' => false,
                    'schema' => [
                        'type' => $this->getType($value),
                        'example' => $value
                    ]
                ];
            }
        }
        if ($queryparams = $debugActionAttrObj->getQueryparams()) {
            foreach ($queryparams as $key => $value) {
                $body['parameters'][] = [
                    'name' => $key,
                    'in' => 'url',
                    'required' => false,
                    'schema' => [
                        'type' => $this->getType($value),
                        'example' => $value
                    ]
                ];
            }
        }
        if ($headers = $debugActionAttrObj->getHeaders()) {
            foreach ($headers as $key => $value) {
                if ($key == 'Authorization') {
                    $body['security'] = [
                        [
                            'bearerAuth' => []
                        ]
                    ];
                }
                $body['parameters'][] = [
                    'name' => $key,
                    'in' => 'header',
                    'required' => false,
                    'schema' => [
                        'type' => $this->getType($value),
                        'example' => $value
                    ]
                ];
            }
        }

        if ($request) {
            if (!empty($request->request->all())) {
                $contentType = $request->headers->get('CONTENT_TYPE', 'application/json');
                $body['requestBody'] = [
                    'content' => [
                        $contentType => [
                            'schema' => $this->convertDataToDocFormat($request->request->all())
                        ]
                    ]
                ];
            }
        }
        $status = $response->status();
        $statusText = $response->statusText();
        $contentType = $response->headers->get('content-type', 'application/json');
        try {
            $data = json_decode($response->getContent(), true);
        } catch (\Throwable $th) {
            $data = [];
        }
        $body['responses'] = [
            "$status" => [
                'description' => "$statusText",
                'content' => [
                    $contentType => [
                        'schema' => $this->convertDataToDocFormat($data)
                    ]
                ]
            ]
        ];
        return $body;
    }

    protected function getType($value)
    {
        if ($this->maybeObject($value)) return self::OBJ;
        if (is_numeric($value)) return self::NUM;
        if (is_bool($value)) return self::BOO;
        if (is_string($value)) return self::STR;
        if (is_array($value)) return self::ARR;
        if (is_null($value)) return null;
    }

    protected function convertDataToDocFormat($data)
    {
        switch ($this->getType($data)) {
            case self::OBJ:
                $obj["type"] = self::OBJ;
                $obj['properties'] = [];
                foreach ($data as $key => $value) {
                    $obj['properties'][$key] = $this->convertDataToDocFormat($value);
                }
                return $obj;
                break;
            case self::ARR:
                $arr["type"] = self::ARR;
                $arr['items'] = [];
                if (!empty($data)) {
                    $arr['items'] = $this->convertDataToDocFormat($data[0]);
                } else {
                    $arr['items'] = $this->convertDataToDocFormat(null);
                }
                return $arr;
                break;
            case self::NUM:
                return [
                    "type" => self::NUM,
                    "example" => $data
                ];
                break;
            case self::STR:
                return [
                    "type" => self::STR,
                    "example" => $data
                ];
                break;
            case self::BOO:
                return [
                    "type" => self::BOO,
                    "example" => $data
                ];
                break;
            case null:
                return [
                    "type" => null,
                    "example" => null
                ];
                break;
        }
    }

    protected function maybeObject($array)
    {
        if (!is_array($array)) return false;
        foreach ($array as $key => $value) {
            if (is_string($key)) return true;
        }
        return false;
    }
}
