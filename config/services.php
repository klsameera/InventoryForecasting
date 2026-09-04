<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ml' => [
        'url' => env('ML_SERVICE_URL', 'http://127.0.0.1:8090'),
        'token' => env('ML_SERVICE_TOKEN'),

        /*
         * Which algorithm an Established SKU uses before it has enough scored
         * forecast history for ModelSelectionService to rank algorithms for
         * itself — 'ewma', 'seasonal_naive', 'tft' or 'deepar'.
         *
         * This is the switch that decides whether the trained model is served
         * at all on a fresh install: accuracy history only exists after a
         * forecast's horizon has elapsed and been scored, so until then every
         * SKU takes this value. A neural name the ML service reports it cannot
         * serve degrades to 'ewma' rather than failing.
         */
        'default_algorithm' => env('ML_DEFAULT_ALGORITHM', 'ewma'),

        /*
         * Where demand history comes from, for both training and serving:
         *
         *   'ledger'   — this application's own stock ledger, via
         *                `inventory_daily_snapshots`. What every phase up to
         *                Phase 10 was built against.
         *   'buyabans' — demand synced from the BuyAbans back office, via
         *                `buyabans_daily_demands`.
         *
         * One switch governs both deliberately. Training on one source and
         * serving from the other would condition a model on one distribution
         * and then feed it another, and nothing in the stack would report an
         * error — it would just quietly forecast worse.
         */
        'demand_source' => env('FORECAST_DEMAND_SOURCE', 'ledger'),

        /*
         * Seconds to wait on /forecast/run. Two values because the work differs
         * by an order of magnitude: a baseline batch is arithmetic and returns
         * in well under a second, while a neural batch loads a checkpoint on
         * first use and runs a real forward pass over every series in the run.
         */
        'timeout' => env('ML_SERVICE_TIMEOUT', 30),
        'neural_timeout' => env('ML_SERVICE_NEURAL_TIMEOUT', 300),
    ],

    /*
     * The BuyAbans back office — the system of record for catalog, stock and
     * sales. This application forecasts; it does not operate stock, and it
     * authors none of this data itself. Everything is pulled read-only from
     * that system's /api/forecasting endpoints.
     *
     * Authentication is Passport client credentials, so this application
     * authenticates as a machine. Create the client on the back office with:
     *   php artisan passport:client --client --name="Inventory Forecasting"
     */
    'buyabans' => [
        'url' => env('BUYABANS_API_URL', 'http://buyabans-backoffice.test'),
        'client_id' => env('BUYABANS_CLIENT_ID'),
        'client_secret' => env('BUYABANS_CLIENT_SECRET'),

        /*
         * Seconds to wait on a single page. Generous, because the daily-sales
         * aggregate groups over the whole order book and a wide date window is
         * genuinely expensive on the back office's side.
         */
        'timeout' => env('BUYABANS_API_TIMEOUT', 120),

        /* Rows per page. The back office caps this at 5000. */
        'page_size' => env('BUYABANS_PAGE_SIZE', 1000),

        /*
         * Which location grain demand is synced at — 'warehouse', 'channel' or
         * 'national'. Syncing more than one is supported and they coexist:
         * rows are keyed by grain, so they never overwrite each other.
         */
        'grain' => env('BUYABANS_GRAIN', 'warehouse'),

        /*
         * How many days of sales history a full sync reaches back for.
         *
         * 1500 covers the four years the back office now holds with room to
         * spare. A full sync that reaches back less than the source holds does
         * not fail — it silently trains on a shorter history than exists.
         */
        'history_days' => env('BUYABANS_HISTORY_DAYS', 1500),

        /*
         * The currency `buyabans_daily_demands.revenue` is denominated in.
         *
         * Not a conversion setting — nothing here converts anything. It is the
         * label the dashboard puts in front of a money figure, and it belongs
         * under this namespace because the money arrived through this API. The
         * back office records 271,666 of its 271,721 orders as 'LKR' (the
         * remaining 55 predate the integration and say 'Rs.', the same thing
         * written informally), so the default is a measured fact about the
         * source, not an assumption about the reader.
         */
        'currency' => env('BUYABANS_CURRENCY', 'LKR'),

    ],

];
