<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Jobs\SendLineMessage;
use App\Models\Booking;
use App\Models\Member;
use App\Models\MemberPackage;
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
     * Redemption receipt after staff deducts a member's entitlement (counter
     * redeem OR booking check-in). Reports the item, how many units were taken,
     * and the member's remaining balance for that item.
     *
     * @param  string  $itemName   Human label of the redeemed item.
     * @param  int     $qtyTaken   Units deducted for the DIRECTLY-requested item.
     * @param  int     $remaining  The member's remaining balance for that item.
     */
    public function redemptionReceipt(Member $member, string $itemName, int $qtyTaken, int $remaining): void
    {
        $this->dispatch(
            $member,
            "🧾 ใช้บริการ {$itemName} x{$qtyTaken} • คงเหลือ {$remaining} ครั้ง\n"
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
     * Near-expiry reminder for an owned lot (members:remind-expiry, within 7 days
     * of `expires_at`). Fired once per lot, guarded by
     * `member_packages.expiry_reminded_at` at the command level.
     *
     * @param  MemberPackage  $lot        The lot whose expiry is approaching.
     * @param  int            $remaining  Total redeemable units left in the lot.
     */
    public function nearExpiry(MemberPackage $lot, int $remaining): void
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
            "⚠️ แพ็กเกจของคุณใกล้หมดอายุ\n"
            . "หมดอายุ: {$date}\n"
            . "คงเหลือ: {$remaining} ครั้ง\n"
            . 'รีบใช้สิทธิ์ก่อนหมดอายุนะคะ',
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
