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

Install available packages and publish.

```sh
strukt add tests --publish
```

Available packages:

- db
- auth
- tests
- asset

Update Installer.

```sh
strukt update:me
```
