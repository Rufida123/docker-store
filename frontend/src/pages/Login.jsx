import { useState } from 'react';
import { Link as RouterLink, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Alert, Button, IconButton, InputAdornment, Link, Stack, TextField, Typography } from '@mui/material';
import Visibility from '@mui/icons-material/VisibilityOutlined';
import VisibilityOff from '@mui/icons-material/VisibilityOffOutlined';
import { useAuth } from '../context/AuthContext';
import AuthShell from '../components/AuthShell';
import ErrorAlert from '../components/ErrorAlert';

export default function Login() {
  const { login, isAuthenticated, sessionExpired } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const fromLoc = location.state?.from;
  const from = fromLoc ? `${fromLoc.pathname}${fromLoc.search || ''}` : '/orders';
  const [form, setForm] = useState({ email: '', password: '' });
  const [show, setShow] = useState(false);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);

  if (isAuthenticated) return <Navigate to={from} replace />;

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  // After 5 failed attempts the API blocks this IP + email for 15 minutes (429).
  const blocked = error?.code === 'TOO_MANY_REQUESTS';

  const submit = async (e) => {
    e.preventDefault();
    if (loading) return;
    setError(null);
    setLoading(true);
    try {
      await login(form.email.trim(), form.password);
      navigate(from, { replace: true });
    } catch (err) {
      setError(err);
      setLoading(false);
    }
  };

  return (
    <AuthShell title="Welcome back" subtitle="Sign in to manage your orders.">
      <form onSubmit={submit} noValidate>
        <Stack spacing={2.5}>
          {sessionExpired && !error && (
            <Alert severity="info">Your session has expired. Please sign in again.</Alert>
          )}
          <ErrorAlert error={error} />
          <TextField
            label="Email"
            type="email"
            autoComplete="username"
            autoFocus
            required
            fullWidth
            value={form.email}
            onChange={set('email')}
          />
          <TextField
            label="Password"
            type={show ? 'text' : 'password'}
            autoComplete="current-password"
            required
            fullWidth
            value={form.password}
            onChange={set('password')}
            InputProps={{
              endAdornment: (
                <InputAdornment position="end">
                  <IconButton
                    onClick={() => setShow((s) => !s)}
                    edge="end"
                    aria-label={show ? 'Hide password' : 'Show password'}
                  >
                    {show ? <VisibilityOff /> : <Visibility />}
                  </IconButton>
                </InputAdornment>
              ),
            }}
          />
          <Button
            type="submit"
            variant="contained"
            size="large"
            disabled={loading || blocked || !form.email || !form.password}
          >
            {loading ? 'Signing in…' : 'Sign in'}
          </Button>
          <Typography variant="body2" color="text.secondary" textAlign="center">
            New here?{' '}
            <Link component={RouterLink} to="/register" fontWeight={600} underline="hover">
              Create an account
            </Link>
          </Typography>
        </Stack>
      </form>
    </AuthShell>
  );
}
