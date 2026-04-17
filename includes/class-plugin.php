<?php
namespace GCAL_WPEM;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin {
    public static function init(): void {
        Settings::init();
        PendingDigest::init();
        PendingDigest::ensure_scheduled();
        Cron::init();

        add_action('admin_post_gcal_wpem_run_sync', [self::class, 'manual_sync']);
        add_action('admin_post_gcal_wpem_test_sync', [self::class, 'test_sync']);
        add_action('admin_post_gcal_wpem_backfill', [self::class, 'backfill_defaults']);
        add_action('admin_post_gcal_wpem_backfill_manual_listings', [self::class, 'backfill_manual_listings']);
        add_action('admin_post_gcal_wpem_series_action', [self::class, 'series_action']);
        add_action('admin_post_gcal_wpem_save_series_media', [self::class, 'save_series_media']);
        add_action('admin_post_gcal_wpem_imported_series_banner', [self::class, 'save_imported_series_banner']);
    }

    public static function backfill_defaults(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('gcal_wpem_backfill');

        $stats = Importer::backfill_imported_events();

        self::log(sprintf('Backfill: updated %d imported event listing(s).', $stats['updated']));

        wp_safe_redirect(
            Settings::admin_page_url(
                [
                    'backfilled' => '1',
                    'n'          => $stats['updated'],
                ]
            )
        );
        exit;
    }

