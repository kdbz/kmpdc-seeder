<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Source URL
    |--------------------------------------------------------------------------
    |
    | The KMPDC practitioners register URL to scrape data from.
    | Can be overridden via KMPDC_SOURCE_URL environment variable.
    |
    */
    'source_url' => env('KMPDC_SOURCE_URL', 'https://kmpdc.go.ke/Registers/practitioners.php'),

    /*
    |--------------------------------------------------------------------------
    | CSV Storage Path
    |--------------------------------------------------------------------------
    |
    | Where to store the scraped CSV file within the package.
    | Can be overridden via KMPDC_CSV_PATH environment variable.
    |
    */
    'csv_storage_path' => env('KMPDC_CSV_PATH',dirname(__DIR__) . '/storage/app/kmpdc-data/csv'),


    /*
    |--------------------------------------------------------------------------
    | CSV Filename
    |--------------------------------------------------------------------------
    |
    | Name of the CSV file to store scraped data.
    | Can be overridden via KMPDC_CSV_FILENAME environment variable.
    |
    */
    'csv_filename' => env('KMPDC_CSV_FILENAME', 'kmpdc_practitioners.csv'),

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    |
    | HTTP request configuration for the scraper.
    |
    */
    'request_timeout' => env('KMPDC_REQUEST_TIMEOUT', 3000), // seconds
    'request_delay' => env('KMPDC_REQUEST_DELAY', 1000),  // milliseconds between requests
    'max_retries' => env('KMPDC_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Sync Schedule
    |--------------------------------------------------------------------------
    |
    | How often to sync data (used by scheduled commands).
    | Options: daily, weekly, monthly
    |
    */
    'sync_frequency' => env('KMPDC_SYNC_FREQUENCY', 'daily'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    |
    | Additional settings for the HTTP client.
    |
    */
    'verify_ssl' => env('KMPDC_VERIFY_SSL', true), // Set to false if SSL issues occur
    'user_agent' => env('KMPDC_USER_AGENT', 'Mozilla/5.0 (compatible; KmpdcSeeder/1.0)'),
];