<?php
namespace GCAL_WPEM;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-Cron schedule for automatic Google Calendar → listings sync.
 */
class Cron {
    public const HOOK = 'gcal_wpem_sync_events';

    public const CRON_DAILY    = 'gcal_wpem_sync_daily';
    public const CRON_INTERVAL = 'gcal_wpem_sync_interval';

    /** Bump when default schedule behavior changes so existing crons are rebuilt. */
    public const CONFIG_VERSION = 2;

    /** @var ?int Used while scheduling so cron_schedules matches unsaved settings. */
    private static $override_interval_hours = null;

    /**
     * @return array<int,int>
     */
    public static function allowed_interval_hours(): array {
        return [1, 2, 3, 4, 6, 8, 12, 24];
    }

    public static function init(): void {
        add_action(self::HOOK, [Importer::class, 'run']);
        add_filter('cron_schedules', [self::class, 'register_schedules']);
        register_activation_hook(GCAL_WPEM_PATH . 'gcal-to-wpem.php', [self::class, 'activate']);
        register_deactivation_hook(GCAL_WPEM_PATH . 'gcal-to-wpem.php', [self::class, 'deactivate']);
        add_action('plugins_loaded', [self::class, 'maybe_migrate_schedule'], 25);
    }

    /**
     * One-time: move sites from legacy hourly sync to saved schedule (default daily).
     */
    public static function maybe_migrate_schedule(): void {
        $v = (int) get_option('gcal_wpem_cron_config_version', 0);
        if ($v >= self::CONFIG_VERSION) {
            return;
        }
        self::apply_schedule_for_settings();
        update_option('gcal_wpem_cron_config_version', self::CONFIG_VERSION, true);
    }

    /**
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public static function register_schedules(array $schedules): array {
        $schedules[self::CRON_DAILY] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __('Once daily (Google Calendar sync)', 'gcal-to-wpem'),
        ];

        $hours = self::$override_interval_hours !== null
            ? self::$override_interval_hours
            : self::normalize_interval_hours(Settings::get_settings()['sync_interval_hours'] ?? 6);

        $schedules[self::CRON_INTERVAL] = [
            'interval' => $hours * HOUR_IN_SECONDS,
            'display'  => $hours === 1
                ? __('Every hour (Google Calendar sync)', 'gcal-to-wpem')
                : sprintf(
                    /* translators: %d: number of hours between runs */
                    __('Every %d hours (Google Calendar sync)', 'gcal-to-wpem'),
                    $hours
                ),
        ];

        return $schedules;
    }

    public static function normalize_interval_hours($value): int {
        $h = absint($value);
        if (!in_array($h, self::allowed_interval_hours(), true)) {
            return 6;
        }

        return $h;
    }

    /**
     * (Re)schedule the sync hook from current DB settings.
     */
    public static function apply_schedule_for_settings(): void {
        self::apply_schedule_with_settings(Settings::get_settings());
    }

    /**
     * @param array<string,mixed> $settings Merged settings (e.g. during sanitize, before DB write).
     */
    public static function apply_schedule_with_settings(array $settings): void {
        wp_clear_scheduled_hook(self::HOOK);

        $mode = isset($settings['sync_schedule']) ? (string) $settings['sync_schedule'] : 'daily';
        if ($mode === 'interval') {
            self::$override_interval_hours = self::normalize_interval_hours($settings['sync_interval_hours'] ?? 6);
            wp_schedule_event(time() + 90, self::CRON_INTERVAL, self::HOOK);
            self::$override_interval_hours = null;

            return;
        }

        $hour = isset($settings['sync_hour_local']) ? absint($settings['sync_hour_local']) : 3;
        $hour = min(23, max(0, $hour));
        wp_schedule_event(self::next_run_at_local_hour($hour), self::CRON_DAILY, self::HOOK);
    }

    public static function next_run_at_local_hour(int $hourLocal): int {
        if (!function_exists('wp_timezone')) {
            return time() + DAY_IN_SECONDS;
        }

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $at  = $now->setTime($hourLocal, 0, 0);
        if ($at <= $now) {
            $at = $at->modify('+1 day');
        }

        return $at->getTimestamp();
    }

    public static function get_next_run_human(): string {
        $ts = wp_next_scheduled(self::HOOK);
        if (!$ts) {
            return '—';
        }

        return wp_date(
            sprintf('%s %s', get_option('date_format', 'Y-m-d'), get_option('time_format', 'H:i')),
            $ts
        );
    }

    public static function activate(): void {
        self::apply_schedule_for_settings();
        update_option('gcal_wpem_cron_config_version', self::CONFIG_VERSION, true);
        PendingDigest::ensure_scheduled();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::HOOK);
        PendingDigest::unschedule();
    }
}
