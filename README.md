# ci4-simple-auth

A lightweight HTTP Basic Auth library for closed systems built on CodeIgniter 4. Users do not sign up; they are created by an administrator.

- Supported databases: **MySQL, PostgreSQL, SQLite** (SQL Server is not supported)
- PHP 8.2+, CodeIgniter 4.7+

## Installation

```bash
composer require nuhsait/ci4-simple-auth
php spark simpleauth:setup
php spark migrate
```

`simpleauth:setup` asks, in order:

1. For each identity field (`phone`, `identity_number`, `email`, `username`):
   - Include this field?
   - Country code for `identity_number` (currently only `tr`)
   - Can it be left empty?
   - Length (for `email` and `username`; `phone` and `identity_number` have fixed lengths)
2. Login field: chosen by number, among required fields only
3. bcrypt cost (default 12)
4. Failed attempt limit and cooldown

It then generates two files:

- `app/Database/Migrations/<date>_CreateAuthUserTable.php`
- `app/Config/SimpleAuth.php`

### Changing things after installation

The identity fields, the login field and the identity number country are tied to the database schema. **To change them, run setup again from scratch.** Setup refuses to run while the table exists, so the table has to be removed first:

```bash
php spark migrate:rollback   # WARNING: deletes every user in auth_user
php spark simpleauth:setup
php spark migrate
```

`$hashCost`, `$maxAttempts`, `$cooldownMinutes` and `$realm` in `app/Config/SimpleAuth.php` do not affect the schema and can be edited by hand. When the cost changes, existing passwords keep working; the new cost applies only to passwords set afterwards.

## Table: `auth_user`

| Column | Description |
|---|---|
| `id` | Primary key |
| `name` | Name (at most 64 characters) |
| `password_hash` | bcrypt hash |
| `created_at`, `updated_at` | Automatic timestamps |
| `phone` | *Optional.* E.164 format, e.g. `+905321234567` |
| `identity_number` | *Optional.* Validated per country (TR: T.C. Kimlik No / YKN) |
| `email` | *Optional.* Converted to lowercase |
| `username` | *Optional.* `a-z` only, converted to lowercase |

Every selected identity field is unique.

## Managing users

The model returns rows as `Nuhsait\Ci4SimpleAuth\Entities\AuthUser` entities. Both arrays and entities can be saved.

```php
use Nuhsait\Ci4SimpleAuth\Entities\AuthUser;
use Nuhsait\Ci4SimpleAuth\Models\User;

$users = model(User::class);

// With an entity
$user = new AuthUser([
    'name'            => 'Ali Veli',
    'identity_number' => '10000000146',
    'phone'           => '+90 (532) 123 45 67', // stored as +905321234567
]);
$user->password = 'secret-password';

$id = $users->insert($user);

if ($id === false) {
    $errors = $users->errors();
}

$user        = $users->find($id);
$user->email = 'Ali@Example.com';           // stored as ali@example.com
$users->save($user);

// With an array
$id = $users->insert([
    'name'            => 'Ayşe Yılmaz',
    'identity_number' => '10000000078',
    'phone'           => '+90 533 000 00 00',
    'password'        => 'secret-password',
]);

$users->update($id, ['password' => 'new-password']);
$users->delete($id);
```

- The password is given in plain text through the `password` field; the model hashes it with bcrypt into the `password_hash` column. `password_hash` cannot be written directly.
- A password may be at most 72 bytes (bcrypt ignores everything after 72 bytes).
- Identity fields are normalized before saving: `email` and `username` are lowercased, and spaces, dashes and parentheses are removed from `phone`. Empty values in optional fields are stored as `NULL`.
- `password` and `password_hash` never appear in the entity's `toArray()` or JSON output.
- In JSON output `created_at` and `updated_at` are ISO 8601 strings (e.g. `"2026-09-26T08:07:26+00:00"`). In PHP they are `Time` objects.
- Direct Query Builder operations (`$db->table('auth_user')->...`) bypass the model callbacks, and therefore normalization and hashing as well.
- `delete()` removes the row permanently.

## Basic Auth filter

The filter is registered automatically under the `simpleauth` alias. Apply it to routes or to the global filters:

```php
// app/Config/Routes.php
$routes->group('api', ['filter' => 'simpleauth'], static function ($routes) {
    $routes->get('me', 'Api::me');
});
```

The client sends `Authorization: Basic base64(<login field value>:<password>)` on every request. The authenticated user is available as:

```php
$user = auth()->user();  // AuthUser entity, without password_hash
$name = auth()->user()?->name;
$id   = auth()->id();
$ok   = auth()->check();
```

Responses:

| Case | Response |
|---|---|
| Request is not HTTPS | `403` |
| No header, unknown user or wrong password | `401` + `WWW-Authenticate` |
| Failed attempt limit exceeded | `429` + `Retry-After` |

- **HTTPS is mandatory.** The password is sent with every request, so requests over HTTP are refused.
- The same amount of time is spent when the user does not exist, so the response time does not reveal which users exist.
- bcrypt verification runs on every request. With cost 12 this is about 250 ms per request on a typical server.

## Rate limiting

- Only failed attempts are counted, and the counter is kept per **request IP**.
- The first failed attempt opens a window as long as the cooldown. If the limit is reached within that window, the IP is locked for the cooldown (the correct password is not accepted while locked either).
- A successful login resets the counter.
- Counters are stored in the CodeIgniter cache. With multiple servers, use a shared cache such as Redis or Memcached.

## Running behind a proxy

If the application runs behind a reverse proxy (Cloudflare, a load balancer, Nginx on another server, etc.), CodeIgniter by default sees every request as coming from the proxy. This has two consequences:

- All requests appear to come from the proxy's IP, so one person's failed attempts lock out **everyone**.
- If the connection between the proxy and the application is HTTP, the HTTPS check rejects every request.

The fix is to add the proxy's IP to `$proxyIPs` in `app/Config/App.php` and make sure the proxy sends the `X-Forwarded-For` and `X-Forwarded-Proto` headers:

```php
public array $proxyIPs = [
    '10.0.0.0/8' => 'X-Forwarded-For',
];
```

Standard setups such as Nginx + PHP-FPM or Apache on the same machine do not need this.

## Adding a country

Identity number rules live in `src/IdentityNumber/Countries/`. To add a country, write a class implementing `IdentityNumberInterface` and register it in `Registry::COUNTRIES`; the setup command lists it automatically.

## License

MIT
