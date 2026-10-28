import { Alert, Box, Step, StepLabel, Stepper, Typography } from '@mui/material';
import { STATUS_FLOW, capitalize, formatDate, historyTime } from '../utils/format';

// Visual progress of an order: pending -> confirmed -> shipped -> delivered,
// with the date each step was reached (taken from the order history).
export default function OrderProgress({ order }) {
  const history = Array.isArray(order.history) ? order.history : [];
  const when = (s) => {
    const entry = history.find((h) => h.to_status === s);
    return entry ? formatDate(historyTime(entry)) : '';
  };

  if (order.status === 'cancelled') {
    return (
      <Alert severity="error" variant="outlined">
        This order was cancelled{when('cancelled') ? ` on ${when('cancelled')}` : ''}. The reserved stock was returned.
      </Alert>
    );
  }

  const idx = STATUS_FLOW.indexOf(order.status);
  const activeStep = order.status === 'delivered' ? STATUS_FLOW.length : Math.max(idx, 0);

  return (
    <Box sx={{ overflowX: 'auto', pb: 0.5 }}>
      <Stepper activeStep={activeStep} alternativeLabel sx={{ minWidth: 420 }}>
        {STATUS_FLOW.map((s) => (
          <Step key={s}>
            <StepLabel
              optional={
                <Typography variant="caption" color="text.secondary">
                  {when(s) || ' '}
                </Typography>
              }
            >
              {capitalize(s)}
            </StepLabel>
          </Step>
        ))}
      </Stepper>
    </Box>
  );
}
