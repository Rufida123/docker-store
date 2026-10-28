import { Chip } from '@mui/material';
import { STATUS_COLORS } from '../utils/format';

export default function StatusChip({ status, size = 'small' }) {
  const label = status || 'unknown';
  return (
    <Chip
      size={size}
      label={label.charAt(0).toUpperCase() + label.slice(1)}
      color={STATUS_COLORS[label] || 'default'}
      variant="outlined"
      sx={{ borderWidth: 1.5 }}
    />
  );
}
