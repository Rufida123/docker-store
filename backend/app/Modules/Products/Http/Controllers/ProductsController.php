<?php

namespace App\Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\ProductsService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductsController extends Controller
{
    public function __construct(private ProductsService $service)
    {
    }

    // GET /products  (public)  ?category=electronics&page=1&limit=20
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::json($this->service->listProducts($request->query->all()));
    }

    // GET /products/:id  (public)
    public function show(string $id): JsonResponse
    {
        return ApiResponse::json($this->service->getProduct($id));
    }

    // POST /products  (admin only)
    // multipart/form-data: name, price, stock?, category?, description?, image? (JPEG/PNG/WebP, max 2 MB)
    // (a JSON body without an image also works)
    public function store(Request $request): JsonResponse
    {
        $file = $request->file('image');
        $imageBytes = $file !== null ? file_get_contents($file->getRealPath()) : null;

        $product = $this->service->createProduct(self::body($request), $imageBytes === false ? null : $imageBytes);

        return ApiResponse::json($product, 201, ['Location' => '/api/v1/products/'.$product['id']]);
    }

    // PATCH /products/:id  (admin only)  JSON: { name?, description?, category?, price? }
    public function update(Request $request, string $id): JsonResponse
    {
        return ApiResponse::json($this->service->updateProduct($id, $request->json()->all()));
    }

    // PATCH /products/:id/stock  (admin only)  JSON: { "change": 25 } or { "change": -3 }
    public function updateStock(Request $request, string $id): JsonResponse
    {
        return ApiResponse::json($this->service->changeStock($id, $request->json()->all()));
    }

    /**
     * The create endpoint takes multipart form fields or a JSON object; any
     * other body counts as empty, like the original parsers left it.
     */
    private static function body(Request $request): array
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));

        if (str_starts_with($contentType, 'multipart/form-data')) {
            return $request->request->all();
        }

        return $request->json()->all();
    }
}
