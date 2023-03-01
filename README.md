Strukt Installer
===

# Installation

```php
composer global require "strukt/install"
```

# Usage

Create your application.

```sh
strukt new contactapp
```

Install available packages and publish.

```sh
strukt package:install test contactapp/ --publish
```

Available packages:

- do
- roles
- tests
- asset

Update Installer.

```sh
strukt update:me
```
