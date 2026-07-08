# remixpost

Self-hosted social media management platform. Schedule posts across Twitter/X, Facebook, LinkedIn, and Instagram from a single dashboard. Open source, no license keys, no monthly fees.

Built with **Laravel 11 + Inertia.js + Vue 3 + Tailwind CSS + MySQL 8 + Redis**.

## Features

### MVP (this release)
- ✅ User authentication (register, login, logout)
- ✅ Dashboard with stats (total/scheduled/published posts, connected accounts)
- ✅ Post composer (text content, multi-account selection, schedule datetime)
- ✅ Post scheduling with queue worker (Laravel Queue + Redis)
- ✅ Calendar view (monthly grid showing all scheduled/published posts)
- ✅ Social accounts management UI (connect/disconnect)
- ✅ Docker compose deployment (one-command self-hosted)
- ✅ CI/CD pipeline (auto-build Docker image to GHCR on push)

### Coming soon (iterative)
- 🚧 OAuth integration for Twitter/X, Facebook, LinkedIn, Instagram
- 🚧 Actual post publishing via provider APIs
- 🚧 AI caption generation (OpenAI-compatible endpoint support)
- 🚧 Analytics (engagement metrics per post/account)
- 🚧 Team management (multi-user, roles, workspaces)
- 🚧 Image upload (currently URL-only)
- 🚧 Draft auto-save

## Quick start

### Option 1: Docker (recommended)

```bash
git clone https://github.com/kelvinzer0/remixpost.git
cd remixpost

cp .env.example .env
# Edit .env: set DB_PASSWORD, APP_KEY, social API credentials
nano .env

# Generate APP_KEY locally (or let entrypoint do it)
# docker run --rm -v $(pwd):/app -w /app php:8.3-fpm-alpine php artisan key:generate

docker compose up -d --build

# Run migrations (also auto-run on container start)
docker compose exec app php artisan migrate

# Open http://localhost:8080
```

### Option 2: Pre-built image from GHCR

```bash
mkdir remixpost && cd remixpost
curl -O https://raw.githubusercontent.com/kelvinzer0/remixpost/main/docker-compose.yml
curl -O https://raw.githubusercontent.com/kelvinzer0/remixpost/main/.env.example
cp .env.example .env
# Edit .env
nano .env

# Edit docker-compose.yml: change `build: .` to `image: ghcr.io/kelvinzer0/remixpost:latest`
docker compose up -d
```

### Option 3: Local development

Prerequisites: PHP 8.3+, Composer, Node.js 20+, MySQL 8, Redis.

```bash
git clone https://github.com/kelvinzer0/remixpost.git
cd remixpost

composer install
npm install

cp .env.example .env
php artisan key:generate

# Edit .env: set DB_* to your local MySQL, REDIS_* to your Redis

php artisan migrate
npm run dev  # in one terminal
php artisan serve  # in another
php artisan queue:listen  # in a third (for scheduled post publishing)

# Open http://localhost:8000
```

## Configuration

### Social media provider credentials

To enable actual post publishing, register API apps at each provider and fill in `.env`:

| Provider | Get credentials at | Env vars |
|---|---|---|
| Twitter/X | https://developer.twitter.com | `TWITTER_CLIENT_ID`, `TWITTER_CLIENT_SECRET` |
| Facebook | https://developers.facebook.com | `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET` |
| Instagram | (via Facebook Graph API) | Same as Facebook |
| LinkedIn | https://developer.linkedin.com | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` |

> **Note:** OAuth flow implementation is in progress. The UI for connecting accounts exists, but actual OAuth callbacks are stubbed. See [Roadmap](#coming-soon-iterative).

### AI integration (optional)

For AI caption generation (when implemented), set any OpenAI-compatible endpoint:

```env
OPENAI_API_KEY=your-key
OPENAI_API_BASE_URL=https://your-endpoint/v1  # e.g. router9, OpenAI, Azure
OPENAI_MODEL=your-model
```

## Architecture

```
┌─────────────────────────────────────────────────────┐
│  VPS Anda                                           │
│                                                     │
│  ┌────────────────┐                                 │
│  │  Nginx :8080   │  (reverse proxy + static)       │
│  └───────┬────────┘                                 │
│          │                                          │
│  ┌───────▼────────┐   ┌──────────┐   ┌──────────┐  │
│  │  PHP-FPM       │──▶│  MySQL 8 │   │  Redis   │  │
│  │  (Laravel app) │   │  :3306   │   │  :6379   │  │
│  └───────┬────────┘   └──────────┘   └──────────┘  │
│          │                                          │
│  ┌───────▼────────┐                                 │
│  │  Queue Worker  │  (publishes scheduled posts)    │
│  │  (Laravel)     │                                 │
│  └────────────────┘                                 │
└─────────────────────────────────────────────────────┘
        ↑
        │ HTTPS (via Caddy/nginx on host)
        │
┌──────────────────┐
│  Browser Anda    │
│  (Vue 3 SPA)     │
└──────────────────┘
```

## Tech stack

| Component | Technology |
|---|---|
| Backend | Laravel 11 (PHP 8.3) |
| Frontend | Vue 3 + Inertia.js + Tailwind CSS |
| Database | MySQL 8 |
| Cache/Queue | Redis 7 |
| Web server | Nginx (in container) |
| Process manager | Supervisor (nginx + php-fpm + queue worker) |
| Build tool | Vite 5 |
| Container | Docker (multi-stage build, alpine-based) |
| CI/CD | GitHub Actions → GHCR |

## Development

```bash
# Run tests
php artisan test

# Code style
./vendor/bin/pint

# Build assets for production
npm run build

# Create migration
php artisan make:migration create_xxx_table

# Tinker
php artisan tinker
```

## License

**Apache License 2.0** — see [LICENSE](LICENSE) for full text.

You are free to:
- ✅ Use this software commercially
- ✅ Modify and distribute
- ✅ Fork and rebrand
- ✅ Host privately or publicly
- ✅ Sell hosted instances

Just keep the [NOTICE](NOTICE) file attribution. See [LICENSE](LICENSE) for details.

## Acknowledgements

This project is an independent implementation inspired by open source social media management tools. We respect and learn from projects like [Mixpost](https://github.com/inovector/mixpost) and [Postiz](https://github.com/gitroomhq/postiz-app). remixpost is written from scratch with its own codebase, architecture, and design.

## Contributing

Pull requests welcome! Please:
1. Fork the repo
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push (`git push origin feature/amazing-feature`)
5. Open a PR

## Support

- Open an issue: https://github.com/kelvinzer0/remixpost/issues
- Discussions: https://github.com/kelvinzer0/remixpost/discussions
