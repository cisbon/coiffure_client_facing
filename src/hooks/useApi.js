/**
 * useApi Hook
 * Generic hook for API calls with loading/error states
 */

import { useState, useCallback } from 'react';

/**
 * Hook for handling API calls with loading and error states
 * @param {Function} apiFunction - The API function to call
 */
export function useApi(apiFunction) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  /**
   * Execute the API call
   */
  const execute = useCallback(async (...args) => {
    setLoading(true);
    setError(null);

    try {
      const result = await apiFunction(...args);
      setData(result);
      return result;
    } catch (err) {
      const errorMessage = err.message || 'An error occurred';
      setError(errorMessage);
      throw err;
    } finally {
      setLoading(false);
    }
  }, [apiFunction]);

  /**
   * Reset state
   */
  const reset = useCallback(() => {
    setData(null);
    setError(null);
    setLoading(false);
  }, []);

  return {
    data,
    loading,
    error,
    execute,
    reset,
    isSuccess: !loading && !error && data !== null,
    isError: !loading && error !== null,
  };
}

export default useApi;
