# Laravel Affiliate Awin Network

[![Latest Version](http://img.shields.io/packagist/v/soluzione-software/laravel-affiliate-awin-network.svg?label=Release&style=for-the-badge)](https://packagist.org/packages/soluzione-software/laravel-affiliate-awin-network)
[![MIT License](https://img.shields.io/github/license/soluzione-software/laravel-affiliate-awin-network.svg?label=License&color=blue&style=for-the-badge)](https://github.com/soluzione-software/laravel-affiliate-awin-network/blob/master/LICENSE.md)

> Note the package is currently in beta. During the beta period things can and probably will change. Don't use it in production until a stable version has been released. We appreciate you helping out with testing and reporting any bugs.

[Awin](https://www.awin.com) network integration
for [soluzione-software/laravel-affiliate](https://github.com/soluzione-software/laravel-affiliate).

## Installation & Configuration

```bash
composer require soluzione-software/laravel-affiliate-awin-network
```

Edit `config/affiliate.php`:

```php
<?php

return [

    //...

    /*
    |--------------------------------------------------------------------------
    | Networks Configuration
    |--------------------------------------------------------------------------
    */
    'networks' => [
        //...
        
        'awin' => [
            /*
             * see https://wiki.awin.com/index.php/Publisher_Click_Ref
             */
            'tracking_code_param' => 'clickRef',
            
            'api_key' => env('AWIN_API_KEY'),
            
            'publisher_id' => env('AWIN_PUBLISHER_ID'),
            
            'product_feed' => [
                'api_key' => env('AWIN_PRODUCT_FEED_API_KEY'),

                /*
                 * Extra columns to download
                 * array
                 */
                'extra_columns' => [
                    //
                ],
            ],
        ],
    ],

    //...
];

```

Edit `.env`:

```dotenv
AWIN_API_KEY=
AWIN_PUBLISHER_ID=
AWIN_PRODUCT_FEED_API_KEY=
```
