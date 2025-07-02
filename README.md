# OC2WP Sync

**Contributors:** LaraibRabbani
**Tags:** opencart, woocommerce, sync, import, variations, attributes, automation
**Requires at least:** 5.0
**Tested up to:** 6.8
**Stable tag:** 1.9

A WordPress plugin that synchronizes products from an OpenCart store into WooCommerce as **variable products**, complete with categories, brands, attributes, and variations.

## Description

OC2WP Sync connects directly to your OpenCart database and:

1. Imports product base data (model, price, images)
2. Maps categories and creates them in WooCommerce
3. Registers a global `pa_brand` attribute and assigns manufacturers
4. Reads all product options from OpenCart and turns them into WooCommerce attributes
5. Generates every combination of option values as individual WooCommerce variations
6. Splits the import into configurable chunks to avoid timeouts

A built-in Settings page lets you configure your OpenCart DB connection without touching any code. A single-page Sync UI shows real-time logs as products and variations are imported.

## Installation

1. Head over to plugins on wordpress pick the zipped file and click install.
2. Activate OC2WP Sync in your WordPress Plugins screen.
3. Open the OC2WP Sync Settings page in your admin menu.
4. Enter your OpenCart database host name database name username and password then save the settings.
5. Go to the main Sync page choose the starting product number and click Start Sync.
6. Watch the live log as each product description and variation appears in WooCommerce.

## Frequently Asked Questions

**Q: What OpenCart version does this support?**
A: Any 2.x or 3.x OpenCart installation, as long as it uses the standard oc\_product, oc\_product\_option, and oc\_product\_option\_value tables.

**Q: Can I run this on a staging environment?**
A: Yes. Simply point the plugin’s Settings to your staging OpenCart DB.


## Changelog

### 1.9 (2025-07-02)

* Fully self-contained DB connect only on demand (no PHP warnings on fresh install)
* Admin notice if credentials are missing
* AJAX error fallback for missing or invalid credentials

### 1.8 (2025-07-01)

* Added built-in Settings page for DB credentials
* Integrated OC2WP logo on the Sync page
* Refactored credentials out of hard-coded constants

### 1.7 (2025-06-29)

* Initial release: product import + description, categories, brand, attributes & variations import

## Upgrade Notice

Ensure you re-enter your DB credentials after upgrading to 1.8 or above.

## License

This plugin is released under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html) license.
