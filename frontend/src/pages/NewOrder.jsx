import { useEffect, useMemo, useRef, useState } from 'react';
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router-dom';
import {
  Autocomplete,
  Box,
  Button,
  Card,
  Divider,
  IconButton,
  Skeleton,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import RemoveIcon from '@mui/icons-material/Remove';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import Inventory2Icon from '@mui/icons-material/Inventory2Outlined';
import { api } from '../api/client';
import { invalidate } from '../hooks/useQuery';
import { orderPlaced, useCatalog } from '../hooks/queries';
import { useToast } from '../components/Toast';
import PageHeader from '../components/PageHeader';
import ErrorAlert from '../components/ErrorAlert';
import EmptyState from '../components/EmptyState';
import { capitalize, formatMoney, lineTotal, stockInfo } from '../utils/format';

let rowKey = 0;
const newRow = (product = null) => ({ key: ++rowKey, product, quantity: 1 });

export default function NewOrder() {
  const navigate = useNavigate();
  const toast = useToast();
  const [search] = useSearchParams();
  const preselect = search.get('product');
  const { data: catalog, loading: catalogLoading, error: catalogError, refetch } = useCatalog();
  const products = useMemo(() => [...(catalog || [])].sort((a, b) => a.name.localeCompare(b.name)), [catalog]);

  const [rows, setRows] = useState(() => [newRow()]);
  const [touched, setTouched] = useState(false);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);

  // Arriving from a product page ("Order this product"): pre-fill the first line once.
  const applied = useRef(false);
  useEffect(() => {
    if (applied.current || !preselect || !catalog) return;
    applied.current = true;
    const p = catalog.find((x) => x.id === preselect && x.stock > 0);
    if (p) setRows([newRow(p)]);
  }, [catalog, preselect]);

  // Use the freshest stock/price for products already chosen (the catalog can refresh in the background).
  const byId = useMemo(() => new Map(products.map((p) => [p.id, p])), [products]);
  const live = (r) => (r.product ? byId.get(r.product.id) || r.product : null);

  const update = (key, patch) => setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...patch } : r)));
  const remove = (key) => setRows((rs) => (rs.length > 1 ? rs.filter((r) => r.key !== key) : rs));
  const chosen = new Set(rows.map((r) => r.product?.id).filter(Boolean));

  const rowErrors = (r) => {
    const p = live(r);
    const qty = Number(r.quantity);
    return {
      product: !p ? 'Choose a product' : '',
      quantity: !Number.isInteger(qty) || qty < 1 ? 'Min. 1' : p && qty > p.stock ? `Only ${p.stock} in stock` : '',
    };
  };
  const valid = rows.every((r) => {
    const e = rowErrors(r);
    return !e.product && !e.quantity;
  });

  const totalUnits = rows.reduce((s, r) => s + (Number(r.quantity) || 0), 0);
  // An estimate: the server always recomputes the real total from its own prices.
  const estimate = rows.reduce((s, r) => s + (live(r) ? lineTotal(live(r).price, Number(r.quantity) || 0) : 0), 0);

  const submit = async (e) => {
    e.preventDefault();
    setTouched(true);
    if (!valid || loading) return;
    setError(null);
    setLoading(true);
    try {
      // Only product_id and quantity are sent: prices and the owner come from the server.
      const order = await api.createOrder({
        items: rows.map((r) => ({ product_id: r.product.id, quantity: Number(r.quantity) })),
      });
      orderPlaced(order);
      toast('Order placed successfully');
      navigate(order?.id ? `/orders/${order.id}` : '/orders', { replace: true });
    } catch (err) {
      setError(err);
      setLoading(false);
      // Stock or catalog changed under us: show the up-to-date numbers.
      if (err.code === 'INSUFFICIENT_STOCK' || err.code === 'PRODUCT_NOT_FOUND') invalidate('catalog', 'products');
    }
  };

  const empty = !catalogLoading && !catalogError && products.length === 0;

  return (
    <>
      <PageHeader title="New order" subtitle="Pick your products and quantities. You will see the final price once the order is placed." />
      <form onSubmit={submit} noValidate>
        <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', md: '1fr 320px' }, alignItems: 'start' }}>
          <Card sx={{ p: { xs: 2, sm: 3 } }}>
            <Stack spacing={2.5}>
              <ErrorAlert error={error} />
              <ErrorAlert error={catalogError} onRetry={refetch} />
              {catalogLoading && (
                <>
                  <Skeleton variant="rounded" height={56} />
                  <Skeleton variant="rounded" height={56} />
                </>
              )}
              {empty && (
                <EmptyState icon={<Inventory2Icon />} title="No products available" description="There is nothing to order right now." py={4} />
              )}
              {!catalogLoading && products.length > 0 &&
                rows.map((r, i) => {
                  const p = live(r);
                  const err = touched ? rowErrors(r) : { product: '', quantity: '' };
                  const qty = Number(r.quantity) || 0;
                  return (
                    <Stack key={r.key} spacing={1} sx={{ pb: 2, borderBottom: i < rows.length - 1 ? 1 : 0, borderColor: 'divider' }}>
                      <Stack direction="row" spacing={1.5} alignItems="flex-start">
                        <Autocomplete
                          fullWidth
                          options={products}
                          value={p}
                          onChange={(_, product) => update(r.key, { product, quantity: product ? Math.min(Number(r.quantity) || 1, product.stock) : r.quantity })}
                          getOptionLabel={(o) => o.name}
                          isOptionEqualToValue={(o, v) => o.id === v.id}
                          getOptionDisabled={(o) => o.stock < 1 || (chosen.has(o.id) && o.id !== r.product?.id)}
                          noOptionsText="No matching product"
                          renderOption={(props, o) => (
                            <li {...props} key={o.id}>
                              <Stack sx={{ width: '100%' }}>
                                <Stack direction="row" justifyContent="space-between" spacing={2}>
                                  <Typography variant="body2" fontWeight={600}>
                                    {o.name}
                                  </Typography>
                                  <Typography variant="body2" fontWeight={600}>
                                    {formatMoney(o.price)}
                                  </Typography>
                                </Stack>
                                <Typography variant="caption" color={o.stock < 1 ? 'error' : 'text.secondary'}>
                                  {[o.category && capitalize(o.category), stockInfo(o.stock).label].filter(Boolean).join(' · ')}
                                  {chosen.has(o.id) && o.id !== r.product?.id ? ' · already in your order' : ''}
                                </Typography>
                              </Stack>
                            </li>
                          )}
                          renderInput={(params) => (
                            <TextField {...params} label={`Product ${i + 1}`} placeholder="Search products" error={Boolean(err.product)} helperText={err.product || ' '} />
                          )}
                        />
                        <IconButton onClick={() => remove(r.key)} disabled={rows.length === 1} aria-label={`Remove product ${i + 1}`} sx={{ mt: 1 }}>
                          <DeleteIcon />
                        </IconButton>
                      </Stack>

                      <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={2}>
                        <Stack direction="row" alignItems="flex-start" spacing={0.5}>
                          <IconButton
                            onClick={() => update(r.key, { quantity: Math.max(1, qty - 1) })}
                            disabled={qty <= 1}
                            aria-label="Decrease quantity"
                            sx={{ mt: 0.5 }}
                          >
                            <RemoveIcon />
                          </IconButton>
                          <TextField
                            label="Qty"
                            type="number"
                            size="small"
                            value={r.quantity}
                            onChange={(e) => update(r.key, { quantity: e.target.value })}
                            error={Boolean(err.quantity)}
                            helperText={err.quantity || ' '}
                            inputProps={{ min: 1, max: p?.stock, step: 1, style: { textAlign: 'center' } }}
                            sx={{ width: 96, mt: 0.5 }}
                          />
                          <IconButton
                            onClick={() => update(r.key, { quantity: p ? Math.min(p.stock, qty + 1) : qty + 1 })}
                            disabled={Boolean(p) && qty >= p.stock}
                            aria-label="Increase quantity"
                            sx={{ mt: 0.5 }}
                          >
                            <AddIcon />
                          </IconButton>
                        </Stack>
                        <Typography fontWeight={600} sx={{ pt: 1.5 }}>
                          {p ? formatMoney(lineTotal(p.price, qty)) : '—'}
                        </Typography>
                      </Stack>
                    </Stack>
                  );
                })}
              {!catalogLoading && products.length > 0 && (
                <Box>
                  <Button startIcon={<AddIcon />} onClick={() => setRows((rs) => [...rs, newRow()])} disabled={rows.length >= products.length}>
                    Add another product
                  </Button>
                </Box>
              )}
            </Stack>
          </Card>

          <Card sx={{ p: 3, position: { md: 'sticky' }, top: { md: 96 } }}>
            <Typography variant="h6">Summary</Typography>
            <Stack spacing={1.5} sx={{ mt: 2 }}>
              {rows.filter((r) => live(r)).map((r) => (
                <Stack key={r.key} direction="row" justifyContent="space-between" spacing={2}>
                  <Typography variant="body2" color="text.secondary" noWrap>
                    {Number(r.quantity) || 0} × {live(r).name}
                  </Typography>
                  <Typography variant="body2">{formatMoney(lineTotal(live(r).price, Number(r.quantity) || 0))}</Typography>
                </Stack>
              ))}
              <Stack direction="row" justifyContent="space-between">
                <Typography color="text.secondary">Total units</Typography>
                <Typography fontWeight={600}>{totalUnits}</Typography>
              </Stack>
              <Divider />
              <Stack direction="row" justifyContent="space-between" alignItems="baseline">
                <Typography fontWeight={600}>Estimated total</Typography>
                <Typography variant="h6">{formatMoney(estimate)}</Typography>
              </Stack>
              <Typography variant="caption" color="text.secondary">
                Final prices are set by the server when the order is placed.
              </Typography>
              <Button type="submit" variant="contained" size="large" disabled={loading || products.length === 0} fullWidth>
                {loading ? 'Placing order…' : 'Place order'}
              </Button>
              <Button component={RouterLink} to="/orders" disabled={loading} fullWidth>
                Cancel
              </Button>
            </Stack>
          </Card>
        </Box>
      </form>
    </>
  );
}
