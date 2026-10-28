import { useEffect, useMemo, useState } from 'react';
import { Link as RouterLink, useSearchParams } from 'react-router-dom';
import {
  Box,
  Button,
  Card,
  CardActionArea,
  CardActions,
  CardContent,
  Chip,
  IconButton,
  InputAdornment,
  LinearProgress,
  Pagination,
  Skeleton,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/EditOutlined';
import InventoryIcon from '@mui/icons-material/Inventory2Outlined';
import SearchIcon from '@mui/icons-material/Search';
import ClearIcon from '@mui/icons-material/Clear';
import AddShoppingCartIcon from '@mui/icons-material/AddShoppingCartOutlined';
import StorefrontIcon from '@mui/icons-material/StorefrontOutlined';
import { useAuth } from '../context/AuthContext';
import { prefetchProducts, useCatalog, useProducts } from '../hooks/queries';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import PageHeader from '../components/PageHeader';
import ErrorAlert from '../components/ErrorAlert';
import EmptyState from '../components/EmptyState';
import ProductImage from '../components/ProductImage';
import { ProductDialog, StockDialog } from '../components/ProductDialogs';
import { capitalize, formatMoney, normalizeList, stockInfo } from '../utils/format';

const PAGE_SIZE = 12;

function ProductCard({ product, isAdmin, onEdit, onStock }) {
  const stock = stockInfo(product.stock);
  const soldOut = !(product.stock > 0);
  return (
    <Card sx={{ height: '100%', display: 'flex', flexDirection: 'column', transition: 'box-shadow .15s, transform .15s', '&:hover': { boxShadow: 4, transform: 'translateY(-2px)' } }}>
      <CardActionArea component={RouterLink} to={`/products/${product.id}`} sx={{ flexGrow: 1, display: 'flex', flexDirection: 'column', alignItems: 'stretch', justifyContent: 'flex-start' }}>
        <ProductImage src={product.image_url} alt={product.name} />
        <CardContent sx={{ flexGrow: 1 }}>
          <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={1}>
            <Typography variant="subtitle1" component="h2" fontWeight={700} lineHeight={1.25}>
              {product.name}
            </Typography>
            <Typography fontWeight={700} color="primary.main" sx={{ whiteSpace: 'nowrap' }}>
              {formatMoney(product.price)}
            </Typography>
          </Stack>
          {product.category && <Chip label={capitalize(product.category)} size="small" sx={{ mt: 1 }} />}
          <Typography
            variant="body2"
            color="text.secondary"
            sx={{ mt: 1.5, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden', minHeight: 40 }}
          >
            {product.description || 'No description available.'}
          </Typography>
        </CardContent>
      </CardActionArea>
      <CardActions sx={{ px: 2, pb: 2, pt: 0, justifyContent: 'space-between' }}>
        <Chip label={stock.label} color={stock.color} size="small" variant="outlined" />
        {isAdmin ? (
          <Stack direction="row">
            <Tooltip title="Edit">
              <IconButton size="small" onClick={onEdit} aria-label={`Edit ${product.name}`}>
                <EditIcon fontSize="small" />
              </IconButton>
            </Tooltip>
            <Tooltip title="Adjust stock">
              <IconButton size="small" onClick={onStock} aria-label={`Adjust stock for ${product.name}`}>
                <InventoryIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          </Stack>
        ) : (
          <Button
            size="small"
            variant="contained"
            startIcon={<AddShoppingCartIcon />}
            component={RouterLink}
            to={`/orders/new?product=${product.id}`}
            disabled={soldOut}
          >
            Order
          </Button>
        )}
      </CardActions>
    </Card>
  );
}

export default function Products() {
  const { isAdmin } = useAuth();
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const category = params.get('category') || '';
  const q = (params.get('q') || '').trim();
  const [input, setInput] = useState(q);
  const [dialog, setDialog] = useState(null);
  const searching = q !== '';

  const update = (patch) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === '' || v == null || (k === 'page' && v === 1) ? next.delete(k) : next.set(k, String(v))));
    setParams(next, { replace: true });
  };

  // Typing updates the URL after a short pause (shareable, back-button friendly).
  const debounced = useDebouncedValue(input.trim(), 300);
  useEffect(() => {
    if (debounced !== q) update({ q: debounced, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);

  // Browsing: the server filters by category and pages the results.
  const listParams = { page, limit: PAGE_SIZE, ...(category ? { category } : {}) };
  const list = useProducts(listParams, { enabled: !searching });
  // Search has no API parameter, so it runs over the cached catalog (also feeds the category chips).
  const catalog = useCatalog({ enabled: searching || Boolean(list.data) });

  const categories = useMemo(() => {
    const counts = new Map();
    (catalog.data || []).forEach((p) => p.category && counts.set(p.category, (counts.get(p.category) || 0) + 1));
    return [...counts].sort(([a], [b]) => a.localeCompare(b));
  }, [catalog.data]);

  let items;
  let total;
  let totalPages;
  if (searching) {
    const text = q.toLowerCase();
    const matches = (catalog.data || []).filter(
      (p) =>
        (!category || p.category === category) &&
        [p.name, p.category, p.description].some((v) => String(v || '').toLowerCase().includes(text))
    );
    total = matches.length;
    totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    items = matches.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
  } else {
    ({ items, total, totalPages } = normalizeList(list.data || {}));
  }

  const source = searching ? catalog : list;
  const loading = source.loading;
  const filtered = searching || Boolean(category);

  // Warm the next page so "next" feels instant.
  useEffect(() => {
    if (!searching && list.data && page < totalPages) prefetchProducts({ ...listParams, page: page + 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searching, list.data, page, totalPages, category]);

  // If a page no longer exists (e.g. products were removed), go back to page 1.
  useEffect(() => {
    if (!loading && !source.placeholder && !source.error && page > 1 && page > totalPages) update({ page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loading, source.placeholder, page, totalPages]);

  const clearFilters = () => {
    setInput('');
    setParams(new URLSearchParams(), { replace: true });
  };

  return (
    <>
      <PageHeader
        title="Products"
        subtitle={loading ? 'Loading the catalog…' : `${total} product${total === 1 ? '' : 's'}${filtered ? ' found' : ' in the catalog'}`}
        action={
          isAdmin ? (
            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog({ type: 'edit', product: null })}>
              Add product
            </Button>
          ) : null
        }
      />

      <Stack spacing={2} sx={{ mb: 3 }}>
        <TextField
          size="small"
          placeholder="Search by name, category or description"
          value={input}
          onChange={(e) => setInput(e.target.value)}
          fullWidth
          InputProps={{
            startAdornment: (
              <InputAdornment position="start">
                <SearchIcon fontSize="small" />
              </InputAdornment>
            ),
            endAdornment: input ? (
              <InputAdornment position="end">
                <IconButton size="small" aria-label="Clear search" onClick={() => setInput('')}>
                  <ClearIcon fontSize="small" />
                </IconButton>
              </InputAdornment>
            ) : null,
          }}
          inputProps={{ 'aria-label': 'Search products' }}
        />
        {categories.length > 0 && (
          <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap" role="group" aria-label="Filter by category">
            <Chip label="All" color={category ? 'default' : 'primary'} variant={category ? 'outlined' : 'filled'} onClick={() => update({ category: '', page: 1 })} />
            {categories.map(([c, count]) => (
              <Chip
                key={c}
                label={`${capitalize(c)} · ${count}`}
                color={category === c ? 'primary' : 'default'}
                variant={category === c ? 'filled' : 'outlined'}
                onClick={() => update({ category: category === c ? '' : c, page: 1 })}
              />
            ))}
          </Stack>
        )}
        <Box sx={{ height: 3 }}>{source.fetching && !loading && <LinearProgress />}</Box>
      </Stack>

      {source.error && <ErrorAlert error={source.error} onRetry={source.refetch} sx={{ mb: 2 }} />}

      {loading ? (
        <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(3, 1fr)' } }}>
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} variant="rounded" height={340} />
          ))}
        </Box>
      ) : items.length === 0 && !source.error ? (
        <Card>
          <EmptyState
            icon={<StorefrontIcon />}
            title={filtered ? 'No products match your filters' : 'No products yet'}
            description={filtered ? 'Try a different search or category.' : isAdmin ? 'Add your first product to start selling.' : 'Please check back soon.'}
            action={filtered ? <Button onClick={clearFilters}>Clear filters</Button> : undefined}
          />
        </Card>
      ) : (
        <Box
          sx={{
            display: 'grid',
            gap: 2.5,
            gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(3, 1fr)' },
            opacity: source.placeholder ? 0.6 : 1,
            transition: 'opacity .15s',
          }}
        >
          {items.map((p) => (
            <ProductCard
              key={p.id}
              product={p}
              isAdmin={isAdmin}
              onEdit={() => setDialog({ type: 'edit', product: p })}
              onStock={() => setDialog({ type: 'stock', product: p })}
            />
          ))}
        </Box>
      )}

      {totalPages > 1 && (
        <Stack alignItems="center" sx={{ mt: 4 }}>
          <Pagination count={totalPages} page={Math.min(page, totalPages)} onChange={(_, p) => update({ page: p })} color="primary" shape="rounded" />
        </Stack>
      )}

      {dialog?.type === 'edit' && <ProductDialog product={dialog.product} onClose={() => setDialog(null)} />}
      {dialog?.type === 'stock' && <StockDialog product={dialog.product} onClose={() => setDialog(null)} />}
    </>
  );
}
