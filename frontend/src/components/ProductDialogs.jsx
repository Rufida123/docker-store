import { useEffect, useMemo, useState } from 'react';
import {
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  InputAdornment,
  Stack,
  TextField,
  Typography,
  useMediaQuery,
  useTheme,
} from '@mui/material';
import ImageIcon from '@mui/icons-material/AddPhotoAlternateOutlined';
import CloseIcon from '@mui/icons-material/Close';
import { api, fieldErrors } from '../api/client';
import { productChanged, productCreated } from '../hooks/queries';
import { PRICE_RE } from '../utils/format';
import { useToast } from './Toast';
import ErrorAlert from './ErrorAlert';

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_IMAGE = 2 * 1024 * 1024;

const initialForm = (p) => ({
  name: p?.name || '',
  description: p?.description || '',
  category: p?.category || '',
  price: p?.price != null ? String(p.price) : '',
  stock: '0',
});

function validate(form, isEdit) {
  const e = {};
  if (!form.name.trim()) e.name = 'Name is required';
  if (!PRICE_RE.test(form.price.trim())) e.price = 'Enter a price like 19.99 (max 2 decimals)';
  if (!isEdit && !/^\d+$/.test(String(form.stock).trim())) e.stock = 'Use a whole number, 0 or more';
  return e;
}

// Create (POST multipart, with optional image) or edit (PATCH, only the changed fields).
// Stock is deliberately not editable here: it has its own atomic endpoint (see StockDialog).
export function ProductDialog({ product, onClose }) {
  const isEdit = Boolean(product);
  const theme = useTheme();
  const fullScreen = useMediaQuery(theme.breakpoints.down('sm'));
  const toast = useToast();
  const [form, setForm] = useState(() => initialForm(product));
  const [image, setImage] = useState(null);
  const [imageError, setImageError] = useState('');
  const [touched, setTouched] = useState(false);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);

  const preview = useMemo(() => (image ? URL.createObjectURL(image) : ''), [image]);
  useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview]);

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const changes = useMemo(() => {
    if (!isEdit) return null;
    const c = {};
    if (form.name.trim() !== (product.name || '')) c.name = form.name.trim();
    if (form.description.trim() !== (product.description || '')) c.description = form.description.trim();
    if (form.category.trim().toLowerCase() !== (product.category || '')) c.category = form.category.trim();
    if (PRICE_RE.test(form.price.trim()) && Number(form.price) !== Number(product.price)) c.price = Number(form.price);
    return c;
  }, [form, isEdit, product]);

  const errors = validate(form, isEdit);
  const server = fieldErrors(error);
  const shown = (k) => (touched && errors[k]) || server[k] || '';
  // Only "nothing to save" when the form is valid AND unchanged; invalid input must show its error instead.
  const nothingToSave = isEdit && Object.keys(errors).length === 0 && Object.keys(changes).length === 0;

  const pickImage = (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (!IMAGE_TYPES.includes(file.type)) return setImageError('Use a JPEG, PNG or WebP image.');
    if (file.size > MAX_IMAGE) return setImageError('The image is larger than 2 MB.');
    setImageError('');
    setImage(file);
  };

  const submit = async (e) => {
    e.preventDefault();
    setTouched(true);
    if (Object.keys(errors).length || saving || nothingToSave) return;
    setError(null);
    setSaving(true);
    try {
      if (isEdit) {
        productChanged(await api.updateProduct(product.id, changes)); // patch caches, no refetch
        toast('Product updated');
      } else {
        const body = new FormData();
        body.append('name', form.name.trim());
        body.append('price', form.price.trim());
        body.append('stock', String(form.stock).trim());
        if (form.description.trim()) body.append('description', form.description.trim());
        if (form.category.trim()) body.append('category', form.category.trim());
        if (image) body.append('image', image);
        await api.createProduct(body);
        productCreated();
        toast('Product created');
      }
      onClose();
    } catch (err) {
      setError(err);
      setSaving(false);
    }
  };

  return (
    <Dialog open onClose={saving ? undefined : onClose} fullWidth maxWidth="sm" fullScreen={fullScreen}>
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{isEdit ? 'Edit product' : 'Add product'}</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2} sx={{ pt: 0.5 }}>
            <ErrorAlert error={Object.keys(server).length ? null : error} />
            <TextField label="Name" value={form.name} onChange={set('name')} required autoFocus fullWidth error={Boolean(shown('name'))} helperText={shown('name') || ' '} />
            <TextField label="Description" value={form.description} onChange={set('description')} multiline minRows={2} fullWidth error={Boolean(shown('description'))} helperText={shown('description') || ' '} />
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField
                label="Category"
                value={form.category}
                onChange={set('category')}
                fullWidth
                error={Boolean(shown('category'))}
                helperText={shown('category') || 'Saved in lower case'}
              />
              <TextField
                label="Price"
                value={form.price}
                onChange={set('price')}
                required
                fullWidth
                error={Boolean(shown('price'))}
                helperText={shown('price') || ' '}
                inputProps={{ inputMode: 'decimal' }}
                InputProps={{ startAdornment: <InputAdornment position="start">$</InputAdornment> }}
              />
            </Stack>
            {!isEdit && (
              <>
                <TextField
                  label="Starting stock"
                  type="number"
                  value={form.stock}
                  onChange={set('stock')}
                  inputProps={{ min: 0, step: 1 }}
                  error={Boolean(shown('stock'))}
                  helperText={shown('stock') || ' '}
                />
                <Stack direction="row" spacing={2} alignItems="center">
                  <Button component="label" variant="outlined" startIcon={<ImageIcon />}>
                    {image ? 'Change image' : 'Choose image'}
                    <input hidden type="file" accept={IMAGE_TYPES.join(',')} onChange={pickImage} />
                  </Button>
                  {preview && (
                    <Stack direction="row" alignItems="center" spacing={1}>
                      <Box component="img" src={preview} alt="Selected" sx={{ width: 48, height: 48, objectFit: 'cover', borderRadius: 1.5 }} />
                      <IconButton size="small" onClick={() => setImage(null)} aria-label="Remove image">
                        <CloseIcon fontSize="small" />
                      </IconButton>
                    </Stack>
                  )}
                </Stack>
                <Typography variant="caption" color={imageError || server.image ? 'error' : 'text.secondary'}>
                  {imageError || server.image || 'Optional. JPEG, PNG or WebP, up to 2 MB.'}
                </Typography>
              </>
            )}
            {isEdit && (
              <Typography variant="caption" color="text.secondary">
                Only the fields you change are sent. Use “Adjust stock” to change the quantity in stock.
              </Typography>
            )}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" variant="contained" disabled={saving || nothingToSave}>
            {saving ? 'Saving…' : isEdit ? 'Save changes' : 'Create product'}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

