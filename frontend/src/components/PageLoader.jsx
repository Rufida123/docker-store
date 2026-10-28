import { LinearProgress } from '@mui/material';

// Thin bar at the top of the screen while a page chunk loads.
export default function PageLoader() {
  return <LinearProgress sx={{ position: 'fixed', top: 0, left: 0, right: 0, zIndex: 2000, height: 3 }} />;
}
