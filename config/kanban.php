<?php

return [

    /*
     * Requests per minute each signed-in user may make to the board API. The
     * app itself stays far below this; it's a ceiling for scripted clients.
     */
    'api_requests_per_minute' => (int) env('KANBAN_API_REQUESTS_PER_MINUTE', 300),

];
