<?php

declare(strict_types=1);

namespace Laika\Route\Tests\Unit;

use Laika\Route\Asset;
use Laika\Route\Dispatcher;
use Laika\Service\Config;
use Laika\Service\CORS;
use Laika\Service\MimeType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The servability decision is the security boundary of the whole framework:
 * Laika has no public/ directory, so every file on disk is one request away
 * from being served. These cover the predicates that decide it, plus the HTTP
 * layer built on top.
 */
final class AssetTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
        MimeType::$available = true;
        Asset::flushRules();

        foreach (['HTTP_IF_NONE_MATCH', 'HTTP_IF_MODIFIED_SINCE', 'HTTP_IF_RANGE', 'HTTP_RANGE'] as $key) {
            unset($_SERVER[$key]);
        }

        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        Config::reset();
        MimeType::$available = true;
        Asset::flushRules();
    }

    /** Reach a private static; the class exposes only serve() by design */
    private static function call(string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod(Asset::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    private static function configure(array $assets): void
    {
        Config::$data['assets'] = $assets;
        Asset::flushRules();
    }

    /*======================== CONFIG NORMALISATION ========================*/

    public function testListOfLowercasesTrimsAndDeduplicates(): void
    {
        self::assertSame(
            ['css', 'js'],
            self::call('listOf', [[' CSS ', 'css', 'Js', '', '   ']])
        );
    }

    public function testBlockedCannotBeDefeatedByAnEntryInExtensions(): void
    {
        self::configure(['extensions' => ['css', 'php'], 'blocked' => ['php']]);

        $rules = self::call('rules');

        self::assertSame(['css'], $rules['extensions']);
        self::assertContains('php', $rules['blocked']);
    }

    public function testUnbootedContainerFallsBackToTheBuiltInDefaults(): void
    {
        Config::$booted = false;
        Asset::flushRules();

        $rules = self::call('rules');

        // The php family must be refused even with no config to read
        self::assertContains('php', $rules['blocked']);
        self::assertContains('svg', $rules['blocked']);
        self::assertSame(['uploads'], $rules['untrusted_roots']);
    }

    public function testRulesSurviveAMimeTableThatCannotBeResolved(): void
    {
        // The relay is always autoloadable, so class_exists() returns true even
        // with no registry behind it and MimeType::all() throws. Guarding the
        // call with class_exists() left that throw outside the try, which
        // defeated the whole "fall back rather than fatal" contract.
        MimeType::$available = false;
        Config::$booted = false;
        Asset::flushRules();

        $rules = self::call('rules');

        // No mime table means nothing is servable, but the process survives
        self::assertSame([], $rules['extensions']);
        self::assertContains('php', $rules['blocked']);
    }

    public function testConfiguredExtensionsStillApplyWithoutAMimeTable(): void
    {
        // The table is only the *default* for 'extensions'; an explicit list
        // must not be lost just because the default could not be computed.
        MimeType::$available = false;
        self::configure(['extensions' => ['css'], 'blocked' => []]);

        self::assertSame(['css'], self::call('rules')['extensions']);
    }

    public function testSendfileOnlyAcceptsTheTwoKnownModes(): void
    {
        self::configure(['sendfile' => 'X-Accel-Redirect']);
        self::assertSame('x-accel-redirect', self::call('rules')['sendfile']);

        self::configure(['sendfile' => 'something else']);
        self::assertNull(self::call('rules')['sendfile']);
    }

    /*============================= LOCATION ==============================*/

    /** @dataProvider forbiddenLocations */
    public function testServableLocationRefusesFrameworkInternals(string $relative): void
    {
        self::assertFalse(self::call('servableLocation', [$relative]));
    }

    public static function forbiddenLocations(): array
    {
        return [
            'config'          => ['lf-config/app.php'],
            'storage'         => ['lf-storage/keys/app.key'],
            'logs'            => ['lf-logs/error.log'],
            'vendor'          => ['vendor/autoload.php'],
            'docs'            => ['docs/readme.txt'],
            'uppercase root'  => ['LF-CONFIG/app.php'],
            'dot root'        => ['.env'],
            'dot directory'   => ['.git/config'],
            'nested dot file' => ['assets/css/.secret.css'],
        ];
    }

    /** @dataProvider allowedLocations */
    public function testServableLocationAllowsOrdinaryRoots(string $relative): void
    {
        self::assertTrue(self::call('servableLocation', [$relative]));
    }

    public static function allowedLocations(): array
    {
        return [
            'assets'   => ['assets/css/site.css'],
            'template' => ['template/assets/css/style.css'],
            'uploads'  => ['uploads/note.txt'],
        ];
    }

    public function testRelativeToAppRefusesAPathOutsideTheRoot(): void
    {
        $outside = realpath(__DIR__ . '/../fixtures/outside/escape.css');

        self::assertNotFalse($outside);
        self::assertNull(self::call('relativeToApp', [$outside, APP_PATH]));
    }

    public function testRelativeToAppReturnsThePathBeneathTheRoot(): void
    {
        $inside = realpath(APP_PATH . '/assets/css/site.css');

        self::assertSame('assets/css/site.css', self::call('relativeToApp', [$inside, APP_PATH]));
    }

    /*=============================== TYPE ================================*/

    public function testUnlistedExtensionIsRefusedRatherThanSentAsOctetStream(): void
    {
        self::configure(['extensions' => ['css'], 'blocked' => []]);

        self::assertFalse(self::call('servableType', ['twig', 'template/home.twig']));
        self::assertTrue(self::call('servableType', ['css', 'assets/css/site.css']));
    }

    public function testMarkupIsRefusedInsideAnUntrustedRootButServedElsewhere(): void
    {
        self::configure([
            'extensions' => ['svg', 'css'],
            'blocked'    => [],
            'untrusted'  => ['roots' => ['uploads'], 'blocked' => ['svg']],
        ]);

        self::assertFalse(self::call('servableType', ['svg', 'uploads/evil.svg']));
        self::assertTrue(self::call('servableType', ['svg', 'assets/css/ok.svg']));
    }

    /*========================== CACHE POLICY =============================*/

    public function testPerExtensionMaxAgeOverridesTheDefault(): void
    {
        self::configure(['cache' => ['default' => 60, 'max_age' => ['css' => 604800]]]);

        self::assertSame('public, max-age=604800', self::call('cacheControl', ['css']));
        self::assertSame('public, max-age=60', self::call('cacheControl', ['png']));
    }

    public function testZeroMaxAgeStillPermitsRevalidation(): void
    {
        self::configure(['cache' => ['max_age' => ['map' => 0]]]);

        self::assertSame('no-cache, must-revalidate', self::call('cacheControl', ['map']));
    }

    public function testAVersionedUrlIsCachedImmutably(): void
    {
        self::configure(['cache' => ['default' => 60, 'versioned_max_age' => 31536000]]);
        $_GET['v'] = '1.0.0';

        try {
            self::assertSame('public, max-age=31536000, immutable', self::call('cacheControl', ['css']));
        } finally {
            unset($_GET['v']);
        }
    }

    /*======================= CONDITIONAL REQUESTS ========================*/

    public function testIfNoneMatchRecognisesTheTagInEveryAcceptedForm(): void
    {
        $etag = '"abc-1"';

        foreach ([$etag, 'W/' . $etag, '*', '"other", ' . $etag] as $header) {
            $_SERVER['HTTP_IF_NONE_MATCH'] = $header;
            self::assertTrue(self::call('notModified', [$etag, 1000]), $header);
        }
    }

    public function testAStaleTagIsModified(): void
    {
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"deadbeef-1"';

        self::assertFalse(self::call('notModified', ['"abc-1"', 1000]));
    }

    public function testIfNoneMatchWinsOverIfModifiedSince(): void
    {
        // RFC 9110: a present If-None-Match settles it, even when the date says
        // otherwise. Consulting the date as well would 304 on stale bytes.
        $_SERVER['HTTP_IF_NONE_MATCH']     = '"deadbeef-1"';
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', 2000) . ' GMT';

        self::assertFalse(self::call('notModified', ['"abc-1"', 1000]));
    }

    public function testIfModifiedSinceComparesAgainstTheModificationTime(): void
    {
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', 1000) . ' GMT';
        self::assertTrue(self::call('notModified', ['"abc-1"', 1000]));

        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', 999) . ' GMT';
        self::assertFalse(self::call('notModified', ['"abc-1"', 1000]));
    }

    public function testAnUnparseableDateIsNotTreatedAsCurrent(): void
    {
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = 'not a date';

        self::assertFalse(self::call('notModified', ['"abc-1"', 1000]));
    }

    /*============================= RANGES ================================*/

    /** @dataProvider ranges */
    public function testResolveRange(string $header, int $size, array|false|null $expected): void
    {
        self::assertSame(
            $expected,
            self::call('resolveRange', [$header, $size, '"t"', 'Mon, 01 Jan 2024 00:00:00 GMT'])
        );
    }

    public static function ranges(): array
    {
        return [
            'closed'              => ['bytes=0-99',      1000, [0, 99]],
            'open ended'          => ['bytes=500-',      1000, [500, 999]],
            'suffix'              => ['bytes=-500',      1000, [500, 999]],
            'suffix over length'  => ['bytes=-5000',     1000, [0, 999]],
            'end past eof clamps' => ['bytes=900-9999',  1000, [900, 999]],
            'whole file'          => ['bytes=0-999',     1000, [0, 999]],
            'start at eof'        => ['bytes=1000-',     1000, false],
            'start beyond eof'    => ['bytes=9999-',     1000, false],
            'inverted'            => ['bytes=500-100',   1000, false],
            'zero length suffix'  => ['bytes=-0',        1000, false],
            'empty file'          => ['bytes=0-10',         0, false],
            'multi range'         => ['bytes=0-9,20-29', 1000, null],
            'unknown unit'        => ['items=0-9',       1000, null],
            'no numbers'          => ['bytes=-',         1000, null],
            'garbage'             => ['nonsense',        1000, null],
        ];
    }

    public function testAStaleIfRangeSendsTheWholeFile(): void
    {
        $_SERVER['HTTP_IF_RANGE'] = '"stale"';

        self::assertNull(
            self::call('resolveRange', ['bytes=0-99', 1000, '"t"', 'Mon, 01 Jan 2024 00:00:00 GMT'])
        );
    }

    public function testAMatchingIfRangeKeepsTheRange(): void
    {
        $_SERVER['HTTP_IF_RANGE'] = '"t"';
        self::assertSame(
            [0, 99],
            self::call('resolveRange', ['bytes=0-99', 1000, '"t"', 'Mon, 01 Jan 2024 00:00:00 GMT'])
        );

        $_SERVER['HTTP_IF_RANGE'] = 'Mon, 01 Jan 2024 00:00:00 GMT';
        self::assertSame(
            [0, 99],
            self::call('resolveRange', ['bytes=0-99', 1000, '"t"', 'Mon, 01 Jan 2024 00:00:00 GMT'])
        );
    }

    /*========================== FALL THROUGH =============================*/

    public function testAPathWithNoExtensionIsLeftToTheRouter(): void
    {
        self::assertFalse(Asset::serve('/users/profile'));
    }

    public function testAPathWithNoFileOnDiskIsLeftToTheRouter(): void
    {
        // The point of the fall-through: these are routable URLs, not assets
        self::assertFalse(Asset::serve('/sitemap.xml'));
        self::assertFalse(Asset::serve('/feed.json'));
        self::assertFalse(Asset::serve('/users/john.doe'));
        self::assertFalse(Asset::serve('/assets/css/absent.css'));
    }

    public function testDispatchAssetLeavesANonFileToTheRouter(): void
    {
        // What lets lf-boot call this before the function and hook files load:
        // a path with no file behind it must come back false so the boot
        // carries on into the full dispatch.
        $_SERVER['REQUEST_URI'] = '/sitemap.xml';

        self::assertFalse(Dispatcher::dispatchAsset());
    }

    public function testDispatchAssetSendsCorsHeadersOnlyOnce(): void
    {
        // CORS::handle() answers an OPTIONS preflight with 204 and exits, so
        // it has to run before the asset is served -- and exactly once, since
        // dispatch() would otherwise repeat it after the early call.
        $_SERVER['REQUEST_URI'] = '/sitemap.xml';
        CORS::$handled = 0;
        Dispatcher::flushHeaders();

        Dispatcher::dispatchAsset();
        Dispatcher::dispatchAsset();

        self::assertSame(1, CORS::$handled);
    }

    public function testFlushHeadersLetsTheNextRequestSendThemAgain(): void
    {
        // A host that serves many requests from one process would otherwise
        // send CORS and the security headers only for the first of them.
        $_SERVER['REQUEST_URI'] = '/sitemap.xml';
        CORS::$handled = 0;
        Dispatcher::flushHeaders();

        Dispatcher::dispatchAsset();
        Dispatcher::flushHeaders();
        Dispatcher::dispatchAsset();

        self::assertSame(2, CORS::$handled);
    }

    public function testAFileOutsideTheRootIsNeverReportedAsRelative(): void
    {
        // escape.css exists, but outside APP_PATH, so containment must fail
        // before anything decides whether its extension is servable.
        self::assertNull(self::call('relativeToApp', [
            realpath(APP_PATH . '/../outside/escape.css'),
            APP_PATH,
        ]));
    }
}
