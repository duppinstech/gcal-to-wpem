<?php
namespace GCAL_WPEM;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects recurring / duplicate-title series, stores a pending approval queue,
 * and imports or removes series in bulk.
 */
class RecurringQueue {
    public const OPTION_PENDING          = 'gcal_wpem_pending_recurring';
    public const OPTION_BLACKLIST        = 'gcal_wpem_recurring_blacklist';
    public const OPTION_IMPORTED_BANNERS = 'gcal_wpem_imported_series_banners';
    /** Approved recurring series (persist after first approval; future sync imports new occurrences). */
    public const OPTION_APPROVED         = 'gcal_wpem_approved_recurring_series';

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function get_approved_by_id(): array {
        $raw = get_option(self::OPTION_APPROVED, []);
        if (!isset($raw['series']) || !is_array($raw['series'])) {
            return [];
        }

        return $raw['series'];
    }

    /**
     * @return array<string,bool>
     */
    public static function get_approved_id_set(): array {
        $set = [];
        foreach (array_keys(self::get_approved_by_id()) as $id) {
            $set[(string) $id] = true;
        }

        return $set;
    }

    public static function is_series_approved(string $id): bool {
        $byId = self::get_approved_by_id();

        return isset($byId[ $id ]);
    }

    /**
     * Banner attachment saved at approval time, else settings default.
     */
    public static function get_effective_banner_id_for_approved_series(string $id, array $settings): int {
        $byId = self::get_approved_by_id();
        $from = isset($byId[ $id ]) ? absint($byId[ $id ]['banner_id'] ?? 0) : 0;
        if ($from > 0) {
            return $from;
        }

        return absint($settings['recurring_approve_banner_id'] ?? 0);
    }

