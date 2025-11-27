/**
 * App.js
 * Root component with routing and context providers
 */

import { Suspense, lazy } from 'react';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';

// Context providers
import { SalonProvider } from './context/SalonContext';
import { CustomerProvider } from './context/CustomerContext';
import { LanguageProvider } from './context/LanguageContext';

// Components
import TabletLayout from './components/layout/TabletLayout';
import ErrorBoundary from './components/common/ErrorBoundary';
import Spinner from './components/common/Spinner';

// Auth check (simplified for now - in production, use proper auth flow)
import { isAuthenticated } from './services/authService';

// Lazy-loaded pages for code splitting
const OnboardingPage = lazy(() => import('./pages/OnboardingPage'));
const SocialLinksPage = lazy(() => import('./pages/SocialLinksPage'));
const AIConsultantPage = lazy(() => import('./pages/AIConsultantPage'));

// Loading fallback
function LoadingFallback() {
  return (
    <div style={{
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      minHeight: '50vh'
    }}>
      <Spinner size="large" />
    </div>
  );
}

// Protected route wrapper
function ProtectedRoute({ children }) {
  // For development/demo, skip auth check
  // In production, uncomment the following:
  // if (!isAuthenticated()) {
  //   window.location.href = 'login.html';
  //   return null;
  // }

  return children;
}

// Main app content with layout
function AppContent() {
  return (
    <Routes>
      {/* Main routes with layout */}
      <Route
        path="/"
        element={
          <TabletLayout>
            <Suspense fallback={<LoadingFallback />}>
              <OnboardingPage />
            </Suspense>
          </TabletLayout>
        }
      />

      <Route
        path="/social"
        element={
          <TabletLayout>
            <Suspense fallback={<LoadingFallback />}>
              <SocialLinksPage />
            </Suspense>
          </TabletLayout>
        }
      />

      {/* AI Consultant - fullscreen, no layout */}
      <Route
        path="/ai-consultant"
        element={
          <Suspense fallback={<LoadingFallback />}>
            <AIConsultantPage />
          </Suspense>
        }
      />

      {/* Redirect any unknown routes to home */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

// Root App component
export default function App() {
  return (
    <ErrorBoundary>
      <LanguageProvider>
        <SalonProvider>
          <CustomerProvider>
            <HashRouter>
              <ProtectedRoute>
                <AppContent />
              </ProtectedRoute>
            </HashRouter>
          </CustomerProvider>
        </SalonProvider>
      </LanguageProvider>
    </ErrorBoundary>
  );
}
