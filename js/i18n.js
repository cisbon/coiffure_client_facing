/**
 * i18n Translation System
 * Simple internationalization for SalonLyft
 */

const i18n = {
    currentLang: 'de', // Default language
    translations: {},
    fallbackLang: 'de',

    /**
     * Initialize i18n system
     */
    async init() {
        // First check if user is logged in and has a language preference
        const userData = localStorage.getItem('user_data');
        if (userData) {
            try {
                const user = JSON.parse(userData);
                if (user.preferred_language && (user.preferred_language === 'de' || user.preferred_language === 'en')) {
                    this.currentLang = user.preferred_language;
                }
            } catch (e) {
                console.warn('Failed to parse user data for language preference');
            }
        }

        // Fallback to localStorage app_language
        if (!this.currentLang || this.currentLang === 'de') {
            const savedLang = localStorage.getItem('app_language');
            if (savedLang && (savedLang === 'de' || savedLang === 'en')) {
                this.currentLang = savedLang;
            }
        }

        // Load translations for current language
        await this.loadLanguage(this.currentLang);

        // Apply translations to the page
        this.applyTranslations();
    },

    /**
     * Load translation file for specified language
     */
    async loadLanguage(lang) {
        try {
            const response = await fetch(`lang/${lang}.json`);
            if (!response.ok) {
                throw new Error(`Failed to load ${lang} translations`);
            }
            this.translations = await response.json();
            this.currentLang = lang;
            console.log(`Loaded ${lang} translations successfully`);
            return true;
        } catch (error) {
            console.error('Error loading translations:', error);
            // Try loading fallback language if current language fails
            if (lang !== this.fallbackLang) {
                console.log(`Loading fallback language: ${this.fallbackLang}`);
                return await this.loadLanguage(this.fallbackLang);
            }
            return false;
        }
    },

    /**
     * Get translation by key
     * Supports nested keys like "app.title" or "admin.dashboard"
     */
    t(key, params = {}) {
        const keys = key.split('.');
        let value = this.translations;

        // Navigate through nested object
        for (const k of keys) {
            if (value && typeof value === 'object' && k in value) {
                value = value[k];
            } else {
                console.warn(`Translation key not found: ${key}`);
                return key; // Return key if translation not found
            }
        }

        // Replace parameters in translation string
        if (typeof value === 'string' && Object.keys(params).length > 0) {
            return value.replace(/\{(\w+)\}/g, (match, param) => {
                return params[param] !== undefined ? params[param] : match;
            });
        }

        return value;
    },

    /**
     * Change language and reload translations
     */
    async setLanguage(lang) {
        if (lang === this.currentLang) {
            return; // Already using this language
        }

        const success = await this.loadLanguage(lang);
        if (success) {
            // Save preference locally
            localStorage.setItem('app_language', lang);

            // Update user_data in localStorage if user is logged in
            const userData = localStorage.getItem('user_data');
            if (userData) {
                try {
                    const user = JSON.parse(userData);
                    user.preferred_language = lang;
                    localStorage.setItem('user_data', JSON.stringify(user));

                    // Save to backend if authenticated
                    await this.saveLanguagePreference(lang);
                } catch (e) {
                    console.warn('Failed to update user language preference');
                }
            }

            // Re-apply translations to the page
            this.applyTranslations();

            // Dispatch event for other components to react
            window.dispatchEvent(new CustomEvent('languageChanged', {
                detail: { language: lang }
            }));
        }
    },

    /**
     * Save language preference to backend
     */
    async saveLanguagePreference(lang) {
        const sessionToken = localStorage.getItem('session_token');
        if (!sessionToken) {
            return; // Not authenticated
        }

        try {
            const API_BASE_URL = window.API_BASE_URL || 'https://clouedo.com/coiffure/api';
            const response = await fetch(`${API_BASE_URL}/user-settings.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${sessionToken}`
                },
                body: JSON.stringify({ language: lang })
            });

            const result = await response.json();
            if (!result.success) {
                console.warn('Failed to save language preference:', result.error);
            }
        } catch (error) {
            console.warn('Error saving language preference:', error);
        }
    },

    /**
     * Apply translations to elements with data-i18n attribute
     */
    applyTranslations() {
        // Translate elements with data-i18n attribute
        document.querySelectorAll('[data-i18n]').forEach(element => {
            const key = element.getAttribute('data-i18n');
            const translation = this.t(key);

            // Update text content
            if (element.tagName === 'INPUT' && element.type === 'button') {
                element.value = translation;
            } else {
                element.textContent = translation;
            }
        });

        // Translate placeholder attributes separately
        document.querySelectorAll('[data-i18n-placeholder]').forEach(element => {
            const key = element.getAttribute('data-i18n-placeholder');
            const translation = this.t(key);
            element.placeholder = translation;
        });

        // Translate elements with data-i18n-html attribute (for HTML content)
        document.querySelectorAll('[data-i18n-html]').forEach(element => {
            const key = element.getAttribute('data-i18n-html');
            const translation = this.t(key);
            element.innerHTML = translation;
        });

        // Update document title if present
        const titleElement = document.querySelector('[data-i18n-title]');
        if (titleElement) {
            const key = titleElement.getAttribute('data-i18n-title');
            document.title = this.t(key);
        }
    },

    /**
     * Get current language code
     */
    getCurrentLanguage() {
        return this.currentLang;
    },

    /**
     * Get available languages
     */
    getAvailableLanguages() {
        return [
            { code: 'de', name: 'Deutsch' },
            { code: 'en', name: 'English' }
        ];
    }
};

// Auto-initialize on DOM ready if not already initialized
// Skip auto-init if window.skipI18nAutoInit is set (for index.html which uses salon language)
if (!window.skipI18nAutoInit) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            if (!i18n.translations || Object.keys(i18n.translations).length === 0) {
                i18n.init();
            }
        });
    } else {
        // DOM already loaded
        if (!i18n.translations || Object.keys(i18n.translations).length === 0) {
            i18n.init();
        }
    }
}
