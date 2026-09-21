<?php

namespace App\Http\Controllers;

use App\Services\Tracking\OpenRecorder;
use Illuminate\Http\Response;

/**
 * The open-tracking pixel.
 *
 * Whatever the token, the answer is the same 1x1 transparent GIF with the
 * same headers: a broken image in somebody's inbox is the one outcome to
 * avoid, and a different answer for unknown tokens would let anybody probe
 * which ones exist. Recording is a side effect, never the response.
 */
class OpenTrackingController extends Controller
{
    /** A 1x1 transparent GIF, 42 bytes. */
    public const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __invoke(string $token, OpenRecorder $recorder): Response
    {
        $recorder->record($token);

        $gif = base64_decode(self::GIF);

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen($gif),
            // Never cached, so every open reaches us rather than a proxy.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
