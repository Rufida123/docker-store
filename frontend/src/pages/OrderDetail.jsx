import { useEffect, useMemo, useState } from 'react';
import { Link as RouterLink, useLocation, useNavigate, useParams } from 'react-router-dom';
import {
  Box,
  Button,
  Card,
  Divider,
  IconButton,
  Link,
  Skeleton,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tooltip,
  Typography,
} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import CopyIcon from '@mui/icons-material/ContentCopy';
import SearchOffIcon from '@mui/icons-material/SearchOff';
import { api } from '../api/client';
import { useAuth } from '../context/AuthContext';
import { invalidate } from '../hooks/useQuery';
import { orderUpdated, useCatalog, useOrder, useUsers } from '../hooks/queries';
import { useToast } from '../components/Toast';
import PageHeader from '../components/PageHeader';
import StatusChip from '../components/StatusChip';
import ErrorAlert from '../components/ErrorAlert';
import EmptyState from '../components/EmptyState';
import ConfirmDialog from '../components/ConfirmDialog';
import OrderProgress from '../components/OrderProgress';
import { TRANSITIONS, UUID_RE, formatDate, formatMoney, historyTime, lineTotal, shortId, userLabel } from '../utils/format';

function Field({ label, children }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary">
        {label}
      </Typography>
      <Box sx={{ mt: 0.25 }}>{children}</Box>
    </Box>
  );
}

const mono = { fontFamily: 'ui-monospace, monospace', wordBreak: 'break-all' };

