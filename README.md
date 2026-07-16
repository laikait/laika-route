# laika-route

Routing package for the Laika PHP MVC Framework.

## Install

```bash
composer require laikait/laika-route
```

## Methods

```php
use Laika\Route\Url;

Url::get('/users', 'UserController@index');
Url::post('/users', 'UserController@store');
Url::put('/users/{id}', 'UserController@update');
Url::patch('/users/{id}', 'UserController@patch');
Url::delete('/users/{id}', 'UserController@destroy');
Url::options('/users', 'UserController@options');
```

## Route Params

```php
Url::get('/users/{id}', 'UserController@show');
Url::get('/users/{id:\d+}', 'UserController@show'); // regex constraint
```

## Pipeline

```php
// Single
Url::get('/dashboard', 'DashboardController@index')->pipeline('Auth');

// Multiple
Url::get('/orders', 'OrderController@index')->pipeline(['Auth', 'VerifiedEmail']);

// With args
Url::get('/admin', 'AdminController@index')->pipeline(['Role|role=admin']);

// Multiple args
Url::get('/reports', 'ReportController@index')->pipeline(['Throttle|limit=60,window=60']);

// Global (applies to all routes)
Url::globalPipeline(['Csrf', 'Cors']);
```

Class:

```php
namespace App\Pipeline;

use Laika\Route\Interfaces\PipelineInterface;

class Role implements PipelineInterface
{
    public function handle(callable $next, array &$params)
    {
        if (($_SESSION['role'] ?? null) !== ($params['role'] ?? null)) {
            http_response_code(403);
            return 'Forbidden'; // stops chain and print
        }
        // return $next(false); Stop pipelines chain and call controller
        return $next(); // continues chain
    }
}
```

- `$params` — route params + pipeline config args (`role=admin`), merged, passed by reference through the whole chain (pipeline → controller → filter).
- Return value short-circuits the response if not `$next()`.

## Filter

```php
// Single
Url::get('/orders', 'OrderController@index')->filter('LogAccess');

// Multiple
Url::get('/orders', 'OrderController@index')->filter(['LogAccess', 'CacheResponse']);

// With args
Url::get('/reports', 'ReportController@index')->filter(['LogAccess|level=info']);

// Global
Url::globalFilter(['LogResponse']);
```

Class:

```php
namespace App\Filter;

use Laika\Route\Interfaces\FilterInterface;

class LogAccess implements FilterInterface
{
    public function terminate(callable $next, ?string $response, array &$params): ?string
    {
        error_log('level=' . ($params['level'] ?? 'default'));
    }
}
```

## Named Routes

```php
Url::get('/users/{id}', 'UserController@show')->name('users.show');

$url = Url::url('users.show', ['id' => 5]); // /users/5
```

## Groups

```php
// Basic
Url::group('admin', function () {
    Url::get('/dashboard', 'Admin\DashboardController@index');
});

// Nested (inherits parent's pipeline/filter)
Url::group('admin', function () {
    Url::group('billing', function () {
        Url::get('/invoices', 'Admin\Billing\InvoiceController@index');
    })->pipeline(['Permission|perm=billing.view']);
})->pipeline(['Auth']);

// Chained pipeline + filter (applied retroactively to all routes in group)
Url::group('api', function () {
    Url::post('/payments', 'PaymentController@store')->pipeline(['ApiKey']);
})->pipeline(['Cors'])->filter(['LogApi']);
```

## Fallback

```php
// Per group prefix
Url::fallback('admin', fn() => '<h1>Admin route not found</h1>');

// Default (no prefix)
Url::fallback(null, fn() => '<h1>Page not found</h1>');
```

Longest-prefix match; falls back to built-in `_404::show()` if nothing matches.

## Dispatch

```php
Url::dispatch();
```

Lifecycle: `preDispatcher()` → `registerInitiators()` (headers + hook files) → match route/asset/fallback → run pipeline chain → run controller → run filter chain.

## API Reference

| Class | Purpose |
|---|---|
| `Url` | Static facade — routes, groups, pipeline/filter chaining, dispatch, named URLs |
| `Handler` | Route registry — storage, group stack, fallback, naming |
| `Dispatcher` | Request lifecycle, matching, asset serving, fallback resolution |
| `Invoke` | Pipeline/Filter chain execution, controller resolution |
| `Reflection` | Named-argument injection for controllers |
| `Path` | URI normalization, pattern compiling, request matching |
| `_404` | Default 404 page |
| `PipelineInterface` | `handle(callable $next, array &$params): ?string` |
| `FilterInterface` | `terminate(callable $next, ?string $response, array &$params): ?string` |

## License

Laika-route is protected under the [LICENSE](https://choosealicense.com/licenses) License. For more details, refer to the [LICENSE](https://choosealicense.com/licenses/) file.

---

## Acknowledgments

- Credit `contributors`, `inspiration`, `references`, etc.

<div align="right">

[![][back-to-top]](#top)

</div>


[back-to-top]: https://img.shields.io/badge/-BACK_TO_TOP-151515?style=flat-square


---
