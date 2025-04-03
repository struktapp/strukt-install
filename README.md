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
