<?php

namespace App\Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\ProductsImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the saved images at /uploads/products/<name>. A missing file gets the
 * app's JSON 404. Image names are random and never reused, so browsers may
 * cache them for a week.
 */
class ImagesController extends Controller
{
    private const TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function show(string $file): BinaryFileResponse
    {
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        $path = ProductsImages::dir().DIRECTORY_SEPARATOR.$file;

        if ($type === null
            || basename($file) !== $file
            || str_starts_with($file, '.')
            || ! is_file($path)) {
            throw new NotFoundHttpException();
        }

        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=604800',
            // The default policy allows same-origin only; images must also load
            // in a front end on another port/domain.
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
