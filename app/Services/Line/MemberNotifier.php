<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Jobs\SendLineMessage;
use App\Models\Booking;
use App\Models\CreditLot;
use App\Models\Member;
use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * MemberNotifier — the SINGLE place that turns a domain event into a friendly
 * Thai LINE push. It owns the message COPY (templates) and the DISPATCH; callers
 * (login / booking / redemption / the reminder commands) just name the event and
 * hand over the model. Nothing here talks to LINE directly — it queues a
 * {@see SendLineMessage} so the web-request path never waits on an HTTP call.
 *
 * BEST-EFFORT, always: every method is a NO-OP when the member has no
 * `line_user_id` (never linked LINE, so there's nobody to push to). Even when a
 * job IS queued, delivery is best-effort inside {@see LineMessagingService} — a
 * non-friend / blocked / outage never surfaces here. A push must NEVER break the
 * action that triggered it, so callers invoke these AFTER the action has
 * committed and don't inspect any result.
 *
 * Shop name comes from the singleton {@see Setting::current()} (falls back to the
 * configured app name). Dates are rendered in Thai for the customer's inbox:
 * converted to Asia/Bangkok wall-clock and formatted with the `th` locale
 * (independent of the app's default `en` locale / UTC storage).
 */
final class MemberNotifier
{
    /**
     * Display timezone for customer-facing datetimes. Datetimes are stored/handled
     * in UTC (config/app.php); a member reads times in local Thai wall-clock.
     */
    private const DISPLAY_TIMEZONE = 'Asia/Bangkok';

    /**
     * Welcome a brand-new LINE-linked walk-in member (createNew path). Fired once,
     * right after the fresh account is minted + logged in.
     */
    public function welcome(Member $member): void
    {
        $shop = $this->shopName();

        $this->dispatch(
            $member,
            "🎉 ยินดีต้อนรับสู่ {$shop}!\n"
            . 'บัญชีสมาชิกของคุณพร้อมใช้งานแล้ว สามารถจองคิวและดูสิทธิ์แพ็กเกจได้เลยค่ะ',
        );
    }

    /**
     * Confirm that an EXISTING counter member just linked their LINE account
     * (submitCode path). Reassures the customer the link succeeded.
     */
    public function linked(Member $member): void
    {
        $shop = $this->shopName();

        $this->dispatch(
            $member,
            "✅ เชื่อมบัญชี LINE กับสมาชิก {$shop} เรียบร้อยแล้ว\n"
            . 'ตั้งแต่นี้คุณจะได้รับการแจ้งเตือนการจองและสิทธิ์คงเหลือผ่าน LINE ค่ะ',
        );
    }

    /**
     * Booking confirmation receipt. Fired after BookingService::create() returns
     * (member self-booking OR staff-on-behalf). No-op if the member isn't linked.
     */
    public function bookingConfirmed(Booking $booking): void
    {
        $member = $booking->member;

        if ($member === null) {
            return;
        }

        $branch = $booking->branch?->name ?? '-';
        $when = $this->formatDateTime($booking->scheduled_start);

        $this->dispatch(
            $member,
            "📅 จองคิวสำเร็จ\n"
            . "บริการ: {$booking->item_name}\n"
            . "สาขา: {$branch}\n"
            . "วันเวลา: {$when}",
        );
    }

    /**
     * Top-up confirmation after staff sells credit to a member (WalletService::topUp).
     * Reports the member's new spendable wallet balance. Fired AFTER the top-up
     * committed; no-op if the member isn't LINE-linked.
     *
     * @param  string  $newBalance  The member's spendable balance after the top-up
     *                              (decimal-2 STRING, §5.6).
     */
    public function topupReceipt(Member $member, string $newBalance): void
    {
        $this->dispatch(
            $member,
            "💳 เติมเครดิตสำเร็จ\n"
            . "เครดิตคงเหลือ: {$this->baht($newBalance)} บาท\n"
            . 'ขอบคุณที่ใช้บริการค่ะ 🙏',
        );
    }

    /**
     * Service-charge receipt after a wallet DEBIT (counter manual charge OR booking
     * check-in). Reports the service, the amount charged, and the member's remaining
     * wallet balance. Fired AFTER the debit committed; no-op if not LINE-linked.
     *
     * @param  string  $itemName   Human label of the charged service.
     * @param  string  $amount     Baht debited for this service (decimal-2 STRING).
     * @param  string  $remaining  The member's remaining spendable balance (decimal-2 STRING).
     */
    public function serviceChargeReceipt(Member $member, string $itemName, string $amount, string $remaining): void
    {
        $this->dispatch(
            $member,
            "🧾 ใช้บริการ {$itemName} • หัก {$this->baht($amount)} บาท\n"
            . "เครดิตคงเหลือ: {$this->baht($remaining)} บาท\n"
            . 'ขอบคุณที่ใช้บริการค่ะ 🙏',
        );
    }

    /**
     * Upcoming-booking reminder (bookings:remind, within 24h of the slot). Fired
     * once per booking, guarded by `bookings.reminded_at` at the command level.
     */
    public function bookingReminder(Booking $booking): void
    {
        $member = $booking->member;

        if ($member === null) {
            return;
        }

        $branch = $booking->branch?->name ?? '-';
        $when = $this->formatDateTime($booking->scheduled_start);

        $this->dispatch(
            $member,
            "⏰ เตือนนัดหมาย\n"
            . "บริการ: {$booking->item_name}\n"
            . "สาขา: {$branch}\n"
            . "วันเวลา: {$when}\n"
            . 'แล้วพบกันนะคะ 😊',
        );
    }

    /**
     * Near-expiry reminder for an owned credit lot (members:remind-expiry, within 7
     * days of `expires_at`). Fired once per lot, guarded by
     * `credit_lots.expiry_reminded_at` at the command level.
     *
     * NOTE: expiry stays OFF (every lot ships with `expires_at = null`), so this is
     * never fired in practice — kept wired so enabling expiry needs no code change.
     *
     * @param  CreditLot  $lot        The lot whose expiry is approaching.
     * @param  string     $remaining  Spendable baht left in the lot (decimal-2 STRING).
     */
    public function nearExpiry(CreditLot $lot, string $remaining): void
    {
        $member = $lot->member;

        if ($member === null) {
            return;
        }

        $date = $lot->expires_at !== null
            ? $this->formatDate($lot->expires_at)
            : '-';

        $this->dispatch(
            $member,
            "⚠️ เครดิตของคุณใกล้หมดอายุ\n"
            . "หมดอายุ: {$date}\n"
            . "คงเหลือ: {$this->baht($remaining)} บาท\n"
            . 'รีบใช้เครดิตก่อนหมดอายุนะคะ',
        );
    }

    /**
     * Queue a push to the member IFF they have a linked LINE id. The web-request
     * path never blocks: this only enqueues a {@see SendLineMessage}. A member
     * without `line_user_id` is a silent no-op (nobody to push to).
     */
    private function dispatch(Member $member, string $text): void
    {
        $lineUserId = $member->line_user_id;

        if ($lineUserId === null || $lineUserId === '') {
            return;
        }

        SendLineMessage::dispatch($lineUserId, $text);
    }

    /**
     * Format a decimal-2 money STRING for the customer's inbox with thousands
     * separators and 2 dp (e.g. "10000.00" → "10,000.00"). Parses via bcmath scale
     * 2 first so a stray "300" renders "300.00"; the money value itself is never
     * treated as a native float for arithmetic (§5.6) — this is display-only.
     */
    private function baht(string $amount): string
    {
        return number_format((float) bcadd($amount, '0', 2), 2);
    }

    /**
     * The shop name for message copy: the owner-editable singleton setting,
     * falling back to the configured app name when unset.
     */
    private function shopName(): string
    {
        $name = Setting::current()->shop_name;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return (string) config('app.name', 'ร้านของเรา');
    }

    /**
     * Render a datetime for the customer's inbox: converted to Thai wall-clock and
     * formatted in the `th` locale (e.g. "จ. 7 ก.ค. 2569 14:00"). Kept independent
     * of the app's default `en` locale / UTC storage so the copy always reads Thai.
     */
    private function formatDateTime(CarbonInterface $when): string
    {
        return $when
            ->copy()
            ->setTimezone(self::DISPLAY_TIMEZONE)
            ->locale('th')
            ->translatedFormat('D j M Y H:i') . ' น.';
    }

    /**
     * Render a date-only value (no time) for the customer's inbox in Thai.
     */
    private function formatDate(CarbonInterface $when): string
    {
        return $when
            ->copy()
            ->setTimezone(self::DISPLAY_TIMEZONE)
            ->locale('th')
            ->translatedFormat('j M Y');
    }
}
