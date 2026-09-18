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
use Laika\Service\Infra;
use Laika\Service\Response as ResponseService;

class Dispatcher
{
    /** @var bool CORS::handle() is not idempotent-free; run it once per request */
    private static bool $headersRegistered = false;

    public static function registerHeaders(): void
    {
        if (static::$headersRegistered) {
            return;
        }

        static::$headersRegistered = true;
        CORS::handle();
    }

    /**
     * Forget That The Headers Were Sent
     *
     * The flag above is per-process, which is one request under FPM and the
     * built-in server. A host that serves many requests from one process has
     * to call this between them, or every request after the first goes out
     * with no CORS and no security headers.
     *
     * @return void
     */
    public static function flushHeaders(): void
    {
        static::$headersRegistered = false;
    }

    /**
     * Answer The Request if it is a Static File
     *
     * Separate from dispatch() so the boot can call it before the function and
     * hook files are discovered and loaded: a static file needs none of them.
     * Everything it does need -- the relay container -- is already built by
     * vendor/autoload.php.
     *
     * CORS runs first even here. It supplies Access-Control-Allow-Origin and
     * Vary for cross-origin webfonts, and it is what answers an OPTIONS
     * preflight with 204 before Asset::send() can refuse the method.
     *
     * A path that cannot be decoded returns false rather than 404ing, so the
     * one 404 for it stays in dispatch() where the fallbacks are.
     *
     * @return bool True when the request was answered as a static file
     */
    public static function dispatchAsset(): bool
    {
        static::registerHeaders();

        $normalized = Path::requestPath();

        return $normalized !== null && Asset::serve($normalized);
    }

    public static function dispatch(): void
    {
        // Register Headers
        static::registerHeaders();

        // One canonical, percent-decoded path for the matcher, the asset
        // handler and the fallback alike. Null means the request encoded a
        // separator (%2F, %5C), smuggled a NUL, or is not valid UTF-8 -- none
        // of which may reach Asset::serve(), whose traversal checks assume a
        // path that means what it says.
        $normalized = Path::requestPath();

        if ($normalized === null) {
            ResponseService::setStatus(404);
            Response\Html::render(_404::show());
            return;
        }

        // Asset::serve() answers only when a real file resolved. A path that
        // maps to nothing on disk -- /sitemap.xml, /feed.json, /users/john.doe
        // -- keeps going and is matched as a route like any other.
        if (Asset::serve($normalized)) {
            return;
        }

        // Load Routes and Match Request
        foreach (Infra::getRouteFiles() as $rf) require_once $rf;

        // Get Route and Params
        ['route' => $route, 'params' => $params] = Path::matchRequestRoute($normalized);

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

    private static function dispatchFallback(string $uri): void
    {
        $fallbacks = Handler::getFallbacks();
        uksort($fallbacks, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($fallbacks as $prefix => $fallback) {
            if (str_starts_with($uri . '/', $prefix)) {
                $response = Invoke::pipeline(
                    $fallback['pipelines'],
                    fn() => ($fallback['callback'])()
                )();

                Response\Html::render($response);
                return;
            }
        }

        // Set it on the Response service, not via http_response_code(): send()
        // writes the service status last and would overwrite the bare call.
        ResponseService::setStatus(404);
        Response\Html::render(_404::show());
    }
}
