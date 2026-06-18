# FAQ

## Is ObfusX a PHP extension?
Current implementation ships as a bootstrap loader (`/runtime/loader.php`).

## Where is the decryption key stored?
It is provided at runtime through `OBFUSX_MASTER_KEY` and never embedded in encoded output.

## What licensing checks are supported?
Domain binding, IP binding, expiry dates, and hardware fingerprint checks.

## Does it stop all reverse engineering?
No software-only solution can guarantee this. ObfusX raises the bar with layered controls.
