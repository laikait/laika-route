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
use Laika\Service\Config;
use Laika\Service\Infra;
use Laika\Service\MimeType;
use Laika\Service\Response as ResponseService;
use Laika\Service\Activity;
use Throwable;

class Dispatcher
{
    /**
     * @var string[] Roots never served, matched against the first path segment.
     * Framework internals; deliberately the same list nginx.conf denies, so the
     * front controller and the web server agree. Glob patterns allowed.
     */
    private const FORBIDDEN_ROOTS = ['lf-*', 'vendor', 'docs'];

    /**
     * @var string[] Refused when lf-config/assets.php is absent.
     * Reproduces the pre-config behaviour: the php family must never leave the
     * disk as bytes, and the markup types all render, so a file served from a
     * user-writable path becomes stored XSS.
     */
    private const DEFAULT_BLOCKED = ['php', 'phar', 'phtml', 'phps', 'html', 'htm', 'svg', 'xml', 'json'];

    /** @var string[] Roots written by users rather than by the app author */
    private const DEFAULT_UNTRUSTED_ROOTS = ['uploads'];

    /** @var string[] Types refused inside an untrusted root. All of them render as markup */
    private const DEFAULT_UNTRUSTED_TYPES = ['html', 'htm', 'svg', 'xml'];

    /** @var ?array Resolved once per process, see rules() */
    private static ?array $rules = null;

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
     * Serve a Static File
     *
     * Every request reaches the front controller, including one that maps to a
     * real file on disk, so this method is the only gatekeeper the web server
     * leaves in place. Two independent checks decide it: where the file
     * resolved to, and what its extension is.
     *
     * Path::normalize() only trims slashes, it does not collapse "..", so the
     * incoming path is untrusted: "/assets/../lf-storage/keys/app.key" reaches
     * here intact. Containment is therefore decided by realpath(), never by
     * inspecting the request string.
     *
     * Every rejection is a bare 404, so a forbidden path is indistinguishable
     * from a missing one.
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

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === '') {
            http_response_code(404);
            return;
        }

        // realpath() collapses ".." and resolves symlinks. This is the boundary:
        // a symlink pointing out of template/ lands on its target and is judged
        // there, not where the request said it was.
        $real = realpath(APP_PATH . '/' . ltrim($path, '/'));

        if ($real === false || !is_file($real)) {
            http_response_code(404);
            return;
        }

        $relative = self::relativeToApp($real);

        if ($relative === null || !self::servableLocation($relative) || !self::servableType($ext, $relative)) {
            http_response_code(404);
            return;
        }

        header('Content-Type: ' . MimeType::fromExtension($ext));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($real));
        readfile($real);
    }

    /**
     * Path Relative to APP_PATH
     * @param string $real Absolute path, already through realpath()
     * @return ?string Null when the path resolved outside the application
     */
    private static function relativeToApp(string $real): ?string
    {
        $root = realpath(APP_PATH);

        if ($root === false) {
            return null;
        }

        // Trailing slash matters: without it a sibling directory whose name
        // merely starts with APP_PATH would also match.
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $real = str_replace('\\', '/', $real);

        // The filesystem is case-insensitive on Windows, so the check must be too
        $match = PHP_OS_FAMILY === 'Windows'
            ? stripos($real, $root) === 0
            : str_starts_with($real, $root);

        return $match ? substr($real, strlen($root)) : null;
    }

    /**
     * Is a Resolved Path in a Servable Location
     * @param string $relative Path relative to APP_PATH
     * @return bool
     */
    private static function servableLocation(string $relative): bool
    {
        $segments = explode('/', $relative);

        // A dot path is never public: .git/, .env, .htaccess, lf-storage/.htaccess
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }

        $root = strtolower($segments[0]);

        // FNM_CASEFOLD is a GNU extension and undefined on some builds, so both
        // sides are lowercased rather than passing the flag.
        foreach (self::FORBIDDEN_ROOTS as $pattern) {
            if (fnmatch($pattern, $root)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is an Extension Servable From This Location
     *
     * An extension outside the configured list is refused outright rather than
     * sent as application/octet-stream, which is what leaked .twig sources.
     *
     * @param string $ext Lowercased extension
     * @param string $relative Path relative to APP_PATH
     * @return bool
     */
    private static function servableType(string $ext, string $relative): bool
    {
        $rules = self::rules();

        if (in_array($ext, $rules['blocked'], true) || !in_array($ext, $rules['extensions'], true)) {
            return false;
        }

        $root = strtolower(explode('/', $relative)[0]);

        return !(in_array($root, $rules['untrusted_roots'], true)
            && in_array($ext, $rules['untrusted_blocked'], true));
    }

    /**
     * Extension Rules From lf-config/assets.php
     *
     * MimeType::register() adds a Content-Type, it does not make a type
     * servable. Only this config decides what may leave the disk.
     *
     * @return array{extensions:string[],blocked:string[],untrusted_roots:string[],untrusted_blocked:string[]}
     */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        try {
            $extensions = Config::get('assets', 'extensions', array_keys(MimeType::all()));
            $blocked    = Config::get('assets', 'blocked', self::DEFAULT_BLOCKED);
            $untrusted  = (array) (Config::get('assets', 'untrusted') ?? []);
        } catch (Throwable) {
            // No container yet (a unit test, a CLI script that never booted).
            // Fall back to the built-in defaults rather than fatal.
            $extensions = array_keys(MimeType::all());
            $blocked    = self::DEFAULT_BLOCKED;
            $untrusted  = [];
        }

        $blocked = self::listOf($blocked);

        return self::$rules = [
            // Subtracting here means a type named in both lists is refused, so
            // 'blocked' cannot be defeated by an entry in 'extensions'.
            'extensions'        => array_values(array_diff(self::listOf($extensions), $blocked)),
            'blocked'           => $blocked,
            'untrusted_roots'   => self::listOf($untrusted['roots'] ?? self::DEFAULT_UNTRUSTED_ROOTS),
            'untrusted_blocked' => self::listOf($untrusted['blocked'] ?? self::DEFAULT_UNTRUSTED_TYPES),
        ];
    }

    /**
     * Normalize a Configured List
     * @param mixed $values Whatever the config file held
     * @return string[] Lowercased, trimmed, no blanks, no duplicates
     */
    private static function listOf(mixed $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($value): string => strtolower(trim((string) $value)), (array) $values),
            static fn (string $value): bool => $value !== ''
        )));
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
