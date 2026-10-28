import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Alert,
  Box,
  Button,
  Card,
  Chip,
  Divider,
  LinearProgress,
  List,
  ListItemButton,
  Skeleton,
  Stack,
  TablePagination,
  Typography,
} from '@mui/material';
import NotificationsOffIcon from '@mui/icons-material/NotificationsOffOutlined';
import { api } from '../api/client';
import { notificationsChanged, useNotifications } from '../hooks/queries';
import { enablePush, pushStatus, syncPush } from '../push/push';
import PageHeader from '../components/PageHeader';
import ErrorAlert from '../components/ErrorAlert';
import EmptyState from '../components/EmptyState';
import StatusChip from '../components/StatusChip';
import { UnreadDot, useOpenNotification } from '../components/NotificationBell';
import { useToast } from '../components/Toast';
import { formatDate, normalizeList, timeAgo } from '../utils/format';

const PAGE_SIZES = [10, 20, 50];
const TYPES = [
  { value: 'order_created', label: 'New orders' },
  { value: 'order_status_changed', label: 'Status updates' },
];

// Push notifications for this browser: lets the user turn them on and explains why if they can't.
function PushCard() {
  const toast = useToast();
  const [state, setState] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let alive = true;
    pushStatus()
      .then((s) => alive && setState(s))
      .catch(() => alive && setState('unsupported'));
    return () => {
      alive = false;
    };
  }, []);

  const run = async (action) => {
    setBusy(true);
    try {
      const permission = await action();
      if (permission === 'denied') {
        setState('denied');
        toast('Notifications were blocked in this browser.', 'warning');
      } else if (permission === 'granted' || permission === undefined) {
        setState('granted');
        toast('Push notifications are on for this browser.', 'success');
      } else {
        setState(permission);
      }
    } catch (err) {
      console.error('Push registration failed:', err);
      setState('failed');
      toast('Could not turn on push notifications.', 'error');
    } finally {
      setBusy(false);
    }
  };

  if (!state || state === 'off') return null; // no Firebase config in this build

  const content = {
    default: {
      severity: 'info',
      text: 'Get a notification when your orders change, even when this tab is closed.',
      action: { label: 'Turn on', onClick: () => run(enablePush) },
    },
    granted: {
      severity: 'success',
      text: 'Push notifications are on in this browser.',
    },
    failed: {
      severity: 'warning',
      text: 'Notifications are allowed, but this browser could not be registered for push.',
      action: { label: 'Try again', onClick: () => run(syncPush) },
    },
    denied: {
      severity: 'warning',
      text: 'Notifications are blocked for this site. Allow them in the site settings (the icon left of the address), then reload.',
    },
    insecure: {
      severity: 'info',
      text: 'Push notifications need a secure page: open the store at https:// or http://localhost.',
    },
    unsupported: {
      severity: 'info',
      text: 'This browser does not support push notifications.',
    },
  }[state];

  if (!content) return null;
  return (
    <Alert
      severity={content.severity}
      sx={{ mb: 2, alignItems: 'center' }}
      action={
        content.action && (
          <Button color="inherit" size="small" variant="outlined" disabled={busy} onClick={content.action.onClick}>
            {busy ? 'Please wait…' : content.action.label}
          </Button>
        )
      }
    >
      {content.text}
    </Alert>
  );
}

