# Taurus_VarnishConfig

A Magento 2 module that automatically keeps your Varnish configuration up to date by downloading a remote VCL template, processing it with Magento's native placeholder substitution, and applying it to the running Varnish instance.

## How it works

1. A cron job runs once a day
2. The VCL template is downloaded from a configured URL
3. Magento's built-in `VclGenerator` replaces all standard placeholders with live values from the store configuration (backend host/port, access list, grace period, SSL offload header, design exceptions)
4. The processed VCL is saved to `var/tmp/varnish_extended.vcl`
5. The new configuration is loaded and activated via `varnishadm`:
   ```
   varnishadm vcl.load magento2 <file>
   varnishadm vcl.use magento2
   ```

## Requirements

- Magento 2 with `Magento_PageCache` module
- PHP 8.1+
- `varnishadm` available in the system `PATH` of the user running Magento cron

## Installation

```bash
composer require taurus-media/module-varnish-config
bin/magento module:enable Taurus_VarnishConfig
bin/magento setup:upgrade
```

## CLI command

The VCL update can also be triggered manually:

```bash
bin/magento taurus:varnish:update-vcl
```

This runs the same logic as the cron job and exits with code `0` on success or `1` on failure, making it suitable for use in deployment pipelines.
