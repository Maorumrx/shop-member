<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * app:assert-production-safe — a DEPLOY GUARDRAIL that FAILS LOUDLY when the app
 * is about to serve production traffic while misconfigured. Born from a confirmed
 * incident: production (bansuan-thaimassage.com) ran with APP_ENV=local +
 * APP_DEBUG=true, which exposed a passwordless dev backdoor and leaked secrets via
 * the Ignition error page.
 *
 * The runbook (docs/deploy-plesk.md §7) runs this RIGHT AFTER `php artisan
 * config:cache` — so it checks the *cached, resolved* config the app will
 * actually serve, not the raw .env — and ABORTS the deploy on a non-zero exit.
 *
 * A deploy is only "safe" when BOTH hold, read from the CACHED config:
 *   - config('app.env') === 'production'
 *   - config('app.debug') is falsy (APP_DEBUG=false)
 *
 * We deliberately assert on `config('app.env')`, NOT `app()->environment()`: the
 * latter re-reads the raw `APP_ENV` env var at bootstrap, which `config:cache`
 * does NOT freeze the way it freezes `config('app.*')`. Since this runs AFTER
 * `config:cache`, `config('app.env')` is the frozen value the app will serve — so
 * the check stays correct even if `APP_ENV` is unset in the deploy shell but
 * baked into the cached config.
 *
 * Any other combination returns Command::FAILURE with a red, self-explaining
 * message so a misconfigured deploy stops LOUDLY instead of silently going live.
 */
class AssertProductionSafe extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:assert-production-safe';

    /**
     * @var string
     */
    protected $description = 'Deploy guardrail: fail (non-zero) unless APP_ENV=production and APP_DEBUG=false.';

    /**
     * Assert the CACHED, resolved config is production-safe. Reads the frozen
     * `config('app.env')` / `config('app.debug')` (see class docblock for why not
     * `app()->environment()`). Returns Command::FAILURE (a non-zero exit) so the
     * deploy script can `&&`-chain and abort; returns Command::SUCCESS only when
     * env is production AND debug is off.
     */
    public function handle(): int
    {
        $environment = (string) config('app.env');
        $debug = (bool) config('app.debug');

        $isProduction = $environment === 'production';

        if (! $isProduction || $debug) {
            $this->error('DEPLOY BLOCKED — the app is NOT production-safe.');
            $this->newLine();
            $this->error("  APP_ENV   = {$environment} (must be: production)");
            $this->error('  APP_DEBUG = '.($debug ? 'true' : 'false').' (must be: false)');
            $this->newLine();
            $this->error('  Fix .env, re-run `php artisan config:cache`, then deploy again.');

            return self::FAILURE;
        }

        $this->info('Production-safe: APP_ENV=production and APP_DEBUG=false. OK to deploy.');

        return self::SUCCESS;
    }
}
