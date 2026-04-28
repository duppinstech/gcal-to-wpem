<?php
namespace GCAL_WPEM;

if (!defined('ABSPATH')) {
    exit;
}

class Importer {
    /**
     * Listing expiry: calendar day after the last day of the event (uses start date if end is empty).
     */
    public static function listing_expiry_for_end_date(string $end_date, string $start_date_fallback = ''): string {
        $end_date = trim($end_date);
        if ($end_date === '') {
            $end_date = trim($start_date_fallback);
        }
        if ($end_date === '') {
            return '';
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $end_date, $m)) {
            $end_date = $m[1];
        }

        try {
            $tz = wp_timezone();
            $dt = new \DateTimeImmutable($end_date . ' 00:00:00', $tz);

            return $dt->modify('+1 day')->format('Y-m-d');
        } catch (\Throwable $e) {
            $ts = strtotime($end_date . ' +1 day');

            return $ts ? gmdate('Y-m-d', $ts) : '';
        }
    }

    /**
     * Fill expiry, organizer, location, zip, country, and venue on existing imports (meta key _gcal_uid).
     *
     * @return array{updated:int}
     */
    public static function backfill_imported_events(): array {
        if (!post_type_exists('event_listing')) {
            return ['updated' => 0];
        }

        $settings = Settings::get_settings();
        $ids      = get_posts([
            'post_type'      => 'event_listing',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_gcal_uid',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $updated = 0;

        foreach ($ids as $post_id) {
            if (self::backfill_single_event((int) $post_id, $settings)) {
                $updated++;
            }
        }

        return ['updated' => $updated];
    }

    /**
     * Manual / non-sync listings: set missing listing expiry (day after end date) and apply default organizer,
     * venue, and location fields like the Google Calendar backfill (any event without both expiry meta values).
     *
     * @return array{updated:int,skipped_no_date:int}
     */
    public static function backfill_listings_missing_expiry_and_defaults(): array {
        if (!post_type_exists('event_listing')) {
            return ['updated' => 0, 'skipped_no_date' => 0];
        }

        $settings = Settings::get_settings();
        $ids      = get_posts([
            'post_type'              => 'event_listing',
            'post_status'            => ['publish', 'pending', 'draft', 'future', 'private', 'expired'],
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $updated         = 0;
        $skipped_no_date = 0;

        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;

            $exp1 = get_post_meta($post_id, '_event_expiry_date', true);
            $exp2 = get_post_meta($post_id, '_event_expires', true);
            if (trim((string) $exp1) !== '' || trim((string) $exp2) !== '') {
                continue;
            }

            $sd = self::ymd_from_wpem_meta_date(get_post_meta($post_id, '_event_start_date', true));
            $ed = self::ymd_from_wpem_meta_date(get_post_meta($post_id, '_event_end_date', true));
            $expiry = self::listing_expiry_for_end_date($ed, $sd);

            if ($expiry === '') {
                $skipped_no_date++;
                continue;
            }

            update_post_meta($post_id, '_event_expiry_date', $expiry);
            update_post_meta($post_id, '_event_expires', $expiry);

            self::backfill_single_event($post_id, $settings);
            $updated++;
        }

        return [
            'updated'         => $updated,
            'skipped_no_date' => $skipped_no_date,
        ];
    }

    /**
     * How many listings still need expiry meta (both _event_expiry_date and _event_expires empty).
     * Used to show the All Events admin prompt only when relevant.
     */
    public static function count_listings_missing_both_expiry_meta(): int {
        global $wpdb;

        if (!post_type_exists('event_listing')) {
            return 0;
        }

        $statuses = ['publish', 'pending', 'draft', 'future', 'private', 'expired'];
        $in       = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is only %s placeholders.
        $sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m1 ON m1.post_id = p.ID AND m1.meta_key = '_event_expiry_date'
            LEFT JOIN {$wpdb->postmeta} m2 ON m2.post_id = p.ID AND m2.meta_key = '_event_expires'
            WHERE p.post_type = 'event_listing'
            AND p.post_status IN ($in)
            AND (m1.meta_id IS NULL OR TRIM(m1.meta_value) = '')
            AND (m2.meta_id IS NULL OR TRIM(m2.meta_value) = '')
        ";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded count query with prepared placeholders.
        $count = $wpdb->get_var($wpdb->prepare($sql, $statuses));

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Fix legacy rows: iCal all-day used exclusive DTEND (end = day after), which looked like a 2-day event
     * and shifted listing expiry. Collapse to inclusive single-day + clear times. Recomputes expiry.
     *
     * @return bool True if any meta was written.
     */
    protected static function maybe_fix_stored_imported_event_dates(int $post_id): bool {
        $start_raw = get_post_meta($post_id, '_event_start_date', true);
        $end_raw   = get_post_meta($post_id, '_event_end_date', true);
        $st_raw    = get_post_meta($post_id, '_event_start_time', true);
        $et_raw    = get_post_meta($post_id, '_event_end_time', true);

        $sd = self::ymd_from_wpem_meta_date($start_raw);
        $ed = self::ymd_from_wpem_meta_date($end_raw);

        $changed = false;

        if ($sd === '') {
            return false;
        }

        $st = self::normalize_time_meta_for_compare($st_raw);
        $et = self::normalize_time_meta_for_compare($et_raw);

        $looks_date_only = ($st === '' && $et === '')
            || ($st === '00:00:00' && ($et === '' || $et === '00:00:00' || $et === '23:59:59'));

        // Legacy all-day single: end meta was exclusive next calendar day.
        if ($looks_date_only && $ed !== '' && self::calendar_day_offset($sd, $ed) === 1) {
            update_post_meta($post_id, '_event_end_date', $sd);
            update_post_meta($post_id, '_event_start_time', '');
            update_post_meta($post_id, '_event_end_time', '');
            $changed = true;
            $ed      = $sd;
        } elseif ($sd === $ed && $looks_date_only && ($st !== '' || $et !== '')) {
            update_post_meta($post_id, '_event_start_time', '');
            update_post_meta($post_id, '_event_end_time', '');
            $changed = true;
        }

        $expiry = self::listing_expiry_for_end_date($ed !== '' ? $ed : '', $sd);
        if ($expiry !== '') {
            update_post_meta($post_id, '_event_expiry_date', $expiry);
            update_post_meta($post_id, '_event_expires', $expiry);
            $changed = true;
        }

        return $changed;
    }

    protected static function ymd_from_wpem_meta_date($raw): string {
        if (!is_string($raw) && !is_numeric($raw)) {
            return '';
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * HH:MM:SS or empty for comparison.
     */
    protected static function normalize_time_meta_for_compare($raw): string {
        if ($raw === '' || $raw === false || $raw === null) {
            return '';
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}:\d{2}:\d{2})/', $s, $m)) {
            $parts = array_map('intval', explode(':', $m[1]));

            return sprintf('%02d:%02d:%02d', $parts[0], $parts[1] ?? 0, $parts[2] ?? 0);
        }

        return $s;
    }

    /**
     * Signed day difference: second Y-m-d minus first (e.g. +1 if end is the next day).
     */
    protected static function calendar_day_offset(string $ymd_a, string $ymd_b): int {
        try {
            $a = new \DateTimeImmutable($ymd_a . ' 00:00:00', wp_timezone());
            $b = new \DateTimeImmutable($ymd_b . ' 00:00:00', wp_timezone());

            $secs = $b->getTimestamp() - $a->getTimestamp();

            return (int) round($secs / DAY_IN_SECONDS);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return bool True if any meta was written.
     */
    protected static function backfill_single_event(int $post_id, array $settings): bool {
        $changed = self::maybe_fix_stored_imported_event_dates($post_id);

        $online = get_post_meta($post_id, '_event_online', true) === 'yes';

        $org_id = absint($settings['default_organizer_id'] ?? 0);
        if ($org_id > 0 && self::is_valid_organizer_id($org_id)) {
            $existing_org = get_post_meta($post_id, '_event_organizer_ids', true);
            if (!self::event_has_organizer_ids_meta($existing_org)) {
                update_post_meta($post_id, '_event_organizer_ids', self::organizer_ids_meta_array($org_id));
                $changed = true;
            }
        }

        $existing_orgs = get_post_meta($post_id, '_event_organizer_ids', true);
        if (
            $existing_orgs !== false
            && $existing_orgs !== null
            && $existing_orgs !== ''
            && !is_array($existing_orgs)
            && self::normalize_organizer_ids_meta_value($existing_orgs) !== []
        ) {
            update_post_meta($post_id, '_event_organizer_ids', self::normalize_organizer_ids_meta_value($existing_orgs));
            $changed = true;
        }

        if ($online) {
            return $changed;
        }

        if (!empty($settings['default_location'])) {
            $raw_loc = get_post_meta($post_id, '_event_location', true);
            $loc     = is_string($raw_loc) ? trim($raw_loc) : '';
            if ($loc === '') {
                update_post_meta($post_id, '_event_location', $settings['default_location']);
                $changed = true;
            }
        }

        if (!empty($settings['default_pincode'])) {
            $pin = get_post_meta($post_id, '_event_pincode', true);
            if ($pin === '' || $pin === false) {
                update_post_meta($post_id, '_event_pincode', $settings['default_pincode']);
                $changed = true;
            }
        }

        if (!empty($settings['default_country'])) {
            $country = get_post_meta($post_id, '_event_country', true);
            if ($country === '' || $country === false) {
                update_post_meta($post_id, '_event_country', $settings['default_country']);
                $changed = true;
            }
        }

        $venue_id = absint($settings['default_venue_id'] ?? 0);
        if ($venue_id > 0 && self::is_valid_venue_id($venue_id)) {
            $existing = get_post_meta($post_id, '_event_venue_ids', true);
            if (!self::event_has_venue_ids_meta($existing)) {
                update_post_meta($post_id, '_event_venue_ids', self::venue_ids_meta_array($venue_id));
                $changed = true;
            }
        }

        $existing_venues = get_post_meta($post_id, '_event_venue_ids', true);
        if (
            $existing_venues !== false
            && $existing_venues !== null
            && $existing_venues !== ''
            && !is_array($existing_venues)
            && self::normalize_venue_ids_meta_value($existing_venues) !== []
        ) {
            update_post_meta($post_id, '_event_venue_ids', self::normalize_venue_ids_meta_value($existing_venues));
            $changed = true;
        }

        return $changed;
    }

    protected static function is_valid_venue_id(int $venue_id): bool {
        if ($venue_id <= 0) {
            return false;
        }

        if (!post_type_exists('event_venue')) {
            return false;
        }

        $p = get_post($venue_id);

        return $p && $p->post_type === 'event_venue';
    }

    protected static function is_valid_organizer_id(int $organizer_id): bool {
        if ($organizer_id <= 0) {
            return false;
        }

        if (!post_type_exists('event_organizer')) {
            return false;
        }

        $p = get_post($organizer_id);

        return $p && $p->post_type === 'event_organizer';
    }

    /**
     * WPEM stores venue/organizer as arrays (Chosen multi-select). Scalars break the admin UI.
     *
     * @param mixed $raw
     * @return array<int,int>
     */
    protected static function normalize_relational_post_ids_meta($raw): array {
        if ($raw === '' || $raw === false || $raw === null) {
            return [];
        }

        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $id) {
                $id = absint($id);
                if ($id > 0) {
                    $out[] = $id;
                }
            }

            return array_values(array_unique($out));
        }

        if (is_numeric($raw)) {
            $id = absint($raw);

            return $id > 0 ? [$id] : [];
        }

        return [];
    }

    protected static function normalize_venue_ids_meta_value($raw): array {
        return self::normalize_relational_post_ids_meta($raw);
    }

    protected static function normalize_organizer_ids_meta_value($raw): array {
        return self::normalize_relational_post_ids_meta($raw);
    }

    protected static function event_has_venue_ids_meta($raw): bool {
        return self::normalize_relational_post_ids_meta($raw) !== [];
    }

    protected static function event_has_organizer_ids_meta($raw): bool {
        return self::normalize_relational_post_ids_meta($raw) !== [];
    }

    /**
     * @param int $venue_id
     * @return array<int,int>
     */
    protected static function venue_ids_meta_array(int $venue_id): array {
        return [absint($venue_id)];
    }

    /**
     * @param int $organizer_id
     * @return array<int,int>
     */
    protected static function organizer_ids_meta_array(int $organizer_id): array {
        return [absint($organizer_id)];
    }

    /**
     * WP Event Manager uses both keys when filtering cancelled listings.
     */
    public static function set_wpem_cancelled_meta(int $post_id, bool $cancelled): void {
        $v = $cancelled ? 1 : 0;
        update_post_meta($post_id, '_cancelled', $v);
        update_post_meta($post_id, '_event_cancelled', $v);
    }

    /**
     * True when iCal STATUS is CANCELLED.
     */
    protected static function event_marked_cancelled_in_ics(array $event): bool {
        if (!empty($event['is_cancelled'])) {
            return true;
        }

        $st = isset($event['status']) ? strtoupper(trim((string) $event['status'])) : '';

        return $st === 'CANCELLED';
    }

    /**
     * First Y-m-d from stored end date meta (may include time).
     */
    protected static function listing_end_date_ymd(int $post_id): string {
        $raw = get_post_meta($post_id, '_event_end_date', true);
        $s   = is_string($raw) || is_numeric($raw) ? (string) $raw : '';

        if ($s !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Only future (and today’s) listings are auto-cancelled when missing from the feed;
     * past events are ignored so they are not flagged when the parser omits past occurrences.
     */
    protected static function listing_is_future_or_today_for_orphan_check(int $post_id): bool {
        $end = self::listing_end_date_ymd($post_id);
        if ($end === '') {
            return false;
        }

        $today = wp_date('Y-m-d');

        return $end >= $today;
    }

    /**
     * Mark imported listings cancelled when no longer present in this sync’s feed.
     *
     * @param array<string,bool> $feed_occurrence_keys
     * @param array<string,bool> $feed_series_uids    Bare iCal UIDs still present in the feed
     */
    protected static function mark_absent_from_feed_cancelled(array $feed_occurrence_keys, array $feed_series_uids): int {
        $ids = get_posts([
            'post_type'      => 'event_listing',
            'post_status'    => ['publish', 'pending', 'draft', 'future', 'expired', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_gcal_uid',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $n = 0;

        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;
            $key     = get_post_meta($post_id, '_gcal_uid', true);

            if (!is_string($key) || $key === '') {
                continue;
            }

            $still_in_feed = isset($feed_occurrence_keys[$key]);

            if (!$still_in_feed && strpos($key, '::') === false && isset($feed_series_uids[$key])) {
                $still_in_feed = true;
            }

            if ($still_in_feed) {
                continue;
            }

            if (!self::listing_is_future_or_today_for_orphan_check($post_id)) {
                continue;
            }

            self::set_wpem_cancelled_meta($post_id, true);
            $n++;

            $title = get_the_title($post_id);
            Plugin::log('Cancelled (removed from calendar feed): ' . ($title !== '' ? $title : '#' . $post_id));
        }

        return $n;
    }

    /**
     * Drop recurring series occurrences when the setting is "one-off only".
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    public static function apply_recurring_import_pref(array $events, array $settings): array {
        if (($settings['recurring_import_mode'] ?? 'all') !== 'one_off_only') {
            return $events;
        }

        return array_values(
            array_filter(
                $events,
                static function (array $e): bool {
                    return empty($e['is_recurring']);
                }
            )
        );
    }

    /**
     * Create or update one parsed event (used by sync and recurring approval).
     *
     * @return int Post ID, or 0 if not created/updated.
     */
    public static function import_parsed_event(array $event, array $settings): int {
        if (!post_type_exists('event_listing')) {
            return 0;
        }

        $approve_publish = !empty($event['_gcal_approve_publish']);
        unset($event['_gcal_approve_publish']);

        $status = $approve_publish
            ? 'publish'
            : (string) ($settings['post_status'] ?? 'publish');

        $post_id = self::find_existing_event(
            (string) ($event['occurrence_key'] ?? ''),
            (string) ($event['uid'] ?? '')
        );

        if ($post_id) {
            self::update_event($post_id, $event);
        } else {
            $post_id = self::create_event($event, $status);
        }

        if ($approve_publish && $post_id > 0) {
            self::publish_listing_after_series_approval((int) $post_id);
        }

        return (int) $post_id;
    }

    /**
     * Meta key for the event banner (`_event_banner`). We store the image URL so core WPEM banner output matches.
     * The same file is also set as the featured image (`_thumbnail_id`) when missing.
     */
    public static function wpem_event_banner_meta_key(): string {
        return (string) apply_filters('gcal_wpem_event_banner_meta_key', '_event_banner');
    }

    /** @deprecated Use wpem_event_banner_meta_key() */
    public static function wpem_banner_attachment_meta_key(): string {
        return self::wpem_event_banner_meta_key();
    }

    /**
     * @param mixed $existing Raw meta value from the database.
     */
    public static function is_wpem_banner_meta_empty($existing): bool {
        if ($existing === '' || $existing === false || $existing === null) {
            return true;
        }
        if (is_string($existing) && trim($existing) === '') {
            return true;
        }
        if (is_array($existing)) {
            return array_filter(array_map('strval', $existing)) === [];
        }

        return false;
    }

    /**
     * Whether `_event_banner` should be (re)written from a chosen attachment.
     * True for empty/placeholder meta, legacy numeric IDs, etc. Independent of post status and featured image.
     *
     * @param mixed $existing Raw `_event_banner` meta.
     */
    public static function should_fill_event_banner_meta($existing): bool {
        if (self::is_wpem_banner_meta_empty($existing)) {
            return true;
        }

        if (is_string($existing)) {
            $t = trim($existing);
            if ($t === '0') {
                return true;
            }
            if ($t !== '' && ctype_digit($t)) {
                return true;
            }
            if (strpos($t, 'http') === 0 && self::is_placeholder_event_banner_url($t)) {
                return true;
            }
        }

        return (bool) apply_filters('gcal_wpem_should_fill_event_banner_meta', false, $existing);
    }

    /**
     * Listing has a real custom banner (not empty, not placeholder, not legacy-only id string unless filtered).
     */
    public static function has_usable_event_banner_meta($existing): bool {
        return !self::should_fill_event_banner_meta($existing);
    }

    /**
     * Default WP Event Manager placeholder / generic banner URLs — treat as missing so we can fill.
     */
    protected static function is_placeholder_event_banner_url(string $url): bool {
        $l = strtolower($url);
        if (strpos($l, 'wpem-placeholder') !== false) {
            return true;
        }
        if (strpos($l, '/wp-event-manager/assets/images/') !== false && strpos($l, 'placeholder') !== false) {
            return true;
        }

        return (bool) apply_filters('gcal_wpem_is_placeholder_event_banner_url', false, $url);
    }

    /**
     * Set listing to publish (live) when not trash; refreshes post cache first. Safe to call repeatedly.
     */
    public static function publish_listing_after_series_approval(int $post_id): void {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return;
        }

        if (function_exists('clean_post_cache')) {
            clean_post_cache($post_id);
        }

        $st = get_post_status($post_id);
        if ($st === false || $st === 'trash') {
            return;
        }
        if ($st === 'publish') {
            return;
        }

        $result = wp_update_post(
            [
                'ID'          => $post_id,
                'post_status' => 'publish',
            ],
            true
        );
        if (is_wp_error($result)) {
            Plugin::log('Publish listing after series approval failed for post ' . $post_id . ': ' . $result->get_error_message());
        }
    }

    /**
     * Apply a media-library image as the event banner and (when missing) the featured image for the same file.
     * `_event_banner` gets the image URL; `_thumbnail_id` is set via set_post_thumbnail() to the attachment id.
     * Runs for any post status (including publish): featured image does not block filling a missing banner.
     *
     * @param bool $publish_when_thumbnail_set If true (series approval), publish as soon as the featured image is set.
     * @return bool True if banner meta and/or featured image was updated.
     */
    public static function apply_banner_attachment_to_listing(int $post_id, int $attachment_id, bool $publish_when_thumbnail_set = false): bool {
        $attachment_id = absint($attachment_id);
        if ($post_id <= 0 || $attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
            return false;
        }

        if (function_exists('clean_post_cache')) {
            clean_post_cache($post_id);
        }

        $key          = self::wpem_event_banner_meta_key();
        $existing     = get_post_meta($post_id, $key, true);
        $updated      = false;
        $wrote_banner = false;

        if (self::should_fill_event_banner_meta($existing)) {
            $url = wp_get_attachment_url($attachment_id);
            if ($url !== false && $url !== '') {
                update_post_meta($post_id, $key, esc_url_raw($url));
                $updated      = true;
                $wrote_banner = true;
            }
        }

        // Featured image (`_thumbnail_id`): same attachment as `_event_banner` when we just set that banner.
        if ($wrote_banner && !has_post_thumbnail($post_id)) {
            set_post_thumbnail($post_id, $attachment_id);
            $updated = true;
            if ($publish_when_thumbnail_set && has_post_thumbnail($post_id)) {
                self::publish_listing_after_series_approval($post_id);
            }
        }

        return $updated;
    }

    /**
     * After approving a recurring series: set banner when missing (from attachment), publish non-published listings.
     * Banner fill uses should_fill_event_banner_meta() and is not skipped for published listings or when a featured image exists.
     */
    public static function apply_recurring_series_approval_extras(int $post_id, int $banner_attachment_id): void {
        if ($post_id <= 0) {
            return;
        }

        $banner_attachment_id = absint($banner_attachment_id);
        if ($banner_attachment_id > 0) {
            self::apply_banner_attachment_to_listing($post_id, $banner_attachment_id, true);
        }

        self::publish_listing_after_series_approval($post_id);
    }

    public static function run(): array|\WP_Error {
        if (!post_type_exists('event_listing')) {
            $err = new \WP_Error('missing_post_type', 'WP Event Manager is not active.');
            Plugin::log('ERROR: ' . $err->get_error_message());
            return $err;
        }

        Plugin::log('Starting sync...');

        $settings = Settings::get_settings();
        $ics = Fetcher::get_ical_data($settings['ical_url']);

        if (is_wp_error($ics)) {
            Plugin::log('ERROR: ' . $ics->get_error_message());
            return $ics;
        }

        $all_events = Parser::parse($ics);

        $feed_occurrence_keys = [];
        $feed_series_uids     = [];

        foreach ($all_events as $ev) {
            if (!empty($ev['occurrence_key'])) {
                $feed_occurrence_keys[$ev['occurrence_key']] = true;
            }
            if (!empty($ev['uid'])) {
                $feed_series_uids[$ev['uid']] = true;
            }
        }

        $mode          = $settings['recurring_import_mode'] ?? 'all';
        $partition     = RecurringQueue::partition($all_events);
        $occ_to_series = RecurringQueue::occurrence_to_series_id_map($partition['series']);
        $blacklist     = RecurringQueue::get_blacklist_id_set();
        $series_active = RecurringQueue::filter_series_blacklisted($partition['series'], $blacklist);
        $feed_events   = RecurringQueue::filter_events_blacklisted($all_events, $occ_to_series, $blacklist);

        $skipped_recurring   = 0;
        $queued_series       = 0;
        $queued_occ          = 0;
        $skipped_blacklisted = count($all_events) - count($feed_events);
        $approved_sync_count = 0;

        if ($mode === 'pending_approval') {
            $approved_set       = RecurringQueue::get_approved_id_set();
            $series_for_queue   = RecurringQueue::filter_series_excluding_approved($series_active, $approved_set);
            RecurringQueue::merge_pending_series($series_for_queue);
            $queued_series = count($series_for_queue);
            foreach ($series_for_queue as $s) {
                $queued_occ += count($s['occurrence_keys'] ?? []);
            }
            $approved_events = RecurringQueue::prepare_feed_events_for_approved_series_sync(
                $feed_events,
                $occ_to_series,
                $approved_set,
                $series_active
            );
            $approved_sync_count = count($approved_events);
            $events              = array_merge($partition['one_off'], $approved_events);
        } elseif ($mode === 'one_off_only') {
            $events            = self::apply_recurring_import_pref($feed_events, $settings);
            $skipped_recurring = count($feed_events) - count($events);
        } else {
            $events = RecurringQueue::attach_series_ids($feed_events, $series_active);
        }

        $stats = [
            'created'                  => 0,
            'updated'                  => 0,
            'skipped'                  => 0,
            'skipped_recurring'        => $skipped_recurring,
            'skipped_blacklisted'      => $skipped_blacklisted,
            'queued_series'            => $queued_series,
            'queued_occurrences'     => $queued_occ,
            'cancelled_ics_status'     => 0,
            'cancelled_missing_feed'   => 0,
            'year_prefix_backfilled'   => 0,
        ];

        if ($mode === 'pending_approval') {
            foreach ($events as $event) {
                $occ_key        = (string) ($event['occurrence_key'] ?? '');
                $uid            = (string) ($event['uid'] ?? '');
                $existed_before = self::find_existing_event($occ_key, $uid) > 0;

                $do_extras = !empty($event['_gcal_approve_publish']);
                $banner_id = 0;
                if ($do_extras) {
                    $banner_id = RecurringQueue::get_effective_banner_id_for_approved_series(
                        (string) ($event['_gcal_series_id'] ?? ''),
                        $settings
                    );
                }

                $post_id = self::import_parsed_event($event, $settings);
                if ($post_id && $do_extras) {
                    self::apply_recurring_series_approval_extras($post_id, $banner_id);
                }

                if ($post_id) {
                    if ($existed_before) {
                        $stats['updated']++;
                        Plugin::log('Updated: ' . $event['title']);
                    } else {
                        $stats['created']++;
                        Plugin::log('Created: ' . $event['title']);
                    }
                }

                if (self::event_marked_cancelled_in_ics($event)) {
                    $stats['cancelled_ics_status']++;
                }
            }
        } else {
            foreach ($events as $event) {
                $post_id = self::find_existing_event($event['occurrence_key'], $event['uid']);

                if ($post_id) {
                    self::update_event($post_id, $event);
                    $stats['updated']++;
                    Plugin::log('Updated: ' . $event['title']);
                } else {
                    self::create_event($event, $settings['post_status']);
                    $stats['created']++;
                    Plugin::log('Created: ' . $event['title']);
                }

                if (self::event_marked_cancelled_in_ics($event)) {
                    $stats['cancelled_ics_status']++;
                }
            }
        }

        $stats['cancelled_missing_feed'] = self::mark_absent_from_feed_cancelled($feed_occurrence_keys, $feed_series_uids);

        $stats['year_prefix_backfilled'] = self::backfill_stored_listings_year_prefix();

        if ($mode === 'pending_approval') {
            Plugin::log(
                sprintf(
                    'Sync complete (pending approval mode). Created: %d, Updated: %d. Approved-series occurrences imported this run: %d. Queued %d series (%d occurrences) for approval. Blacklisted (skipped feed rows): %d. Cancelled in iCal: %d. Cancelled (missing from feed, future/today): %d. Year prefix backfill (stored listings): %d.',
                    $stats['created'],
                    $stats['updated'],
                    $approved_sync_count,
                    $stats['queued_series'],
                    $stats['queued_occurrences'],
                    $stats['skipped_blacklisted'],
                    $stats['cancelled_ics_status'],
                    $stats['cancelled_missing_feed'],
                    $stats['year_prefix_backfilled']
                )
            );
        } else {
            Plugin::log(
                sprintf(
                    'Sync complete. Created: %d, Updated: %d. Skipped recurring: %d. Blacklisted (skipped feed rows): %d. Cancelled in iCal: %d. Cancelled (missing from feed, future/today): %d. Year prefix backfill (stored listings): %d.',
                    $stats['created'],
                    $stats['updated'],
                    $stats['skipped_recurring'],
                    $stats['skipped_blacklisted'],
                    $stats['cancelled_ics_status'],
                    $stats['cancelled_missing_feed'],
                    $stats['year_prefix_backfilled']
                )
            );
        }

        return $stats;
    }

    protected static function find_existing_event(string $occurrence_key, string $legacy_uid = ''): int {
        $posts = get_posts([
            'post_type'   => 'event_listing',
            'post_status' => 'any',
            'meta_key'    => '_gcal_uid',
            'meta_value'  => $occurrence_key,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        if (!empty($posts)) {
            return (int) $posts[0];
        }

        if ($legacy_uid !== '') {
            $posts = get_posts([
                'post_type'   => 'event_listing',
                'post_status' => 'any',
                'meta_key'    => '_gcal_uid',
                'meta_value'  => $legacy_uid,
                'numberposts' => 1,
                'fields'      => 'ids',
            ]);

            return !empty($posts) ? (int) $posts[0] : 0;
        }

        return 0;
    }

    /**
     * Prefix title with start year (settings) so slugs differ year over year.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    protected static function apply_title_year_prefix(array $event, array $settings): array {
        if (empty($settings['prepend_year_to_title'])) {
            return $event;
        }

        // iCal recurring instances and any listing in a detected series (incl. same-title clusters / approved series).
        if (!empty($event['is_recurring']) || !empty($event['_gcal_series_id'])) {
            return $event;
        }

        $ymd = self::ymd_from_wpem_meta_date($event['start_date'] ?? '');
        if ($ymd === '' || strlen($ymd) < 4) {
            return $event;
        }

        $year = substr($ymd, 0, 4);
        if (!ctype_digit($year)) {
            return $event;
        }

        $title = trim((string) ($event['title'] ?? ''));
        if ($title === '') {
            return $event;
        }

        if (preg_match('/^' . preg_quote($year, '/') . '(\s|[-–—])/u', $title)) {
            return $event;
        }

        if (preg_match('/^\d{4}(\s|[-–—])/u', $title)) {
            return $event;
        }

        $event['title']                 = $year . ' ' . $title;
        $event['_gcal_did_prepend_year'] = true;

        return $event;
    }

    /**
     * After a normal sync pass, apply the year-prefix setting to imported listings that were not touched above
     * (e.g. outside the feed window) or imported before the option existed. Only non-published, non-series listings.
     *
     * @return int Number of posts updated.
     */
    protected static function backfill_stored_listings_year_prefix(): int {
        $settings = Settings::get_settings();
        if (empty($settings['prepend_year_to_title'])) {
            return 0;
        }

        if (!post_type_exists('event_listing')) {
            return 0;
        }

        $ids = get_posts([
            'post_type'              => 'event_listing',
            'post_status'            => ['pending', 'draft', 'future', 'private', 'expired'],
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'update_post_meta_cache' => false,
            'meta_query'             => [
                'relation' => 'AND',
                [
                    'key'     => '_gcal_uid',
                    'compare' => 'EXISTS',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_gcal_series_id',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_gcal_series_id',
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],
            ],
        ]);

        $n = 0;

        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;

            $series = get_post_meta($post_id, '_gcal_series_id', true);
            if (is_string($series) && trim($series) !== '') {
                continue;
            }

            $fake = [
                'title'            => get_the_title($post_id),
                'start_date'       => get_post_meta($post_id, '_event_start_date', true),
                'is_recurring'     => false,
                '_gcal_series_id'  => '',
            ];

            $fake = self::apply_title_year_prefix($fake, $settings);

            if (empty($fake['_gcal_did_prepend_year'])) {
                continue;
            }

            $post_status = get_post_status($post_id) ?: 'draft';
            $post_parent = (int) wp_get_post_parent_id($post_id);
            $slug        = sanitize_title($fake['title']);

            wp_update_post([
                'ID'        => $post_id,
                'post_title'=> $fake['title'],
                'post_name' => wp_unique_post_slug($slug, $post_id, $post_status, 'event_listing', $post_parent),
            ]);
            $n++;
        }

        return $n;
    }

    protected static function create_event(array $event, string $status): int {
        $settings = Settings::get_settings();
        $event    = self::apply_title_year_prefix($event, $settings);

        $insert = [
            'post_type'    => 'event_listing',
            'post_status'  => $status,
            'post_title'   => $event['title'],
            'post_content' => $event['description'],
        ];

        if (!empty($settings['prepend_year_to_title']) && !empty($event['_gcal_did_prepend_year'])) {
            $slug                = sanitize_title($event['title']);
            $insert['post_name'] = wp_unique_post_slug($slug, 0, $status, 'event_listing', 0);
        }

        unset($event['_gcal_did_prepend_year']);

        $post_id = wp_insert_post($insert);

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        self::save_meta($post_id, $event);

        return (int) $post_id;
    }

    protected static function update_event(int $post_id, array $event): void {
        $settings = Settings::get_settings();

        // Published listings: keep title and slug stable; still sync description and calendar meta from the feed.
        if (get_post_status($post_id) === 'publish') {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $event['description'],
            ]);
            self::save_meta($post_id, $event);

            return;
        }

        $event = self::apply_title_year_prefix($event, $settings);

        $args = [
            'ID'           => $post_id,
            'post_title'   => $event['title'],
            'post_content' => $event['description'],
        ];

        if (!empty($settings['prepend_year_to_title']) && !empty($event['_gcal_did_prepend_year'])) {
            $slug              = sanitize_title($event['title']);
            $post_status       = get_post_status($post_id) ?: 'publish';
            $post_parent       = (int) wp_get_post_parent_id($post_id);
            $args['post_name'] = wp_unique_post_slug($slug, $post_id, $post_status, 'event_listing', $post_parent);
        }

        unset($event['_gcal_did_prepend_year']);

        wp_update_post($args);

        self::save_meta($post_id, $event);
    }

    protected static function save_meta(int $post_id, array $event): void {
        $settings = Settings::get_settings();

        update_post_meta($post_id, '_gcal_uid', $event['occurrence_key']);

        if (array_key_exists('_gcal_series_id', $event)) {
            $sid = trim((string) $event['_gcal_series_id']);
            if ($sid !== '') {
                update_post_meta($post_id, '_gcal_series_id', sanitize_text_field($sid));
            } else {
                delete_post_meta($post_id, '_gcal_series_id');
            }
        }

        update_post_meta($post_id, '_event_start_date', $event['start_date']);
        update_post_meta($post_id, '_event_end_date', $event['end_date']);
        update_post_meta($post_id, '_event_start_time', $event['start_time']);
        update_post_meta($post_id, '_event_end_time', $event['end_time']);

        $online = !empty($event['is_online']);
        update_post_meta($post_id, '_event_online', $online ? 'yes' : 'no');

        $expiry = self::listing_expiry_for_end_date(
            (string) ($event['end_date'] ?? ''),
            (string) ($event['start_date'] ?? '')
        );
        if ($expiry !== '') {
            update_post_meta($post_id, '_event_expiry_date', $expiry);
            update_post_meta($post_id, '_event_expires', $expiry);
        }

        $organizer_id = absint($settings['default_organizer_id'] ?? 0);
        if ($organizer_id > 0 && self::is_valid_organizer_id($organizer_id)) {
            $existing_org = get_post_meta($post_id, '_event_organizer_ids', true);
            if (!self::event_has_organizer_ids_meta($existing_org)) {
                update_post_meta($post_id, '_event_organizer_ids', self::organizer_ids_meta_array($organizer_id));
            }
        }

        $oraw = get_post_meta($post_id, '_event_organizer_ids', true);
        if (
            $oraw !== false
            && $oraw !== null
            && $oraw !== ''
            && !is_array($oraw)
            && self::normalize_organizer_ids_meta_value($oraw) !== []
        ) {
            update_post_meta($post_id, '_event_organizer_ids', self::normalize_organizer_ids_meta_value($oraw));
        }

        self::set_wpem_cancelled_meta($post_id, self::event_marked_cancelled_in_ics($event));

        if ($online) {
            delete_post_meta($post_id, '_event_location');
            delete_post_meta($post_id, '_event_pincode');
            delete_post_meta($post_id, '_event_country');
            delete_post_meta($post_id, '_event_venue_ids');

            return;
        }

        $location = trim((string) ($event['location'] ?? ''));
        if ($location === '' && !empty($settings['default_location'])) {
            $location = $settings['default_location'];
        }
        update_post_meta($post_id, '_event_location', $location);

        if (!empty($settings['default_pincode'])) {
            $existing_pin = get_post_meta($post_id, '_event_pincode', true);
            if ($existing_pin === '' || $existing_pin === false) {
                update_post_meta($post_id, '_event_pincode', $settings['default_pincode']);
            }
        }

        if (!empty($settings['default_country'])) {
            $existing_country = get_post_meta($post_id, '_event_country', true);
            if ($existing_country === '' || $existing_country === false) {
                update_post_meta($post_id, '_event_country', $settings['default_country']);
            }
        }

        $venue_id = absint($settings['default_venue_id'] ?? 0);
        if ($venue_id > 0 && self::is_valid_venue_id($venue_id)) {
            $existing_venue = get_post_meta($post_id, '_event_venue_ids', true);
            if (!self::event_has_venue_ids_meta($existing_venue)) {
                update_post_meta($post_id, '_event_venue_ids', self::venue_ids_meta_array($venue_id));
            }
        }

        $vraw = get_post_meta($post_id, '_event_venue_ids', true);
        if (
            $vraw !== false
            && $vraw !== null
            && $vraw !== ''
            && !is_array($vraw)
            && self::normalize_venue_ids_meta_value($vraw) !== []
        ) {
            update_post_meta($post_id, '_event_venue_ids', self::normalize_venue_ids_meta_value($vraw));
        }
    }
}