import { useState } from 'react';
import { Link as RouterLink, useLocation, useNavigate, useParams } from 'react-router-dom';
import { Box, Button, Card, Chip, Divider, Skeleton, Stack, Typography } from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import EditIcon from '@mui/icons-material/EditOutlined';
import InventoryIcon from '@mui/icons-material/Inventory2Outlined';
import AddShoppingCartIcon from '@mui/icons-material/AddShoppingCartOutlined';
import SearchOffIcon from '@mui/icons-material/SearchOff';
import { useAuth } from '../context/AuthContext';
import { useProduct } from '../hooks/queries';
import ErrorAlert from '../components/ErrorAlert';
import EmptyState from '../components/EmptyState';
import ProductImage from '../components/ProductImage';
import { ProductDialog, StockDialog } from '../components/ProductDialogs';
import { UUID_RE, capitalize, formatDate, formatMoney, stockInfo } from '../utils/format';

export default function ProductDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { isAdmin } = useAuth();
  const [dialog, setDialog] = useState(null);
  const validId = UUID_RE.test(id); // a malformed id would only produce a 400, so don't ask
  const { data: product, loading, error, refetch } = useProduct(id, { enabled: validId });

  const goBack = () => (location.key !== 'default' ? navigate(-1) : navigate('/products'));
  const notFound = !validId || error?.code === 'PRODUCT_NOT_FOUND';
  const stock = stockInfo(product?.stock);

  return (
    <>
      <Button startIcon={<ArrowBackIcon />} onClick={goBack} sx={{ mb: 2, ml: -1 }}>
        Back
      </Button>

      {notFound ? (
        <Card>
          <EmptyState
            icon={<SearchOffIcon />}
            title="Product not found"
            description="It may have been removed, or the link is incorrect."
            action={
              <Button variant="contained" component={RouterLink} to="/products">
                Browse products
              </Button>
            }
          />
        </Card>
      ) : error && !product ? (
        <ErrorAlert error={error} onRetry={refetch} />
      ) : loading || !product ? (
        <Box sx={{ display: 'grid', gap: 4, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
          <Skeleton variant="rounded" sx={{ aspectRatio: '4 / 3', height: 'auto' }} />
          <Stack spacing={2}>
            <Skeleton width="70%" height={44} />
            <Skeleton width="30%" height={32} />
            <Skeleton variant="rounded" height={120} />
          </Stack>
        </Box>
      ) : (
        <Box sx={{ display: 'grid', gap: { xs: 3, md: 5 }, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' }, alignItems: 'start' }}>
          <Card sx={{ overflow: 'hidden' }}>
            <ProductImage src={product.image_url} alt={product.name} />
          </Card>

          <Stack spacing={2.5}>
            <Box>
              {product.category && <Chip label={capitalize(product.category)} size="small" sx={{ mb: 1.5 }} />}
              <Typography variant="h4" component="h1">
                {product.name}
              </Typography>
            </Box>
            <Stack direction="row" spacing={2} alignItems="center">
              <Typography variant="h4" color="primary.main" fontWeight={700}>
                {formatMoney(product.price)}
              </Typography>
              <Chip label={stock.label} color={stock.color} variant="outlined" />
            </Stack>
            <Typography color="text.secondary" sx={{ whiteSpace: 'pre-line' }}>
              {product.description || 'No description available.'}
            </Typography>
            <Divider />

            {isAdmin ? (
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
                <Button variant="contained" startIcon={<EditIcon />} onClick={() => setDialog('edit')}>
                  Edit product
                </Button>
                <Button variant="outlined" startIcon={<InventoryIcon />} onClick={() => setDialog('stock')}>
                  Adjust stock
                </Button>
              </Stack>
            ) : (
              <Button
                variant="contained"
                size="large"
                startIcon={<AddShoppingCartIcon />}
                component={RouterLink}
                to={`/orders/new?product=${product.id}`}
                disabled={!(product.stock > 0)}
                sx={{ alignSelf: 'flex-start' }}
              >
                {product.stock > 0 ? 'Order this product' : 'Out of stock'}
              </Button>
            )}

            {(product.created_at || product.updated_at) && (
              <Typography variant="caption" color="text.secondary">
                {product.created_at && `Added ${formatDate(product.created_at)}`}
                {product.updated_at && ` · Updated ${formatDate(product.updated_at)}`}
              </Typography>
            )}
          </Stack>
        </Box>
      )}

      {dialog === 'edit' && product && <ProductDialog product={product} onClose={() => setDialog(null)} />}
      {dialog === 'stock' && product && <StockDialog product={product} onClose={() => setDialog(null)} />}
    </>
  );
}
