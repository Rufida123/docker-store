import { Suspense, useEffect, useState } from 'react';
import { Outlet, NavLink, useNavigate, useLocation } from 'react-router-dom';
import {
  AppBar,
  Avatar,
  Box,
  Button,
  Chip,
  Divider,
  Drawer,
  IconButton,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Menu,
  MenuItem,
  Stack,
  Toolbar,
  Tooltip,
  Typography,
  alpha,
  useMediaQuery,
  useTheme,
} from '@mui/material';
import MenuIcon from '@mui/icons-material/Menu';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLongOutlined';
import AddShoppingCartIcon from '@mui/icons-material/AddShoppingCartOutlined';
import PeopleIcon from '@mui/icons-material/PeopleAltOutlined';
import LogoutIcon from '@mui/icons-material/LogoutOutlined';
import DarkModeIcon from '@mui/icons-material/DarkModeOutlined';
import LightModeIcon from '@mui/icons-material/LightModeOutlined';
import StorefrontIcon from '@mui/icons-material/Storefront';
import Inventory2Icon from '@mui/icons-material/Inventory2Outlined';
import { useAuth } from '../context/AuthContext';
import { useColorMode } from '../theme';
import { initials } from '../utils/format';
import PageLoader from './PageLoader';

const DRAWER_WIDTH = 264;

function Brand() {
  return (
    <Stack direction="row" spacing={1.5} alignItems="center" sx={{ px: 2.5, py: 2.5 }}>
      <Avatar sx={{ bgcolor: 'primary.main', width: 38, height: 38, borderRadius: 2.5 }}>
        <StorefrontIcon fontSize="small" />
      </Avatar>
      <Box>
        <Typography fontWeight={700} lineHeight={1.1}>
          Orders
        </Typography>
        <Typography variant="caption" color="text.secondary">
          {/* role-aware tagline */}
          Store console
        </Typography>
      </Box>
    </Stack>
  );
}

