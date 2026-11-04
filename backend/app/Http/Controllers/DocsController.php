<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the OpenAPI document and the Swagger UI. The UI assets live in
 * resources/swagger-ui (shipped with the app, no CDN) and are served through
 * the app so they carry the same headers as everything else.
 */
class DocsController extends Controller
{
    private const ASSET_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'png' => 'image/png',
    ];

    public function spec(): BinaryFileResponse
    {
        return response()->file(dirname(base_path()).'/docs/openapi.yaml', [
            'Content-Type' => 'text/yaml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=0',
        ]);
    }

    public function index(Request $request): Response
    {
        // The page references its assets with relative URLs, so it only works
        // from the trailing-slash form; redirect the bare path to it with the
        // same minimal document and locked-down CSP the static handler used.
        if (! str_ends_with($request->getPathInfo(), '/')) {
            $doc = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
                ."<title>Redirecting</title>\n</head>\n<body>\n"
                ."<pre>Redirecting to /api-docs/</pre>\n</body>\n</html>\n";

            return new Response($doc, 301, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => "default-src 'none'",
                'X-Content-Type-Options' => 'nosniff',
                'Location' => '/api-docs/',
            ]);
        }

        return $this->page();
    }

    public function asset(string $path): Response
    {
        if (str_ends_with('/'.$path, '/package.json')) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if (str_ends_with('/'.$path, '/swagger-ui-init.js')) {
            return new Response(
                file_get_contents(resource_path('swagger-ui/swagger-ui-init.js')),
                200,
                ['Content-Type' => 'application/javascript; charset=utf-8'],
            );
        }

        $file = resource_path('swagger-ui/'.$path);
        $type = self::ASSET_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        if ($type !== null && basename($path) === $path && is_file($file)) {
            return response()->file($file, [
                'Content-Type' => $type,
                'Cache-Control' => 'public, max-age=0',
            ]);
        }

        // Anything else under /api-docs falls back to the UI page.
        return $this->page();
    }

    private function page(): Response
    {
        return new Response(
            file_get_contents(resource_path('swagger-ui/page.html')),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
