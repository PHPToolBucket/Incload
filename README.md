# Incload

Looks for files that cannot be autoloaded (e.g. functions) and compiles a list of
`require()`s out of them.

## Installation

```
composer require php-tool-bucket/incload --dev
```

Then `chdir` to the project root and run:

```
php vendor/php-tool-bucket/incload/incload update
```

Then edit `composer.json` and add:
```
{
    // ...
    "autoload": {
        // ...
        "files": ["composer-includes.php"]
    },
    "autoload-dev": {
        // ...
        "files": ["composer-includes-dev.php"]
    }
}
```

Then run the following, so that the two files are added to `vendor/autoload.php`:
 
```
composer update
```
 
Finally, proceed creating `.inc.php` files within the project's folders. These files will be
added automatically to said `composer-includes.php` and `composer-includes-dev.php`
files as soon as the program notices them.

## Options list

- **--composer = getcwd() . "/composer.json"**<br>
  Specify `composer.json` path
- **--file = "composer-includes"**<br>
  Specify the main include file name (exclusive of `.php`)
- **--devfile = "composer-includes-dev"**<br>
  Specify the main include-dev file name (exclusive of `.php`)
- **--ext = "inc.php;fn.php;function.php;class.php;const.php;constant.php;ns.php;namespace.php"**<br>
  Specify the file extensions, semicolon separated 
- **--interval = "5"**<br>
  Specify interval in seconds between each check for changes
- **--errdelay = "30"**<br>
  Specify interval in seconds between an error and the consecutive retry
