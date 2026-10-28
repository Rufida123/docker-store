import { Stack, Typography } from '@mui/material';

export default function EmptyState({ icon, title, description, action, py = 8 }) {
  return (
    <Stack alignItems="center" textAlign="center" spacing={1.5} sx={{ py, px: 2 }}>
      {icon && <Stack sx={{ color: 'text.disabled', '& svg': { fontSize: 52 } }}>{icon}</Stack>}
      <Typography fontWeight={600}>{title}</Typography>
      {description && (
        <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 380 }}>
          {description}
        </Typography>
      )}
      {action}
    </Stack>
  );
}
