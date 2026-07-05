# laika-route

Routing package for the Laika PHP MVC Framework.

## Install

```bash
composer require laikait/laika-route
```

## Methods

```php
use Laika\Route\Http;

Http::get('/users', 'UserController@index');
Http::post('/users', 'UserController@store');
Http::put('/users/{id}', 'UserController@update');
Http::patch('/users/{id}', 'UserController@patch');
Http::delete('/users/{id}', 'UserController@destroy');
Http::options('/users', 'UserController@options');
```

## Route Params

```php
Http::get('/users/{id}', 'UserController@show');
Http::get('/users/{id:\d+}', 'UserController@show'); // regex constraint
```

## Middleware

```php
// Single
Http::get('/dashboard', 'DashboardController@index')->middleware(['Auth']);

// Multiple
Http::get('/orders', 'OrderController@index')->middleware(['Auth', 'VerifiedEmail']);

// With args
Http::get('/admin', 'AdminController@index')->middleware(['Role|role=admin']);

// Multiple args
Http::get('/reports', 'ReportController@index')->middleware(['Throttle|limit=60,window=60']);

// Global (applies to all routes)
Http::globalMiddleware(['Csrf', 'Cors']);
```

Class:

```php
namespace App\Middleware;

use Laika\Route\MiddlewareInterface;

class Role implements MiddlewareInterface
{
    public function handle(array $params, callable $next)
    {
        if (($_SESSION['role'] ?? null) !== ($params['role'] ?? null)) {
            http_response_code(403);
            return 'Forbidden'; // stops chain
        }
        return $next(); // continues chain
    }
}
```

- `$params` — route params + middleware config args (`role=admin`), merged, passed by reference through the whole chain (middleware → controller → afterware).
- Return value short-circuits the response if not `$next()`.

## Afterware

```php
// Single
Http::get('/orders', 'OrderController@index')->afterware(['LogAccess']);

// Multiple
Http::get('/orders', 'OrderController@index')->afterware(['LogAccess', 'CacheResponse']);

// With args
Http::get('/reports', 'ReportController@index')->afterware(['LogAccess|level=info']);

// Global
Http::globalAfterware(['LogResponse']);
```

Class:

```php
namespace App\Afterware;

use Laika\Route\AfterwareInterface;

class LogAccess implements AfterwareInterface
{
    public function terminate(array $params, $response): void
    {
        error_log('level=' . ($params['level'] ?? 'default'));
    }
}
```

## Named Routes

```php
Http::get('/users/{id}', 'UserController@show')->name('users.show');

$url = Http::url('users.show', ['id' => 5]); // /users/5
```

## Groups

```php
// Basic
Http::group('admin', function () {
    Http::get('/dashboard', 'Admin\DashboardController@index');
});

// Nested (inherits parent's middleware/afterware)
Http::group('admin', function () {
    Http::group('billing', function () {
        Http::get('/invoices', 'Admin\Billing\InvoiceController@index');
    })->middleware(['Permission|perm=billing.view']);
})->middleware(['Auth']);

// Chained middleware + afterware (applied retroactively to all routes in group)
Http::group('api', function () {
    Http::post('/payments', 'PaymentController@store')->middleware(['ApiKey']);
})->middleware(['Cors'])->afterware(['LogApi']);
```

## Fallback

```php
// Per group prefix
Http::fallback('admin', fn() => '<h1>Admin route not found</h1>');

// Default (no prefix)
Http::fallback(null, fn() => '<h1>Page not found</h1>');
```

Longest-prefix match; falls back to built-in `_404::show()` if nothing matches.

## Dispatch

```php
Http::dispatch();
```

Lifecycle: `preDispatcher()` → `registerInitiators()` (headers + hook files) → match route/asset/fallback → run middleware chain → run controller → run afterware chain.

## Assets

```php
Dispatcher::registerAssetRoute('/style.css', __DIR__ . '/public/style.css');
```

## API Reference

| Class | Purpose |
|---|---|
| `Http` | Static facade — routes, groups, middleware/afterware chaining, dispatch, named URLs |
| `Handler` | Route registry — storage, group stack, fallback, naming |
| `Dispatcher` | Request lifecycle, matching, asset serving, fallback resolution |
| `Invoke` | Middleware/afterware chain execution, controller resolution |
| `Reflection` | Named-argument injection for controllers |
| `Url` | URI normalization, pattern compiling, request matching |
| `_404` | Default 404 page |
| `MiddlewareInterface` | `handle(array $params, callable $next)` |
| `AfterwareInterface` | `terminate(array $params, $response): void` |

## License

MIT — Showket Ahmed
