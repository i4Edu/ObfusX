# FAQ

## Is ObfusX a PHP extension?
Current implementation ships as a bootstrap loader (`/runtime/loader.php`).

## Does it work with Laravel?
Yes for standard entrypoints such as `public/index.php` and `artisan`. ObfusX
preserves `__DIR__` / `__FILE__` semantics relative to the encoded file so
Laravel's default bootstrap paths continue to resolve.

## Where is the decryption key stored?
It is provided at runtime through `OBFUSX_MASTER_KEY` and never embedded in encoded output.

## What licensing checks are supported?
Domain binding, IP binding, expiry dates, and hardware fingerprint checks.

## Does it stop all reverse engineering?
No software-only solution can guarantee this. ObfusX raises the bar with layered controls.
