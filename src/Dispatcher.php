<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Route;

use Laika\Service\CORS;
use Laika\Service\MimeType;
use Laika\Service\Response as ResponseService;

class Dispatcher
{
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
        CORS::handle();
    }

    public static function dispatch(): void
    {
        static::preDispatcher();

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $normalized = Path::normalize(Path::stripBasePath(parse_url($requestUri, PHP_URL_PATH) ?? '/'));

        if (pathinfo($normalized, PATHINFO_EXTENSION)) {
            self::serveAsset($normalized);
            return;
        }

        // Load Routes and Match Request
        Path::loadRoutes();

        // Get Route and Params
        ['route' => $route, 'params' => $params] = Path::matchRequestRoute($requestUri);

        // Dispatch Fallback if no route is matched
        if ($route === null) {
            static::dispatchFallback($normalized);
            return;
        }

        $pipelines = array_merge(Handler::getGlobalPipelines(), $route['pipelines']);
        $filters = array_merge(Handler::getGlobalFilters(), $route['filters']);

        $core = function () use ($route, &$params) {
            return Invoke::controller($route['controller'], $params);
        };

        $response = Invoke::pipeline($pipelines, $core, $params)();
        $response = Invoke::filter($filters, $response, $params);

        // Send Response
        self::serveResponse($response);
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
        if (!str_starts_with($filePath, '/template') && !str_starts_with($filePath, '/assets') && !str_starts_with($filePath, '/uploads')) {
            http_response_code(404);
            return;
        }
        $file = APP_PATH . $filePath;
        if (!is_file($file)) {
            http_response_code(404);
            return;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext == 'php') {
            http_response_code(404);
            return;
        }

        $mime = MimeType::fromExtension($ext);
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
