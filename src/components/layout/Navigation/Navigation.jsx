/**
 * Navigation Component
 * Tab navigation for main pages
 */

import { NavLink } from 'react-router-dom';
import { useLanguage } from '../../../context/LanguageContext';
import styles from './Navigation.module.css';

export default function Navigation() {
  const { t } = useLanguage();

  const tabs = [
    { to: '/', label: t('tabs.onboarding') },
    { to: '/social', label: t('tabs.social') },
    { to: '/ai-consultant', label: t('tabs.ai_consultation') },
  ];

  return (
    <nav className={styles.nav}>
      <div className={styles.container}>
        <div className={styles.tabs}>
          {tabs.map((tab) => (
            <NavLink
              key={tab.to}
              to={tab.to}
              className={({ isActive }) =>
                `${styles.tab} ${isActive ? styles.active : ''}`
              }
              end={tab.to === '/'}
            >
              {tab.label}
            </NavLink>
          ))}
        </div>
      </div>
    </nav>
  );
}
