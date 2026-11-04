<?php

namespace App\Modules\Products\Http\Middleware;

use App\Modules\Products\ProductsImages;
use App\Shared\AppError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Upload rules for multipart requests, matching the previous parser's limits:
 * one optional file in the "image" field (max 2 MB, checked before anything is
 * written to disk), at most 10 text fields of at most 10 KB each. Problems are
 * reported in the unified error format. Placed after authorize: only admins
 * can upload.
 */
class UploadLimits
{
    private const MAX_FIELDS = 10;

    private const MAX_FIELD_BYTES = 10 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));

        if (str_starts_with($contentType, 'multipart/form-data')) {
            $this->checkFiles($request);
            $this->checkFields($request);
        }

        return $next($request);
    }

    private function checkFiles(Request $request): void
    {
        foreach ($request->files->all() as $field => $value) {
            if ($field !== 'image') {
                throw new AppError(400, 'INVALID_UPLOAD', 'Invalid upload', [
                    ['field' => $field, 'message' => 'Unexpected field'],
                ]);
            }
            if (is_array($value)) {
                throw new AppError(400, 'INVALID_UPLOAD', 'Invalid upload', [
                    ['field' => 'image', 'message' => 'Unexpected field'],
                ]);
            }
        }

        $file = $request->files->get('image');
        if (! $file instanceof UploadedFile) {
            return;
        }

        $tooLarge = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($file->getError() === UPLOAD_ERR_OK && $file->getSize() > ProductsImages::MAX_IMAGE_BYTES);

        if ($tooLarge) {
            throw new AppError(413, 'FILE_TOO_LARGE', 'Image is too large', [
                ['field' => 'image', 'message' => 'image must be at most '.(ProductsImages::MAX_IMAGE_BYTES / (1024 * 1024)).' MB'],
            ]);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new AppError(400, 'INVALID_UPLOAD', 'Invalid upload', [
                ['field' => 'image', 'message' => 'Upload failed'],
            ]);
        }
    }

    private function checkFields(Request $request): void
    {
        $fields = $request->request->all();

        if (count($fields) > self::MAX_FIELDS) {
            throw new AppError(400, 'INVALID_UPLOAD', 'Invalid upload', [
                ['field' => 'image', 'message' => 'Too many fields'],
            ]);
        }

        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item) && strlen($item) > self::MAX_FIELD_BYTES) {
                    throw new AppError(400, 'INVALID_UPLOAD', 'Invalid upload', [
                        ['field' => (string) $name, 'message' => 'Field value too long'],
                    ]);
                }
            }
        }
    }
}
