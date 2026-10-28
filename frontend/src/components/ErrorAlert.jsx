import { Alert, AlertTitle, Button } from '@mui/material';
import { describeError } from '../api/client';

export default function ErrorAlert({ error, onRetry, sx }) {
  if (!error) return null;
  const { title, lines, severity } = describeError(error);
  return (
    <Alert
      severity={severity}
      sx={sx}
      action={
        onRetry ? (
          <Button color="inherit" size="small" onClick={onRetry}>
            Retry
          </Button>
        ) : undefined
      }
    >
      <AlertTitle sx={{ mb: lines.length ? 0.5 : 0 }}>{title}</AlertTitle>
      {lines.map((m, i) => (
        <div key={i}>{m}</div>
      ))}
    </Alert>
  );
}
