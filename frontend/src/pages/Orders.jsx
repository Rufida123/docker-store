import { useEffect, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
  Autocomplete,
  Box,
  Button,
  Card,
  Chip,
  IconButton,
  LinearProgress,
  Skeleton,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import InboxIcon from '@mui/icons-material/InboxOutlined';
import RefreshIcon from '@mui/icons-material/Refresh';
import { useAuth } from '../context/AuthContext';
import { prefetchOrders, useOrders, useUsers } from '../hooks/queries';
import PageHeader from '../components/PageHeader';
import StatusChip from '../components/StatusChip';
import ErrorAlert from '../components/ErrorAlert';
import EmptyState from '../components/EmptyState';
import { ORDER_STATUSES, UUID_RE, capitalize, formatDate, formatMoney, normalizeList, shortId, userLabel } from '../utils/format';

const PAGE_SIZES = [10, 20, 50];

export default function Orders() {
  const { isAdmin } = useAuth();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();

  // Filters live in the URL: refresh, back button and shared links all keep them.
  const page = Math.max(1, Number(params.get('page')) || 1);
  const limit = PAGE_SIZES.includes(Number(params.get('limit'))) ? Number(params.get('limit')) : 10;
  const status = ORDER_STATUSES.includes(params.get('status')) ? params.get('status') : '';
  const userId = isAdmin && UUID_RE.test(params.get('user_id') || '') ? params.get('user_id') : '';

  const update = (patch) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === '' || v == null || (k === 'page' && v === 1) ? next.delete(k) : next.set(k, String(v))));
    setParams(next, { replace: true });
  };

  const query = { page, limit, ...(status ? { status } : {}), ...(userId ? { user_id: userId } : {}) };
  const { data, error, loading, fetching, placeholder, refetch } = useOrders(query);
  const { items, total, totalPages } = normalizeList(data || {});

  // Admins see who each order belongs to, and can filter by picking a customer.
  const users = useUsers({ enabled: isAdmin });
  const userMap = useMemo(() => new Map((users.data || []).map((u) => [u.id, u])), [users.data]);

  useEffect(() => {
    if (data && page < totalPages) prefetchOrders({ ...query, page: page + 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, page, totalPages, limit, status, userId]);

  useEffect(() => {
    if (data && !placeholder && !loading && page > 1 && page > totalPages) update({ page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, placeholder, page, totalPages]);

  const filtered = Boolean(status || userId);
  const empty = !loading && !error && items.length === 0;
  const selectedUser = userId ? userMap.get(userId) || { id: userId, email: shortId(userId) } : null;

  return (
    <>
      <PageHeader
        title={isAdmin ? 'All orders' : 'My orders'}
        subtitle={isAdmin ? 'Review and update every customer order.' : 'Track everything you have ordered.'}
        action={
          !isAdmin && (
            <Button variant="contained" startIcon={<AddIcon />} onClick={() => navigate('/orders/new')}>
              New order
            </Button>
          )
        }
      />

      <Card>
        <Stack spacing={2} sx={{ p: 2 }}>
          <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1}>
            <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap" role="group" aria-label="Filter by status">
              <Chip label="All" color={status ? 'default' : 'primary'} variant={status ? 'outlined' : 'filled'} onClick={() => update({ status: '', page: 1 })} />
              {ORDER_STATUSES.map((s) => (
                <Chip
                  key={s}
                  label={capitalize(s)}
                  color={status === s ? 'primary' : 'default'}
                  variant={status === s ? 'filled' : 'outlined'}
                  onClick={() => update({ status: status === s ? '' : s, page: 1 })}
                />
              ))}
            </Stack>
            <Tooltip title="Refresh">
              <span>
                <IconButton onClick={refetch} disabled={fetching} aria-label="Refresh orders">
                  <RefreshIcon />
                </IconButton>
              </span>
            </Tooltip>
          </Stack>

          {isAdmin && (
            <Autocomplete
              size="small"
              options={users.data || []}
              value={selectedUser}
              loading={users.loading}
              onChange={(_, u) => update({ user_id: u?.id || '', page: 1 })}
              getOptionLabel={(u) => u.email || u.name || u.id}
              isOptionEqualToValue={(o, v) => o.id === v.id}
              noOptionsText="No matching customer"
              renderOption={(props, u) => (
                <li {...props} key={u.id}>
                  <Stack>
                    <Typography variant="body2" fontWeight={600}>
                      {userLabel(u)}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      {u.role}
                    </Typography>
                  </Stack>
                </li>
              )}
              renderInput={(p) => <TextField {...p} placeholder="Filter by customer (search by email)" />}
            />
          )}
        </Stack>

        <Box sx={{ height: 4 }}>{fetching && <LinearProgress />}</Box>

        {error && <ErrorAlert error={error} onRetry={refetch} sx={{ m: 2 }} />}

        {loading && (
          <Stack spacing={1} sx={{ p: 2 }}>
            {Array.from({ length: 5 }, (_, i) => (
              <Skeleton key={i} variant="rounded" height={44} />
            ))}
          </Stack>
        )}

        {!loading && !(error && !data) && !empty && (
          <TableContainer sx={{ opacity: placeholder ? 0.6 : 1, transition: 'opacity .15s' }}>
            <Table>
              <TableHead>
                <TableRow>
                  <TableCell>Order</TableCell>
                  <TableCell>Date</TableCell>
                  {isAdmin && <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Customer</TableCell>}
                  <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}>Items</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell align="right">Total</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {items.map((o) => (
                  <TableRow
                    key={o.id}
                    hover
                    tabIndex={0}
                    role="link"
                    aria-label={`Open order ${shortId(o.id)}`}
                    sx={{ cursor: 'pointer', '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: -2 } }}
                    onClick={() => navigate(`/orders/${o.id}`)}
                    onKeyDown={(e) => e.key === 'Enter' && navigate(`/orders/${o.id}`)}
                  >
                    <TableCell>
                      <Typography fontWeight={600} fontFamily="ui-monospace, monospace" fontSize={13}>
                        #{shortId(o.id)}
                      </Typography>
                    </TableCell>
                    <TableCell>{formatDate(o.created_at || o.createdAt)}</TableCell>
                    {isAdmin && (
                      <TableCell sx={{ display: { xs: 'none', md: 'table-cell' }, maxWidth: 220 }}>
                        <Typography variant="body2" noWrap>
                          {userMap.has(o.user_id) ? userLabel(userMap.get(o.user_id)) : shortId(o.user_id)}
                        </Typography>
                      </TableCell>
                    )}
                    <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}>{Array.isArray(o.items) ? o.items.length : '—'}</TableCell>
                    <TableCell>
                      <StatusChip status={o.status} />
                    </TableCell>
                    <TableCell align="right" sx={{ fontWeight: 600 }}>
                      {formatMoney(o.total ?? o.total_amount ?? o.total_price)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        {empty && (
          <EmptyState
            icon={<InboxIcon />}
            title="No orders found"
            description={filtered ? 'Nothing matches these filters.' : isAdmin ? 'Customer orders will show up here.' : 'You have not placed any orders yet.'}
            action={
              filtered ? (
                <Button onClick={() => setParams(new URLSearchParams(), { replace: true })}>Clear filters</Button>
              ) : !isAdmin ? (
                <Button variant="contained" onClick={() => navigate('/orders/new')}>
                  Place your first order
                </Button>
              ) : undefined
            }
          />
        )}

        <TablePagination
          component="div"
          count={total}
          page={Math.min(page - 1, Math.max(totalPages - 1, 0))}
          rowsPerPage={limit}
          rowsPerPageOptions={PAGE_SIZES}
          onPageChange={(_, p) => update({ page: p + 1 })}
          onRowsPerPageChange={(e) => update({ limit: e.target.value, page: 1 })}
        />
      </Card>
    </>
  );
}
