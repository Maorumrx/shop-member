<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Line\LineMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued best-effort LINE push. Keeps the actual HTTP call OFF the web-request
 * path: a login / booking / redemption dispatches this and returns immediately;
 * the push is delivered later by the queue worker (drained by the scheduled
 * `queue:work --stop-when-empty`, since Plesk runs no queue daemon).
 *
 * The heavy lifting — and the fail-safe behaviour — lives in
 * {@see LineMessagingService::pushText()}, which NEVER throws and returns false
 * for an unconfigured token / non-friend / LINE outage. This job therefore
 * rarely fails; `$tries`/`backoff` only guard the transient case where the whole
 * dispatch machinery hiccups. A push is intentionally NON-CRITICAL: if it is
 * ultimately dropped, no member-facing action is affected.
 *
 * Message copy is built by {@see \App\Services\Line\MemberNotifier} before
 * dispatch; this job carries only the resolved recipient + text, so no model is
 * serialised and a later member edit can't change an already-queued message.
 */
final class SendLineMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Retry a couple of times — a transient hiccup shouldn't drop the push, but
     * a push is non-critical so we don't retry endlessly.
     */
    public int $tries = 3;

    /**
     * Back off between attempts (seconds): 10s, then 30s. Keeps a rate-limited
     * or briefly-unavailable LINE endpoint from being hammered.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30];

    /**
     * @param  string  $lineUserId  The member's stable LINE user id (`sub`).
     * @param  string  $text        The already-built message body.
     */
    public function __construct(
        private readonly string $lineUserId,
        private readonly string $text,
    ) {
    }

    /**
     * Deliver the push. A blank recipient (member never linked LINE) is a no-op
     * — nothing to send. The service handles the unconfigured-token / non-friend
     * / outage cases internally and returns false without throwing, so a failed
     * push simply ends the job cleanly.
     */
    public function handle(LineMessagingService $messaging): void
    {
        if ($this->lineUserId === '') {
            return;
        }

        $messaging->pushText($this->lineUserId, $this->text);
    }
}
