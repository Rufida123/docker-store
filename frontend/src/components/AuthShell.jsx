import { Avatar, Box, Paper, Stack, Typography } from '@mui/material';
import StorefrontIcon from '@mui/icons-material/Storefront';
import CheckCircleIcon from '@mui/icons-material/CheckCircleOutline';

const points = ['Place and track orders in seconds', 'Real-time status updates', 'Secure, role-based access'];

// Split-screen layout shared by the login and register pages.
export default function AuthShell({ title, subtitle, children }) {
  return (
    <Box sx={{ minHeight: '100vh', display: 'flex' }}>
      <Box
        sx={{
          display: { xs: 'none', md: 'flex' },
          flex: 1,
          flexDirection: 'column',
          justifyContent: 'space-between',
          p: 6,
          color: '#fff',
          background:
            'radial-gradient(circle at 20% 15%, #6366f1 0%, transparent 45%), radial-gradient(circle at 85% 85%, #0ea5e9 0%, transparent 40%), linear-gradient(135deg, #312e81, #1e1b4b)',
        }}
      >
        <Stack direction="row" spacing={1.5} alignItems="center">
          <Avatar sx={{ bgcolor: 'rgba(255,255,255,0.18)', borderRadius: 2.5 }}>
            <StorefrontIcon />
          </Avatar>
          <Typography variant="h6">Orders</Typography>
        </Stack>
        <Box>
          <Typography variant="h3" fontWeight={700} sx={{ letterSpacing: '-0.03em', maxWidth: 460 }}>
            Every order, one clear view.
          </Typography>
          <Stack spacing={1.5} sx={{ mt: 4 }}>
            {points.map((p) => (
              <Stack key={p} direction="row" spacing={1.5} alignItems="center">
                <CheckCircleIcon sx={{ opacity: 0.85 }} />
                <Typography sx={{ opacity: 0.9 }}>{p}</Typography>
              </Stack>
            ))}
          </Stack>
        </Box>
        <Typography variant="caption" sx={{ opacity: 0.6 }}>
          © {new Date().getFullYear()} Orders
        </Typography>
      </Box>

      <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', p: 3 }}>
        <Paper elevation={0} sx={{ width: '100%', maxWidth: 420, bgcolor: 'transparent' }}>
          <Typography variant="h4" component="h1">
            {title}
          </Typography>
          <Typography color="text.secondary" sx={{ mt: 1, mb: 4 }}>
            {subtitle}
          </Typography>
          {children}
        </Paper>
      </Box>
    </Box>
  );
}
