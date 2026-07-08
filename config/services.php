<?php

return [
    'twitter' => [
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect' => env('TWITTER_REDIRECT_URI', '/social-accounts/callback/twitter'),
        'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
        'pkce' => true,
    ],
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', '/social-accounts/callback/facebook'),
        'scopes' => ['pages_manage_posts', 'pages_read_engagement', 'pages_show_list',
                     'pages_manage_engagement', 'publish_to_groups',
                     'instagram_content_publish'],
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v18.0'),
    ],
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI', '/social-accounts/callback/linkedin'),
        'scopes' => ['openid', 'profile', 'email',
                     'w_member_social', 'rw_organization', 'r_organization_social'],
    ],
    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect' => env('YOUTUBE_REDIRECT_URI', '/social-accounts/callback/youtube'),
        'scopes' => ['https://www.googleapis.com/auth/youtube.upload',
                     'https://www.googleapis.com/auth/youtube'],
    ],
    'tiktok' => [
        'client_id' => env('TIKTOK_CLIENT_ID'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI', '/social-accounts/callback/tiktok'),
        'scopes' => ['video.publish', 'user.info.basic'],
    ],
    'pinterest' => [
        'client_id' => env('PINTEREST_CLIENT_ID'),
        'client_secret' => env('PINTEREST_CLIENT_SECRET'),
        'redirect' => env('PINTEREST_REDIRECT_URI', '/social-accounts/callback/pinterest'),
        'scopes' => ['boards:read', 'pins:write'],
    ],
    'mastodon' => [
        'url' => env('MASTODON_URL', 'https://mastodon.social'),
        'client_id' => env('MASTODON_CLIENT_ID'),
        'client_secret' => env('MASTODON_CLIENT_SECRET'),
        'redirect' => env('MASTODON_REDIRECT_URI', '/social-accounts/callback/mastodon'),
        'scopes' => ['read', 'write'],
    ],
    'telegram' => [
        'bot_token' => env('TELEGRAM_TOKEN'),
        'bot_name' => env('TELEGRAM_BOT_NAME'),
    ],
];
