# ADP Custom Addons

A WordPress/WooCommerce plugin that extends [Advanced Dynamic Pricing for WooCommerce](https://wordpress.org/plugins/advanced-dynamic-pricing-for-woocommerce/) with enhanced frontend display and administrative controls.

## Important Notice

This is a **fully custom solution developed specifically for Milliart** and may not work correctly on other WooCommerce sites without modifications. The plugin is tailored to specific business requirements and pricing strategies.

### Dual Currency Feature (Temporary)

The plugin includes dual currency support (BGN + EUR) for Bulgaria's transition to Euro starting January 1, 2026. This feature displays prices in both BGN and EUR when the store currency is set to BGN. **This feature will become obsolete in January 2027** and may be removed in future versions.

## Requirements

- WordPress
- WooCommerce
- [Advanced Dynamic Pricing for WooCommerce](https://wordpress.org/plugins/advanced-dynamic-pricing-for-woocommerce/) (ADP)
- Optional: WPML (for multi-language support)

## Features

### Frontend Enhancements

- **Discount Percentage Badges** - Visual "% OFF" badges displayed on product archives showing the actual discount amount
- **ADP-Aware Price Display** - Custom price templates that properly display discounted prices from ADP rules
- **Pricing Rule Titles** - Shows the name of active discount rules below product prices (e.g., "Bulk Discount", "Volume Pricing")
- **Smart Price Formatting** - Handles both simple and variable products with strike-through regular prices and highlighted discounted prices
- **Dual Currency Display (BGN/EUR)** - Automatically shows prices in both BGN and EUR when store currency is BGN, using official conversion rate (1.95583)
- **Multi-language Support** - Full WPML compatibility with translated rule titles

### Admin Panel

- **Rule Discovery Scanner** - Automatically scans all products to discover active ADP pricing rules
- **Visibility Control** - Admin interface to selectively show/hide discount rule titles on the frontend
- **WooCommerce Integration** - Accessible via WooCommerce > ADP Custom menu
- **Batch Processing** - Efficiently scans large product catalogs
- **Caching System** - Optimized performance with WordPress object cache

### Technical Features

- Custom template overrides for WooCommerce price display
- Currency conversion with configurable exchange rates
- Dual currency display across shop, cart, checkout, and emails
- Security: WordPress nonces and capability checks
- Error handling with graceful fallbacks
- Conditional asset loading for performance
- Multi-language ready with WPML hooks

## Installation

1. Ensure WooCommerce and Advanced Dynamic Pricing for WooCommerce are installed and active
2. Upload the plugin to `/wp-content/plugins/adp-custom/`
3. Activate the plugin through WordPress admin
4. Navigate to WooCommerce > ADP Custom to configure

## Usage

1. Go to **WooCommerce > ADP Custom — Rule Titles**
2. Click **"Scan rules (update list)"** to discover all active ADP pricing rules
3. Check/uncheck rules to control which discount titles appear on the frontend
4. Click **"Save visibility"** to apply changes

## Author

Denis Marinov

## Version

2.2.1

---

## Changelog

### Version 2.2.1 (2025)
**EUR (BGN) Dual Currency Mode**

- Added reverse dual currency: EUR with BGN equivalent when currency=EUR and language=Bulgarian
- Prices display as `20.00 € (39.11 лв.)` in EUR mode
- Language detection via WPML with WordPress locale fallback
- New feature flag: `ADP_CUSTOM_DUAL_CURRENCY_EUR_BGN_ENABLED`
- Fixed side cart checkout button to show dual currency
- Fixed cart table price display for EUR mode
- Both BGN→EUR and EUR→BGN modes work independently based on store currency
- Complete coverage: products, cart, checkout, orders, emails, Elementor widgets

### Version 2.0.0 (2025)
**Major Update: Dual Currency Support**

- Added dual currency display (BGN + EUR) for Bulgaria's Euro transition
- Prices now display in both BGN and EUR when store currency is BGN
- Uses official fixed conversion rate: 1 EUR = 1.95583 BGN
- Optional integration with WPML Multi-currency for dynamic rates
- Dual currency styling for shop, cart, checkout, and email templates
- Responsive design for mobile devices
- **Note:** This feature is temporary and will be obsolete in January 2027

### Version 1.1.0
**Initial Release**

- ADP-aware price display with discount detection
- Discount percentage badges on product archives
- Admin panel for managing pricing rule visibility
- Rule discovery scanner for automatic detection
- Custom price templates with strike-through formatting
- WPML multi-language support
- Caching system for optimized performance