export default function OrderDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { isAdmin } = useAuth();
  const toast = useToast();
  const [saving, setSaving] = useState('');
  const [saveError, setSaveError] = useState(null);
  const [confirmCancel, setConfirmCancel] = useState(false);

  const validId = UUID_RE.test(id);
  const { data: order, loading, error, refetch } = useOrder(id, { enabled: validId });

  // Item lines may only carry product ids: resolve names from the shared catalog cache.
  const items = Array.isArray(order?.items) ? order.items : [];
  const needNames = items.some((it) => !(it.name || it.product_name));
  const catalog = useCatalog({ enabled: needNames });
  const names = useMemo(() => new Map((catalog.data || []).map((p) => [p.id, p.name])), [catalog.data]);

  // Admins see the customer's email instead of a bare id.
  const users = useUsers({ enabled: isAdmin && Boolean(order) });
  const customer = users.data?.find((u) => u.id === order?.user_id);

  useEffect(() => setSaveError(null), [id]);

  const goBack = () => (location.key !== 'default' ? navigate(-1) : navigate('/orders'));
  const notFound = !validId || error?.code === 'ORDER_NOT_FOUND';

  const next = TRANSITIONS[order?.status] || [];
  const forward = next.find((s) => s !== 'cancelled');
  const canCancel = next.includes('cancelled');

  const change = async (target) => {
    setSaving(target);
    setSaveError(null);
    try {
      const updated = await api.changeStatus(order.id, target);
      orderUpdated(updated); // caches the new order, refreshes lists (and stock when cancelled)
      toast(target === 'cancelled' ? 'Order cancelled and stock returned' : `Order marked as ${target}`);
      setConfirmCancel(false);
    } catch (err) {
      setSaveError(err);
      setConfirmCancel(false);
      if (err.status === 409) invalidate('order'); // someone else changed it: show the real status
    } finally {
      setSaving('');
    }
  };

  const copyId = async () => {
    try {
      await navigator.clipboard.writeText(order.id);
      toast('Order ID copied');
    } catch {
      toast('Could not copy', 'error');
    }
  };

  return (
    <>
      <Button startIcon={<ArrowBackIcon />} onClick={goBack} sx={{ mb: 1, ml: -1 }}>
        Back to orders
      </Button>

      {notFound && (
        <Card>
          <EmptyState
            icon={<SearchOffIcon />}
            title="Order not found"
            description="It does not exist, or it belongs to another customer."
            action={
              <Button variant="contained" component={RouterLink} to="/orders">
                Go to orders
              </Button>
            }
          />
        </Card>
      )}

      {!notFound && error && !order && <ErrorAlert error={error} onRetry={refetch} />}

      {!notFound && loading && (
        <>
          <Skeleton variant="text" width={240} height={44} />
          <Skeleton variant="rounded" height={260} sx={{ mt: 2 }} />
        </>
      )}

      {order && (
        <>
          <PageHeader
            title={`Order #${shortId(order.id)}`}
            subtitle={`Placed ${formatDate(order.created_at || order.createdAt)}`}
            action={<StatusChip status={order.status} size="medium" />}
          />

          <Card sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
            <OrderProgress order={order} />
          </Card>

          <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', md: '1fr 320px' }, alignItems: 'start' }}>
            <Card sx={{ overflow: 'hidden' }}>
              <Box sx={{ p: 2.5 }}>
                <Typography variant="h6">Items</Typography>
              </Box>
              <Box sx={{ overflowX: 'auto' }}>
                <Table>
                  <TableHead>
                    <TableRow>
                      <TableCell>Product</TableCell>
                      <TableCell align="right">Qty</TableCell>
                      <TableCell align="right" sx={{ display: { xs: 'none', sm: 'table-cell' } }}>
                        Unit price
                      </TableCell>
                      <TableCell align="right">Subtotal</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {items.map((it, i) => {
                      const unit = it.unit_price ?? it.price;
                      const sub = it.subtotal ?? (unit !== undefined ? lineTotal(unit, it.quantity) : undefined);
                      const name = it.name || it.product_name || names.get(it.product_id);
                      return (
                        <TableRow key={it.id || i}>
                          <TableCell>
                            {name ? (
                              <Link component={RouterLink} to={`/products/${it.product_id}`} underline="hover" fontWeight={600} color="inherit">
                                {name}
                              </Link>
                            ) : needNames && catalog.loading ? (
                              <Skeleton width={120} />
                            ) : (
                              <Typography fontWeight={600}>Product</Typography>
                            )}
                            <Typography variant="caption" color="text.secondary" display="block" sx={mono}>
                              {shortId(it.product_id)}
                            </Typography>
                          </TableCell>
                          <TableCell align="right">{it.quantity}</TableCell>
                          <TableCell align="right" sx={{ display: { xs: 'none', sm: 'table-cell' } }}>
                            {formatMoney(unit)}
                          </TableCell>
                          <TableCell align="right" sx={{ fontWeight: 600 }}>
                            {formatMoney(sub)}
                          </TableCell>
                        </TableRow>
                      );
                    })}
                    {items.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={4} align="center" sx={{ py: 4, color: 'text.secondary' }}>
                          No item details returned.
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </Box>
              <Stack direction="row" justifyContent="space-between" alignItems="baseline" sx={{ p: 2.5, borderTop: 1, borderColor: 'divider' }}>
                <Typography fontWeight={600}>Total</Typography>
                <Typography variant="h6">{formatMoney(order.total ?? order.total_amount ?? order.total_price)}</Typography>
              </Stack>
            </Card>

            <Stack spacing={3}>
              {isAdmin && (
                <Card sx={{ p: 3 }}>
                  <Typography variant="h6" sx={{ mb: 2 }}>
                    Update status
                  </Typography>
                  <Stack spacing={1.5}>
                    <ErrorAlert error={saveError} />
                    {next.length === 0 ? (
                      <Typography variant="body2" color="text.secondary">
                        This order is {order.status}. Final orders cannot be changed.
                      </Typography>
                    ) : (
                      <>
                        {forward && (
                          <Button variant="contained" onClick={() => change(forward)} disabled={Boolean(saving)}>
                            {saving === forward ? 'Saving…' : `Mark as ${forward}`}
                          </Button>
                        )}
                        {canCancel && (
                          <Button variant="outlined" color="error" onClick={() => setConfirmCancel(true)} disabled={Boolean(saving)}>
                            Cancel order
                          </Button>
                        )}
                      </>
                    )}
                  </Stack>
                </Card>
              )}

              <Card sx={{ p: 3 }}>
                <Typography variant="h6" sx={{ mb: 2 }}>
                  Details
                </Typography>
                <Stack spacing={2} divider={<Divider flexItem />}>
                  <Field label="Order ID">
                    <Stack direction="row" alignItems="center" spacing={0.5}>
                      <Typography variant="body2" sx={mono}>
                        {order.id}
                      </Typography>
                      <Tooltip title="Copy">
                        <IconButton size="small" onClick={copyId} aria-label="Copy order ID">
                          <CopyIcon fontSize="inherit" />
                        </IconButton>
                      </Tooltip>
                    </Stack>
                  </Field>
                  {isAdmin && order.user_id && (
                    <Field label="Customer">
                      {customer ? (
                        <Link component={RouterLink} to={`/orders?user_id=${order.user_id}`} underline="hover" variant="body2" fontWeight={600}>
                          {userLabel(customer)}
                        </Link>
                      ) : (
                        <Typography variant="body2" sx={mono}>
                          {order.user_id}
                        </Typography>
                      )}
                    </Field>
                  )}
                  <Field label="Last updated">
                    <Typography variant="body2">{formatDate(order.updated_at || order.updatedAt)}</Typography>
                  </Field>
                </Stack>
              </Card>

              {Array.isArray(order.history) && order.history.length > 0 && (
                <Card sx={{ p: 3 }}>
                  <Typography variant="h6" sx={{ mb: 2 }}>
                    History
                  </Typography>
                  <Stack spacing={1.5}>
                    {[...order.history].reverse().map((h, i) => (
                      <Stack key={h.id || i} direction="row" justifyContent="space-between" alignItems="center" spacing={1}>
                        <StatusChip status={h.to_status} />
                        <Typography variant="caption" color="text.secondary" textAlign="right">
                          {formatDate(historyTime(h))}
                        </Typography>
                      </Stack>
                    ))}
                  </Stack>
                </Card>
              )}
            </Stack>
          </Box>

          <ConfirmDialog
            open={confirmCancel}
            title="Cancel this order?"
            message="The reserved stock goes back to the shop right away. A cancelled order is final and cannot be reopened."
            confirmLabel="Cancel order"
            color="error"
            loading={saving === 'cancelled'}
            onConfirm={() => change('cancelled')}
            onClose={() => setConfirmCancel(false)}
          />
        </>
      )}
    </>
  );
}
