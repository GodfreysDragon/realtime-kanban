<?php

namespace App\Http\Middleware;

use App\Services\BoardSequence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Adds X-Board-Events: the sequence numbers this request broadcast. */
class ExposeBoardEvents
{
    public function __construct(private readonly BoardSequence $sequence) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The service is scoped, but a long-lived worker (Octane, or the test
        // client) can reuse the container across requests.
        $this->sequence->reset();
        $response = $next($request);

        if ($header = $this->sequence->header()) {
            $response->headers->set('X-Board-Events', $header);
        }

        return $response;
    }
}
