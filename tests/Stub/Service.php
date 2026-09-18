<?php
/**
 * Test doubles for the Laika\Service relays.
 *
 * laika-route imports Laika\Service\{Config,MimeType} but cannot depend on the
 * packages that define them: laika-core requires laika-route, so a dev
 * dependency the other way would be a cycle. These stubs stand in for them so
 * the suite runs against nothing but this package.
 */

declare(strict_types=1);

namespace Laika\Service;

use RuntimeException;

class Config
{
    /** @var array Config files the test has set up */
    public static array $data = [];

    /** @var bool False reproduces a process where the container never booted */
    public static bool $booted = true;

    public static function reset(): void
    {
        static::$data = [];
        static::$booted = true;
    }

    public static function get(string $name, ?string $key = null, mixed $default = null): mixed
    {
        if (!static::$booted) {
            throw new RuntimeException('Container not booted');
        }

        $config = static::$data[$name] ?? null;

        if ($config === null) {
            return $default;
        }

        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }
}

class MimeType
{
    /**
     * @var bool False makes every call throw the way a relay does before the
     * registry exists. class_exists() cannot detect that -- a relay is always
     * autoloadable -- which is exactly the case Asset::rules() has to survive.
     */
    public static bool $available = true;

    /** @var array<string,string> A small stand-in for the real table */
    private static array $types = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'map'  => 'application/json',
        'json' => 'application/json',
        'txt'  => 'text/plain',
        'html' => 'text/html',
        'xml'  => 'text/xml',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'webm' => 'video/webm',
        'php'  => 'text/x-php',
        'twig' => 'text/plain',
    ];

    private const CHARSET_TYPES = ['css', 'js', 'map', 'json', 'txt', 'html', 'xml', 'svg'];

    public static function all(): array
    {
        static::guard();

        return static::$types;
    }

    public static function fromExtension(string $extension, bool $withCharset = false): string
    {
        static::guard();

        $extension = strtolower($extension);
        $type = static::$types[$extension] ?? 'application/octet-stream';

        if ($withCharset && in_array($extension, static::CHARSET_TYPES, true)) {
            $type .= '; charset=utf-8';
        }

        return $type;
    }

    /** Mirrors Laika\Relay\Exceptions\RelayException reaching the caller */
    private static function guard(): void
    {
        if (!static::$available) {
            throw new RuntimeException('RelayRegistry has not been set.');
        }
    }
}

class CORS
{
    /** @var int Times handle() was called, to prove it runs once per request */
    public static int $handled = 0;

    public static function handle(): void
    {
        static::$handled++;
    }
}
