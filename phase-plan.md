# CoiffureAI Development Phase Plan

> A comprehensive roadmap for improving and extending CoiffureAI - a SaaS platform for German hair salons running on tablets.

**Constraints:**
- Must run on GitHub Pages (static hosting, no build step)
- Using Preact + HTM for React-like development without bundling
- Backend API at `https://clouedo.com/coiffure/api` (PHP/MySQL)
- Primary language: German (with English support)

---

## Phase 1: Foundation & High-Value Features

### 1.1 Preact + HTM Setup
- [ ] Set up Preact + HTM via ES modules (CDN imports)
- [ ] Create `/js/components/` folder structure
- [ ] Implement base `App.js` component
- [ ] Create shared state management with Preact signals/context
- [ ] Set up component hot-reload friendly structure

### 1.2 Code Migration & Cleanup
- [ ] Integrate new `js/app.js` module system into `index.html`
- [ ] Migrate ~2000 lines of inline JavaScript to modules
- [ ] Remove verbose `console.log` debug statements (keep error logs only)
- [ ] Extract shared utilities to `js/utils/` folder
  - [ ] `api.js` - Centralized API client
  - [ ] `auth.js` - Authentication helpers
  - [ ] `colors.js` - Color manipulation (adjustBrightness, etc.)
  - [ ] `dates.js` - Date formatting utilities
- [ ] Migrate `login.html` inline JS to shared modules
- [ ] Migrate `admin-dashboard.html` inline JS to shared modules

### 1.3 Core Components Migration
- [ ] `TabNavigation.js` - Tab switching with active states
- [ ] `OnboardingForm.js` - Customer registration with validation
- [ ] `SignatureCanvas.js` - Digital signature capture
- [ ] `SocialLinks.js` - Social media grid with QR modals
- [ ] `AIConsultation.js` - Photo capture + style selection wizard
- [ ] `LanguageSwitcher.js` - DE/EN toggle component

### 1.4 Walk-in Queue System (High Value)
- [ ] Design queue data structure and API endpoints
- [ ] Create `QueueDisplay.js` - Show current queue status
- [ ] Create `JoinQueue.js` - Customer queue registration
- [ ] Implement estimated wait time calculation
- [ ] Add queue position notifications
- [ ] Admin: Queue management panel (call next, remove, etc.)

### 1.5 Digital Loyalty Card (High Value)
- [ ] Design loyalty points/stamps data model
- [ ] Create `LoyaltyCard.js` - Visual stamp card component
- [ ] Implement points earning logic (per visit, per service)
- [ ] Create rewards catalog display
- [ ] Add loyalty status to customer profile
- [ ] Admin: Configure rewards and point values

---

## Phase 2: Customer Engagement Features

### 2.1 Style Gallery Browser
- [ ] Create `StyleGallery.js` - Browsable hairstyle inspiration
- [ ] Implement category filtering (women/men, length, style)
- [ ] Add search functionality
- [ ] Create "Save to favorites" feature
- [ ] Integrate with AI consultation ("Try this style")
- [ ] Admin: Manage gallery images and categories

### 2.2 Post-Visit Feedback System
- [ ] Create `FeedbackForm.js` - Star rating + comments
- [ ] Implement stylist-specific ratings
- [ ] Add "Would you recommend us?" NPS question
- [ ] Create Google Review prompt flow (redirect to Google)
- [ ] Design feedback thank-you screen with loyalty bonus
- [ ] Admin: Feedback analytics dashboard

### 2.3 Hair Care Tips & Content
- [ ] Create `TipsCarousel.js` - Educational content slider
- [ ] Design content card component
- [ ] Implement category tabs (styling, care, trends)
- [ ] Add "Tip of the day" feature
- [ ] Admin: Content management for tips

### 2.4 Appointment Quick Actions
- [ ] Create `QuickRebook.js` - "Book next appointment" flow
- [ ] Generate shareable appointment reminder QR code
- [ ] Implement "Add to calendar" (.ics download)
- [ ] Create appointment confirmation display

---

## Phase 3: AI & Personalization Enhancements

### 3.1 AI Color Consultation
- [ ] Extend AI consultation for hair color suggestions
- [ ] Implement skin tone analysis from photo
- [ ] Create color palette recommendations UI
- [ ] Add seasonal color trend suggestions
- [ ] Generate before/after color preview

