/**
 * Customer Context
 * Manages current customer session data
 */

import { createContext, useContext, useReducer, useCallback } from 'react';
import { STORAGE_KEYS } from '../config/constants';

// Initial state
const initialState = {
  customer: null,
  formDraft: null,
  aiConsultation: null,
  isSubmitting: false,
  error: null,
};

// Action types
const ACTIONS = {
  SET_CUSTOMER: 'SET_CUSTOMER',
  SAVE_FORM_DRAFT: 'SAVE_FORM_DRAFT',
  CLEAR_FORM_DRAFT: 'CLEAR_FORM_DRAFT',
  SET_AI_CONSULTATION: 'SET_AI_CONSULTATION',
  CLEAR_AI_CONSULTATION: 'CLEAR_AI_CONSULTATION',
  SET_SUBMITTING: 'SET_SUBMITTING',
  SET_ERROR: 'SET_ERROR',
  RESET: 'RESET',
};

// Reducer
function customerReducer(state, action) {
  switch (action.type) {
    case ACTIONS.SET_CUSTOMER:
      return {
        ...state,
        customer: action.payload,
        error: null,
      };

    case ACTIONS.SAVE_FORM_DRAFT:
      // Persist to sessionStorage
      sessionStorage.setItem(STORAGE_KEYS.FORM_DRAFT, JSON.stringify(action.payload));
      return {
        ...state,
        formDraft: action.payload,
      };

    case ACTIONS.CLEAR_FORM_DRAFT:
      sessionStorage.removeItem(STORAGE_KEYS.FORM_DRAFT);
      return {
        ...state,
        formDraft: null,
      };

    case ACTIONS.SET_AI_CONSULTATION:
      return {
        ...state,
        aiConsultation: action.payload,
      };

    case ACTIONS.CLEAR_AI_CONSULTATION:
      return {
        ...state,
        aiConsultation: null,
      };

    case ACTIONS.SET_SUBMITTING:
      return {
        ...state,
        isSubmitting: action.payload,
      };

    case ACTIONS.SET_ERROR:
      return {
        ...state,
        error: action.payload,
        isSubmitting: false,
      };

    case ACTIONS.RESET:
      sessionStorage.removeItem(STORAGE_KEYS.FORM_DRAFT);
      return initialState;

    default:
      return state;
  }
}

// Load initial form draft from sessionStorage
function getInitialState() {
  const draft = sessionStorage.getItem(STORAGE_KEYS.FORM_DRAFT);
  if (draft) {
    try {
      return {
        ...initialState,
        formDraft: JSON.parse(draft),
      };
    } catch {
      return initialState;
    }
  }
  return initialState;
}

// Create context
const CustomerContext = createContext();

/**
 * Customer Provider Component
 */
export function CustomerProvider({ children }) {
  const [state, dispatch] = useReducer(customerReducer, null, getInitialState);

  // Actions
  const setCustomer = useCallback((customer) => {
    dispatch({ type: ACTIONS.SET_CUSTOMER, payload: customer });
  }, []);

  const saveFormDraft = useCallback((formData) => {
    dispatch({ type: ACTIONS.SAVE_FORM_DRAFT, payload: formData });
  }, []);

  const clearFormDraft = useCallback(() => {
    dispatch({ type: ACTIONS.CLEAR_FORM_DRAFT });
  }, []);

  const setAIConsultation = useCallback((data) => {
    dispatch({ type: ACTIONS.SET_AI_CONSULTATION, payload: data });
  }, []);

  const clearAIConsultation = useCallback(() => {
    dispatch({ type: ACTIONS.CLEAR_AI_CONSULTATION });
  }, []);

  const setSubmitting = useCallback((isSubmitting) => {
    dispatch({ type: ACTIONS.SET_SUBMITTING, payload: isSubmitting });
  }, []);

  const setError = useCallback((error) => {
    dispatch({ type: ACTIONS.SET_ERROR, payload: error });
  }, []);

  const reset = useCallback(() => {
    dispatch({ type: ACTIONS.RESET });
  }, []);

  // Context value
  const value = {
    ...state,
    setCustomer,
    saveFormDraft,
    clearFormDraft,
    setAIConsultation,
    clearAIConsultation,
    setSubmitting,
    setError,
    reset,
  };

  return (
    <CustomerContext.Provider value={value}>
      {children}
    </CustomerContext.Provider>
  );
}

/**
 * Hook to access customer context
 */
export function useCustomer() {
  const context = useContext(CustomerContext);

  if (!context) {
    throw new Error('useCustomer must be used within a CustomerProvider');
  }

  return context;
}

export default CustomerContext;
