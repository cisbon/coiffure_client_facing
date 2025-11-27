/**
 * TabletLayout Component
 * Main layout wrapper for tablet-optimized display
 */

import Header from '../Header';
import Navigation from '../Navigation';
import styles from './TabletLayout.module.css';

export default function TabletLayout({ children, showNav = true }) {
  return (
    <div className={styles.layout}>
      <Header />

      <main className={styles.main}>
        <div className={styles.container}>
          {showNav && <Navigation />}
          <div className={styles.content}>{children}</div>
        </div>
      </main>
    </div>
  );
}