### 3.2 Style History & Comparison
- [ ] Create `StyleHistory.js` - Past AI consultations gallery
- [ ] Implement side-by-side comparison view
- [ ] Add "Try again with different style" feature
- [ ] Enable sharing consultations via QR/link
- [ ] Store history per customer (with consent)

### 3.3 Celebrity Style Match
- [ ] Add celebrity hairstyle reference database
- [ ] Create "Style like [celebrity]" search
- [ ] Implement celebrity face shape matching
- [ ] Generate AI preview with celebrity-inspired style

### 3.4 Product Recommendations
- [ ] Create `ProductRecommendations.js` component
- [ ] Implement hair type/style analysis
- [ ] Link recommendations to salon's product inventory
- [ ] Add "Ask your stylist" call-to-action
- [ ] Admin: Manage product catalog and recommendations

---

## Phase 4: Admin Dashboard Enhancements

### 4.1 Analytics Dashboard
- [ ] Create `AnalyticsDashboard.js` - Overview metrics
- [ ] Implement customer insights (new vs returning, demographics)
- [ ] Add AI consultation statistics (popular styles, usage)
- [ ] Create revenue tracking charts (daily/weekly/monthly)
- [ ] Implement peak hours visualization
- [ ] Add export to CSV/PDF functionality

### 4.2 Customer Data Management
- [ ] Create `CustomerDetails.js` - Full customer profile view
- [ ] Implement stylist notes (preferences, allergies, history)
- [ ] Add visit history timeline
- [ ] Create GDPR one-click data export
- [ ] Implement data retention automation (auto-cleanup)
- [ ] Add customer search and filtering

### 4.3 Marketing Tools
- [ ] Create `CampaignBuilder.js` - SMS/email campaign creator
- [ ] Implement promo code generator with usage limits
- [ ] Add campaign scheduling
- [ ] Create template library for common campaigns
- [ ] Implement campaign analytics (open rates, conversions)

### 4.4 Operations Management
- [ ] Create `ServiceMenu.js` - Manage services, prices, duration
- [ ] Implement staff/stylist management
- [ ] Add stylist availability scheduler
- [ ] Create inventory alerts for low stock products
- [ ] Implement multi-location support (future)

---

## Phase 5: Styling & UX Improvements

### 5.1 CSS Architecture
- [ ] Extract inline CSS from `index.html` to `css/main.css`
- [ ] Create `css/components.css` for reusable component styles
- [ ] Create `css/branding.css` for salon-specific dynamic styles
- [ ] Implement CSS custom properties for all brand colors
- [ ] Add dark mode support (optional per salon)

### 5.2 Loading & Transitions
- [ ] Replace spinners with skeleton loaders
- [ ] Add page transition animations
- [ ] Implement optimistic UI updates
- [ ] Add pull-to-refresh for tablet (optional)
- [ ] Create smooth tab switching animations

### 5.3 Form UX
- [ ] Implement inline validation with error messages
- [ ] Add input formatting (phone numbers, etc.)
- [ ] Create multi-step form progress indicators
- [ ] Add autosave for long forms
- [ ] Implement smart keyboard types (tel, email)

---

## Phase 6: Performance & Offline (PWA)

### 6.1 PWA Foundation
- [ ] Create `manifest.json` for installable app
- [ ] Design app icons (multiple sizes)
- [ ] Implement Service Worker registration
- [ ] Add "Install app" prompt for tablets
- [ ] Configure standalone display mode

### 6.2 Offline Capabilities
- [ ] Cache static assets (Tailwind, libraries, translations)
- [ ] Implement offline fallback UI ("No connection")
- [ ] Add request queuing for form submissions when offline
- [ ] Sync queued data when connection restored
- [ ] Cache customer data for offline access (with limits)

### 6.3 Performance Optimization
- [ ] Lazy load images with placeholder blur
- [ ] Implement virtual scrolling for long lists
- [ ] Add resource prefetching for likely next pages
- [ ] Optimize camera/photo capture performance
- [ ] Reduce bundle size (audit CDN imports)

---

## Phase 7: Accessibility & Internationalization

### 7.1 Accessibility (WCAG 2.1)
- [ ] Add ARIA labels to all interactive elements
- [ ] Implement proper focus management
- [ ] Add screen reader announcements for dynamic content
- [ ] Ensure minimum touch target size (44x44px)
- [ ] Verify color contrast ratios with salon branding
- [ ] Add skip navigation links
- [ ] Test with VoiceOver/TalkBack

