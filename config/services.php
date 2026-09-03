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
         * Seconds to wait on /forecast/run. Two values because the work differs
         * by an order of magnitude: a baseline batch is arithmetic and returns
         * in well under a second, while a neural batch loads a checkpoint on
         * first use and runs a real forward pass over every series in the run.
         */
        'timeout' => env('ML_SERVICE_TIMEOUT', 30),
        'neural_timeout' => env('ML_SERVICE_NEURAL_TIMEOUT', 300),
    ],

];