export default function Layout() {
  const theme = useTheme();
  const isDesktop = useMediaQuery(theme.breakpoints.up('md'));
  const { user, isAdmin, logout } = useAuth();
  const { mode, toggle } = useColorMode();
  const navigate = useNavigate();
  const location = useLocation();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [anchor, setAnchor] = useState(null);

  const nav = [
    { to: '/orders', label: 'Orders', icon: <ReceiptLongIcon />, match: (p) => p.startsWith('/orders') && p !== '/orders/new' },
    { to: '/products', label: 'Products', icon: <Inventory2Icon />, match: (p) => p.startsWith('/products') },
    ...(!isAdmin
      ? [{ to: '/orders/new', label: 'New order', icon: <AddShoppingCartIcon />, match: (p) => p === '/orders/new' }]
      : []),
    ...(isAdmin ? [{ to: '/users', label: 'Users', icon: <PeopleIcon />, match: (p) => p.startsWith('/users') }] : []),
  ];

  const current = nav.find((n) => n.match(location.pathname));

  // New page = start at the top, and give it a meaningful browser-tab title.
  useEffect(() => {
    window.scrollTo(0, 0);
  }, [location.pathname]);
  useEffect(() => {
    document.title = current ? `${current.label} · Orders` : 'Orders';
  }, [current]);

  const signOut = () => {
    setAnchor(null);
    setMobileOpen(false);
    logout();
    navigate('/login');
  };

  const drawer = (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      <Brand />
      <Divider />
      <List component="nav" aria-label="Main" sx={{ px: 1.5, py: 2, flexGrow: 1 }}>
        <Typography variant="overline" color="text.secondary" sx={{ px: 1.5 }}>
          Menu
        </Typography>
        {nav.map((item) => {
          const selected = item.match(location.pathname);
          return (
            <ListItemButton
              key={item.to}
              component={NavLink}
              to={item.to}
              selected={selected}
              aria-current={selected ? 'page' : undefined}
              onClick={() => setMobileOpen(false)}
              sx={{
                mb: 0.5,
                '&.Mui-selected': {
                  bgcolor: 'primary.main',
                  color: 'primary.contrastText',
                  '& .MuiListItemIcon-root': { color: 'inherit' },
                  '&:hover': { bgcolor: 'primary.dark' },
                },
              }}
            >
              <ListItemIcon sx={{ minWidth: 40 }}>{item.icon}</ListItemIcon>
              <ListItemText primary={item.label} primaryTypographyProps={{ fontWeight: 600, fontSize: 14 }} />
            </ListItemButton>
          );
        })}
      </List>
      <Divider />
      <Stack direction="row" spacing={1.5} alignItems="center" sx={{ p: 2 }}>
        <Avatar sx={{ bgcolor: 'primary.main', width: 36, height: 36, fontSize: 14 }}>{initials(user)}</Avatar>
        <Box sx={{ minWidth: 0, flexGrow: 1 }}>
          <Typography variant="body2" fontWeight={600} noWrap title={user?.email}>
            {user?.name || user?.email}
          </Typography>
          <Chip size="small" label={user?.role} sx={{ height: 20, fontSize: 11, textTransform: 'capitalize' }} />
        </Box>
        <Tooltip title="Sign out">
          <IconButton size="small" onClick={signOut} aria-label="Sign out">
            <LogoutIcon fontSize="small" />
          </IconButton>
        </Tooltip>
      </Stack>
    </Box>
  );

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      {/* Keyboard users can jump straight past the navigation. */}
      <Button
        href="#main"
        sx={{
          position: 'fixed',
          left: 8,
          top: -64,
          zIndex: 3000,
          bgcolor: 'background.paper',
          '&:focus': { top: 8 },
        }}
      >
        Skip to content
      </Button>

      <AppBar
        position="fixed"
        color="inherit"
        elevation={0}
        sx={{
          width: { md: `calc(100% - ${DRAWER_WIDTH}px)` },
          ml: { md: `${DRAWER_WIDTH}px` },
          bgcolor: (t) => alpha(t.palette.background.default, 0.82),
          backdropFilter: 'blur(12px)',
          borderBottom: 1,
          borderColor: 'divider',
        }}
      >
        <Toolbar>
          {!isDesktop && (
            <IconButton edge="start" onClick={() => setMobileOpen(true)} sx={{ mr: 1 }} aria-label="Open menu">
              <MenuIcon />
            </IconButton>
          )}
          <Typography fontWeight={700} sx={{ display: { md: 'none' } }}>
            {current?.label || 'Orders'}
          </Typography>
          <Box sx={{ flexGrow: 1 }} />
          <Tooltip title={mode === 'dark' ? 'Light mode' : 'Dark mode'}>
            <IconButton onClick={toggle} aria-label="Toggle color mode">
              {mode === 'dark' ? <LightModeIcon /> : <DarkModeIcon />}
            </IconButton>
          </Tooltip>
          <Tooltip title="Account">
            <IconButton onClick={(e) => setAnchor(e.currentTarget)} sx={{ ml: 0.5 }} aria-label="Account menu">
              <Avatar sx={{ bgcolor: 'primary.main', width: 34, height: 34, fontSize: 13 }}>{initials(user)}</Avatar>
            </IconButton>
          </Tooltip>
          <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)}>
            <Box sx={{ px: 2, py: 1 }}>
              <Typography variant="body2" fontWeight={600}>
                {user?.name || 'Signed in'}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {user?.email}
              </Typography>
            </Box>
            <Divider />
            <MenuItem onClick={signOut}>
              <ListItemIcon>
                <LogoutIcon fontSize="small" />
              </ListItemIcon>
              Sign out
            </MenuItem>
          </Menu>
        </Toolbar>
      </AppBar>

      <Box component="nav" aria-label="Sidebar" sx={{ width: { md: DRAWER_WIDTH }, flexShrink: { md: 0 } }}>
        <Drawer
          variant={isDesktop ? 'permanent' : 'temporary'}
          open={isDesktop ? true : mobileOpen}
          onClose={() => setMobileOpen(false)}
          ModalProps={{ keepMounted: true }}
          PaperProps={{ sx: { width: DRAWER_WIDTH, borderRight: 1, borderColor: 'divider' } }}
        >
          {drawer}
        </Drawer>
      </Box>

      <Box component="main" id="main" tabIndex={-1} sx={{ flexGrow: 1, minWidth: 0, p: { xs: 2, sm: 3, md: 4 }, pt: { xs: 10, md: 11 }, outline: 'none' }}>
        <Box sx={{ maxWidth: 1100, mx: 'auto' }}>
          <Suspense fallback={<PageLoader />}>
            <Outlet />
          </Suspense>
        </Box>
      </Box>
    </Box>
  );
}