### 7.2 Internationalization
- [ ] Audit and complete `lang/de.json` translations
- [ ] Audit and complete `lang/en.json` translations
- [ ] Create `useTranslation.js` hook for components
- [ ] Add missing translation keys for new features
- [ ] Implement pluralization support
- [ ] Add RTL support structure (future languages)
- [ ] Create translation contribution guide

---

## Phase 8: Error Handling & Security

### 8.1 Error Handling
- [ ] Create global error boundary component
- [ ] Implement user-friendly error messages (German/English)
- [ ] Add retry logic with exponential backoff for API calls
- [ ] Create fallback UI for AI consultation failures
- [ ] Implement session expiry detection and auto-redirect
- [ ] Add error reporting/logging service integration

### 8.2 Security
- [ ] Implement input sanitization (XSS protection)
- [ ] Add CSRF token handling
- [ ] Secure localStorage data (consider encryption)
- [ ] Implement rate limiting awareness on client
- [ ] Add Content Security Policy headers guidance
- [ ] Create security audit checklist

---

## Phase 9: Testing & Quality Assurance

### 9.1 Testing Infrastructure
- [ ] Set up testing framework (compatible with no-build)
- [ ] Create unit tests for utility functions
- [ ] Add integration tests for critical flows
  - [ ] Customer onboarding flow
  - [ ] AI consultation flow
  - [ ] Authentication flow
- [ ] Implement visual regression testing (optional)
- [ ] Create test data generators

### 9.2 Code Quality
- [ ] Set up ESLint configuration
- [ ] Set up Prettier configuration
- [ ] Add JSDoc comments to public functions
- [ ] Create code review checklist
- [ ] Document component API contracts

### 9.3 Documentation
- [ ] Create `CONTRIBUTING.md` with code standards
- [ ] Write component documentation
- [ ] Create API integration guide
- [ ] Add troubleshooting guide
- [ ] Document deployment process

---

## Phase 10: Future Features (Backlog)

### 10.1 Advanced Booking
- [ ] Full appointment booking system
- [ ] Stylist selection with availability
- [ ] Service duration and pricing display
- [ ] Booking confirmation and reminders
- [ ] Calendar integration (Google, Apple)

### 10.2 Advanced AI Features
- [ ] AR real-time hairstyle try-on (camera filter)
- [ ] AI-powered styling suggestions based on face shape
- [ ] Virtual hair color try-on
- [ ] AI chatbot for styling advice

### 10.3 Customer Portal
- [ ] Customer login/account system
- [ ] View booking history
- [ ] Manage personal preferences
- [ ] Access loyalty rewards
- [ ] Rebook favorite services

### 10.4 Integrations
- [ ] POS system integration
- [ ] Salon management software sync
- [ ] WhatsApp Business API
- [ ] Instagram/Facebook integration
- [ ] Payment processing

### 10.5 Entertainment
- [ ] Mini-games while waiting
- [ ] Magazine/news feed integration
- [ ] Music preference selection
- [ ] Video content player

---

## Priority Matrix

| Phase | Priority | Effort | Business Value |
|-------|----------|--------|----------------|
| Phase 1 | Critical | High | Foundation |
| Phase 2 | High | Medium | Customer Retention |
| Phase 3 | High | Medium | Differentiation |
| Phase 4 | Medium | High | Operations |
| Phase 5 | Medium | Low | User Experience |
| Phase 6 | Medium | Medium | Reliability |
| Phase 7 | Medium | Low | Compliance |
| Phase 8 | High | Medium | Security |
| Phase 9 | Low | Medium | Maintainability |
| Phase 10 | Low | High | Future Growth |

---

## Tech Stack Summary

| Layer | Technology |
|-------|------------|
| UI Framework | Preact + HTM (via CDN) |
| Styling | Tailwind CSS (CDN) + Custom CSS |
| State Management | Preact Signals / Context |
| Routing | Hash-based SPA routing |
| i18n | Custom i18n.js system |
| API | Fetch + custom wrapper |
| Hosting | GitHub Pages (static) |
| Backend | PHP + MySQL (existing) |
| AI | Google Gemini (via PHP backend) |

---

## Getting Started

1. Complete Phase 1.1 (Preact + HTM Setup)
2. Migrate one component at a time (start with TabNavigation)
3. Test on actual tablet device
4. Iterate based on salon feedback

---

*Last updated: 2025-11-28*
