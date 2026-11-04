<?php

namespace App\Modules\Products;

use App\Shared\AppError;
use Illuminate\Support\Str;

/**
 * Product images: checks, saves and removes the uploaded files.
 * In Docker the folder is a named volume (see docker-compose.yml), so images
 * survive rebuilds.
 */
class ProductsImages
{
    public const PUBLIC_PATH = '/uploads/products';

    public const MAX_IMAGE_BYTES = 2 * 1024 * 1024; // 2 MB

    public static function dir(): string
    {
        return rtrim((string) config('store.uploads_dir'), '/\\').DIRECTORY_SEPARATOR.'products';
    }

    /**
     * The real type is read from the file's first bytes, never from its name or
     * from the Content-Type the client sent (both are easy to fake). SVG is
     * refused: it can carry scripts.
     */
    public static function detectType(string $bytes): ?string
    {
        if (strlen($bytes) >= 3 && str_starts_with($bytes, "\xff\xd8\xff")) {
            return 'jpg';
        }
        if (strlen($bytes) >= 8 && str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }

    /**
     * Saves the image under a new random name (the client's file name is never
     * used, so it can't overwrite files or escape the folder). Returns the
     * image's public URL.
     */
    public static function save(string $bytes): string
    {
        $ext = self::detectType($bytes);
        if ($ext === null) {
            throw new AppError(400, 'INVALID_IMAGE', 'Invalid image', [
                ['field' => 'image', 'message' => 'image must be a JPEG, PNG or WebP file'],
            ]);
        }

        $dir = self::dir();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = Str::uuid()->toString().'.'.$ext;

        // "x" mode: fail instead of overwriting, should the name ever collide.
        $handle = fopen($dir.DIRECTORY_SEPARATOR.$name, 'xb');
        fwrite($handle, $bytes);
        fclose($handle);

        return self::PUBLIC_PATH.'/'.$name;
    }

    /**
     * Deletes an image saved by save(), e.g. when saving the product itself failed.
     */
    public static function remove(string $url): void
    {
        @unlink(self::dir().DIRECTORY_SEPARATOR.basename($url));
    }
}
