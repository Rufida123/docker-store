import { useState } from 'react';
import { Link as RouterLink, Navigate, useNavigate } from 'react-router-dom';
import { Button, IconButton, InputAdornment, Link, Stack, TextField, Typography } from '@mui/material';
import Visibility from '@mui/icons-material/VisibilityOutlined';
import VisibilityOff from '@mui/icons-material/VisibilityOffOutlined';
import { fieldErrors } from '../api/client';
import { useAuth } from '../context/AuthContext';
import AuthShell from '../components/AuthShell';
import ErrorAlert from '../components/ErrorAlert';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MIN_PASSWORD = 8;

export default function Register() {
  const { register, isAuthenticated } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ email: '', password: '', confirm: '' });
  const [touched, setTouched] = useState({});
  const [show, setShow] = useState(false);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);

  if (isAuthenticated) return <Navigate to="/orders" replace />;

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const blur = (k) => () => setTouched((t) => ({ ...t, [k]: true }));

  const server = fieldErrors(error); // inline errors from the API, e.g. email already used
  const errors = {
    email: !EMAIL_RE.test(form.email.trim()) ? 'Enter a valid email address' : '',
    password: form.password.length < MIN_PASSWORD ? `Use at least ${MIN_PASSWORD} characters` : '',
    confirm: form.confirm !== form.password ? 'Passwords do not match' : '',
  };
  const shown = (k) => (touched[k] && errors[k]) || server[k] || '';
  const valid = !errors.email && !errors.password && !errors.confirm;

  const submit = async (e) => {
    e.preventDefault();
    setTouched({ email: true, password: true, confirm: true });
    if (!valid || loading) return;
    setError(null);
    setLoading(true);
    try {
      // The API takes email + password; the account is created as a customer.
      await register({ email: form.email.trim(), password: form.password });
      navigate('/orders', { replace: true });
    } catch (err) {
      setError(err);
      setLoading(false);
    }
  };

  const toggle = (
    <InputAdornment position="end">
      <IconButton onClick={() => setShow((s) => !s)} edge="end" aria-label={show ? 'Hide passwords' : 'Show passwords'}>
        {show ? <VisibilityOff /> : <Visibility />}
      </IconButton>
    </InputAdornment>
  );

  return (
    <AuthShell title="Create your account" subtitle="It takes less than a minute.">
      <form onSubmit={submit} noValidate>
        <Stack spacing={2}>
          {/* Field-level problems are shown under the fields, so only show the banner for the rest. */}
          <ErrorAlert error={error && Object.keys(server).length === 0 ? error : null} />
          <TextField
            label="Email"
            type="email"
            autoComplete="email"
            autoFocus
            required
            fullWidth
            value={form.email}
            onChange={set('email')}
            onBlur={blur('email')}
            error={Boolean(shown('email'))}
            helperText={shown('email') || ' '}
          />
          <TextField
            label="Password"
            type={show ? 'text' : 'password'}
            autoComplete="new-password"
            required
            fullWidth
            value={form.password}
            onChange={set('password')}
            onBlur={blur('password')}
            error={Boolean(shown('password'))}
            helperText={shown('password') || `At least ${MIN_PASSWORD} characters`}
            InputProps={{ endAdornment: toggle }}
          />
          <TextField
            label="Confirm password"
            type={show ? 'text' : 'password'}
            autoComplete="new-password"
            required
            fullWidth
            value={form.confirm}
            onChange={set('confirm')}
            onBlur={blur('confirm')}
            error={Boolean(shown('confirm'))}
            helperText={shown('confirm') || ' '}
          />
          <Button type="submit" variant="contained" size="large" disabled={loading}>
            {loading ? 'Creating account…' : 'Create account'}
          </Button>
          <Typography variant="body2" color="text.secondary" textAlign="center">
            Already have an account?{' '}
            <Link component={RouterLink} to="/login" fontWeight={600} underline="hover">
              Sign in
            </Link>
          </Typography>
        </Stack>
      </form>
    </AuthShell>
  );
}