const QUICK = [-10, -1, 1, 10];

// Relative, atomic stock change: safe even while customers are ordering.
export function StockDialog({ product, onClose }) {
  const toast = useToast();
  const [change, setChange] = useState('');
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);

  const n = Number(change);
  const valid = change !== '' && Number.isInteger(n) && n !== 0;
  const after = valid ? product.stock + n : product.stock;
  const tooLow = valid && after < 0;

  const submit = async (e) => {
    e.preventDefault();
    if (!valid || tooLow || saving) return;
    setError(null);
    setSaving(true);
    try {
      const updated = await api.changeProductStock(product.id, n);
      productChanged(updated);
      toast(`Stock updated: ${updated.stock} in stock`);
      onClose();
    } catch (err) {
      setError(err);
      setSaving(false);
    }
  };

  return (
    <Dialog open onClose={saving ? undefined : onClose} fullWidth maxWidth="xs">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>Adjust stock</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <Typography variant="body2" color="text.secondary">
              <b>{product.name}</b> has {product.stock} unit{product.stock === 1 ? '' : 's'}. Add or remove units with a positive or negative number.
            </Typography>
            <ErrorAlert error={error} />
            <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap">
              {QUICK.map((q) => (
                <Chip
                  key={q}
                  label={q > 0 ? `+${q}` : q}
                  variant="outlined"
                  onClick={() => setChange(String((Number(change) || 0) + q))}
                />
              ))}
            </Stack>
            <TextField
              label="Stock change"
              type="number"
              value={change}
              onChange={(e) => setChange(e.target.value)}
              autoFocus
              required
              inputProps={{ step: 1 }}
              error={tooLow}
              helperText={
                tooLow
                  ? `You can remove at most ${product.stock}.`
                  : valid
                    ? `New stock: ${after}`
                    : 'Enter a whole number, e.g. 5 or -2'
              }
            />
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" variant="contained" disabled={saving || !valid || tooLow}>
            {saving ? 'Updating…' : 'Update stock'}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

