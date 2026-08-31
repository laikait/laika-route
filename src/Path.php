<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Route;

class Path
{
    /**
     * @var string Placeholder shape for splitting. Exactly one capturing group:
     * PREG_SPLIT_DELIM_CAPTURE hands back every group, so an inner capture here
     * would emit the bare parameter name as a literal part of its own.
     */
    private const PLACEHOLDER_SPLIT = '(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^}]+)?\})';

    /** @var string Same shape, capturing the name and the optional constraint */
    private const PLACEHOLDER_PARTS = '\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}';

    public static function normalize(string $uri): string
    {
        $uri = '/' . trim($uri, '/');
        return $uri === '//' ? '/' : $uri;
    }

    public static function normalizeFallbackKey(?string $group): string
    {
        if ($group === null || $group === '') {
            return '/';
        }
        return '/' . trim($group, '/') . '/';
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Decode a Path Segment by Segment
     *
     * REQUEST_URI always arrives percent-encoded, so "/বাংলা" reaches PHP as
     * "/%E0%A6%AC%E0%A6%BE...". Route keys are stored as the author wrote them,
     * so both sides have to be brought into the same decoded space or a
     * non-ASCII route can never match.
     *
     * Decoding happens per segment, never on the joined path: %2F inside a
     * parameter would otherwise decode into a real separator and change the
     * segment count, which is a routing-bypass class. A segment that still
     * holds a separator after decoding means the request encoded one, and the
     * caller is told to refuse it.
     *
     * rawurldecode, not urldecode: "+" is a literal plus in a path, not a space.
     *
     * @param string $path Normalized path
     * @return ?string Null when the request encoded a separator or is not UTF-8
     */
    protected static function decodeSegments(string $path): ?string
    {
        $segments = explode('/', $path);

        foreach ($segments as $index => $segment) {
            $segment = rawurldecode($segment);

            // A decoded separator can only come from %2F/%5C, and a NUL byte
            // makes every PHP path function throw
            if (strpbrk($segment, "/\\\0") !== false) {
                return null;
            }

            $segments[$index] = $segment;
        }

        $path = implode('/', $segments);

        // compilePattern() emits /u patterns, and preg_match returns false
        // rather than 0 on a malformed subject. Refuse it here instead, so the
        // asset handler and the fallback see the same verdict as the matcher.
        return preg_match('//u', $path) === 1 ? $path : null;
    }

    /**
     * Canonical Path for The Current Request
     *
     * The single source of truth for the matcher, the asset handler and the
     * fallback. They each used to derive it separately, so a decode applied to
     * one of them would have left the others judging a different URI.
     *
     * @param ?string $requestUri Defaults to the current request
     * @return ?string Null when the request must be refused, see decodeSegments()
     */
    public static function requestPath(?string $requestUri = null): ?string
    {
        $requestUri = $requestUri ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $path = static::normalize(static::stripBasePath(parse_url($requestUri, PHP_URL_PATH) ?? '/'));

        return static::decodeSegments($path);
    }

    /**
     * Decode a Registered Route URI
     *
     * Route keys live in the same decoded space as the request, so an author
     * who pastes an already-encoded URI into web.php still matches. Parameter
     * placeholders hold no percent sign and pass through untouched.
     *
     * @param string $uri URI as written by the author
     * @return string
     */
    public static function normalizeRouteUri(string $uri): string
    {
        $uri = static::normalize($uri);

        return static::decodeSegments($uri) ?? $uri;
    }

    /**
     * Match a Canonical Path Against The Registered Routes
     *
     * Takes the path already produced by requestPath(), never a raw
     * REQUEST_URI. Decoding here as well would decode twice, and "/%2525"
     * would collapse to "/%" over two passes.
     *
     * @param string $requestUri Canonical path from requestPath()
     * @return array{route:?array,params:array}
     */
    public static function matchRequestRoute(string $requestUri): array
    {
        $method = static::method();
        $routes = Handler::getOnlyRoutes($method);

        foreach ($routes as $uri => $route) {
            $pattern = static::compilePattern($uri);

            if (preg_match($pattern, $requestUri, $matches)) {
                $params = array_filter(
                    $matches,
                    fn($key) => !is_int($key),
                    ARRAY_FILTER_USE_KEY
                );

                return ['route' => $route, 'params' => $params];
            }
        }

        return ['route' => null, 'params' => []];
    }

    public static function stripBasePath(string $path): string
    {
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Compile a Route URI Into a Pattern
     *
     * The literal parts are quoted and the placeholders are not, so only the
     * author's own constraint is ever treated as regex. Interpolating the URI
     * whole used to make "/price.list" match "/priceXlist", and a "#" anywhere
     * in a route closed the delimiter early and broke preg_match outright.
     *
     * The u flag makes a constraint like {slug:\w+} count characters rather
     * than bytes, which is what a non-ASCII slug needs. preg_quote only escapes
     * ASCII metacharacters, so it leaves UTF-8 literals intact.
     *
     * @param string $uri Registered route URI, already decoded
     * @return string
     */
    protected static function compilePattern(string $uri): string
    {
        $parts = preg_split(
            '#' . static::PLACEHOLDER_SPLIT . '#',
            $uri,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $pattern = '';

        foreach ($parts as $part) {
            if (preg_match('#^' . static::PLACEHOLDER_PARTS . '$#', $part, $m)) {
                $regex = ($m[2] ?? '') !== '' ? $m[2] : '[^/]+';
                $pattern .= "(?P<{$m[1]}>{$regex})";
                continue;
            }

            $pattern .= preg_quote($part, '#');
        }

        return '#^' . $pattern . '$#u';
    }

    public static function loadRoutes(?string $path = null): void
    {
        $path = $path ?? APP_PATH . '/lf-routes';
        foreach (glob(rtrim($path, '/') . '/*.php') as $file) {
            require_once $file;
        }
    }
}
