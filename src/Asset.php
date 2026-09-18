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

use Laika\Service\Config;
use Laika\Service\MimeType;
use Laika\Service\Response as ResponseService;
use Throwable;

/**
 * Static File Server
 *
 * Laika has no public/ directory and the shipped .htaccess and nginx.conf both
 * send every request to index.php, including one that maps to a real file on
 * disk. This class is therefore the only gatekeeper the web server leaves in
 * place: it decides what may leave the disk, and it is the whole HTTP layer for
 * everything that may.
 *
 * Two independent questions decide servability: where the file resolved to, and
 * what its extension is. Path::normalize() only trims slashes, it does not
 * collapse "..", so the incoming path is untrusted -- containment is decided by
 * realpath(), never by inspecting the request string.
 *
 * Every rejection renders the same 404 an unrouted URL renders, so a forbidden
 * path is indistinguishable from a missing one.
 */
final class Asset
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

    /** @var int Seconds an extension with no configured max-age is cached for */
    private const DEFAULT_MAX_AGE = 3600;

    /** @var string[] The only methods a static file answers */
    private const ALLOWED_METHODS = ['GET', 'HEAD'];

    /** @var int Read size for a ranged response, in bytes */
    private const CHUNK_SIZE = 8192;

    /** @var ?array Resolved once per process, see rules() */
    private static ?array $rules = null;

    ##########################################################################
    /*============================ EXTERNAL API ============================*/
    ##########################################################################
    /**
     * Answer a Request From The Filesystem
     *
     * Answers only when a real file resolved. A path that maps to nothing on
     * disk is left for the router, which is what makes "/sitemap.xml",
     * "/feed.json" and "/users/john.doe" routable -- a dot in the last segment
     * used to divert every one of them here and 404.
     *
     * Deciding on file existence rather than on the extension is what keeps
     * that safe: a route can never shadow the refusal of a file that is
     * actually on disk, so template/home.twig and lf-config/app.php are still
     * refused here rather than handed to a controller.
     *
     * @param string $filePath Canonical path from Path::requestPath()
     * @return bool True when this request was answered here
     */
    public static function serve(string $filePath): bool
    {
        // A backslash is a directory separator on Windows, so "/assets\..\.." is
        // traversal there. Fold it before anything looks at the path.
        $path = str_replace('\\', '/', $filePath);

        // PHP 8 throws on a NUL byte in any path function. A smuggled NUL is a
        // refusal, not a miss, so it is answered here rather than routed --
        // Path::decodeSegments() already caught it, this is defence in depth.
        if (str_contains($path, "\0")) {
            self::refuse();
            return true;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === '') {
            return false;
        }

        // realpath() collapses ".." and resolves symlinks. This is the boundary:
        // a symlink pointing out of template/ lands on its target and is judged
        // there, not where the request said it was.
        $real = realpath(APP_PATH . '/' . ltrim($path, '/'));

        if ($real === false || !is_file($real)) {
            return false;
        }

        $relative = self::relativeToApp($real);

        if ($relative === null || !self::servableLocation($relative) || !self::servableType($ext, $relative)) {
            self::refuse();
            return true;
        }

        return self::send($real, $relative, $ext);
    }

    /**
     * Drop The Memoised Rules
     *
     * rules() resolves once per process, which is right under FPM but stale in
     * a worker that outlives a config change (laika-queue).
     *
     * @return void
     */
    public static function flushRules(): void
    {
        self::$rules = null;
    }

    ##########################################################################
    /*============================ INTERNAL API ============================*/
    ##########################################################################
    /**
     * Send a File That Has Already Been Authorised
     *
     * @param string $real Absolute path, already through realpath()
     * @param string $relative Path relative to APP_PATH
     * @param string $ext Lowercased extension
     * @return bool Always true, the request is answered either way
     */
    private static function send(string $real, string $relative, string $ext): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // OPTIONS never reaches here: CORS::handle() exits 204 first.
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            self::clearBuffers();
            self::dropContentType();
            header('Allow: ' . implode(', ', self::ALLOWED_METHODS));
            http_response_code(405);
            return true;
        }

        // Content-Length below is filesize(), so anything a boot hook echoed
        // would make the declared length a lie and truncate the body.
        self::clearBuffers();

        $size  = (int) filesize($real);
        $mtime = (int) filemtime($real);

        // Strong, not weak: the bytes are identical for a given mtime+size, and
        // only a strong validator may be used by If-Range.
        $etag     = '"' . dechex($mtime) . '-' . dechex($size) . '"';
        $lastMod  = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastMod);
        header('Cache-Control: ' . self::cacheControl($ext));
        header('Accept-Ranges: bytes');

        if (self::notModified($etag, $mtime)) {
            // A 304 carries the validators and the cache policy, never a body,
            // a Content-Type or a Content-Length.
            self::dropContentType();
            http_response_code(304);
            return true;
        }

        header('Content-Type: ' . MimeType::fromExtension($ext, true));
        header('X-Content-Type-Options: nosniff');

        // The web server can finish the transfer without pinning this worker,
        // while the authorisation decision above stays with the app.
        if (self::handOff($real, $relative)) {
            return true;
        }

        // A HEAD is answered with the same status and headers a GET would get,
        // including 206 and 416 -- that is how a client probes for range
        // support before committing to the transfer.
        $range = isset($_SERVER['HTTP_RANGE'])
            ? self::resolveRange((string) $_SERVER['HTTP_RANGE'], $size, $etag, $lastMod)
            : null;

        if ($range === false) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            return true;
        }

        if ($range !== null) {
            [$start, $end] = $range;
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . ($end - $start + 1));
            http_response_code(206);

            if ($method !== 'HEAD') {
                self::stream($real, $start, $end);
            }

            return true;
        }

        header('Content-Length: ' . $size);

        // A HEAD gets every header a GET would, and none of the bytes. The SAPI
        // discards the body anyway, but reading it costs the same either way.
        if ($method !== 'HEAD') {
            readfile($real);
        }

        return true;
    }

    /**
     * Refuse a Request
     *
     * A forbidden path must be indistinguishable from a missing one, so this
     * renders the very page Dispatcher::dispatchFallback() renders when no
     * route matched. A bare http_response_code(404) would not: an empty body
     * where every unrouted URL returns the 404 page is itself the answer to
     * "does this file exist", which is how a probe confirms composer.json or
     * lf-storage/keys/app.key is there.
     *
     * @return void
     */
    private static function refuse(): void
    {
        self::clearBuffers();
        ResponseService::setStatus(404);
        Response\Html::render(_404::show());
    }

    /**
     * Suppress The Content-Type Header
     *
     * header_remove() alone is not enough: the SAPI writes default_mimetype
     * back in when no type was set, so a bodyless 304 or 405 still went out
     * claiming text/html. Blanking the ini is what makes PHP omit it.
     *
     * @return void
     */
    private static function dropContentType(): void
    {
        header_remove('Content-Type');
        ini_set('default_mimetype', '');
    }

    /**
     * Discard Any Buffered Output
     *
     * Content-Length is taken from filesize(), so a hook that echoed during
     * boot would corrupt the response.
     *
     * @return void
     */
    private static function clearBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * Evaluate The Conditional Request Headers
     *
     * RFC 9110 order: If-None-Match wins outright when present, and
     * If-Modified-Since is consulted only in its absence.
     *
     * @param string $etag Strong entity tag, quoted
     * @param int $mtime File modification time
     * @return bool True when the client's copy is still current
     */
    private static function notModified(string $etag, int $mtime): bool
    {
        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

        if ($ifNoneMatch !== '') {
            if ($ifNoneMatch === '*') {
                return true;
            }

            foreach (explode(',', $ifNoneMatch) as $candidate) {
                // A cache is free to weaken a tag it stored, so W/"x" has to
                // compare equal to "x" here.
                $candidate = trim($candidate);
                $candidate = preg_replace('/^W\//i', '', $candidate) ?? $candidate;

                if ($candidate === $etag) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

        if ($ifModifiedSince === '') {
            return false;
        }

        $since = strtotime($ifModifiedSince);

        return $since !== false && $since >= $mtime;
    }

    /**
     * Cache-Control Header for an Extension
     *
     * Template\Asset::printStyles()/printScripts() append "?v={version}" to
     * every tag, so a request carrying a version query is already
     * content-addressed and may be cached immutably.
     *
     * @param string $ext Lowercased extension
     * @return string
     */
    private static function cacheControl(string $ext): string
    {
        $rules = self::rules();

        if (!empty($_GET['v']) && $rules['cache_versioned'] > 0) {
            return 'public, max-age=' . $rules['cache_versioned'] . ', immutable';
        }

        $maxAge = $rules['cache_max_age'][$ext] ?? $rules['cache_default'];

        // no-cache still permits a 304, so revalidation stays cheap
        return $maxAge > 0 ? 'public, max-age=' . $maxAge : 'no-cache, must-revalidate';
    }

    /**
     * Hand The Transfer Off to The Web Server
     *
     * readfile() holds a PHP worker for the whole transfer. When the deployment
     * opted in, the server is told to send the file instead and this process
     * returns immediately.
     *
     * @param string $real Absolute path
     * @param string $relative Path relative to APP_PATH
     * @return bool True when the transfer was handed off
     */
    private static function handOff(string $real, string $relative): bool
    {
        $mode = self::rules()['sendfile'];

        if ($mode === 'x-sendfile') {
            // mod_xsendfile wants an absolute path and sets the length itself
            header('X-Sendfile: ' . $real);
            return true;
        }

        if ($mode === 'x-accel-redirect') {
            // nginx wants a URI matching an "internal" location, not a path.
            // It re-resolves the range, so leave Accept-Ranges to it.
            header('X-Accel-Redirect: /' . $relative);
            return true;
        }

        return false;
    }

    /**
     * Resolve a Range Header Against The File
     *
     * Single range only. A multi-range request falls back to the full body:
     * multipart/byteranges buys nothing for the media this serves, and a client
     * that asked for several ranges always accepts a 200.
     *
     * @param string $header Raw Range header
     * @param int $size File size
     * @param string $etag Strong entity tag
     * @param string $lastMod Last-Modified value
     * @return array{0:int,1:int}|false|null Bounds, false when unsatisfiable, null to send the whole file
     */
    private static function resolveRange(string $header, int $size, string $etag, string $lastMod)
    {
        // If-Range makes the range conditional on the client's copy still being
        // current. A stale validator means it gets the whole file, not a 412.
        $ifRange = trim((string) ($_SERVER['HTTP_IF_RANGE'] ?? ''));

        if ($ifRange !== '' && $ifRange !== $etag && $ifRange !== $lastMod) {
            return null;
        }

        if (!preg_match('/^bytes\s*=\s*(.+)$/i', trim($header), $matches)) {
            return null;
        }

        $spec = trim($matches[1]);

        if (str_contains($spec, ',') || !preg_match('/^(\d*)-(\d*)$/', $spec, $bounds)) {
            return null;
        }

        [, $first, $last] = $bounds;

        if ($first === '' && $last === '') {
            return null;
        }

        // Every range over a zero-length file is unsatisfiable
        if ($size === 0) {
            return false;
        }

        // "bytes=-500" is the last 500 bytes, not a range ending at 500
        if ($first === '') {
            $length = (int) $last;

            if ($length <= 0) {
                return false;
            }

            return [max(0, $size - $length), $size - 1];
        }

        $start = (int) $first;
        $end   = $last === '' ? $size - 1 : (int) $last;

        if ($end >= $size) {
            $end = $size - 1;
        }

        if ($start > $end || $start >= $size) {
            return false;
        }

        return [$start, $end];
    }

    /**
     * Write a Byte Range to The Output
     *
     * Chunked rather than readfile() so an abandoned media scrub stops at the
     * next chunk instead of transferring the rest of the file into a closed
     * connection.
     *
     * @param string $real Absolute path
     * @param int $start First byte, inclusive
     * @param int $end Last byte, inclusive
     * @return void
     */
    private static function stream(string $real, int $start, int $end): void
    {
        $handle = fopen($real, 'rb');

        if ($handle === false) {
            return;
        }

        fseek($handle, $start);
        $remaining = $end - $start + 1;

        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, (int) min(self::CHUNK_SIZE, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $remaining -= strlen($chunk);

            if (connection_aborted()) {
                break;
            }
        }

        fclose($handle);
    }

    /**
     * Path Relative to The Application Root
     *
     * @param string $real Absolute path, already through realpath()
     * @param ?string $root Defaults to APP_PATH; a test may point it elsewhere
     * @return ?string Null when the path resolved outside the application
     */
    private static function relativeToApp(string $real, ?string $root = null): ?string
    {
        $root = realpath($root ?? APP_PATH);

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
     * Rules From lf-config/assets.php
     *
     * MimeType::register() adds a Content-Type, it does not make a type
     * servable. Only this config decides what may leave the disk.
     *
     * @return array{extensions:string[],blocked:string[],untrusted_roots:string[],untrusted_blocked:string[],cache_default:int,cache_max_age:array<string,int>,cache_versioned:int,sendfile:?string}
     */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        // Its own guard, not a class_exists() check: MimeType here is the
        // relay, which is always autoloadable, so class_exists() proves
        // nothing about whether the registry behind it exists. Only calling it
        // does. Without a mime table nothing can be given a Content-Type, so
        // nothing is servable -- an empty list is the safe reading of that,
        // not a permissive one.
        try {
            $known = array_keys(MimeType::all());
        } catch (Throwable) {
            $known = [];
        }

        try {
            $extensions = Config::get('assets', 'extensions', $known);
            $blocked    = Config::get('assets', 'blocked', self::DEFAULT_BLOCKED);
            $untrusted  = (array) (Config::get('assets', 'untrusted') ?? []);
            $cache      = (array) (Config::get('assets', 'cache') ?? []);
            $sendfile   = Config::get('assets', 'sendfile');
        } catch (Throwable) {
            // No container yet (a unit test, a CLI script that never booted).
            // Fall back to the built-in defaults rather than fatal.
            $extensions = $known;
            $blocked    = self::DEFAULT_BLOCKED;
            $untrusted  = [];
            $cache      = [];
            $sendfile   = null;
        }

        $blocked  = self::listOf($blocked);
        $sendfile = is_string($sendfile) ? strtolower(trim($sendfile)) : null;

        $maxAge = [];

        foreach ((array) ($cache['max_age'] ?? []) as $ext => $seconds) {
            $maxAge[strtolower(trim((string) $ext))] = max(0, (int) $seconds);
        }

        return self::$rules = [
            // Subtracting here means a type named in both lists is refused, so
            // 'blocked' cannot be defeated by an entry in 'extensions'.
            'extensions'        => array_values(array_diff(self::listOf($extensions), $blocked)),
            'blocked'           => $blocked,
            'untrusted_roots'   => self::listOf($untrusted['roots'] ?? self::DEFAULT_UNTRUSTED_ROOTS),
            'untrusted_blocked' => self::listOf($untrusted['blocked'] ?? self::DEFAULT_UNTRUSTED_TYPES),
            'cache_default'     => max(0, (int) ($cache['default'] ?? self::DEFAULT_MAX_AGE)),
            'cache_max_age'     => $maxAge,
            'cache_versioned'   => max(0, (int) ($cache['versioned_max_age'] ?? 0)),
            'sendfile'          => in_array($sendfile, ['x-sendfile', 'x-accel-redirect'], true) ? $sendfile : null,
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
}
