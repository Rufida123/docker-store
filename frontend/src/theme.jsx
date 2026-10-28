import { createContext, useContext, useMemo, useState, useEffect } from 'react';
import { createTheme, ThemeProvider, CssBaseline, alpha } from '@mui/material';
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';

const ColorModeContext = createContext({ mode: 'light', toggle: () => {} });
export const useColorMode = () => useContext(ColorModeContext);

const readMode = () => {
  try {
    const saved = localStorage.getItem('orders.mode');
    if (saved === 'light' || saved === 'dark') return saved;
  } catch {
    /* ignore */
  }
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

function buildTheme(mode) {
  const dark = mode === 'dark';
  return createTheme({
    palette: {
      mode,
      primary: { main: dark ? '#818cf8' : '#4f46e5' },
      secondary: { main: '#0ea5e9' },
      success: { main: '#16a34a' },
      warning: { main: '#d97706' },
      error: { main: '#dc2626' },
      background: dark
        ? { default: '#0b1020', paper: '#121830' }
        : { default: '#f5f6fb', paper: '#ffffff' },
      divider: dark ? 'rgba(255,255,255,0.08)' : 'rgba(15,23,42,0.08)',
    },
    shape: { borderRadius: 12 },
    typography: {
      fontFamily: '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
      h4: { fontWeight: 700, letterSpacing: '-0.02em' },
      h5: { fontWeight: 700, letterSpacing: '-0.01em' },
      h6: { fontWeight: 600 },
      button: { textTransform: 'none', fontWeight: 600 },
    },
    components: {
      MuiCssBaseline: {
        styleOverrides: {
          html: { colorScheme: mode, scrollBehavior: 'smooth' },
          '@media (prefers-reduced-motion: reduce)': {
            '*, *::before, *::after': { animationDuration: '0.01ms !important', transitionDuration: '0.01ms !important', scrollBehavior: 'auto !important' },
          },
        },
      },
      MuiButton: {
        defaultProps: { disableElevation: true },
        styleOverrides: { root: { borderRadius: 10, paddingInline: 18 } },
      },
      MuiPaper: { styleOverrides: { root: { backgroundImage: 'none' } } },
      MuiCard: {
        defaultProps: { variant: 'outlined' },
        styleOverrides: { root: { borderColor: dark ? 'rgba(255,255,255,0.08)' : 'rgba(15,23,42,0.08)' } },
      },
      MuiTooltip: { defaultProps: { arrow: true } },
      MuiTextField: { defaultProps: { variant: 'outlined', size: 'medium' } },
      MuiOutlinedInput: { styleOverrides: { root: { borderRadius: 10 } } },
      MuiTableRow: {
        styleOverrides: { root: { '&:last-child td': { borderBottom: 0 } } },
      },
      MuiTableCell: {
        styleOverrides: {
          head: ({ theme }) => ({
            fontWeight: 600,
            fontSize: 12,
            textTransform: 'uppercase',
            letterSpacing: '0.06em',
            color: theme.palette.text.secondary,
            backgroundColor: alpha(theme.palette.primary.main, dark ? 0.08 : 0.04),
          }),
        },
      },
      MuiChip: { styleOverrides: { root: { fontWeight: 600 } } },
      MuiListItemButton: { styleOverrides: { root: { borderRadius: 10 } } },
    },
  });
}

export function AppThemeProvider({ children }) {
  const [mode, setMode] = useState(readMode);
  useEffect(() => {
    try {
      localStorage.setItem('orders.mode', mode);
    } catch {
      /* ignore */
    }
  }, [mode]);
  const theme = useMemo(() => buildTheme(mode), [mode]);
  const value = useMemo(
    () => ({ mode, toggle: () => setMode((m) => (m === 'light' ? 'dark' : 'light')) }),
    [mode]
  );
  return (
    <ColorModeContext.Provider value={value}>
      <ThemeProvider theme={theme}>
        <CssBaseline />
        {children}
      </ThemeProvider>
    </ColorModeContext.Provider>
  );
}
