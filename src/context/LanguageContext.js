/**
 * Language Context
 * Handles internationalization (i18n) for German/English
 */

import { createContext, useContext, useState, useCallback, useEffect } from 'react';
import { LANGUAGES, DEFAULT_LANGUAGE, STORAGE_KEYS } from '../config/constants';

// Import translations
import de from '../utils/translations/de';
import en from '../utils/translations/en';

const translations = { de, en };

// Create context
const LanguageContext = createContext();

/**
 * Get nested translation value by key path
 */
function getNestedValue(obj, path) {
  const keys = path.split('.');
  let value = obj;

  for (const key of keys) {
    if (value && typeof value === 'object' && key in value) {
      value = value[key];
    } else {
      return path; // Return key if translation not found
    }
  }

  return value;
}

/**
 * Replace parameters in translation string
 */
function replaceParams(str, params = {}) {
  if (typeof str !== 'string') return str;

  return str.replace(/\{(\w+)\}/g, (match, param) => {
    return params[param] !== undefined ? params[param] : match;
  });
}

/**
 * Language Provider Component
 */
export function LanguageProvider({ initialLanguage, children }) {
  // Initialize language from props, storage, or default
  const [language, setLanguageState] = useState(() => {
    if (initialLanguage && translations[initialLanguage]) {
      return initialLanguage;
    }

    const stored = localStorage.getItem(STORAGE_KEYS.APP_LANGUAGE);
    if (stored && translations[stored]) {
      return stored;
    }

    return DEFAULT_LANGUAGE;
  });

  // Current translations
  const currentTranslations = translations[language] || translations[DEFAULT_LANGUAGE];

  /**
   * Translate function
   * @param {string} key - Dot notation path (e.g., 'onboarding.title')
   * @param {object} params - Parameters to replace in the string
   */
  const t = useCallback((key, params = {}) => {
    const value = getNestedValue(currentTranslations, key);

    if (typeof value === 'string') {
      return replaceParams(value, params);
    }

    return value;
  }, [currentTranslations]);

  /**
   * Set language
   */
  const setLanguage = useCallback((lang) => {
    if (translations[lang]) {
      setLanguageState(lang);
      localStorage.setItem(STORAGE_KEYS.APP_LANGUAGE, lang);

      // Update HTML lang attribute
      document.documentElement.lang = lang;
    }
  }, []);

  /**
   * Toggle between languages
   */
  const toggleLanguage = useCallback(() => {
    const newLang = language === LANGUAGES.DE ? LANGUAGES.EN : LANGUAGES.DE;
    setLanguage(newLang);
  }, [language, setLanguage]);

  /**
   * Get all available languages
   */
  const getAvailableLanguages = useCallback(() => [
    { code: LANGUAGES.DE, name: 'Deutsch' },
    { code: LANGUAGES.EN, name: 'English' },
  ], []);

  // Update HTML lang attribute on mount
  useEffect(() => {
    document.documentElement.lang = language;
  }, [language]);

  // Context value
  const value = {
    language,
    t,
    setLanguage,
    toggleLanguage,
    getAvailableLanguages,
    isGerman: language === LANGUAGES.DE,
    isEnglish: language === LANGUAGES.EN,
  };

  return (
    <LanguageContext.Provider value={value}>
      {children}
    </LanguageContext.Provider>
  );
}

/**
 * Hook to access language context
 */
export function useLanguage() {
  const context = useContext(LanguageContext);

  if (!context) {
    throw new Error('useLanguage must be used within a LanguageProvider');
  }

  return context;
}

export default LanguageContext;
