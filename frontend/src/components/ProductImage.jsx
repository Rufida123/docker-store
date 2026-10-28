import { useEffect, useState } from 'react';
import { Box, Stack } from '@mui/material';
import InventoryIcon from '@mui/icons-material/Inventory2Outlined';
import { assetUrl } from '../api/client';

// Lazy-loaded image with a fixed aspect ratio (no layout jump) and a graceful fallback.
export default function ProductImage({ src, alt = '', ratio = '4 / 3', sx }) {
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [src]);
  const url = assetUrl(src);

  if (!url || failed) {
    return (
      <Stack
        alignItems="center"
        justifyContent="center"
        sx={{ width: '100%', aspectRatio: ratio, bgcolor: 'action.hover', color: 'text.disabled', ...sx }}
      >
        <InventoryIcon sx={{ fontSize: 44 }} />
      </Stack>
    );
  }
  return (
    <Box
      component="img"
      src={url}
      alt={alt}
      loading="lazy"
      decoding="async"
      onError={() => setFailed(true)}
      sx={{ width: '100%', aspectRatio: ratio, objectFit: 'cover', display: 'block', bgcolor: 'action.hover', ...sx }}
    />
  );
}
