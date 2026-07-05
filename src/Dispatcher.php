<?php

declare(strict_types=1);

namespace Laika\Route;

use Laika\Service\Response as ResponseService;
use Laika\Service\MimeType;

class Dispatcher
{
    protected static array $assetRoutes = [];

    public static function preDispatcher(): void
    {
        date_default_timezone_set('UTC');
        static::registerInitiators();
    }

    public static function registerInitiators(): void
    {
        static::registerHeaders();
    }

    public static function registerHeaders(): void
    {
        ResponseService::setDefaultHeaders();
    }

    public static function registerAssetRoute(string $uri, string $filePath): void
    {
        static::$assetRoutes[Url::normalize($uri)] = $filePath;
    }

    public static function dispatch(): void
    {
        static::preDispatcher();

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $normalized = Url::normalize(Url::stripBasePath(parse_url($requestUri, PHP_URL_PATH) ?? '/'));

        if (pathinfo($normalized, PATHINFO_EXTENSION)) {
            self::serveAsset($normalized);
            return;
        }

        // Load Routes and Match Request
        Url::loadRoutes();

        // Get Route and Params
        ['route' => $route, 'params' => $params] = Url::matchRequestRoute($requestUri);

        // Dispatch Fallback if no route is matched
        if ($route === null) {
            static::dispatchFallback($normalized);
            return;
        }

        $middlewares = array_merge(Handler::getGlobalMiddleware(), $route['middlewares']);
        $afterwares = array_merge(Handler::getGlobalAfterware(), $route['afterwares']);

        $core = function () use ($route, $params) {
            return Invoke::controller($route['controller'], $params);
        };

        $response = Invoke::middleware($middlewares, $core, $params)();

        // Send Response
        self::serveResponse($response);

        Invoke::afterware($afterwares, $response, $params);
    }

    /*================================= PRIVATE API =================================*/
    /**
     * Handle Response
     * @param ?string $response Response
     * @return void
     */
    private static function serveResponse(?string $response): void
    {
        if (empty($response)) return;

        $ct = ResponseService::getContentType();

        match (true) {
            str_starts_with($ct, 'application/json')        => Response\Json::render($response),
            str_starts_with($ct, 'text/plain')              => Response\Text::render($response),
            str_starts_with($ct, 'text/html')               => Response\Html::render($response),
            default                                         => Response\Html::render($response)
        };
    }

    private static function serveAsset(string $filePath): void
    {
        $file = APP_PATH . $filePath;
        if (!is_file($file)) {
            http_response_code(404);
            return;
        }

        $mime = MimeType::fromFile($file);
        header('Content-Type: ' . $mime);
        readfile($file);
    }

    private static function dispatchFallback(string $uri): void
    {
        $fallbacks = Handler::getFallbacks();
        uksort($fallbacks, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($fallbacks as $prefix => $fallback) {
            if (str_starts_with($uri . '/', $prefix)) {
                $response = Invoke::middleware(
                    $fallback['middlewares'],
                    fn() => ($fallback['callback'])()
                )();

                Response\Html::render($response);
                return;
            }
        }

        http_response_code(404);
        Response\Html::render(_404::show());
    }
}