    public static function mark_series_approved(string $id, int $banner_id): void {
        if (!self::is_valid_series_id($id)) {
            return;
        }

        $raw = get_option(self::OPTION_APPROVED, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        if (!isset($raw['series']) || !is_array($raw['series'])) {
            $raw['series'] = [];
        }

        $raw['series'][ $id ] = [
            'banner_id'   => absint($banner_id),
            'approved_at' => current_time('mysql'),
        ];

        update_option(self::OPTION_APPROVED, $raw, false);
    }

    public static function remove_series_approved(string $id): void {
        $raw = get_option(self::OPTION_APPROVED, []);
        if (!is_array($raw) || !isset($raw['series'][ $id ])) {
            return;
        }

        unset($raw['series'][ $id ]);
        update_option(self::OPTION_APPROVED, $raw, false);
    }

    /**
     * Pending queue should not accumulate keys for series already approved once.
     *
     * @param array<int,array<string,mixed>> $series
     * @param array<string,bool>              $approved_id_set
     * @return array<int,array<string,mixed>>
     */
    public static function filter_series_excluding_approved(array $series, array $approved_id_set): array {
        return array_values(
            array_filter(
                $series,
                static function (array $s) use ($approved_id_set): bool {
                    $id = (string) ($s['id'] ?? '');

                    return $id === '' || !isset($approved_id_set[ $id ]);
                }
            )
        );
    }

    /**
     * Feed rows for occurrences that belong to an approved series: publish + banner on sync.
     *
     * @param array<int,array<string,mixed>>  $feed_events
     * @param array<string,string>            $occurrence_to_series
     * @param array<string,bool>              $approved_id_set
     * @param array<int,array<string,mixed>> $series_for_attach
     * @return array<int,array<string,mixed>>
     */
    public static function prepare_feed_events_for_approved_series_sync(
        array $feed_events,
        array $occurrence_to_series,
        array $approved_id_set,
        array $series_for_attach
    ): array {
        $filtered = [];
        foreach ($feed_events as $e) {
            $k = (string) ($e['occurrence_key'] ?? '');
            if ($k === '') {
                continue;
            }
            $sid = $occurrence_to_series[ $k ] ?? '';
            if ($sid !== '' && isset($approved_id_set[ $sid ])) {
                $filtered[] = $e;
            }
        }

        $filtered = self::attach_series_ids($filtered, $series_for_attach);
        foreach ($filtered as &$ev) {
            $ev['_gcal_approve_publish'] = true;
        }
        unset($ev);

        return $filtered;
    }

    /**
     * Map occurrence_key -> series id for all series in a partition result.
     *
     * @param array<int,array<string,mixed>> $series
     * @return array<string,string>
     */
    public static function occurrence_to_series_id_map(array $series): array {
        $map = [];
        foreach ($series as $s) {
            $sid = (string) ($s['id'] ?? '');
            if ($sid === '') {
                continue;
            }
            foreach ((array) ($s['occurrence_keys'] ?? []) as $ok) {
                $ok = (string) $ok;
                if ($ok !== '') {
                    $map[$ok] = $sid;
                }
            }
        }

        return $map;
    }

    /**
     * Drop feed rows that belong to a blacklisted series (reject / remove-all).
     *
     * @param array<int,array<string,mixed>> $events
     * @param array<string,string>            $occurrence_to_series
     * @param array<string,bool>              $blacklist_set
     * @return array<int,array<string,mixed>>
     */
    public static function filter_events_blacklisted(array $events, array $occurrence_to_series, array $blacklist_set): array {
        $out = [];
        foreach ($events as $e) {
            $k   = (string) ($e['occurrence_key'] ?? '');
            $sid = ($k !== '' && isset($occurrence_to_series[$k])) ? $occurrence_to_series[$k] : '';
            if ($sid !== '' && isset($blacklist_set[$sid])) {
                continue;
            }
            $out[] = $e;
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $series
     * @param array<string,bool>              $blacklist_set
     * @return array<int,array<string,mixed>>
     */
    public static function filter_series_blacklisted(array $series, array $blacklist_set): array {
        return array_values(
            array_filter(
                $series,
                static function (array $s) use ($blacklist_set): bool {
                    $id = (string) ($s['id'] ?? '');

                    return $id === '' || !isset($blacklist_set[$id]);
                }
            )
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function get_blacklist_by_id(): array {
        $raw = get_option(self::OPTION_BLACKLIST, []);
        if (!isset($raw['ids']) || !is_array($raw['ids'])) {
            return [];
        }

        return $raw['ids'];
    }

    /**
     * @return array<string,bool>
     */
    public static function get_blacklist_id_set(): array {
        $set = [];
        foreach (array_keys(self::get_blacklist_by_id()) as $id) {
            $set[(string) $id] = true;
        }

        return $set;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_blacklist_list_sorted(): array {
        $byId = self::get_blacklist_by_id();
        $list = array_values($byId);
        usort(
            $list,
            static function (array $a, array $b): int {
                return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
            }
        );

        return $list;
    }

    public static function add_to_blacklist(string $id, string $title = ''): void {
        if (!self::is_valid_series_id($id)) {
            return;
        }

        $ids         = self::get_blacklist_by_id();
        $ids[$id]    = [
            'id'          => $id,
            'title'       => $title,
            'rejected_at' => current_time('mysql'),
        ];
        update_option(self::OPTION_BLACKLIST, ['ids' => $ids], false);
        self::remove_series_approved($id);
        self::purge_blacklisted_from_pending();
    }

    public static function remove_from_blacklist(string $id): bool {
        if (!self::is_valid_series_id($id)) {
            return false;
        }
        $ids = self::get_blacklist_by_id();
        if (!isset($ids[$id])) {
            return false;
        }
        unset($ids[$id]);
        update_option(self::OPTION_BLACKLIST, ['ids' => $ids], false);

        return true;
    }

    /**
     * Drop pending rows that match the blacklist (e.g. after a reject stored the id).
     */
    public static function purge_blacklisted_from_pending(): void {
        $bl      = self::get_blacklist_id_set();
        $pending = self::get_pending_by_id();
        $changed = false;
        foreach ($pending as $id => $row) {
            if (isset($bl[$id])) {
                unset($pending[$id]);
                $changed = true;
            }
        }
        if ($changed) {
            update_option(self::OPTION_PENDING, ['series' => $pending], false);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array{series: array<int,array<string,mixed>>, one_off: array<int,array<string,mixed>>}
     */
    public static function partition(array $events): array {
        $occurrenceInSeries = [];

        $byUid = [];
        foreach ($events as $e) {
            $uid = isset($e['uid']) ? trim((string) $e['uid']) : '';
            if ($uid === '') {
                continue;
            }
            if (!isset($byUid[$uid])) {
                $byUid[$uid] = [];
            }
            $byUid[$uid][] = $e;
        }

        $seriesByKey = [];

        foreach ($byUid as $uid => $bucket) {
            $multi  = count($bucket) >= 2;
            $anyRec = false;
            foreach ($bucket as $ev) {
                if (!empty($ev['is_recurring'])) {
                    $anyRec = true;
                    break;
                }
            }
            if (!$multi && !$anyRec) {
                continue;
            }

            $key = 'ical:' . $uid;
            $oks = [];
            foreach ($bucket as $ev) {
                $ok = (string) ($ev['occurrence_key'] ?? '');
                if ($ok !== '') {
                    $oks[] = $ok;
                }
            }
            $oks = array_values(array_unique($oks));
            if ($oks === []) {
                continue;
            }

            $seriesByKey[$key] = [
                'id'              => $key,
                'title'           => (string) ($bucket[0]['title'] ?? ''),
                'detection'       => 'ical',
                'occurrence_keys' => $oks,
            ];
            foreach ($oks as $ok) {
                $occurrenceInSeries[$ok] = $key;
            }
        }

        $byTitle = [];
        foreach ($events as $e) {
            $ok = (string) ($e['occurrence_key'] ?? '');
            if ($ok === '' || isset($occurrenceInSeries[$ok])) {
                continue;
            }
            $t = self::normalize_event_title((string) ($e['title'] ?? ''));
            if (!isset($byTitle[$t])) {
                $byTitle[$t] = [];
            }
            $byTitle[$t][] = $e;
        }

        foreach ($byTitle as $t => $bucket) {
            if (count($bucket) < 2) {
                continue;
            }
            $oks = [];
            foreach ($bucket as $ev) {
                $ok = (string) ($ev['occurrence_key'] ?? '');
                if ($ok !== '') {
                    $oks[] = $ok;
                }
            }
            $oks = array_values(array_unique($oks));
            if (count($oks) < 2) {
                continue;
            }

            $key = 'title:' . md5($t);
            $seriesByKey[$key] = [
                'id'              => $key,
                'title'           => (string) ($bucket[0]['title'] ?? ''),
                'detection'       => 'title_cluster',
                'occurrence_keys' => $oks,
            ];
            foreach ($oks as $ok) {
                $occurrenceInSeries[$ok] = $key;
            }
        }

        $oneOff = [];
        foreach ($events as $e) {
            $ok = (string) ($e['occurrence_key'] ?? '');
            if ($ok !== '' && isset($occurrenceInSeries[$ok])) {
                continue;
            }
            $oneOff[] = $e;
        }

        return [
            'series'  => array_values($seriesByKey),
            'one_off' => $oneOff,
        ];
    }

    public static function normalize_event_title(string $title): string {
        $t = strtolower(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($title))));

        return $t === '' ? '_untitled_' : $t;
    }

    /**
     * @param array<int,array<string,mixed>> $series
     */
    public static function merge_pending_series(array $series): void {
        self::purge_blacklisted_from_pending();

        if ($series === []) {
            return;
        }

        $raw  = get_option(self::OPTION_PENDING, []);
        $byId = (isset($raw['series']) && is_array($raw['series'])) ? $raw['series'] : [];

        foreach ($series as $s) {
            $id = (string) ($s['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $newKeys = isset($s['occurrence_keys']) && is_array($s['occurrence_keys'])
                ? array_values(array_filter(array_map('strval', $s['occurrence_keys'])))
                : [];

            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'id'              => $id,
                    'title'           => (string) ($s['title'] ?? ''),
                    'detection'       => (string) ($s['detection'] ?? ''),
                    'occurrence_keys' => [],
                    'updated_at'      => current_time('mysql'),
                ];
            }

            $merged                          = array_values(array_unique(array_merge($byId[$id]['occurrence_keys'] ?? [], $newKeys)));
            $byId[$id]['occurrence_keys']    = $merged;
            $byId[$id]['count']              = count($merged);
            $byId[$id]['updated_at']         = current_time('mysql');
            $byId[$id]['detection']          = (string) ($s['detection'] ?? $byId[$id]['detection']);
            $byId[$id]['title']              = (string) ($s['title'] ?? $byId[$id]['title']);
        }

        update_option(self::OPTION_PENDING, ['series' => $byId], false);
    }

    /**
     * Store banner attachment ID for a pending series row (saved before Approve).
     */
    public static function save_pending_series_banner(string $id, int $banner_id): bool {
        if (!self::is_valid_series_id($id)) {
            return false;
        }

        $byId = self::get_pending_by_id();
        if (!isset($byId[$id])) {
            return false;
        }

        $byId[$id]['banner_id'] = absint($banner_id);

        update_option(self::OPTION_PENDING, ['series' => $byId], false);

        return true;
    }

    /**
     * @return array<string,int> series_id => attachment_id (0 if none stored)
     */
    public static function get_imported_series_banner_map(): array {
        $raw = get_option(self::OPTION_IMPORTED_BANNERS, []);
        if (!is_array($raw) || !isset($raw['attachments']) || !is_array($raw['attachments'])) {
            return [];
        }

        $out = [];
        foreach ($raw['attachments'] as $sid => $aid) {
            $out[(string) $sid] = absint($aid);
        }

        return $out;
    }

    public static function save_imported_series_banner_attachment(string $id, int $attachment_id): void {
        if (!self::is_valid_series_id($id)) {
            return;
        }

        $raw = get_option(self::OPTION_IMPORTED_BANNERS, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        if (!isset($raw['attachments']) || !is_array($raw['attachments'])) {
            $raw['attachments'] = [];
        }

        if ($attachment_id <= 0) {
            unset($raw['attachments'][ $id ]);
        } else {
            $raw['attachments'][ $id ] = absint($attachment_id);
        }

        update_option(self::OPTION_IMPORTED_BANNERS, $raw, false);
    }

    /**
     * @return int Number of listings updated.
     */
    public static function apply_imported_series_banner_to_listings(string $id, int $attachment_id): int {
        if (!self::is_valid_series_id($id) || !post_type_exists('event_listing')) {
            return 0;
        }

        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
            return 0;
        }

        $ids = get_posts([
            'post_type'      => 'event_listing',
            'post_status'    => ['publish', 'pending', 'draft', 'future', 'expired', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_gcal_series_id',
            'meta_value'     => $id,
        ]);

        $n = 0;
        foreach ($ids as $post_id) {
            if (Importer::apply_banner_attachment_to_listing((int) $post_id, $attachment_id)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * True if at least one listing in the series has a usable WP Event Manager banner (not empty/placeholder).
     */
    public static function imported_series_shows_banner_badge(string $series_id): bool {
        if (!post_type_exists('event_listing')) {
            return false;
        }

        $posts = get_posts([
            'post_type'      => 'event_listing',
            'post_status'    => ['publish', 'pending', 'draft', 'future', 'expired', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_gcal_series_id',
            'meta_value'     => $series_id,
        ]);

        if ($posts === []) {
            return false;
        }

        $key = Importer::wpem_event_banner_meta_key();
        $v   = get_post_meta((int) $posts[0], $key, true);

        return Importer::has_usable_event_banner_meta($v);
    }

    /**
     * Default attachment for the media picker on imported series rows.
     */
    public static function get_imported_series_banner_attachment_id(string $series_id): int {
        $map = self::get_imported_series_banner_map();
        if (isset($map[ $series_id ]) && $map[ $series_id ] > 0) {
            return (int) $map[ $series_id ];
        }

        if (!post_type_exists('event_listing')) {
            return 0;
        }

        $posts = get_posts([
            'post_type'      => 'event_listing',
            'post_status'    => ['publish', 'pending', 'draft', 'future', 'expired', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_gcal_series_id',
            'meta_value'     => $series_id,
        ]);

        if ($posts === []) {
            return 0;
        }

        $key = Importer::wpem_event_banner_meta_key();
        $v   = get_post_meta((int) $posts[0], $key, true);
        if (Importer::should_fill_event_banner_meta($v)) {
            return 0;
        }

        if (is_string($v) && strpos($v, 'http') === 0) {
            $aid = attachment_url_to_postid($v);

            return $aid > 0 ? $aid : 0;
        }

        return 0;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function get_pending_by_id(): array {
        $raw = get_option(self::OPTION_PENDING, []);
        if (!isset($raw['series']) || !is_array($raw['series'])) {
            return [];
        }

        return $raw['series'];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_pending_list_sorted(): array {
        $byId = self::get_pending_by_id();
        $list = array_values($byId);
        usort(
            $list,
            static function (array $a, array $b): int {
                return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
            }
        );

        return $list;
    }

    public static function is_valid_series_id(string $id): bool {
        if (strlen($id) > 500) {
            return false;
        }

        return (bool) preg_match('/^(ical:.+|title:[a-f0-9]{32})$/', $id);
    }

    public static function reject_series(string $id): bool {
        if (!self::is_valid_series_id($id)) {
            return false;
        }
        $byId = self::get_pending_by_id();
        if (!isset($byId[$id])) {
            return false;
        }
        $title = (string) ($byId[$id]['title'] ?? '');
        unset($byId[$id]);
        update_option(self::OPTION_PENDING, ['series' => $byId], false);
        self::add_to_blacklist($id, $title);
        Plugin::log(sprintf('Rejected / blacklisted recurring series "%s" (%s). It will not be queued or imported until you allow it again.', $title !== '' ? $title : $id, $id));

        return true;
    }

    /**
     * Import all occurrences for a pending series that still exist in the current feed.
     *
     * @param int $banner_id Override from approve form (0 = use row + settings default).
     * @return array{imported:int, missing:int}|null Null if series not pending or invalid.
     */
    public static function approve_series(string $id, int $banner_id = 0): ?array {
        if (!self::is_valid_series_id($id)) {
            return null;
        }

        $byId = self::get_pending_by_id();
        if (!isset($byId[$id])) {
            return null;
        }

        if (!post_type_exists('event_listing')) {
            return null;
        }

        $settings = Settings::get_settings();
        $ics      = Fetcher::get_ical_data($settings['ical_url']);
        if (is_wp_error($ics)) {
            Plugin::log('ERROR (approve series): ' . $ics->get_error_message());

            return null;
        }

        $allEvents = Parser::parse($ics);
        $byKey     = [];
        foreach ($allEvents as $ev) {
            $k = (string) ($ev['occurrence_key'] ?? '');
            if ($k !== '') {
                $byKey[$k] = $ev;
            }
        }

        $pendingRow = $byId[$id];
        $wantKeys   = isset($pendingRow['occurrence_keys']) && is_array($pendingRow['occurrence_keys'])
            ? $pendingRow['occurrence_keys']
            : [];

        $titleForLog = (string) ($pendingRow['title'] ?? '');

        $bannerEff = $banner_id > 0
            ? $banner_id
            : absint($pendingRow['banner_id'] ?? 0);
        if ($bannerEff <= 0) {
            $bannerEff = absint($settings['recurring_approve_banner_id'] ?? 0);
        }

        $imported = 0;
        $missing  = 0;

        foreach ($wantKeys as $want) {
            $want = (string) $want;
            if (!isset($byKey[$want])) {
                $missing++;
                continue;
            }
            $ev                         = $byKey[$want];
            $ev['_gcal_series_id']      = $id;
            $ev['_gcal_approve_publish'] = true;
            $postId                     = Importer::import_parsed_event($ev, $settings);
            if ($postId > 0) {
                Importer::apply_recurring_series_approval_extras($postId, $bannerEff);
            }
            $imported++;
        }

        unset($byId[$id]);
        update_option(self::OPTION_PENDING, ['series' => $byId], false);

        self::remove_from_blacklist($id);

        self::mark_series_approved($id, $bannerEff);

        Plugin::log(
            sprintf(
                'Approved recurring series "%s" (%s): imported %d occurrence(s), %d no longer in feed.',
                $titleForLog !== '' ? $titleForLog : $id,
                $id,
                $imported,
                $missing
            )
        );

        return ['imported' => $imported, 'missing' => $missing];
    }

    /**
     * @return int Number of posts moved to trash.
     */
    public static function trash_imported_series(string $id): int {
        if (!self::is_valid_series_id($id)) {
            return 0;
        }

        if (!post_type_exists('event_listing')) {
            return 0;
        }

        $ids = get_posts([
            'post_type'      => 'event_listing',
            'post_status'    => ['publish', 'pending', 'draft', 'future', 'expired', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_gcal_series_id',
            'meta_value'     => $id,
        ]);

        $title = '';
        if (!empty($ids)) {
            $title = (string) get_the_title((int) $ids[0]);
        }

        $n = 0;
        foreach ($ids as $post_id) {
            if (wp_trash_post((int) $post_id)) {
                $n++;
            }
        }

        if ($n > 0) {
            self::add_to_blacklist($id, $title);
            Plugin::log(sprintf('Trashed %d listing(s) for series %s; series blacklisted so sync will not recreate them until allowed again.', $n, $id));
        }

        return $n;
    }

    /**
     * @return array<int,array{series_id:string,title:string,cnt:int}>
     */
    public static function get_imported_series_summary(): array {
        global $wpdb;

        if (!post_type_exists('event_listing')) {
            return [];
        }

        $sql = "
            SELECT pm.meta_value AS series_id,
                   MIN(p.post_title) AS title,
                   COUNT(*) AS cnt
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
              AND p.post_type = %s
              AND p.post_status NOT IN ('trash','auto-draft')
            GROUP BY pm.meta_value
            ORDER BY title ASC
            LIMIT 200
        ";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders below.
        $prepared = $wpdb->prepare($sql, '_gcal_series_id', 'event_listing');
        $rows     = $wpdb->get_results($prepared, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'series_id' => (string) ($row['series_id'] ?? ''),
                'title'     => (string) ($row['title'] ?? ''),
                'cnt'       => (int) ($row['cnt'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @param array<int,array<string,mixed>> $series
     * @return array<int,array<string,mixed>>
     */
    public static function attach_series_ids(array $events, array $series): array {
        $map = [];
        foreach ($series as $s) {
            $sid = (string) ($s['id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $oks = isset($s['occurrence_keys']) && is_array($s['occurrence_keys']) ? $s['occurrence_keys'] : [];
            foreach ($oks as $ok) {
                $map[(string) $ok] = $sid;
            }
        }

        $out = [];
        foreach ($events as $e) {
            $k = (string) ($e['occurrence_key'] ?? '');
            if ($k !== '' && isset($map[$k])) {
                $e['_gcal_series_id'] = $map[$k];
            }
            $out[] = $e;
        }

        return $out;
    }
}
