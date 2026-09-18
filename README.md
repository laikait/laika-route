# laika-route

The router of the [Laika PHP MVC Framework](https://github.com/laikait/laika-framework): routes, named URLs, groups, pipelines (middleware before the controller), filters (middleware after it), fallbacks and static-file serving.

## Install

```bash
composer require laikait/laika-route
```

laika-core requires it, so a Laika app already has it. Registering routes needs only PHP 8.1+.

`Url::dispatch()` and `Asset::serve()` additionally call the `Laika\Service\*` relays
(`Response`, `CORS`, `Config`, `Infra`, `MimeType`), which this package deliberately does
**not** require: laika-core requires laika-route, so depending on it here would be a
cycle. They are resolved at runtime inside a booted Laika app, and the test suite stands
in its own doubles for them. Outside an app, route registration and the path helpers work
on their own; dispatching does not.

In an app, routes live in `lf-routes/*.php`. Every file there, subdirectories included, is loaded when a request is dispatched.

## Routes

```php
use Laika\Route\Url;

Url::get('/users', 'UserController@index')->name('users.index');
Url::post('/users', 'UserController@store');
Url::put('/users/{id}', 'UserController@update');
Url::patch('/users/{id}', 'UserController@patch');
Url::delete('/users/{id}', 'UserController@destroy');
Url::options('/users', 'UserController@options');
```

Every verb has the same signature: `Url::get(string $uri, mixed $controller, string|array $pipelines = []): Url`. There's no `any()`, `match()`, `head()` or `redirect()`.

- Registering the same method and URI twice replaces the first handler.
- `/users/` and `/users` are the same route.
- Routes are tried in registration order; the first match wins. Register `/users/new` before `/users/{id}`.

### Handlers

| Form | Resolves to |
|---|---|
| `'UserController@show'` | `App\Controller\UserController::show()` |
| `'Admin\UserController@show'` | `App\Controller\Admin\UserController::show()` |
| `'\Acme\Blog\PostController@show'` | Leading `\`: used as written |
| `'InvokableController'` | `App\Controller\InvokableController::__invoke()` |
| `[UserController::class, 'show']` | A class/method pair |
| `function () { ... }` | A closure |

A string handler **always** gets `App\Controller\` prepended unless it starts with `\`, so `'App\Controller\HomeController@index'` fails.

A handler returns a **string**, which is sent, or `null`, which sends nothing. Arrays and objects are a `TypeError`. The response is rendered according to the content type set on laika-core's `Response` (HTML by default).

Controllers, pipelines and filters are built through a resolver. laika-core sets it to the service container, which gives them constructor injection. Without a resolver, the router uses a plain `new`:

```php
use Laika\Route\Invoke;

Invoke::setResolver(fn (string $class): object => $container->make($class));
```

Controller **method** arguments are filled by name from route parameters and pipeline values, then by type from the resolver, then from defaults.

### Parameters

```php
Url::get('/users/{id}', 'UserController@show');
Url::get('/users/{id:[0-9]+}', 'UserController@show');  // regex constraint
Url::get('/files/{path:.+}', 'FileController@show');    // catch-all, slashes included
```

- `{name}` matches one path segment. `{name:regex}` uses your pattern, matched with the `u` flag.
- A constraint can't contain `}`, so `{year:[0-9]{4}}` breaks. Write `[0-9][0-9][0-9][0-9]`.
- There are no optional parameters. Register two routes.
- Values reach the controller as **strings**. `int $id` is a `TypeError` under `strict_types`.
- Routes may contain any UTF-8 character. Request paths are percent-decoded per segment before matching.

**A path with a file extension is a static file only when a real file is there.** Otherwise it routes like any other URL, so `/sitemap.xml`, `/feed.json` and `/users/john.doe` all work. A file that exists always wins over a route for the same path — and if `lf-config/assets.php` refuses that file, the request is refused rather than routed. See [Static Files](#static-files).

## Named Routes

```php
Url::get('/users/{id}', 'UserController@show')->name('users.show');

Url::url('users.show', ['id' => 5]);   // "/users/5", a path only
```

Names are unique across the app; registering one twice throws `RuntimeException`. A placeholder you don't pass stays in the URL, and extra params are ignored. In a Laika app, the `named()` helper returns the absolute URL, including any sub-directory.

## Groups

```php
Url::group('admin', function () {
    Url::get('/dashboard', 'Admin\DashboardController@index');      // /admin/dashboard

    Url::group('billing', function () {
        Url::get('/invoices', 'Admin\Billing\InvoiceController@index'); // /admin/billing/invoices
    });
});
```

To put middleware on a group, pass it to `Handler::registerGroup()`. It applies to every route inside, including nested groups:

```php
use Laika\Route\Handler;