export default function Notifications() {
  const toast = useToast();
  const openNotification = useOpenNotification();
  const [params, setParams] = useSearchParams();

  // Filters live in the URL, like the orders page.
  const page = Math.max(1, Number(params.get('page')) || 1);
  const limit = PAGE_SIZES.includes(Number(params.get('limit'))) ? Number(params.get('limit')) : 10;
  const unreadOnly = params.get('filter') === 'unread';
  const type = TYPES.some((t) => t.value === params.get('type')) ? params.get('type') : '';

  const update = (patch) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) =>
      v === '' || v == null || (k === 'page' && v === 1) ? next.delete(k) : next.set(k, String(v))
    );
    setParams(next, { replace: true });
  };

  const query = { page, limit, ...(unreadOnly ? { is_read: 'false' } : {}), ...(type ? { type } : {}) };
  const { data, error, loading, fetching, placeholder, refetch } = useNotifications(query);
  const { items, total, totalPages } = normalizeList(data || {});
  const unread = data?.unread_count || 0;
  const empty = !loading && !error && items.length === 0;
  const filtered = unreadOnly || Boolean(type);

  useEffect(() => {
    if (data && !placeholder && !loading && page > 1 && page > totalPages) update({ page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, placeholder, page, totalPages]);

  const readAll = async () => {
    try {
      const { updated } = await api.markAllNotificationsRead();
      notificationsChanged();
      toast(updated ? `${updated} marked as read.` : 'Nothing to mark.', 'success');
    } catch {
      toast('Could not mark notifications as read.', 'error');
    }
  };

  return (
    <>
      <PageHeader
        title="Notifications"
        subtitle="Updates about your orders."
        action={
          <Button variant="outlined" onClick={readAll} disabled={!unread}>
            Mark all as read
          </Button>
        }
      />

      <PushCard />

      <Card>
        <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap" sx={{ p: 2 }} role="group" aria-label="Filter notifications">
          <Chip
            label="All"
            color={unreadOnly ? 'default' : 'primary'}
            variant={unreadOnly ? 'outlined' : 'filled'}
            onClick={() => update({ filter: '', page: 1 })}
          />
          <Chip
            label={`Unread${unread ? ` (${unread})` : ''}`}
            color={unreadOnly ? 'primary' : 'default'}
            variant={unreadOnly ? 'filled' : 'outlined'}
            onClick={() => update({ filter: 'unread', page: 1 })}
          />
          <Divider orientation="vertical" flexItem sx={{ mx: 0.5 }} />
          {TYPES.map((t) => (
            <Chip
              key={t.value}
              label={t.label}
              color={type === t.value ? 'primary' : 'default'}
              variant={type === t.value ? 'filled' : 'outlined'}
              onClick={() => update({ type: type === t.value ? '' : t.value, page: 1 })}
            />
          ))}
        </Stack>

        <Box sx={{ height: 4 }}>{fetching && <LinearProgress />}</Box>
        {error && <ErrorAlert error={error} onRetry={refetch} sx={{ m: 2 }} />}

        {loading && (
          <Stack spacing={1} sx={{ p: 2 }}>
            {Array.from({ length: 4 }, (_, i) => (
              <Skeleton key={i} variant="rounded" height={64} />
            ))}
          </Stack>
        )}

        {!loading && !empty && (
          <List disablePadding sx={{ opacity: placeholder ? 0.6 : 1, transition: 'opacity .15s' }}>
            {items.map((n) => (
              <ListItemButton
                key={n.id}
                divider
                onClick={() => openNotification(n)}
                sx={{ alignItems: 'flex-start', gap: 1.5, py: 1.5, bgcolor: n.is_read ? undefined : 'action.hover' }}
              >
                <UnreadDot show={!n.is_read} />
                <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                  <Typography fontWeight={n.is_read ? 500 : 700}>{n.title}</Typography>
                  <Typography variant="body2" color="text.secondary">
                    {n.message}
                  </Typography>
                  <Typography variant="caption" color="text.disabled" title={formatDate(n.created_at)}>
                    {timeAgo(n.created_at)}
                  </Typography>
                </Box>
                {n.status && <StatusChip status={n.status} />}
              </ListItemButton>
            ))}
          </List>
        )}

        {empty && (
          <EmptyState
            icon={<NotificationsOffIcon />}
            title={filtered ? 'Nothing here' : 'No notifications yet'}
            description={
              filtered ? 'No notifications match these filters.' : 'You will be notified here when your orders change.'
            }
            action={
              filtered ? <Button onClick={() => setParams(new URLSearchParams(), { replace: true })}>Clear filters</Button> : undefined
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
