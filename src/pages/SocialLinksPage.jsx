/**
 * SocialLinksPage
 * Display social media links and QR codes
 */

import { useState } from 'react';
import { useLanguage } from '../context/LanguageContext';
import { useSalon } from '../context/SalonContext';

import Card from '../components/common/Card';
import Spinner from '../components/common/Spinner';
import Modal from '../components/common/Modal';
import QRCode from '../components/common/QRCode';

import styles from './SocialLinksPage.module.css';

// Social platform icons
const SOCIAL_ICONS = {
  instagram: (
    <svg viewBox="0 0 24 24" fill="currentColor" className={styles.icon}>
      <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
    </svg>
  ),
  facebook: (
    <svg viewBox="0 0 24 24" fill="currentColor" className={styles.icon}>
      <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
    </svg>
  ),
  tiktok: (
    <svg viewBox="0 0 24 24" fill="currentColor" className={styles.icon}>
      <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"/>
    </svg>
  ),
  google_reviews: (
    <svg viewBox="0 0 24 24" fill="currentColor" className={styles.icon}>
      <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
    </svg>
  ),
  yelp: (
    <svg viewBox="0 0 24 24" fill="currentColor" className={styles.icon}>
      <path d="M20.16 12.594l-4.995 1.433c-.96.276-1.71-.266-1.667-1.206l.094-2.29c.043-.94.818-1.71 1.756-1.71l5.14.005c.735 0 1.332.605 1.332 1.34 0 .737-.597 1.342-1.333 1.342h-.327zm-2.3 6.51l-4.12-2.65c-.82-.527-.99-1.522-.378-2.22l1.47-1.674c.61-.696 1.64-.768 2.294-.16l4.175 3.87c.52.482.55 1.295.066 1.816-.483.52-1.295.55-1.816.066l-1.69-1.048zm-9.216-.234l3.02-4.08c.605-.815.34-1.765-.588-2.12l-2.233-.852c-.93-.355-1.91.123-2.186 1.068l-1.694 5.834c-.22.755.218 1.545.973 1.764.755.22 1.545-.218 1.764-.973l.944-1.641zm-2.19-8.046l5.008-1.433c.96-.276 1.344-.98 1.33-1.92l-.097-2.289c-.043-.94-.818-1.71-1.756-1.71l-5.14.005c-.736 0-1.333.604-1.333 1.34 0 .736.597 1.342 1.333 1.342l.655-.007v4.672zm5.553-2.55L10.1 4.177c-.27-.82.193-1.71 1.032-1.98l2.18-.708c.84-.27 1.73.193 2 1.033l1.91 5.618c.217.638-.127 1.333-.765 1.55-.638.217-1.333-.127-1.55-.765l-.918-2.57z"/>
    </svg>
  ),
  twitter: (
    <svg viewBox="0 0 24 24" fill="currentColor" className={styles.icon}>
      <path d="M23.643 4.937c-.835.37-1.732.62-2.675.733.962-.576 1.7-1.49 2.048-2.578-.9.534-1.897.922-2.958 1.13-.85-.904-2.06-1.47-3.4-1.47-2.572 0-4.658 2.086-4.658 4.66 0 .364.042.718.12 1.06-3.873-.195-7.304-2.05-9.602-4.868-.4.69-.63 1.49-.63 2.342 0 1.616.823 3.043 2.072 3.878-.764-.025-1.482-.234-2.11-.583v.06c0 2.257 1.605 4.14 3.737 4.568-.392.106-.803.162-1.227.162-.3 0-.593-.028-.877-.082.593 1.85 2.313 3.198 4.352 3.234-1.595 1.25-3.604 1.995-5.786 1.995-.376 0-.747-.022-1.112-.065 2.062 1.323 4.51 2.093 7.14 2.093 8.57 0 13.255-7.098 13.255-13.254 0-.2-.005-.402-.014-.602.91-.658 1.7-1.477 2.323-2.41z"/>
    </svg>
  ),
  youtube: (
    <svg viewBox="0 0 24 24" fill="currentColor" className={styles.icon}>
      <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
    </svg>
  ),
  custom: (
    <svg viewBox="0 0 24 24" fill="currentColor" className={styles.icon}>
      <path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/>
    </svg>
  ),
};

export default function SocialLinksPage() {
  const { t } = useLanguage();
  const { socialLinks, loading } = useSalon();
  const [selectedLink, setSelectedLink] = useState(null);

  if (loading) {
    return (
      <Card padding="large">
        <div className={styles.loading}>
          <Spinner size="large" />
          <p>{t('social.loading')}</p>
        </div>
      </Card>
    );
  }

  if (!socialLinks || socialLinks.length === 0) {
    return (
      <Card padding="large">
        <div className={styles.empty}>
          <p>{t('social.no_links')}</p>
        </div>
      </Card>
    );
  }

  return (
    <>
      <Card padding="large">
        <h2 className={styles.title}>{t('social.title')}</h2>
        <p className={styles.description}>{t('social.description')}</p>

        <div className={styles.grid}>
          {socialLinks.map((link) => (
            <button
              key={link.id}
              className={styles.socialCard}
              onClick={() => setSelectedLink(link)}
            >
              <div className={styles.iconContainer}>
                {SOCIAL_ICONS[link.link_type] || SOCIAL_ICONS.custom}
              </div>
              <div className={styles.linkInfo}>
                <h3 className={styles.linkName}>
                  {link.display_name || link.link_type}
                </h3>
                {link.description && (
                  <p className={styles.linkDescription}>{link.description}</p>
                )}
              </div>
            </button>
          ))}
        </div>
      </Card>

      {/* QR Code Modal */}
      <Modal
        isOpen={!!selectedLink}
        onClose={() => setSelectedLink(null)}
        title={t('social.qr_modal_title')}
        size="small"
      >
        {selectedLink && (
          <div className={styles.modalContent}>
            <QRCode value={selectedLink.link_url} size={250} />
            <p className={styles.scanText}>{t('social.qr_modal_description')}</p>
            <p className={styles.linkUrl}>{selectedLink.display_name}</p>
          </div>
        )}
      </Modal>
    </>
  );
}