    public static function backfill_manual_listings(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // GET or POST (GET form avoids nesting inside #posts-filter on the Events list).
        $nonce = '';
        if (isset($_REQUEST['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash((string) $_REQUEST['_wpnonce']));
        }
        if ($nonce === '' || !wp_verify_nonce($nonce, 'gcal_wpem_backfill_manual_listings')) {
            wp_die(
                esc_html__('Security check failed. Reload the Events page and try again.', 'gcal-to-wpem'),
                esc_html__('Error', 'gcal-to-wpem'),
                ['response' => 403]
            );
        }

        $stats = Importer::backfill_listings_missing_expiry_and_defaults();

        self::log(
            sprintf(
                'Manual listings backfill: filled expiry/defaults on %d listing(s); skipped %d with no start/end date.',
                $stats['updated'],
                $stats['skipped_no_date']
            )
        );

        wp_safe_redirect(
            add_query_arg(
                [
                    'post_type'                => 'event_listing',
                    'gcal_wpem_manual_listing_fill' => '1',
                    'u'                        => $stats['updated'],
                    's'                        => $stats['skipped_no_date'],
                ],
                admin_url('edit.php')
            )
        );
        exit;
    }

    public static function manual_sync(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('gcal_wpem_manual_sync');

        $result = Importer::run();

        $redirect = Settings::admin_page_url([
            'synced' => is_array($result) ? 1 : 0,
        ]);

        wp_safe_redirect($redirect);
        exit;
    }

    public static function test_sync(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
    
        check_admin_referer('gcal_wpem_test_sync');
    
        $settings = Settings::get_settings();
        $ics = Fetcher::get_ical_data($settings['ical_url']);
    
        if (is_wp_error($ics)) {
            self::log('ERROR: ' . $ics->get_error_message());
            wp_safe_redirect(Settings::admin_page_url(['test' => 'fail']));
            exit;
        }
    
        $all_events = Parser::parse($ics, [
            'range_end' => strtotime('+3 months'),
        ]);

        $mode          = $settings['recurring_import_mode'] ?? 'all';
        $partition     = RecurringQueue::partition($all_events);
        $occ_to_series = RecurringQueue::occurrence_to_series_id_map($partition['series']);
        $blacklist     = RecurringQueue::get_blacklist_id_set();
        $series_active = RecurringQueue::filter_series_blacklisted($partition['series'], $blacklist);
        $feed_events   = RecurringQueue::filter_events_blacklisted($all_events, $occ_to_series, $blacklist);
        $skipped_bl    = count($all_events) - count($feed_events);

        if ($mode === 'pending_approval') {
            $approved_set     = RecurringQueue::get_approved_id_set();
            $series_for_queue = RecurringQueue::filter_series_excluding_approved($series_active, $approved_set);
            $approved_events  = RecurringQueue::prepare_feed_events_for_approved_series_sync(
                $feed_events,
                $occ_to_series,
                $approved_set,
                $series_active
            );
            $events           = array_merge($partition['one_off'], $approved_events);
            $queue_occ        = 0;
            foreach ($series_for_queue as $s) {
                $queue_occ += count($s['occurrence_keys'] ?? []);
            }
            self::log(
                sprintf(
                    'TEST RUN (pending approval): %d event(s) would import now (%d one-off + %d from approved series); %d occurrence(s) in %d non-approved series would queue (nothing saved). Blacklist skipped %d feed row(s).',
                    count($events),
                    count($partition['one_off']),
                    count($approved_events),
                    $queue_occ,
                    count($series_for_queue),
                    $skipped_bl
                )
            );
        } elseif ($mode === 'all') {
            $events  = RecurringQueue::attach_series_ids($feed_events, $series_active);
            $skipped = 0;
            self::log(
                sprintf(
                    'TEST RUN: %d event(s) would import. Blacklist skipped %d feed row(s).',
                    count($events),
                    $skipped_bl
                )
            );
        } else {
            $events  = Importer::apply_recurring_import_pref($feed_events, $settings);
            $skipped = count($feed_events) - count($events);
            self::log(
                sprintf(
                    'TEST RUN: %d event(s) would import (%d recurring skipped by settings). Blacklist skipped %d feed row(s).',
                    count($events),
                    $skipped,
                    $skipped_bl
                )
            );
        }

        // Store preview temporarily
        set_transient('gcal_wpem_last_test', $events, 300);
    
        wp_safe_redirect(Settings::admin_page_url(['test' => 'success']));
        exit;
    }

    public static function series_action(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('gcal_wpem_series_action');

        $base = Settings::admin_page_url(['gcal_tab' => 'recurring']);
        $id    = isset($_POST['series_id']) ? sanitize_text_field(wp_unslash((string) $_POST['series_id'])) : '';
        $act   = isset($_POST['series_action']) ? sanitize_text_field(wp_unslash((string) $_POST['series_action'])) : '';

        if ($act === 'approve') {
            $ban    = isset($_POST['series_banner_id']) ? absint($_POST['series_banner_id']) : 0;
            $result = RecurringQueue::approve_series($id, $ban);
            if ($result === null) {
                wp_safe_redirect(add_query_arg('series', 'approve_fail', $base));
                exit;
            }
            wp_safe_redirect(
                add_query_arg(
                    [
                        'series' => 'approved',
                        'i'      => (int) $result['imported'],
                        'm'      => (int) $result['missing'],
                    ],
                    $base
                )
            );
            exit;
        }

        if ($act === 'reject') {
            $ok = RecurringQueue::reject_series($id);
            wp_safe_redirect(add_query_arg('series', $ok ? 'rejected' : 'reject_fail', $base));
            exit;
        }

        if ($act === 'trash_series') {
            $n = RecurringQueue::trash_imported_series($id);
            wp_safe_redirect(add_query_arg(['series' => 'trashed', 'n' => $n], $base));
            exit;
        }

        if ($act === 'allow_series') {
            $ok = RecurringQueue::remove_from_blacklist($id);
            wp_safe_redirect(add_query_arg('series', $ok ? 'allowed' : 'allow_fail', $base));
            exit;
        }

        wp_safe_redirect($base);
        exit;
    }

    public static function save_imported_series_banner(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('gcal_wpem_imported_series_banner');

        $base = Settings::admin_page_url(['gcal_tab' => 'recurring']);
        $id   = isset($_POST['series_id']) ? sanitize_text_field(wp_unslash((string) $_POST['series_id'])) : '';
        $att  = isset($_POST['imported_series_banner_id']) ? absint($_POST['imported_series_banner_id']) : 0;

        if (!RecurringQueue::is_valid_series_id($id)) {
            wp_safe_redirect(add_query_arg('series', 'imported_banner_fail', $base));
            exit;
        }

        RecurringQueue::save_imported_series_banner_attachment($id, $att);
        $n = RecurringQueue::apply_imported_series_banner_to_listings($id, $att);

        self::log(sprintf('Imported series banner: series %s, attachment %d, updated %d listing(s).', $id, $att, $n));

        wp_safe_redirect(
            add_query_arg(
                [
                    'series' => 'imported_banner_ok',
                    'n'      => $n,
                ],
                $base
            )
        );
        exit;
    }

    public static function save_series_media(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('gcal_wpem_save_series_media');

        $base = Settings::admin_page_url(['gcal_tab' => 'recurring']);
        $id   = isset($_POST['series_id']) ? sanitize_text_field(wp_unslash((string) $_POST['series_id'])) : '';
        $ban  = isset($_POST['series_banner_id']) ? absint($_POST['series_banner_id']) : 0;

        $ok = RecurringQueue::save_pending_series_banner($id, $ban);
        wp_safe_redirect(add_query_arg('series', $ok ? 'media_saved' : 'media_save_fail', $base));
        exit;
    }

    public static function log(string $message): void {
        $logs = get_option('gcal_wpem_logs', []);
    
        $logs[] = [
            'time' => current_time('mysql'),
            'message' => $message,
        ];
    
        // Limit size (avoid DB bloat)
        if (count($logs) > 200) {
            $logs = array_slice($logs, -200);
        }
    
        update_option('gcal_wpem_logs', $logs, false);
    }
}
