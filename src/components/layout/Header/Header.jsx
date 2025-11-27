/**
 * Header Component
 * App header with salon branding and language switcher
 */

import { useSalon } from '../../../context/SalonContext';
import { useLanguage } from '../../../context/LanguageContext';
import styles from './Header.module.css';

export default function Header() {
  const { salon, branding } = useSalon();
  const { language, setLanguage } = useLanguage();

  const logoUrl = branding?.logo_path
    ? `https://clouedo.com/coiffure/api/${branding.logo_path}`
    : null;

  return (
    <header className={styles.header}>
      <div className={styles.container}>
        <div className={styles.branding}>
          {logoUrl && (
            <img
              src={logoUrl}
              alt={salon?.name || 'Salon Logo'}
              className={styles.logo}
            />
          )}
          <div className={styles.titleGroup}>
            <h1 className={styles.title}>
              {salon?.name || branding?.salon_name || 'Coiffure AI'}
            </h1>
          </div>
        </div>

        {/* Language Switcher */}
        <div className={styles.languageSwitcher}>
          <button
            onClick={() => setLanguage('de')}
            className={`${styles.langButton} ${language === 'de' ? styles.active : ''}`}
            title="Deutsch"
            aria-label="Switch to German"
          >
            <svg width="24" height="18" viewBox="0 0 5 3" aria-hidden="true">
              <rect width="5" height="3" fill="#000" />
              <rect width="5" height="2" y="1" fill="#D00" />
              <rect width="5" height="1" y="2" fill="#FFCE00" />
            </svg>
          </button>
          <button
            onClick={() => setLanguage('en')}
            className={`${styles.langButton} ${language === 'en' ? styles.active : ''}`}
            title="English"
            aria-label="Switch to English"
          >
            <svg width="24" height="18" viewBox="0 0 60 30" aria-hidden="true">
              <clipPath id="t">
                <path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z" />
              </clipPath>
              <path d="M0,0 v30 h60 v-30 z" fill="#012169" />
              <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" strokeWidth="6" />
              <path d="M0,0 L60,30 M60,0 L0,30" clipPath="url(#t)" stroke="#C8102E" strokeWidth="4" />
              <path d="M30,0 v30 M0,15 h60" stroke="#fff" strokeWidth="10" />
              <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" strokeWidth="6" />
            </svg>
          </button>
        </div>
      </div>
    </header>
  );
}
