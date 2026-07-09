<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI Caption Generator — uses OpenAI-compatible API (router9/zeroai).
 *
 * Generates social media captions that "think like a human":
 *   - Aware of today's context (holiday, weekday vibe, time of day)
 *   - References yesterday (recap) and tomorrow/day after (preview)
 *   - Adapts tone per user selection
 *   - Optimized per target platform (FB/IG/X/LinkedIn have different style)
 *
 * Uses HolidayContextService to build situational awareness.
 *
 * @license Apache-2.0
 */
class AICaptionService
{
    private const TONES = [
        'casual' => [
            'label' => 'Santai (Casual)',
            'instruction' => 'Gaya santai seperti ngobrol sama teman. Pakai bahasa sehari-hari, sedikit slang, gak kaku. Boleh pakai emoji secukupnya.',
        ],
        'professional' => [
            'label' => 'Profesional',
            'instruction' => 'Gaya profesional tapi tetap mudah dibaca. Cocok untuk LinkedIn/Bisnis. Bahasa baku tapi tidak kaku, ada nilai insight.',
        ],
        'promotional' => [
            'label' => 'Promosi (Sales)',
            'instruction' => 'Gaya promosi yang menggugah. Ada hook di awal, manfaat jelas, call-to-action di akhir. Jangan terlalu pushy, tetap natural.',
        ],
        'storytelling' => [
            'label' => 'Bercerita (Story)',
            'instruction' => 'Gaya bercerita. Mulai dari momen/hook yang relate, lalu sambungkan ke topik. Buat audiens merasa "iya, gw juga gitu".',
        ],
        'humorous' => [
            'label' => 'Lucu (Humor)',
            'instruction' => 'Gaya humor ringan yang relate ke situasi hari ini. Bukan joke receh, tapi observasi lucu yang bikin senyum. Hindari SARA/sensitif.',
        ],
        'inspirational' => [
            'label' => 'Inspiratif',
            'instruction' => 'Gaya motivasi/inspirasi. Ada pesan yang mengangkat, relate ke momen hari ini. Gak terlalu klise, ada twist yang segar.',
        ],
        'informative' => [
            'label' => 'Informatif',
            'instruction' => 'Gaya edukatif. Berbagi info/tips/fakta yang berguna. To the point, jelas, gak bertele-tele.',
        ],
    ];

    /**
     * Generate caption(s) for a post.
     *
     * @param array $input
     *   - prompt: string (optional draft/topic from user)
     *   - tone: string (casual|professional|promotional|storytelling|humorous|inspirational|informative)
     *   - platforms: array (e.g. ['facebook', 'instagram', 'twitter']) — affects style
     *   - target_date: string ISO datetime (when post will be published)
     *   - count: int (how many variations, default 3)
     * @return array
     *   - captions: string[]
     *   - context_used: string (debug — what context was fed to AI)
     */
    public function generate(array $input): array
    {
        $apiKey = config('services.openai.key') ?: env('OPENAI_API_KEY');
        $baseUrl = config('services.openai.base_url') ?: env('OPENAI_API_BASE_URL');
        $model = config('services.openai.model') ?: env('OPENAI_MODEL');

        if (!$apiKey || !$baseUrl || !$model) {
            return [
                'error' => 'AI not configured. Set OPENAI_API_KEY, OPENAI_API_BASE_URL, OPENAI_MODEL in .env',
                'captions' => [],
            ];
        }

        $tone = $input['tone'] ?? 'casual';
        $toneMeta = self::TONES[$tone] ?? self::TONES['casual'];
        $platforms = $input['platforms'] ?? [];
        $targetDate = isset($input['target_date']) ? \Carbon\Carbon::parse($input['target_date'], config('app.timezone')) : null;
        $count = max(1, min(5, (int) ($input['count'] ?? 3)));
        $userPrompt = trim($input['prompt'] ?? '');

        // Build context (today + yesterday + tomorrow + day after)
        $context = HolidayContextService::buildContext($targetDate);

        // Build platform hints
        $platformHints = $this->buildPlatformHints($platforms);

        // Build system prompt — "cara orang berfikir"
        $systemPrompt = $this->buildSystemPrompt($toneMeta, $platformHints, $count);

        // Build user prompt — context + user's draft topic
        $userMessage = $this->buildUserMessage($context, $userPrompt, $tone, $platforms);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post(rtrim($baseUrl, '/') . '/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'temperature' => 0.8, // creative but not too random
                    'max_tokens' => 1500,
                ]);

