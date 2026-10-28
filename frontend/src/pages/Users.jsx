import { useEffect, useMemo, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import {
  Avatar,
  Button,
  Card,
  Chip,
  InputAdornment,
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
  Typography,
} from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';
import PeopleIcon from '@mui/icons-material/PeopleAltOutlined';
import { useUsers } from '../hooks/queries';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import PageHeader from '../components/PageHeader';
import ErrorAlert from '../components/ErrorAlert';
import EmptyState from '../components/EmptyState';
import { capitalize, formatDate, initials } from '../utils/format';

const PAGE_SIZES = [10, 25, 50];

export default function Users() {
  const { data, loading, fetching, error, refetch } = useUsers();
  const users = data || [];
  const [input, setInput] = useState('');
  const query = useDebouncedValue(input.trim().toLowerCase(), 200);
  const [role, setRole] = useState('');
  const [page, setPage] = useState(0);
  const [rows, setRows] = useState(10);

  useEffect(() => setPage(0), [query, role, rows]);

  const roles = useMemo(() => {
    const counts = new Map();
    users.forEach((u) => counts.set(u.role, (counts.get(u.role) || 0) + 1));
    return [...counts];
  }, [users]);

  const filtered = useMemo(
    () =>
      users.filter(
        (u) =>
          (!role || u.role === role) &&
          (!query || [u.email, u.name, u.role, u.id].some((v) => String(v || '').toLowerCase().includes(query)))
      ),
    [users, query, role]
  );
  const visible = filtered.slice(page * rows, page * rows + rows);

  return (
    <>
      <PageHeader title="Users" subtitle={loading ? 'Loading accounts…' : `${users.length} registered account${users.length === 1 ? '' : 's'}`} />
      <Card>
        <Stack spacing={2} sx={{ p: 2 }}>
          <TextField
            size="small"
            placeholder="Search by name, email, role or ID"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            inputProps={{ 'aria-label': 'Search users' }}
            InputProps={{
              startAdornment: (
                <InputAdornment position="start">
                  <SearchIcon fontSize="small" />
                </InputAdornment>
              ),
            }}
          />
          {roles.length > 1 && (
            <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap" role="group" aria-label="Filter by role">
              <Chip label={`All · ${users.length}`} color={role ? 'default' : 'primary'} variant={role ? 'outlined' : 'filled'} onClick={() => setRole('')} />
              {roles.map(([r, n]) => (
                <Chip
                  key={r}
                  label={`${capitalize(r)} · ${n}`}
                  color={role === r ? 'primary' : 'default'}
                  variant={role === r ? 'filled' : 'outlined'}
                  onClick={() => setRole(role === r ? '' : r)}
                />
              ))}
            </Stack>
          )}
        </Stack>
        {fetching && !loading ? <LinearProgress /> : <div style={{ height: 4 }} />}
        {error && <ErrorAlert error={error} onRetry={refetch} sx={{ m: 2 }} />}
        {loading && (
          <Stack spacing={1} sx={{ p: 2 }}>
            {Array.from({ length: 5 }, (_, i) => (
              <Skeleton key={i} variant="rounded" height={48} />
            ))}
          </Stack>
        )}
        {!loading && !error && filtered.length > 0 && (
          <TableContainer>
            <Table>
              <TableHead>
                <TableRow>
                  <TableCell>User</TableCell>
                  <TableCell>Role</TableCell>
                  <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}>Joined</TableCell>
                  <TableCell align="right">Orders</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {visible.map((u) => (
                  <TableRow key={u.id} hover>
                    <TableCell>
                      <Stack direction="row" spacing={1.5} alignItems="center">
                        <Avatar sx={{ width: 34, height: 34, fontSize: 13, bgcolor: 'primary.main' }}>{initials(u)}</Avatar>
                        <div>
                          <Typography fontWeight={600} variant="body2">
                            {u.name || u.email}
                          </Typography>
                          {u.name && (
                            <Typography variant="caption" color="text.secondary">
                              {u.email}
                            </Typography>
                          )}
                        </div>
                      </Stack>
                    </TableCell>
                    <TableCell>
                      <Chip size="small" label={u.role} color={u.role === 'admin' ? 'primary' : 'default'} sx={{ textTransform: 'capitalize' }} />
                    </TableCell>
                    <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}>{formatDate(u.created_at || u.createdAt)}</TableCell>
                    <TableCell align="right">
                      {u.role !== 'admin' && (
                        <Button size="small" component={RouterLink} to={`/orders?user_id=${u.id}`}>
                          View orders
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        )}
        {!loading && !error && filtered.length === 0 && (
          <EmptyState icon={<PeopleIcon />} title="No users found" description={users.length ? 'No users match your search.' : 'Accounts will appear here.'} py={6} />
        )}
        {filtered.length > PAGE_SIZES[0] && (
          <TablePagination
            component="div"
            count={filtered.length}
            page={page}
            rowsPerPage={rows}
            rowsPerPageOptions={PAGE_SIZES}
            onPageChange={(_, p) => setPage(p)}
            onRowsPerPageChange={(e) => setRows(Number(e.target.value))}
          />
        )}
      </Card>
    </>
  );
}
