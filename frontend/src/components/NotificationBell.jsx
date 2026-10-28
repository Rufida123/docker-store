import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Badge,
  Box,
  Button,
  Divider,
  IconButton,
  Menu,
  MenuItem,
  Stack,
  Tooltip,
  Typography,
} from '@mui/material';
import NotificationsIcon from '@mui/icons-material/NotificationsNoneOutlined';
import { api } from '../api/client';
import { notificationsChanged, useNotifications } from '../hooks/queries';
import { timeAgo } from '../utils/format';
import { useToast } from './Toast';

const PREVIEW = 6;

export function UnreadDot({ show }) {
  return (
    <Box
      aria-hidden
      sx={{ width: 8, height: 8, mt: 0.9, borderRadius: '50%', flexShrink: 0, bgcolor: show ? 'primary.main' : 'transparent' }}
    />
  );
}

/** Marks a notification as read (if needed) and opens its order. */
export function useOpenNotification() {
  const navigate = useNavigate();
  return async (n) => {
    if (!n.is_read) {
      try {
        await api.markNotificationRead(n.id);
      } catch {
        /* opening the order matters more than the read flag */
      }
      notificationsChanged();
    }
    if (n.order_id) navigate(`/orders/${n.order_id}`);
  };
}

export default function NotificationBell() {
  const navigate = useNavigate();
  const toast = useToast();
  const openNotification = useOpenNotification();
  const [anchor, setAnchor] = useState(null);
  const { data } = useNotifications({ page: 1, limit: PREVIEW });
  const items = data?.data || [];
  const unread = data?.unread_count || 0;

  const close = () => setAnchor(null);

  const readAll = async () => {
    try {
      await api.markAllNotificationsRead();
      notificationsChanged();
    } catch {
      toast('Could not mark notifications as read.', 'error');
    }
  };

  return (
    <>
      <Tooltip title="Notifications">
        <IconButton
          onClick={(e) => setAnchor(e.currentTarget)}
          aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'}
        >
          <Badge badgeContent={unread} color="error" max={99}>
            <NotificationsIcon />
          </Badge>
        </IconButton>
      </Tooltip>

      <Menu
        anchorEl={anchor}
        open={Boolean(anchor)}
        onClose={close}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}
        slotProps={{ paper: { sx: { width: 380, maxWidth: 'calc(100vw - 32px)' } } }}
      >
        <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 2, py: 1 }}>
          <Typography fontWeight={700}>Notifications</Typography>
          <Button size="small" onClick={readAll} disabled={!unread}>
            Mark all as read
          </Button>
        </Stack>
        <Divider />

        {items.length === 0 && (
          <Typography variant="body2" color="text.secondary" sx={{ px: 2, py: 3, textAlign: 'center' }}>
            You&apos;re all caught up.
          </Typography>
        )}

        {items.map((n) => (
          <MenuItem
            key={n.id}
            onClick={() => {
              close();
              openNotification(n);
            }}
            sx={{ alignItems: 'flex-start', whiteSpace: 'normal', gap: 1.25, py: 1.25 }}
          >
            <UnreadDot show={!n.is_read} />
            <Box sx={{ minWidth: 0 }}>
              <Typography fontSize={14} fontWeight={n.is_read ? 500 : 700}>
                {n.title}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                {n.message}
              </Typography>
              <Typography variant="caption" color="text.disabled">
                {timeAgo(n.created_at)}
              </Typography>
            </Box>
          </MenuItem>
        ))}

        <Divider />
        <MenuItem
          onClick={() => {
            close();
            navigate('/notifications');
          }}
          sx={{ justifyContent: 'center', fontWeight: 600, color: 'primary.main' }}
        >
          View all notifications
        </MenuItem>
      </Menu>
    </>
  );
}
