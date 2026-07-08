<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SocialiteWasCalled::class => [
            \SocialiteProviders\YouTube\YouTubeExtendSocialite::class,
            \SocialiteProviders\Pinterest\PinterestExtendSocialite::class,
            \SocialiteProviders\TikTok\TikTokExtendSocialite::class,
            \SocialiteProviders\Mastodon\MastodonExtendSocialite::class,
        ],
    ];
}
