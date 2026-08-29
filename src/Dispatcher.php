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
use Laika\Service\MimeType;
use Laika\Service\Response as ResponseService;
use Laika\Service\Activity;

class Dispatcher
{
    /** @var string[] Directories, relative to APP_PATH, whose contents may be served */
    private const SERVABLE_ROOTS = ['assets', 'uploads', 'template/assets'];

    /**
     * @var string[] Known MIME types never served from a user-writable path.
     * All of them render as markup, so an uploaded file becomes stored XSS.
     */
    private const BLOCKED_TYPES = ['html', 'htm', 'svg', 'xml', 'json'];

    public static function registerHeaders(): void
    {
        CORS::handle();
    }

    public static function dispatch(): void
    {
        // Register Headers
        static::registerHeaders();

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $normalized = Path::normalize(Path::stripBasePath(parse_url($requestUri, PHP_URL_PATH) ?? '/'));

        if (pathinfo($normalized, PATHINFO_EXTENSION)) {
            self::serveAsset($normalized);
            return;
        }

        // Load Routes and Match Request
        // Path::loadRoutes();
        foreach (Infra::getRouteFiles() as $rf) require_once $rf;

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

        // Make Activities
        Activity::insert();

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

    /**
     * Serve a Static File From a Servable Root
     *
     * Path::normalize() only trims slashes, it does not collapse "..", so the
     * incoming path is untrusted: "/assets/../lf-storage/keys/app.key" reaches
     * here intact. Containment is therefore decided by realpath(), never by
     * inspecting the request string.
     *
     * @param string $filePath Normalized request path
     * @return void
     */
    private static function serveAsset(string $filePath): void
    {
        // A backslash is a directory separator on Windows, so "/assets\..\.." is
        // traversal there. Fold it before anything looks at the path.
        $path = str_replace('\\', '/', $filePath);

        // PHP 8 throws on a NUL byte in any path function
        if (str_contains($path, "\0")) {
            http_response_code(404);
            return;
        }

        $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = MimeType::all();

        // Allowlist. An unknown extension is refused outright rather than sent
        // as application/octet-stream, which is what leaked .twig sources.
        if ($ext === '' || in_array($ext, self::BLOCKED_TYPES, true) || !isset($types[$ext])) {
            http_response_code(404);
            return;
        }

        // realpath() collapses ".." and resolves symlinks. This is the boundary.
        $real = realpath(APP_PATH . '/' . ltrim($path, '/'));

        if ($real === false || !is_file($real) || !self::withinServableRoot($real)) {
            http_response_code(404);
            return;
        }

        header('Content-Type: ' . $types[$ext]);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($real));
        readfile($real);
    }

    /**
     * Is a Resolved Path Inside One of The Servable Roots
     * @param string $real Absolute path, already through realpath()
     * @return bool
     */
    private static function withinServableRoot(string $real): bool
    {
        $real = str_replace('\\', '/', $real);

        foreach (self::SERVABLE_ROOTS as $relative) {
            $base = realpath(APP_PATH . '/' . $relative);

            if ($base === false) {
                continue; // Directory absent, nothing to serve from it.
            }

            // Trailing slash matters: without it "/assets" also matches a
            // sibling "/assets-backup".
            $base = rtrim(str_replace('\\', '/', $base), '/') . '/';

            // The filesystem is case-insensitive on Windows, so the check must be too
            $match = PHP_OS_FAMILY === 'Windows'
                ? stripos($real, $base) === 0
                : str_starts_with($real, $base);

            if ($match) {
                return true;
            }
        }

        return false;
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