Handler::registerGroup('admin', function () {
    Url::get('/dashboard', 'Admin\DashboardController@index');

    Handler::registerGroup('billing', function () {
        Url::get('/invoices', 'Admin\Billing\InvoiceController@index');
    }, ['Permission|perm=billing.view']);

}, ['Authenticate'], ['LogAccess']);
// /admin/billing/invoices: Authenticate → Permission → controller → LogAccess
```

Signature: `Handler::registerGroup(string $prefix, callable $callback, string|array $pipelines = [], string|array $filters = []): void`.

`Url::group(...)->pipeline([...])->filter([...])` also works, for a **top-level** group only. It runs after the callback and appends to every route registered so far whose URI starts with `/prefix`. On a nested group it matches nothing, because it only knows its own prefix.

## Pipelines

Middleware that runs before the controller.

```php
Url::get('/dashboard', 'DashboardController@index')->pipeline('Authenticate');
Url::get('/admin', 'AdminController@index')->pipeline(['Authenticate', 'Role|role=admin']);
Url::get('/admin', 'AdminController@index', ['Authenticate']);   // third argument

Url::globalPipeline([\Laika\Shield\Pipeline\ShieldPipeline::class]);  // every matched route
```

```php
namespace App\Pipeline;

use Laika\Route\Contracts\PipelineInterface;
use Laika\Service\Response;

class Role implements PipelineInterface
{
    public function handle(callable $next, array &$params): ?string
    {
        if (($_SESSION['role'] ?? null) !== ($params['role'] ?? null)) {
            Response::setStatus(403);
            return 'Forbidden';
        }

        return $next();
    }
}
```

| In `handle()` you... | Remaining pipelines | Controller | Filters |
|---|---|---|---|
| `return $next();` | run | runs | run |
| `return $next(false);` | skipped | **runs** | run |
| `return 'text';` | skipped | skipped | run, on `'text'` |
| `return null;` | skipped | skipped | run; nothing is sent |

- Short names resolve to `App\Pipeline\`; a fully qualified name is used as written.
- `$params` holds route parameters plus pipeline args, passed **by reference** through pipelines, controller and filters.
- Args (`'Name|key=value,flag'`) are strings (a bare key is `true`), aren't trimmed, and override a route parameter of the same name.
- Order: global → `registerGroup()` → third argument → `->pipeline()` → group-chained `->pipeline()`.

## Filters

Middleware that runs after the controller, on its response string.

```php
Url::get('/orders', 'OrderController@index')->filter(['LogAccess|level=info']);
Url::globalFilter(['LogResponse']);
```

```php
namespace App\Filter;

use Laika\Route\Contracts\FilterInterface;

