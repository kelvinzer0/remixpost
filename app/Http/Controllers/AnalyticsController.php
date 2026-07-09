<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostMetric;
use App\Models\SocialAccount;
use App\Services\MediaType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    /**
     * Analytics dashboard — per-account summary + per-post metrics.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Get all published posts with metrics + accounts
        $posts = $user->posts()
            ->where('status', Post::STATUS_PUBLISHED)
            ->with(['socialAccounts' => function ($q) {
                $q->select('social_accounts.id', 'provider', 'name', 'username', 'avatar', 'metadata');
            }, 'metrics' => function ($q) {
                $q->with('socialAccount:id,provider,name');
            }])
            ->orderBy('published_at', 'desc')
            ->take(50)
            ->get(['id', 'content', 'published_at', 'status']);

        // Get all accounts
        $accounts = $user->socialAccounts()
            ->where('is_active', true)
            ->get(['id', 'provider', 'name', 'username', 'avatar', 'metadata']);

        // Build per-account summary
        $accountSummaries = [];
        foreach ($accounts as $account) {
            $metrics = PostMetric::where('social_account_id', $account->id)
                ->whereNotNull('fetched_at')
                ->get();

            if ($metrics->isEmpty()) {
                $accountSummaries[] = [
                    'account' => $account,
                    'posts_count' => 0,
                    'total_likes' => 0,
                    'total_comments' => 0,
                    'total_shares' => 0,
                    'total_views' => 0,
                    'total_impressions' => 0,
                    'total_engagement' => 0,
                    'avg_engagement_rate' => 0,
                    'last_fetched' => null,
                ];
                continue;
            }

            $totalLikes = $metrics->sum('likes');
            $totalComments = $metrics->sum('comments');
            $totalShares = $metrics->sum('shares');
            $totalViews = $metrics->sum('views');
            $totalImpressions = $metrics->sum('impressions');
            $totalEngagement = $totalLikes + $totalComments + $totalShares + $metrics->sum('saves');

            $accountSummaries[] = [
                'account' => [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'name' => $account->name,
                    'username' => $account->username,
                    'avatar' => $account->avatar,
                    'metadata' => $account->metadata,
                ],
                'posts_count' => $metrics->count(),
                'total_likes' => $totalLikes,
                'total_comments' => $totalComments,
                'total_shares' => $totalShares,
                'total_views' => $totalViews,
                'total_impressions' => $totalImpressions,
                'total_engagement' => $totalEngagement,
                'avg_engagement_rate' => $totalImpressions > 0
                    ? round(($totalEngagement / $totalImpressions) * 100, 2)
                    : ($totalViews > 0 ? round(($totalEngagement / $totalViews) * 100, 2) : 0),
                'last_fetched' => $metrics->max('fetched_at'),
            ];
        }

        // Build per-post metrics data
        $postData = $posts->map(function ($post) {
            $metricsByAccount = [];
            foreach ($post->metrics as $metric) {
                $metricsByAccount[] = [
                    'account_id' => $metric->social_account_id,
                    'account_name' => $metric->socialAccount?->name ?? 'Unknown',
                    'account_provider' => $metric->socialAccount?->provider ?? 'unknown',
                    'likes' => $metric->likes,
                    'comments' => $metric->comments,
                    'shares' => $metric->shares,
                    'views' => $metric->views,
                    'impressions' => $metric->impressions,
                    'clicks' => $metric->clicks,
                    'saves' => $metric->saves,
                    'engagement_rate' => $metric->engagement_rate,
                    'total_engagement' => $metric->total_engagement,
                    'fetched_at' => $metric->fetched_at?->format('Y-m-d H:i'),
                    'external_post_id' => $metric->external_post_id,
                ];
            }

            return [
                'id' => $post->id,
                'content' => mb_substr($post->content, 0, 80),
                'published_at' => $post->published_at?->format('Y-m-d H:i'),
                'accounts' => $post->socialAccounts->map(fn($a) => [
                    'id' => $a->id,
                    'provider' => $a->provider,
                    'name' => $a->name,
                ]),
                'metrics' => $metricsByAccount,
            ];
        });

        // Overall totals
        $totals = [
            'posts' => $posts->count(),
            'accounts' => $accounts->count(),
            'likes' => array_sum(array_column($accountSummaries, 'total_likes')),
            'comments' => array_sum(array_column($accountSummaries, 'total_comments')),
            'shares' => array_sum(array_column($accountSummaries, 'total_shares')),
            'views' => array_sum(array_column($accountSummaries, 'total_views')),
            'impressions' => array_sum(array_column($accountSummaries, 'total_impressions')),
            'engagement' => array_sum(array_column($accountSummaries, 'total_engagement')),
        ];

        return Inertia::render('Analytics/Index', [
            'accountSummaries' => $accountSummaries,
            'posts' => $postData,
            'totals' => $totals,
        ]);
    }

    /**
     * Refresh metrics from platform APIs.
     * Fetches engagement data for all published posts across all accounts.
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        // Query pivot directly to get external_post_id (eager loading doesn't
        // include pivot extra columns by default)
        $rows = \DB::table('post_social_account')
            ->join('posts', 'posts.id', '=', 'post_social_account.post_id')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_social_account.social_account_id')
            ->where('posts.user_id', $user->id)
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->whereNotNull('post_social_account.external_post_id')
            ->select(
                'post_social_account.post_id',
                'post_social_account.social_account_id',
                'post_social_account.external_post_id',
                'social_accounts.provider',
                'social_accounts.access_token',
                'social_accounts.refresh_token',
                'social_accounts.expires_at',
                'social_accounts.metadata',
                'social_accounts.user_id'
            )
            ->get();

        $updated = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                // For Buffer: refresh token if expired
                $accessToken = $row->access_token;
                if ($row->provider === 'buffer') {
                    $expiresAt = $row->expires_at ? \Carbon\Carbon::parse($row->expires_at) : null;
                    if ($expiresAt && $expiresAt->isPast() && $row->refresh_token) {
                        $refreshResult = $this->refreshBufferToken($row->social_account_id, $row->refresh_token, $row->user_id);
                        if ($refreshResult) {
                            $accessToken = $refreshResult;
                        } else {
                            $errors[] = "Post #{$row->post_id} (buffer): Token refresh failed";
                            continue;
                        }
                    }
                }

                $metrics = $this->fetchMetricsForProvider(
                    $row->provider,
                    $accessToken,
                    $row->external_post_id,
                    is_string($row->metadata) ? json_decode($row->metadata, true) : ($row->metadata ?? [])
                );

                if ($metrics) {
                    PostMetric::updateOrCreate(
                        [
                            'post_id' => $row->post_id,
                            'social_account_id' => $row->social_account_id,
                        ],
                        array_merge($metrics, [
                            'external_post_id' => $row->external_post_id,
                            'fetched_at' => now(),
                        ])
                    );
                    $updated++;
                }
            } catch (\Exception $e) {
                $errors[] = "Post #{$row->post_id} ({$row->provider}): " . $e->getMessage();
            }
        }

        $msg = "Updated {$updated} metric(s).";
        if (!empty($errors)) {
            $msg .= " Errors: " . implode('; ', array_slice($errors, 0, 5));
        }

        return back()->with('message', $msg);
    }

    /**
     * Refresh Buffer access token (tokens expire in 1 hour).
     */
    private function refreshBufferToken(int $accountId, string $refreshToken, int $userId): ?string
    {
        try {
            $response = Http::asForm()->post(config('services.buffer.token_url'), [
                'client_id' => config('services.buffer.client_id'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

            if (!$response->ok()) return null;

            $data = $response->json();
            $newAccessToken = $data['access_token'] ?? null;
            $newRefreshToken = $data['refresh_token'] ?? null;
            $expiresIn = $data['expires_in'] ?? 3600;

            if (!$newAccessToken || !$newRefreshToken) return null;

            // Update ALL Buffer accounts for this user (tokens are user-wide)
            SocialAccount::where('user_id', $userId)
                ->where('provider', 'buffer')
                ->update([
                    'access_token' => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
                    'expires_at' => now()->addSeconds($expiresIn),
                ]);

            return $newAccessToken;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Fetch metrics from specific platform API.
     */
    private function fetchMetricsForProvider(string $provider, string $accessToken, string $externalId, array $metadata = []): ?array
    {
        return match ($provider) {
            'linkedin' => $this->fetchLinkedInMetrics($accessToken, $externalId),
            'facebook' => $this->fetchFacebookMetrics($accessToken, $externalId),
            'youtube' => $this->fetchYouTubeMetrics($accessToken, $externalId),
            'buffer' => $this->fetchBufferMetrics($accessToken, $externalId, $metadata),
            default => null,
        };
    }

    private function fetchLinkedInMetrics(string $accessToken, string $postUrn): ?array
    {
        try {
            // LinkedIn returns share URN (urn:li:share:xxx) from REST Posts API.
            // To get socialActions, we need to convert to activity URN or use
            // the share URN directly with the socialActions endpoint.
            // Try the share URN first, then fallback to different endpoints.
            $encodedUrn = urlencode($postUrn);

            // Try /rest/posts/{urn}/socialActions
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'LinkedIn-Version' => '202601',
                ])
                ->get("https://api.linkedin.com/rest/posts/{$encodedUrn}/socialActions");

            if (!$response->ok()) {
                // Fallback: try v2/socialActions/{urn}
                $response = Http::withToken($accessToken)
                    ->get("https://api.linkedin.com/v2/socialActions/{$encodedUrn}");
                if (!$response->ok()) return null;
            }

            $data = $response->json();
            return [
                'likes' => $data['likesSummary']['totalLikes'] ?? 0,
                'comments' => $data['commentsSummary']['aggregatedTotalComments'] ?? 0,
                'shares' => $data['sharesSummary']['totalShares'] ?? 0,
                'raw_metrics' => $data,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function fetchFacebookMetrics(string $accessToken, string $postId): ?array
    {
        try {
            $response = Http::get("https://graph.facebook.com/v18.0/{$postId}", [
                'fields' => 'reactions.summary(true),comments.summary(true),shares,message',
                'access_token' => $accessToken,
            ]);

            if (!$response->ok()) return null;

            $data = $response->json();
            return [
                'likes' => $data['reactions']['summary']['total_count'] ?? 0,
                'comments' => $data['comments']['summary']['total_count'] ?? 0,
                'shares' => $data['shares']['count'] ?? 0,
                'raw_metrics' => $data,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function fetchYouTubeMetrics(string $accessToken, string $videoId): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/youtube/v3/videos', [
                    'part' => 'statistics',
                    'id' => $videoId,
                ]);

            if (!$response->ok()) return null;

            $items = $response->json('items', []);
            if (empty($items)) return null;

            $stats = $items[0]['statistics'] ?? [];
            return [
                'views' => (int)($stats['viewCount'] ?? 0),
                'likes' => (int)($stats['likeCount'] ?? 0),
                'comments' => (int)($stats['commentCount'] ?? 0),
                'raw_metrics' => $stats,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function fetchBufferMetrics(string $accessToken, string $postId, array $metadata): ?array
    {
        try {
            $query = 'query GetPost($id: ID!) {
  post(input: { id: $id }) {
    id status
    metrics { impressions clicks likes comments shares }
  }
}';
            $response = Http::withToken($accessToken)
                ->post(config('services.buffer.api_url'), [
                    'query' => $query,
                    'variables' => ['id' => $postId],
                ]);

            $body = $response->json();
            if (isset($body['errors']) || !isset($body['data']['post'])) return null;

            $metrics = $body['data']['post']['metrics'] ?? [];
            return [
                'impressions' => $metrics['impressions'] ?? 0,
                'clicks' => $metrics['clicks'] ?? 0,
                'likes' => $metrics['likes'] ?? 0,
                'comments' => $metrics['comments'] ?? 0,
                'shares' => $metrics['shares'] ?? 0,
                'raw_metrics' => $body['data']['post'],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function fetchTelegramMetrics(string $botToken, string $messageId, string $chatId): ?array
    {
        // Telegram Bot API doesn't provide message engagement metrics
        // We could use getChatMemberCount for channel growth, but not per-message
        return null;
    }
}
