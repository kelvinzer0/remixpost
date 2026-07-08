<?php

// Callback URLs use Postiz-compatible pattern: /integrations/social/{provider}
// This allows users migrating from Postiz to keep their existing OAuth callback
// URLs registered at each provider's developer portal.
// Both /integrations/social/{provider} and /social-accounts/callback/{provider}
// are accepted (backward compatible).

return [
    'twitter' => [
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect' => '/integrations/social/twitter',
        'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
        'pkce' => true,
    ],
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => '/integrations/social/facebook',
        'scopes' => ['pages_manage_posts', 'pages_read_engagement', 'pages_show_list',
                     'pages_manage_engagement', 'publish_to_groups',
                     'instagram_content_publish'],
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v18.0'),
    ],
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => '/integrations/social/linkedin',
        // Only use scopes available to all LinkedIn apps.
        // rw_organization and r_organization_social require Marketing API approval.
        // To post to Company Pages, user must be admin — the w_member_social scope
        // allows posting to personal feed. Company Page posting uses the same scope
        // if the user is an admin of the page.
        'scopes' => ['openid', 'profile', 'email', 'w_member_social'],
    ],
    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect' => '/integrations/social/youtube',
        'scopes' => ['https://www.googleapis.com/auth/youtube.upload',
                     'https://www.googleapis.com/auth/youtube',
                     'https://www.googleapis.com/auth/youtube.readonly',
                     'https://www.googleapis.com/auth/userinfo.profile',
                     'https://www.googleapis.com/auth/userinfo.email'],
    ],
    'tiktok' => [
        'client_id' => env('TIKTOK_CLIENT_ID'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => '/integrations/social/tiktok',
        'scopes' => ['video.publish', 'user.info.basic'],
    ],
    'pinterest' => [
        'client_id' => env('PINTEREST_CLIENT_ID'),
        'client_secret' => env('PINTEREST_CLIENT_SECRET'),
        'redirect' => '/integrations/social/pinterest',
        'scopes' => ['boards:read', 'pins:write'],
    ],
    'mastodon' => [
        'url' => env('MASTODON_URL', 'https://mastodon.social'),
        'client_id' => env('MASTODON_CLIENT_ID'),
        'client_secret' => env('MASTODON_CLIENT_SECRET'),
        'redirect' => '/integrations/social/mastodon',
        'scopes' => ['read', 'write'],
    ],
    'telegram' => [
        'bot_token' => env('TELEGRAM_TOKEN'),
        'bot_name' => env('TELEGRAM_BOT_NAME'),
    ],
];