class LogAccess implements FilterInterface
{
    public function terminate(callable $next, ?string $response, array &$params): ?string
    {
        error_log('level=' . ($params['level'] ?? 'default'));

        return $next($response);
    }
}
```

| In `terminate()` you... | Remaining filters | Sent |
|---|---|---|
| `return $next($response);` | run | the response |
| `return $next($response, false);` | skipped | the response |
| `return $next('other');` | run | `'other'` |
| `return 'other';` | skipped | `'other'` |

Short names resolve to `App\Filter\`. Filter args are visible to later filters only, never to the controller. Filters don't run on fallbacks, 404s or static files.

## Fallback

```php
Url::fallback('admin', fn () => '<h1>Admin page not found</h1>');   // under /admin
Url::fallback(null, function () {                                   // everything else
    \Laika\Service\Response::setStatus(404);
    return '<h1>Page not found</h1>';
});
```

`Url::fallback(?string $group, callable $callback, string|array $pipelines = []): void`

- The longest matching prefix wins. With no route and no fallback, the built-in `_404` page is sent with status 404.
- The prefix is absolute, even when `fallback()` is called inside a group.
- The callback takes no arguments and returns a string, sent as HTML. **The status stays 200** unless you set it.
- Global pipelines and filters don't run; pass pipelines as the third argument.

## Dispatch

```php
Url::dispatch();
```

`index.php` calls it once per request. In order, it:

1. sends the CORS headers (once per request);
2. normalises the path;
3. serves a static file if one is on disk (see [Static Files](#static-files)) — usually already done, see below;
4. loads the route files;
5. matches a route or a fallback;
6. runs the pipelines and the controller;
7. runs the filters;
8. renders the result by content type (HTML responses get a hidden `_csrf` field added to every `<form>`).

## Static Files

Laika has no `public/` directory and the shipped rewrite rules send every request to
`index.php`, so `Laika\Route\Asset` is the only thing deciding what may leave the disk.
`Asset::serve()` returns `true` when it answered the request and `false` when no file was
there, which is what lets the dispatcher fall through to the router.

`Dispatcher::dispatchAsset()` is the same check as a standalone entry point, so an
application can answer a static file early in its boot and skip loading everything a
static file does not need. Laika's own `lf-boot/app.php` calls it right after the
autoloader. The only caveat is that anything set up later — a hook registering a mime
type, say — has not run yet.

Two independent questions decide servability — where the path resolved to (`realpath()`,
never the request string) and what its extension is — and both are covered by the test
suite. A refusal renders the same 404 an unrouted URL renders, so a forbidden path cannot
be told apart from a missing one.

What it sends:

| | |
|---|---|
| `ETag` / `Last-Modified` | `If-None-Match` and `If-Modified-Since` are answered `304` |
| `Cache-Control` | per-extension `max_age`; `immutable` for a URL carrying `?v=` |
| `Accept-Ranges` / `Content-Range` | single-range `206`, `416` when unsatisfiable, `If-Range` honoured |
| `Content-Type` | from `MimeType`, with `charset=utf-8` on text types |
| `Allow` | `GET` and `HEAD` only; anything else is `405` |

In an app this is configured by [`lf-config/assets.php`](https://github.com/laikait/laika-framework/blob/main/docs/01_getting-started/03_configuration.md#lf-configassetsphp)
(`extensions`, `blocked`, `untrusted`, `cache`, `sendfile`). With no container to read it
from, built-in defaults apply: the php family and every markup type are refused.

## API Reference

| `Laika\Route\Url` | |
|---|---|
| `static get/post/put/patch/delete/options(string $uri, mixed $controller, string\|array $pipelines = []): self` | Register a route |
| `name(string $name): self` | Name the last route |
| `pipeline(string\|array $pipelines): self` | Append pipelines to the last route (or top-level group) |
| `filter(string\|array $filters): self` | Append filters to the last route (or top-level group) |
| `static group(string $prefix, callable $callback): self` | Prefix routes registered inside |
| `static globalPipeline(string\|array $pipelines): void` | Pipelines for every matched route |
| `static globalFilter(string\|array $filters): void` | Filters for every matched route |
| `static fallback(?string $group, callable $callback, string\|array $pipelines = []): void` | Handler for unmatched URLs |
| `static url(string $name, array $params = []): string` | Path of a named route |
| `static dispatch(): void` | Handle the current request |

| `Laika\Route\Dispatcher` | |
|---|---|
| `static dispatch(): void` | The request lifecycle |
| `static dispatchAsset(): bool` | Answer the request if it is a static file; `false` leaves it to the router. Call it from the boot to serve a file without loading the rest of the framework |
| `static flushHeaders(): void` | Forget that CORS ran, for a host serving many requests from one process |

| Class | Purpose |
|---|---|
| `Handler` | Route registry: `registerGroup()`, `getRoutes()`, `getNamedRoutes()`, ... |
| `Dispatcher` | The request lifecycle above |
| `Asset` | Static-file server: `serve()`, `flushRules()` |
| `Invoke` | Runs pipeline and filter chains, resolves controllers; `setResolver()` |
| `Reflection` | Fills controller arguments by name and type |
| `Path` | Path normalisation, pattern compiling, matching |
| `_404` | The built-in 404 page |
| `Contracts\PipelineInterface` | `handle(callable $next, array &$params): ?string` |
| `Contracts\FilterInterface` | `terminate(callable $next, ?string $response, array &$params): ?string` |
| `Exceptions\ControllerException`, `PipelineException`, `FilterException` | An unresolvable controller, pipeline or filter (status 500) |

## Documentation

The framework docs cover this package in depth: [Routing](https://github.com/laikait/laika-framework/blob/main/docs/02_routing/01_basic.md), [Controllers](https://github.com/laikait/laika-framework/blob/main/docs/02_routing/02_controllers.md), [Pipelines](https://github.com/laikait/laika-framework/blob/main/docs/03_pipeline/01_basic.md), [Filters](https://github.com/laikait/laika-framework/blob/main/docs/04_filter/01_basic.md) and [Request Lifecycle](https://github.com/laikait/laika-framework/blob/main/docs/01_getting-started/05_request-lifecycle.md).

## License

MIT. See [LICENSE](LICENSE).
