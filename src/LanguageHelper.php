<?php
/**
 * Language Helper
 *
 * Centralized language detection with WPML support and fallback to WordPress locale
 */

namespace AdpCustom;

class LanguageHelper {

    /**
     * Check if current language is Bulgarian
     *
     * Checks WPML first for multilingual sites, then falls back to WordPress locale
     *
     * @return bool True if Bulgarian language is active
     */
    public static function is_bulgarian_language(): bool
    {
        // Check WPML first (WooCommerce Multilingual integration)
        $current_lang = apply_filters('wpml_current_language', null);
        if ($current_lang !== null) {
            return $current_lang === 'bg';
        }

        // Fallback to WordPress locale
        $locale = get_locale();
        return strpos($locale, 'bg') === 0; // Matches bg_BG, bg, etc.
    }
}