            if (!$response->ok()) {
                Log::error('AI caption API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [
                    'error' => 'AI API returned error: ' . $response->status() . ' ' . $response->body(),
                    'captions' => [],
                ];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            // Parse captions — AI should return one per "===" separator
            $captions = $this->parseCaptions($content, $count);

            return [
                'captions' => $captions,
                'context_used' => $context,
                'raw' => $content,
            ];
        } catch (\Exception $e) {
            Log::error('AI caption generation failed: ' . $e->getMessage());
            return [
                'error' => 'Failed to generate caption: ' . $e->getMessage(),
                'captions' => [],
            ];
        }
    }

    /**
     * Get available tones for UI.
     */
    public static function getTones(): array
    {
        return array_map(fn($t) => ['value' => array_search($t, self::TONES), 'label' => $t['label']], self::TONES);
    }

    /**
     * Build system prompt that instructs AI to think like a human.
     */
    private function buildSystemPrompt(array $toneMeta, string $platformHints, int $count): string
    {
        return <<<PROMPT
Kamu adalah copywriter social media Indonesia yang sangat paham konteks lokal.

TUGAS: Buat {$count} variasi caption untuk posting social media.

CARA BERFIKIR (pENTING — seperti orang lokal berfikir):
1. Pahami dulu "ada apa hari ini" — apakah hari biasa, hari besar, weekend, atau hari kerja.
2. Hubungkan topik user dengan momen hari ini. Kalau hari Kartini, bisakah caption relate ke perempuan? Kalau weekend, bisakah lebih santai?
3. Kalau kemarin ada event, boleh di-recap singkat ("semalam kita rayakan...").
4. Kalau besok/lusa ada event, boleh jadi teaser ("besok siap-siap ya...").
5. Sesuaikan energi caption dengan suasana hari: Senin semangat baru, Rabu butuh dorongan, Jumat excited weekend, dll.

GAYA BAHASA:
- {$toneMeta['instruction']}
- Bahasa Indonesia natural, bukan terjemahan kaku dari Inggris.
- Hindari kata-kata marketing klise ("jangan sampai kelewatan!", "buruan sebelum kehabisan!").
- Boleh pakai singkatan gaul yang umum (gk, bs, dll) untuk tone casual, hindari untuk professional.

FORMAT OUTPUT:
- Tulis {$count} caption, dipisahkan dengan baris yang berisi hanya "===" (3 karakter sama dengan).
- Setiap caption langsung jadi — tanpa label "Caption 1:" atau penjelasan.
- Setiap caption maksimal 500 karakter (kecuali Twitter/X yang max 280).
- Jangan tambahkan intro/outro seperti "Berikut beberapa caption:" — langsung caption pertama saja.

{$platformHints}
PROMPT;
    }

    /**
     * Build user message with context + user's topic.
     */
    private function buildUserMessage(string $context, string $userPrompt, string $tone, array $platforms): string
    {
        $msg = "=== KONTEKS WAKTU & MOMEN ===\n{$context}\n\n";

        if (!empty($userPrompt)) {
            $msg .= "=== TOPIK/DRAFT DARI USER ===\n{$userPrompt}\n\n";
        } else {
            $msg .= "=== TOPIK/DRAFT DARI USER ===\n(kosong — buat caption bebas yang relate ke momen hari ini)\n\n";
        }

        $msg .= "=== TONE YANG DIPILIH ===\n{$tone}\n\n";

        if (!empty($platforms)) {
            $msg .= "=== PLATFORM TUJUAN ===\n" . implode(', ', $platforms) . "\n\n";
        }

        $msg .= "Buat caption yang relate ke konteks di atas. Pikirkan seperti orang yang sedang scroll medsos dan ingin sesuatu yang segar + relate ke hari ini.";

        return $msg;
    }

    /**
     * Build platform-specific hints for AI.
     */
    private function buildPlatformHints(array $platforms): string
    {
        if (empty($platforms)) {
            return "PLATFORM: tidak spesifik — caption serbaguna.";
        }

        $hints = ["PLATFORM HINTS:"];
        foreach ($platforms as $p) {
            $hint = match ($p) {
                'twitter' => 'Twitter/X: max 280 karakter, tajam, punchy, bisa pakai hashtag 1-2',
                'instagram' => 'Instagram: visual-first, caption boleh panjang (sampai 2200), pakai emoji + hashtag di akhir (3-5)',
                'facebook' => 'Facebook: caption sedang (100-500 karakter), conversational, boleh tanya pertanyaan untuk engagement',
                'linkedin' => 'LinkedIn: profesional, insight-driven, max 3000 karakter tapi ideal 200-500, hindari emoji berlebihan',
                'tiktok' => 'TikTok: caption pendek (150 char), pakai trend sound/vibe, hashtag 3-5',
                'pinterest' => 'Pinterest: deskriptif, keyword-rich untuk SEO, 500 char, panggil ke action "save this pin"',
                'telegram' => 'Telegram: seperti broadcast, jelas dan informatif',
                'email' => 'Email: subject line + body, lebih panjang dan terstruktur',
                'discord' => 'Discord: casual, bisa pakai markdown, sesuai vibe channel',
                'mastodon' => 'Mastodon: max 500 karakter, community-friendly, hindari clickbait',
                'youtube' => 'YouTube: title + description, description boleh panjang dengan timestamp',
                default => null,
            };
            if ($hint) $hints[] = "• {$hint}";
        }
        return implode("\n", $hints);
    }

    /**
     * Parse AI response into array of captions.
     */
    private function parseCaptions(string $content, int $expectedCount): array
    {
        // Split by === separator
        $parts = preg_split('/^\s*===+\s*$/m', $content);
        $captions = [];
        foreach ($parts as $p) {
            $clean = trim($p);
            if (!empty($clean)) {
                $captions[] = $clean;
            }
        }

        // If AI didn't use separator, treat whole response as 1 caption
        if (empty($captions)) {
            $clean = trim($content);
            if (!empty($clean)) {
                $captions[] = $clean;
            }
        }

        return array_slice($captions, 0, $expectedCount);
    }
}
