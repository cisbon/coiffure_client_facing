/**
 * Salon Context
 * Provides salon configuration and branding throughout the app
 */

import { createContext, useContext, useReducer, useEffect, useCallback } from 'react';
import { getSalonBranding, getSocialLinks } from '../services/salonService';
import { getUserSalonId } from '../services/authService';

// Initial state
const initialState = {
  salon: null,
  branding: null,
  socialLinks: [],
  loading: true,
  error: null,
};

// Action types
const ACTIONS = {
  FETCH_START: 'FETCH_START',
  FETCH_SUCCESS: 'FETCH_SUCCESS',
  FETCH_ERROR: 'FETCH_ERROR',
  SET_SOCIAL_LINKS: 'SET_SOCIAL_LINKS',
  UPDATE_BRANDING: 'UPDATE_BRANDING',
};

// Reducer
function salonReducer(state, action) {
  switch (action.type) {
    case ACTIONS.FETCH_START:
      return { ...state, loading: true, error: null };

    case ACTIONS.FETCH_SUCCESS:
      return {
        ...state,
        salon: action.payload.salon,
        branding: action.payload.branding,
        loading: false,
        error: null,
      };

    case ACTIONS.FETCH_ERROR:
      return {
        ...state,
        loading: false,
        error: action.payload,
      };

    case ACTIONS.SET_SOCIAL_LINKS:
      return {
        ...state,
        socialLinks: action.payload,
      };

    case ACTIONS.UPDATE_BRANDING:
      return {
        ...state,
        branding: { ...state.branding, ...action.payload },
      };

    default:
      return state;
  }
}

// Create context
const SalonContext = createContext();

/**
 * Salon Provider Component
 */
export function SalonProvider({ salonId: propSalonId, children }) {
  const [state, dispatch] = useReducer(salonReducer, initialState);

  // Determine salon ID (prop > user's assigned salon)
  const salonId = propSalonId || getUserSalonId();

  // Fetch salon data
  const fetchSalonData = useCallback(async () => {
    if (!salonId) {
      dispatch({
        type: ACTIONS.FETCH_ERROR,
        payload: 'No salon ID available',
      });
      return;
    }

    dispatch({ type: ACTIONS.FETCH_START });

    try {
      // Fetch branding
      const brandingResponse = await getSalonBranding(salonId);

      if (brandingResponse.success) {
        dispatch({
          type: ACTIONS.FETCH_SUCCESS,
          payload: {
            salon: {
              id: salonId,
              name: brandingResponse.branding?.salon_name || 'Coiffure AI',
            },
            branding: brandingResponse.branding || {},
          },
        });

        // Apply branding CSS variables
        applyBrandingCSS(brandingResponse.branding);
      } else {
        throw new Error(brandingResponse.error || 'Failed to load salon branding');
      }

      // Fetch social links
      try {
        const socialResponse = await getSocialLinks(salonId);
        if (socialResponse.success) {
          dispatch({
            type: ACTIONS.SET_SOCIAL_LINKS,
            payload: socialResponse.links || [],
          });
        }
      } catch (err) {
        console.warn('Failed to load social links:', err);
      }
    } catch (error) {
      dispatch({
        type: ACTIONS.FETCH_ERROR,
        payload: error.message,
      });
    }
  }, [salonId]);

  // Load salon data on mount
  useEffect(() => {
    fetchSalonData();
  }, [fetchSalonData]);

  // Context value
  const value = {
    ...state,
    salonId,
    refetch: fetchSalonData,
  };

  return (
    <SalonContext.Provider value={value}>
      {children}
    </SalonContext.Provider>
  );
}

/**
 * Apply branding colors as CSS variables
 */
function applyBrandingCSS(branding) {
  if (!branding) return;

  const root = document.documentElement;

  if (branding.primary_color) {
    root.style.setProperty('--color-primary', branding.primary_color);
  }

  if (branding.secondary_color) {
    root.style.setProperty('--color-secondary', branding.secondary_color);
  }

  if (branding.background_color) {
    root.style.setProperty('--color-background', branding.background_color);
  }

  if (branding.button_color) {
    root.style.setProperty('--color-primary', branding.button_color);
  }

  if (branding.text_color) {
    root.style.setProperty('--color-text', branding.text_color);
  }
}

/**
 * Hook to access salon context
 */
export function useSalon() {
  const context = useContext(SalonContext);

  if (!context) {
    throw new Error('useSalon must be used within a SalonProvider');
  }

  return context;
}

export default SalonContext;
