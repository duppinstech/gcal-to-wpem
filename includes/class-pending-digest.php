<?php
namespace GCAL_WPEM;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily email (4 PM site timezone) when recurring series or event listings await approval.
 */
class PendingDigest {
    public const HOOK = 'gcal_wpem_pending_approval_digest';
    public const CRON_INTERVAL = 'gcal_wpem_daily_digest';

    /** Hour in site-local time (24h). */
    public const DIGEST_HOUR_LOCAL = 16;

    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'register_cron_schedule']);
        add_action(self::HOOK, [self::class, 'run']);
    }

    /**
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public static function register_cron_schedule(array $schedules): array {
        $schedules[ self::CRON_INTERVAL ] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __('Once daily (GCal to WPEM digest)', 'gcal-to-wpem'),
        ];

        return $schedules;
    }

    /**
     * Next Unix timestamp for DIGEST_HOUR_LOCAL in the site timezone.
     */
    public static function next_run_timestamp_local(): int {
        if (!function_exists('wp_timezone')) {
            return time() + DAY_IN_SECONDS;
        }

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $at  = $now->setTime(self::DIGEST_HOUR_LOCAL, 0, 0);
        if ($at <= $now) {
            $at = $at->modify('+1 day');
        }

        return $at->getTimestamp();
    }

    public static function ensure_scheduled(): void {
        if (wp_next_scheduled(self::HOOK)) {
            return;
        }
        wp_schedule_event(self::next_run_timestamp_local(), self::CRON_INTERVAL, self::HOOK);
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::HOOK);
        }
    }

    public static function run(): void {
        $data = self::collect();
        if (!self::has_items($data)) {
            return;
        }

        $recipients = (array) apply_filters('gcal_wpem_pending_digest_recipients', [get_option('admin_email')]);
        $recipients = array_values(array_filter(array_map('sanitize_email', $recipients)));

        if ($recipients === []) {
            Plugin::log('Pending digest: no valid recipient email; skipping send.');

            return;
        }

        $subject = sprintf(
            /* translators: %s: site title */
            __('[%s] Events awaiting your approval', 'gcal-to-wpem'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );

        $body_html = self::build_email_html($data);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $ok = wp_mail($recipients, $subject, $body_html, $headers);

        if (!$ok) {
            Plugin::log('Pending digest: wp_mail failed; sending plain text fallback.');
            wp_mail($recipients, $subject, self::build_email_plain($data), ['Content-Type: text/plain; charset=UTF-8']);
        }

        Plugin::log('Pending approval digest email sent to: ' . implode(', ', $recipients));
    }

    /**
     * @return array{pending_series: array<int,array<string,mixed>>, pending_listings: array<int,\WP_Post>}
     */
    public static function collect(): array {
        $series = RecurringQueue::get_pending_list_sorted();

        $listings = [];
        if (post_type_exists('event_listing')) {
            $q = get_posts([
                'post_type'      => 'event_listing',
                'post_status'    => 'pending',
                'posts_per_page' => 100,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
            $listings = is_array($q) ? $q : [];
        }

        return [
            'pending_series'   => $series,
            'pending_listings' => $listings,
        ];
    }

    /**
     * @param array{pending_series: array<int,array<string,mixed>>, pending_listings: array<int,\WP_Post>} $data
     */
    public static function has_items(array $data): bool {
        return $data['pending_series'] !== [] || $data['pending_listings'] !== [];
    }

    /**
     * @param array{pending_series: array<int,array<string,mixed>>, pending_listings: array<int,\WP_Post>} $data
     */
    protected static function build_email_plain(array $data): string {
        $lines   = [];
        $lines[] = __('Hello,', 'gcal-to-wpem');
        $lines[] = '';
        $lines[] = __('There are items on your site that may need your attention (pending approval).', 'gcal-to-wpem');
        $lines[] = '';

        if ($data['pending_series'] !== []) {
            $lines[] = __('Recurring event series (awaiting approval)', 'gcal-to-wpem');
            foreach ($data['pending_series'] as $row) {
                $title = (string) ($row['title'] ?? '');
                $cnt   = isset($row['occurrence_keys']) && is_array($row['occurrence_keys'])
                    ? count($row['occurrence_keys'])
                    : (int) ($row['count'] ?? 0);
                $lines[] = ' - ' . ($title !== '' ? $title : __('(untitled series)', 'gcal-to-wpem'))
                    . ' (' . sprintf(
                        /* translators: %d: number of occurrences */
                        _n('%d occurrence', '%d occurrences', $cnt, 'gcal-to-wpem'),
                        $cnt
                    ) . ')';
            }
            $lines[] = '';
        }

        if ($data['pending_listings'] !== []) {
            $lines[] = __('Event listings (Pending status)', 'gcal-to-wpem');
            foreach ($data['pending_listings'] as $post) {
                $lines[] = ' - ' . get_the_title($post);
            }
            $lines[] = '';
        }

        $settings_url = Settings::admin_page_url(['gcal_tab' => 'recurring']);
        $lines[]      = __('Links:', 'gcal-to-wpem');
        $lines[]      = __('Log in and open plugin settings (Recurring tab):', 'gcal-to-wpem');
        $lines[]      = wp_login_url($settings_url);
        $lines[]      = '';
        $lines[]      = __('Pending event listings (admin):', 'gcal-to-wpem');
        $lines[]      = admin_url('edit.php?post_type=event_listing&post_status=pending');

        return implode("\n", $lines);
    }

    /**
     * @param array{pending_series: array<int,array<string,mixed>>, pending_listings: array<int,\WP_Post>} $data
     */
    protected static function build_email_html(array $data): string {
        $tz_name = function_exists('wp_timezone_string') ? wp_timezone_string() : '';
        if ($tz_name === '') {
            $tz_name = 'UTC';
        }

        $settings_url = Settings::admin_page_url(['gcal_tab' => 'recurring']);
        $login_url    = wp_login_url($settings_url);
        $pending_url  = admin_url('edit.php?post_type=event_listing&post_status=pending');

        ob_start();
        ?>
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.5; color: #1d2327;">
            <p><?php esc_html_e('Hello,', 'gcal-to-wpem'); ?></p>
            <p><?php esc_html_e('There are new or pending items that may need your approval.', 'gcal-to-wpem'); ?></p>

            <?php if ($data['pending_series'] !== []) : ?>
                <h2 style="font-size: 16px; margin: 24px 0 8px;"><?php esc_html_e('Recurring event series (awaiting approval)', 'gcal-to-wpem'); ?></h2>
                <ul style="margin: 0 0 16px; padding-left: 20px;">
                    <?php foreach ($data['pending_series'] as $row) : ?>
                        <?php
                        $title = (string) ($row['title'] ?? '');
                        $cnt   = isset($row['occurrence_keys']) && is_array($row['occurrence_keys'])
                            ? count($row['occurrence_keys'])
                            : (int) ($row['count'] ?? 0);
                        ?>
                        <li>
                            <strong><?php echo esc_html($title !== '' ? $title : __('(untitled series)', 'gcal-to-wpem')); ?></strong>
                            — <?php echo esc_html(sprintf(_n('%d occurrence', '%d occurrences', $cnt, 'gcal-to-wpem'), $cnt)); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($data['pending_listings'] !== []) : ?>
                <h2 style="font-size: 16px; margin: 24px 0 8px;"><?php esc_html_e('Event listings (Pending status)', 'gcal-to-wpem'); ?></h2>
                <ul style="margin: 0 0 16px; padding-left: 20px;">
                    <?php foreach ($data['pending_listings'] as $post) : ?>
                        <li><?php echo esc_html(get_the_title($post)); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p style="margin-top: 24px;">
                <a href="<?php echo esc_url($login_url); ?>" style="color: #2271b1;"><?php esc_html_e('Log in to WordPress (then opens plugin settings)', 'gcal-to-wpem'); ?></a>
            </p>
            <p style="margin: 8px 0;">
                <a href="<?php echo esc_url($pending_url); ?>" style="color: #2271b1;"><?php esc_html_e('View pending event listings in the admin', 'gcal-to-wpem'); ?></a>
            </p>
            <p style="font-size: 13px; color: #646970; margin-top: 24px;">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: time like 4:00 PM, 2: timezone name */
                        __('This is your daily digest (scheduled for %1$s site time, %2$s).', 'gcal-to-wpem'),
                        '4:00 PM',
                        $tz_name
                    )
                );
                ?>
            </p>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
