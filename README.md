Strukt Installer
===

## Installation

```php
composer global require "strukt/install"
```

## Usage

Create your application.

```sh
strukt new payroll
cd payroll
```

Create an application from Strukt `master` archives instead of Packagist.

```sh
strukt new:dev payroll
cd payroll
```

The `new:dev` command reads the `[dev]` section of `strukt.ini`,
downloads missing GitHub archives, extracts Strukt packages into
`~/.cache/strukt/<branch>/`, and reuses already cached packages on later runs.
They are registered as Composer path repositories while Packagist stays enabled
for third-party dependencies.

Pass `--refresh` to re-download cached packages.

Add a bundle to an existing project from the same cache.

```sh
cd payroll
strukt add:dev book
```

The bundle is resolved as `bundle-<name>` when needed. `add:dev` checks
`~/.cache/strukt/local/` and `~/.cache/strukt/<branch>/` before downloading.
If the bundle cannot be found, the command aborts and asks you to contact the
vendor.

Install the latest stable bundle from Packagist.

```sh
strukt add auth
```

`add` resolves `auth` to `strukt/bundle-auth`, selects the newest stable
version published on Packagist, and requires it with Composer.

Update Installer.

```sh
strukt update:me
```
