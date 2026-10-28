import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, getStoredAuth, storeAuth, STORAGE_KEY } from '../api/client';
import { clearCache } from '../hooks/useQuery';

const AuthContext = createContext(null);
export const useAuth = () => useContext(AuthContext);

// Expiry (ms) read from the JWT payload, or null if it can't be read.
function tokenExpiry(token) {
  try {
    const payload = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const { exp } = JSON.parse(atob(payload));
    return exp ? exp * 1000 : null;
  } catch {
    return null;
  }
}

export function AuthProvider({ children }) {
  const [auth, setAuth] = useState(() => {
    const stored = getStoredAuth();
    const exp = stored?.token ? tokenExpiry(stored.token) : null;
    if (exp && exp <= Date.now()) {
      storeAuth(null);
      return null;
    }
    return stored;
  });
  const [sessionExpired, setSessionExpired] = useState(false);

  const logout = useCallback(() => {
    storeAuth(null);
    clearCache(); // never leak one user's cached data to the next
    setAuth(null);
  }, []);

  const expire = useCallback(() => {
    clearCache();
    setAuth(null);
    setSessionExpired(true);
  }, []);

  // The API client emits this when any request returns 401 (token expired or invalid).
  useEffect(() => {
    window.addEventListener('auth:expired', expire);
    return () => window.removeEventListener('auth:expired', expire);
  }, [expire]);

  // Sign out / in from another tab is reflected here too.
  useEffect(() => {
    const onStorage = (e) => {
      if (e.key !== STORAGE_KEY) return;
      clearCache();
      setAuth(getStoredAuth());
    };
    window.addEventListener('storage', onStorage);
    return () => window.removeEventListener('storage', onStorage);
  }, []);

  // Sign out automatically the moment the token expires (no failed request needed).
  const token = auth?.token;
  useEffect(() => {
    const exp = token ? tokenExpiry(token) : null;
    if (!exp) return undefined;
    const ms = Math.min(exp - Date.now(), 2 ** 31 - 1);
    if (ms <= 0) {
      storeAuth(null);
      expire();
      return undefined;
    }
    const t = setTimeout(() => {
      storeAuth(null);
      expire();
    }, ms);
    return () => clearTimeout(t);
  }, [token, expire]);

  // Refresh the stored user once per app start so role changes are picked up.
  useEffect(() => {
    if (!token) return;
    let cancelled = false;
    api
      .me()
      .then((user) => {
        if (cancelled || !user) return;
        const next = { token, user };
        storeAuth(next);
        setAuth(next);
      })
      .catch(() => {}); // a 401 is handled by the client (auth:expired); other errors are non-fatal
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const login = useCallback(async (email, password) => {
    const res = await api.login({ email, password });
    const newToken = res?.token || res?.accessToken || res?.access_token;
    if (!newToken) throw new Error('Login succeeded but no token was returned.');
    clearCache();
    // Persist the token first so /auth/me can be called if no user came back.
    storeAuth({ token: newToken, user: res.user || null });
    try {
      const user = res.user || (await api.me());
      const next = { token: newToken, user };
      storeAuth(next);
      setSessionExpired(false);
      setAuth(next);
      return user;
    } catch (err) {
      storeAuth(null); // don't keep a half-finished session
      throw err;
    }
  }, []);

  const register = useCallback(
    async (payload) => {
      await api.register(payload);
      return login(payload.email, payload.password);
    },
    [login]
  );

  const value = useMemo(
    () => ({
      user: auth?.user || null,
      token: auth?.token || null,
      isAuthenticated: Boolean(auth?.token),
      isAdmin: auth?.user?.role === 'admin',
      sessionExpired,
      login,
      register,
      logout,
    }),
    [auth, sessionExpired, login, register, logout]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
