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

class AbdDebug extends Command
{
    protected $signature = "abd:debug {--class= : Controller name}";

    protected $description = 'Debug code';

    public function handle()
    {
        $this->resolve();
    }


    protected function controllers()
    {
        $namespace = 'App\Http\\Controllers';
        $path = app_path('Http/Controllers');
        $filter = function ($filename) {
            $array = explode('.', $filename);
            if ($controller = $this->option('class')) {
                return $array[0] == $controller ? $filename : false;
            }
            if (! str_starts_with($filename, 'Controller')) {
                return true;
            }
        };
        $controllers = $this->getFiles(path: $path, namespace: $namespace, filter: $filter);
        if (! $controllers && ($controller = $this->option('class'))) {
            $this->info(yellow('Controller ') . white($controller) . yellow(' is not found.'));
            die;
        }
        return $controllers;
    }


    protected function resolve()
    {
        $controllers = $this->controllers();
        $this->newLine(1);
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
                if (! ($debugActionAttr = $this->isDebugAction($reflectionMethod))) {
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
                    $this->info(gray('✅ '.$reflectionController->getName().'::'.$reflectionMethod->getName() . '()'));
                    $this->info(white('Response: ') . gray($response->getContent()));
                } catch (\Throwable $th) {
                    $message = $th->getMessage();
                    $file = $th->getFile();
                    $line = $th->getLine();
                    $this->info(gray('❌ ' . $reflectionController->getName() . '::'.$reflectionMethod->getName() . '()'));
                    $this->info(white('Error: ') . gray($message));
                    $this->info(white('Location: ') . gray($file . ':' . $line));
                }
                $this->newLine(1);
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


    protected function getHttpMethod(ReflectionMethod $reflectionMethod, DebugActionAttr $debugActionAttrObj)
    {
        if ($debugActionAttrObj->getMethod()) {
            return $_SERVER['REQUEST_METHOD'] = $debugActionAttrObj->getMethod();
        }
        return $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function isDebugAction(ReflectionMethod $reflectionMethod)
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
                    $result = $filter($item);
                    if ($result && is_string($result)) {
                        return [$namespace ? "$namespace\\$item" : $item];
                    } else if ($result) {
                        $results[] = $namespace ? "$namespace\\$item" : $item;
                    }
                } else {
                    $results[] = $namespace ? "$namespace\\$item" : $item;
                }
            }
        }
        return $results;
    }
}
