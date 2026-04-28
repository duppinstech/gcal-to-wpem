<?php
namespace GCAL_WPEM;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {
    const OPTION_KEY = 'gcal_wpem_settings';

    /**
     * Admin URL for this plugin’s screen (under Event Listings).
     *
     * @param array<string,string|int|float> $query Extra query args (e.g. gcal_tab).
     */
    public static function admin_page_url(array $query = []): string {
        return add_query_arg(
            array_merge(
                [
                    'post_type' => 'event_listing',
                    'page'      => 'gcal-wpem',
                ],
                $query
            ),
            admin_url('edit.php')
        );
    }

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu'], 13);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_init', [self::class, 'maybe_clear_logs']);
        add_filter('event_manager_settings', [self::class, 'filter_wpem_settings_tabs'], 99);
        add_action('admin_init', [self::class, 'maybe_redirect_legacy_options_url'], 1);
        add_action('admin_init', [self::class, 'register_wpem_cta_setting'], 100);
        add_action('wpem_event_manager_admin_field_gcal_wpem_settings_cta', [self::class, 'render_wpem_settings_cta'], 10, 4);
        add_action('admin_notices', [self::class, 'render_event_list_manual_backfill_prompt'], 5);
        add_action('admin_notices', [self::class, 'maybe_show_manual_backfill_notice'], 10);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_filter('wp_redirect', [self::class, 'preserve_settings_tab_redirect'], 10, 2);
    }

    protected static function get_query_string(string $key): string {
        $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
        if (!is_string($value)) {
            return '';
        }

        return sanitize_text_field(wp_unslash($value));
    }

    protected static function get_query_int(string $key): int {
        $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
        if (!is_scalar($value)) {
            return 0;
        }

        return absint((string) $value);
    }

    protected static function get_post_string(string $key): string {
        $value = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW);
        if (!is_string($value)) {
            return '';
        }

        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * Adds a short “Google Cal” tab on WP Event Manager → Settings linking to the full Import screen.
     * Full import UI stays on its own page so admin-post actions are not nested inside WPEM’s options form.
     *
     * @param mixed $settings
     * @return mixed
     */
    public static function filter_wpem_settings_tabs($settings) {
        if (!is_array($settings)) {
            return $settings;
        }
        $settings['gcal_wpem_google_cal'] = [
            __('Google Cal', 'gcal-to-wpem'),
            [
                [
                    'name'  => 'gcal_wpem_wpem_settings_cta',
                    'label' => __('Google Calendar import', 'gcal-to-wpem'),
                    'desc'  => '',
                    'type'  => 'gcal_wpem_settings_cta',
                    'std'   => '1',
                ],
            ],
        ];

        return $settings;
    }

    /**
     * Old installs may bookmark Settings → Google Calendar Import (options-general.php?page=gcal-wpem).
     */
    public static function maybe_redirect_legacy_options_url(): void {
        global $pagenow;
        if ($pagenow !== 'options-general.php') {
            return;
        }
        if (sanitize_key(self::get_query_string('page')) !== 'gcal-wpem') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $query = [];
        $tab   = sanitize_key(self::get_query_string('gcal_tab'));
        if ($tab !== '') {
            $query['gcal_tab'] = $tab;
        }
        wp_safe_redirect(self::admin_page_url($query));
        exit;
    }

    public static function register_wpem_cta_setting(): void {
        unregister_setting('event_manager', 'gcal_wpem_wpem_settings_cta');
        register_setting(
            'event_manager',
            'gcal_wpem_wpem_settings_cta',
            [
                'sanitize_callback' => static function ($value): string {
                    unset($value);
                    return '1';
                },
                'default'           => '1',
            ]
        );
    }

    /**
     * @param array<string,mixed> $option
     * @param array<int,string>   $attributes
     * @param mixed               $value
     * @param string              $placeholder
     */
    public static function render_wpem_settings_cta($option, $attributes, $value, $placeholder): void {
        unset($option, $attributes, $value, $placeholder);
        echo '<p class="description">';
        esc_html_e(
            'Configure the iCal import, recurring-series approval, sync actions, and logs on the Import screen.',
            'gcal-to-wpem'
        );
        echo '</p><p><a href="' . esc_url(self::admin_page_url()) . '" class="button button-primary">';
        esc_html_e('Open Import settings', 'gcal-to-wpem');
        echo '</a></p>';
    }

    /**
     * After saving options, return to the same settings tab (Import / Advanced / Recurring / Logging).
     *
     * @param string $location
     */
    public static function preserve_settings_tab_redirect($location, int $status = 302): string {
        unset($status); // Redirect status code from core; not used here.
        $posted_tab = self::get_post_string('gcal_wpem_active_tab');
        if ($posted_tab === '' || !is_string($location)) {
            return $location;
        }
        if (strpos($location, 'page=gcal-wpem') === false) {
            return $location;
        }
        $tab = sanitize_key($posted_tab);
        if ($tab === '' || !in_array($tab, ['import', 'advanced', 'recurring', 'logging'], true)) {
            return $location;
        }

        return add_query_arg('gcal_tab', $tab, $location);
    }

    /**
     * All Events screen: one control bar above the list. Must NOT nest a form inside the list table's #posts-filter form
     * (that breaks nonces in most browsers).
     */
    public static function render_event_list_manual_backfill_prompt(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-event_listing') {
            return;
        }

        $need = Importer::count_listings_missing_both_expiry_meta();
        if ($need < 1) {
            return;
        }

        $action_url = admin_url('admin-post.php');
        ?>
        <div class="notice notice-info" style="margin:10px 0 0;padding:10px 14px;">
            <p style="margin:.25em 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <strong>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of event listings missing expiry meta */
                            _n(
                                '%d listing is missing expiry dates (and may need default organizer/venue).',
                                '%d listings are missing expiry dates (and may need default organizer/venue).',
                                $need,
                                'gcal-to-wpem'
                            ),
                            $need
                        )
                    );
                    ?>
                </strong>
                <form method="get" action="<?php echo esc_url($action_url); ?>" style="display:inline;margin:0;">
                    <?php wp_nonce_field('gcal_wpem_backfill_manual_listings'); ?>
                    <input type="hidden" name="action" value="gcal_wpem_backfill_manual_listings" />
                    <?php
                    submit_button(
                        __('Fill missing expiry & defaults', 'gcal-to-wpem'),
                        'secondary',
                        'submit',
                        false
                    );
                    ?>
                </form>
                <span class="description">
                    <?php esc_html_e('Uses Advanced defaults from Event Listings → Import. Only updates listings with both expiry fields empty.', 'gcal-to-wpem'); ?>
                </span>
            </p>
        </div>
        <?php
    }

    public static function maybe_show_manual_backfill_notice(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (self::get_query_string('gcal_wpem_manual_listing_fill') === '') {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-event_listing') {
            return;
        }

        $u = self::get_query_int('u');
        $s = self::get_query_int('s');

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html(
            sprintf(
                /* translators: 1: updated count, 2: skipped count */
                __('Updated %1$d listing(s). Skipped %2$d with no start/end date to derive expiry.', 'gcal-to-wpem'),
                $u,
                $s
            )
        );
        echo '</p></div>';
    }

    public static function maybe_clear_logs(): void {
        if (self::get_post_string('clear_logs') === '') {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('gcal_wpem_clear_logs');

        update_option('gcal_wpem_logs', [], false);
    }

    public static function add_menu(): void {
        add_submenu_page(
            'edit.php?post_type=event_listing',
            __('Google Calendar Import', 'gcal-to-wpem'),
            __('Import', 'gcal-to-wpem'),
            'manage_options',
            'gcal-wpem',
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting('gcal_wpem_group', self::OPTION_KEY, [
            'sanitize_callback' => [self::class, 'sanitize_settings'],
        ]);

        add_settings_section(
            'gcal_wpem_tab_import',
            '',
            '__return_false',
            'gcal-wpem'
        );

        add_settings_field(
            'ical_url',
            'Google iCal URL',
            [self::class, 'render_ical_url'],
            'gcal-wpem',
            'gcal_wpem_tab_import'
        );

        add_settings_field(
            'post_status',
            'Imported Event Status',
            [self::class, 'render_post_status'],
            'gcal-wpem',
            'gcal_wpem_tab_import'
        );

        add_settings_field(
            'prepend_year_to_title',
            'Event titles',
            [self::class, 'render_prepend_year_to_title'],
            'gcal-wpem',
            'gcal_wpem_tab_import'
        );

        add_settings_field(
            'wp_cron_schedule',
            __('Automatic sync (WP-Cron)', 'gcal-to-wpem'),
            [self::class, 'render_wp_cron_schedule'],
            'gcal-wpem',
            'gcal_wpem_tab_import'
        );

        add_settings_section(
            'gcal_wpem_tab_advanced',
            '',
            [self::class, 'render_advanced_section_intro'],
            'gcal-wpem'
        );

        add_settings_field(
            'default_location',
            'Default event location',
            [self::class, 'render_default_location'],
            'gcal-wpem',
            'gcal_wpem_tab_advanced'
        );

        add_settings_field(
            'default_pincode',
            'Default zip / postal code',
            [self::class, 'render_default_pincode'],
            'gcal-wpem',
            'gcal_wpem_tab_advanced'
        );

        add_settings_field(
            'default_country',
            'Default country',
            [self::class, 'render_default_country'],
            'gcal-wpem',
            'gcal_wpem_tab_advanced'
        );

        add_settings_field(
            'default_venue_id',
            'Default venue',
            [self::class, 'render_default_venue'],
            'gcal-wpem',
            'gcal_wpem_tab_advanced'
        );

        add_settings_field(
            'default_organizer_id',
            'Default organizer',
            [self::class, 'render_default_organizer'],
            'gcal-wpem',
            'gcal_wpem_tab_advanced'
        );

        add_settings_section(
            'gcal_wpem_tab_recurring',
            '',
            [self::class, 'render_recurring_tab_intro'],
            'gcal-wpem'
        );

        add_settings_field(
            'recurring_import_mode',
            'Recurring events',
            [self::class, 'render_recurring_import_mode'],
            'gcal-wpem',
            'gcal_wpem_tab_recurring'
        );

        add_settings_field(
            'recurring_approve_banner_id',
            'Default event banner',
            [self::class, 'render_recurring_default_banner'],
            'gcal-wpem',
            'gcal_wpem_tab_recurring'
        );
    }

    public static function enqueue_admin_assets(string $hook): void {
        if ($hook !== 'event_listing_page_gcal-wpem') {
            return;
        }

        if (defined('EVENT_MANAGER_PLUGIN_URL')) {
            wp_enqueue_style(
                'event_manager_admin_css',
                EVENT_MANAGER_PLUGIN_URL . '/assets/css/backend.min.css',
                [],
                defined('EVENT_MANAGER_VERSION') ? EVENT_MANAGER_VERSION : '0'
            );
        }

        wp_enqueue_script('jquery');
        wp_enqueue_media();

        $js = <<<'JS'
(function($){
  function syncBannerRow(rowKey, bannerId) {
    $('.gcal-wpem-sync-' + rowKey + '[data-field="banner"]').val(bannerId);
  }
  function openPicker(title, onSelect) {
    var frame = wp.media({ title: title, library: { type: 'image' }, button: { text: 'Use this image' }, multiple: false });
    frame.on('select', function() {
      var att = frame.state().get('selection').first().toJSON();
      onSelect(att);
    });
    frame.open();
  }
  function setActiveTabUi(tab) {
    $('.gcal-wpem-tab-link').removeClass('nav-tab-active');
    $('.gcal-wpem-tab-link[data-tab="' + tab + '"]').addClass('nav-tab-active');
    $('.gcal-wpem-tab-panel').hide();
    $('.gcal-wpem-tab-panel[data-tab="' + tab + '"]').show();
    $('#gcal_wpem_active_tab_field').val(tab);
    var showSave = tab !== 'logging';
    $('.gcal-wpem-settings-save-row').toggle(showSave);
    if (history.replaceState) {
      history.replaceState(null, '', '#gcal-wpem-tab-' + tab);
    }
  }
  $(document).on('click', '.gcal-wpem-media-btn', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var rowKey = $btn.data('row') || '';
    var targetSel = $btn.data('target');
    var prevSel = $btn.data('preview');
    var $target = $(targetSel);
    var $prev = $(prevSel);
    openPicker($btn.data('title') || 'Select image', function(att) {
      $target.val(att.id);
      var url = (att.sizes && att.sizes.medium && att.sizes.medium.url) ? att.sizes.medium.url
        : ((att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) ? att.sizes.thumbnail.url : att.url);
      if ($prev.length) {
        $prev.attr('src', url).css('display', 'inline-block');
      }
      if (rowKey) {
        syncBannerRow(rowKey, String(att.id));
      }
    });
  });
  $(document).on('click', '.gcal-wpem-tab-link', function(e) {
    e.preventDefault();
    setActiveTabUi($(this).data('tab'));
  });
  $(function() {
    var params = new URLSearchParams(window.location.search);
    var qt = params.get('gcal_tab');
    if (qt && $('.gcal-wpem-tab-link[data-tab="' + qt + '"]').length) {
      setActiveTabUi(qt);
      return;
    }
    var h = window.location.hash || '';
    var m = h.match(/^#gcal-wpem-tab-(\w+)$/);
    if (m && m[1]) {
      setActiveTabUi(m[1]);
    }
  });
  function gcalWpemCronToggle() {
    var v = $('input[name="gcal_wpem_settings[sync_schedule]"]:checked').val();
    $('.gcal-wpem-cron-daily-only').toggle(v === 'daily');
    $('.gcal-wpem-cron-interval-only').toggle(v === 'interval');
  }
  $(document).on('change', 'input[name="gcal_wpem_settings[sync_schedule]"]', gcalWpemCronToggle);
  $(function() { gcalWpemCronToggle(); });
})(jQuery);
JS;

        wp_add_inline_script('jquery', $js, 'after');
    }

    public static function render_recurring_tab_intro(): void {
        echo '<p class="description">' . esc_html__(
            'When you approve a queued recurring series, occurrences import as published. Missing banner images can be filled from the default below or from per-series choices in the pending table.',
            'gcal-to-wpem'
        ) . '</p>';
    }

    /**
     * @param string $input_name Full option array field name e.g. gcal_wpem_settings[recurring_approve_banner_id]
     */
    protected static function render_media_picker_field(string $input_name, int $attachment_id, string $html_id, string $label): void {
        $url = '';
        if ($attachment_id > 0 && wp_attachment_is_image($attachment_id)) {
            $url = wp_get_attachment_image_url($attachment_id, 'medium') ?: '';
        }
        ?>
        <div class="gcal-wpem-media-field" style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
            <div>
                <input type="hidden" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($html_id); ?>" value="<?php echo esc_attr((string) $attachment_id); ?>" />
                <button type="button" class="button gcal-wpem-media-btn" data-target="#<?php echo esc_attr($html_id); ?>" data-preview="#<?php echo esc_attr($html_id); ?>_preview" data-title="<?php echo esc_attr($label); ?>">
                    <?php esc_html_e('Select image', 'gcal-to-wpem'); ?>
                </button>
                <button type="button" class="button" id="<?php echo esc_attr($html_id); ?>_clear" style="margin-left:4px;">
                    <?php esc_html_e('Clear', 'gcal-to-wpem'); ?>
                </button>
            </div>
            <span id="<?php echo esc_attr($html_id); ?>_preview_wrap" style="display:inline-block;">
                <?php if ($url !== '') : ?>
                    <img id="<?php echo esc_attr($html_id); ?>_preview" src="<?php echo esc_url($url); ?>" alt="" style="max-width:120px;height:auto;vertical-align:top;border:1px solid #c3c4c7;padding:2px;background:#fff;" />
                <?php else : ?>
                    <img id="<?php echo esc_attr($html_id); ?>_preview" src="" alt="" style="display:none;max-width:120px;height:auto;vertical-align:top;border:1px solid #c3c4c7;padding:2px;background:#fff;" />
                <?php endif; ?>
            </span>
        </div>
        <script>
        (function(){
          var hid = document.getElementById(<?php echo wp_json_encode($html_id); ?>);
          var prev = document.getElementById(<?php echo wp_json_encode($html_id . '_preview'); ?>);
          var clr = document.getElementById(<?php echo wp_json_encode($html_id . '_clear'); ?>);
          if (clr && hid && prev) {
            clr.addEventListener('click', function(){
              hid.value = '0';
              prev.style.display = 'none';
              prev.src = '';
            });
          }
        })();
        </script>
        <?php
    }

    public static function render_recurring_default_banner(): void {
        $settings = self::get_settings();
        $id       = absint($settings['recurring_approve_banner_id'] ?? 0);
        self::render_media_picker_field(
            self::OPTION_KEY . '[recurring_approve_banner_id]',
            $id,
            'gcal_wpem_recurring_def_banner',
            __('Default event banner', 'gcal-to-wpem')
        );
        $key = Importer::wpem_event_banner_meta_key();
        echo '<p class="description">' . sprintf(
            /* translators: %s: post meta key */
            esc_html__('The selected image is saved to listings as an image URL in meta %s (WP Event Manager). Existing banners are not overwritten. Filter: gcal_wpem_event_banner_meta_key.', 'gcal-to-wpem'),
            '<code>' . esc_html($key) . '</code>'
        ) . '</p>';
    }

    public static function get_settings(): array {
        $opts = wp_parse_args(get_option(self::OPTION_KEY, []), [
            'ical_url'                       => '',
            'post_status'                    => 'publish',
            'recurring_import_mode'          => 'all',
            'prepend_year_to_title'          => false,
            'recurring_approve_banner_id'    => 0,
            'default_location'               => '',
            'default_pincode'                => '',
            'default_country'                => '',
            'default_venue_id'               => 0,
            'default_organizer_id'           => 0,
            'sync_schedule'                  => 'daily',
            'sync_hour_local'                => 3,
            'sync_interval_hours'            => 6,
        ]);

        $opts['default_venue_id']                    = absint($opts['default_venue_id']);
        $opts['default_organizer_id']              = absint($opts['default_organizer_id']);
        $opts['recurring_approve_banner_id'] = absint($opts['recurring_approve_banner_id']);

        if (!in_array($opts['recurring_import_mode'], ['all', 'one_off_only', 'pending_approval'], true)) {
            $opts['recurring_import_mode'] = 'all';
        }

        $opts['prepend_year_to_title'] = !empty($opts['prepend_year_to_title']);

        if (!in_array($opts['sync_schedule'], ['daily', 'interval'], true)) {
            $opts['sync_schedule'] = 'daily';
        }
        $opts['sync_hour_local']     = min(23, max(0, absint($opts['sync_hour_local'])));
        $opts['sync_interval_hours'] = Cron::normalize_interval_hours($opts['sync_interval_hours'] ?? 6);

        return $opts;
    }

    /**
     * Merge saved options so checkboxes and partial submits do not wipe keys.
     *
     * @param mixed $input
     * @return array<string,mixed>
     */
    public static function sanitize_settings($input): array {
        $prev = get_option(self::OPTION_KEY, []);
        if (!is_array($prev)) {
            $prev = [];
        }
        if (!is_array($input)) {
            return $prev;
        }

        $merged = array_merge($prev, $input);
        $merged['prepend_year_to_title'] = !empty($input['prepend_year_to_title']);

        if (array_key_exists('recurring_approve_banner_id', $input)) {
            $merged['recurring_approve_banner_id'] = absint($input['recurring_approve_banner_id']);
        }

        if (isset($input['sync_schedule'])) {
            $m = sanitize_key((string) $input['sync_schedule']);
            $merged['sync_schedule'] = in_array($m, ['daily', 'interval'], true) ? $m : 'daily';
        }
        if (isset($input['sync_hour_local'])) {
            $merged['sync_hour_local'] = min(23, max(0, absint($input['sync_hour_local'])));
        }
        if (isset($input['sync_interval_hours'])) {
            $merged['sync_interval_hours'] = Cron::normalize_interval_hours($input['sync_interval_hours']);
        }

        Cron::apply_schedule_with_settings($merged);

        return $merged;
    }

    public static function render_advanced_section_intro(): void {
        echo '<p>When the iCal feed omits details, these values fill in. Listing expiry is set automatically to the <strong>day after</strong> the event end date. Default <strong>organizer</strong> applies to all events (including online). Defaults for address and <strong>venue</strong> are skipped for <strong>online</strong> events. Save settings before running the backfill action below.</p>';
    }

    public static function render_ical_url(): void {
        $settings = self::get_settings();
        printf(
            '<input type="url" class="large-text code" name="%s[ical_url]" value="%s" style="width:100%%;max-width:100%%;box-sizing:border-box;" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($settings['ical_url'])
        );
    }

    public static function render_post_status(): void {
        $settings = self::get_settings();
        ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[post_status]">
            <option value="publish" <?php selected($settings['post_status'], 'publish'); ?>>Publish</option>
            <option value="pending" <?php selected($settings['post_status'], 'pending'); ?>>Pending</option>
            <option value="draft" <?php selected($settings['post_status'], 'draft'); ?>>Draft</option>
        </select>
        <?php
    }

    public static function render_recurring_import_mode(): void {
        $settings = self::get_settings();
        $mode     = $settings['recurring_import_mode'];
        $name     = self::OPTION_KEY . '[recurring_import_mode]';
        ?>
        <select name="<?php echo esc_attr($name); ?>" id="gcal_wpem_recurring_import_mode">
            <option value="all" <?php selected($mode, 'all'); ?>>Import all events (including recurring)</option>
            <option value="one_off_only" <?php selected($mode, 'one_off_only'); ?>>One-off events only (skip recurring series)</option>
            <option value="pending_approval" <?php selected($mode, 'pending_approval'); ?>>Require approval for recurring / duplicate-title series</option>
        </select>
        <p class="description">
            Recurring items are detected from the calendar (e.g. weekly services, monthly meetings): they usually have a <code>RECURRENCE-ID</code> or <code>RRULE</code> in the feed.
            Multiple separate events with the same title are also grouped as one series. Choosing &quot;One-off only&quot; skips iCal-flagged recurring rows only.
            &quot;Require approval&quot; imports one-offs immediately but queues each series on this settings page until you approve or reject.
        </p>
        <?php
    }

    public static function render_prepend_year_to_title(): void {
        $settings = self::get_settings();
        $name     = self::OPTION_KEY . '[prepend_year_to_title]';
        ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0" />
        <label>
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked(!empty($settings['prepend_year_to_title'])); ?> />
            <?php esc_html_e('Prepend the event start year to the title (e.g. “2026 Easter Service”)', 'gcal-to-wpem'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Helps one-off events get distinct URLs year over year instead of WordPress adding -2, -3 to the slug. Does not apply to recurring feed rows or same-title series. On each sync, published listings keep their title and slug; pending/draft/etc. can still get title and slug updates. A follow-up pass also adds the prefix to other stored non-published imports when needed.', 'gcal-to-wpem'); ?>
        </p>
        <?php
    }

    public static function render_wp_cron_schedule(): void {
        $settings = self::get_settings();
        $mode     = $settings['sync_schedule'];
        $hour     = (int) $settings['sync_hour_local'];
        $interval = (int) $settings['sync_interval_hours'];
        $opt_key  = self::OPTION_KEY;
        $tz_str   = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';

        $tz_obj = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $base   = new \DateTimeImmutable('today', $tz_obj);
        $fmt    = get_option('time_format', 'g:i a');

        ?>
        <div class="gcal-wpem-wp-cron" style="max-width:640px;">
            <p class="description" style="margin-top:0;">
                <?php esc_html_e('WordPress schedules imports with WP-Cron. Triggers when your site gets traffic; for exact timing, set a system cron calling wp-cron.php.', 'gcal-to-wpem'); ?>
            </p>

            <fieldset class="gcal-wpem-wp-cron-fieldset" style="border:0;padding:0;margin:0;">
                <legend class="screen-reader-text"><?php esc_html_e('Automatic import schedule', 'gcal-to-wpem'); ?></legend>

                <p style="margin:.35em 0;">
                    <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="radio" name="<?php echo esc_attr($opt_key); ?>[sync_schedule]" value="daily" <?php checked($mode, 'daily'); ?> />
                        <?php esc_html_e('Once per day', 'gcal-to-wpem'); ?>
                    </label>
                </p>
                <div class="gcal-wpem-cron-daily-only" style="margin:0 0 12px 28px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
                    <label for="gcal_wpem_sync_hour_local" style="margin-right:8px;">
                        <?php esc_html_e('Run at', 'gcal-to-wpem'); ?>
                    </label>
                    <select name="<?php echo esc_attr($opt_key); ?>[sync_hour_local]" id="gcal_wpem_sync_hour_local">
                        <?php
                        for ($h = 0; $h < 24; $h++) {
                            $label = wp_date($fmt, $base->setTime($h, 0, 0)->getTimestamp());
                            printf(
                                '<option value="%s"%s>%s</option>',
                                esc_attr((string) $h),
                                selected($hour, $h, false),
                                esc_html($label)
                            );
                        }
                        ?>
                    </select>
                    <span class="description" style="margin-left:8px;">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: PHP timezone string e.g. America/Chicago */
                                __('Site time (%s)', 'gcal-to-wpem'),
                                $tz_str !== '' ? $tz_str : 'UTC'
                            )
                        );
                        ?>
                    </span>
                </div>

                <p style="margin:.35em 0;">
                    <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="radio" name="<?php echo esc_attr($opt_key); ?>[sync_schedule]" value="interval" <?php checked($mode, 'interval'); ?> />
                        <?php esc_html_e('Every N hours', 'gcal-to-wpem'); ?>
                    </label>
                </p>
                <div class="gcal-wpem-cron-interval-only" style="margin:0 0 8px 28px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
                    <label for="gcal_wpem_sync_interval_hours" style="margin-right:8px;">
                        <?php esc_html_e('Interval', 'gcal-to-wpem'); ?>
                    </label>
                    <select name="<?php echo esc_attr($opt_key); ?>[sync_interval_hours]" id="gcal_wpem_sync_interval_hours">
                        <?php
                        foreach (Cron::allowed_interval_hours() as $ih) {
                            $lab = $ih === 1
                                ? __('Every hour', 'gcal-to-wpem')
                                : sprintf(
                                    /* translators: %d: whole hours */
                                    __('Every %d hours', 'gcal-to-wpem'),
                                    $ih
                                );
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr((string) $ih),
                                selected($interval, $ih, false),
                                esc_html($lab)
                            );
                        }
                        ?>
                    </select>
                </div>
            </fieldset>

            <table class="widefat striped" style="margin-top:12px;max-width:100%;">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Next automatic run', 'gcal-to-wpem'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong><?php echo esc_html(Cron::get_next_run_human()); ?></strong>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_default_location(): void {
        $settings = self::get_settings();
        printf(
            '<input type="text" class="large-text" name="%s[default_location]" value="%s" placeholder="%s" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($settings['default_location']),
            esc_attr('City, region, or map-friendly address')
        );
        echo '<p class="description">Used when the calendar location is empty (offline events only).</p>';
    }

    public static function render_default_pincode(): void {
        $settings = self::get_settings();
        printf(
            '<input type="text" class="regular-text" name="%s[default_pincode]" value="%s" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($settings['default_pincode'])
        );
        echo '<p class="description">Maps to WP Event Manager “Zip Code” (<code>_event_pincode</code>).</p>';
    }

    public static function render_default_country(): void {
        $settings = self::get_settings();
        $name     = self::OPTION_KEY . '[default_country]';
        $current  = (string) $settings['default_country'];

        if (function_exists('wpem_get_all_countries')) {
            $countries = wpem_get_all_countries();
            if (is_array($countries) && $countries !== []) {
                echo '<select name="' . esc_attr($name) . '" id="gcal_wpem_default_country">';
                echo '<option value="">' . esc_html('— Select —') . '</option>';
                foreach ($countries as $code => $label) {
                    if ($code === '') {
                        continue;
                    }
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr((string) $code),
                        selected($current, (string) $code, false),
                        esc_html((string) $label)
                    );
                }
                echo '</select>';
                echo '<p class="description">Same values as the WP Event Manager submit form.</p>';

                return;
            }
        }

        printf(
            '<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s" />',
            esc_attr($name),
            esc_attr($current),
            esc_attr('e.g. US or full country name')
        );
        echo '<p class="description">Use the exact value your WP Event Manager country field expects (enable WP Event Manager to get a dropdown).</p>';
    }

    public static function render_default_venue(): void {
        $settings = self::get_settings();
        $current  = absint($settings['default_venue_id']);

        echo '<select name="' . esc_attr(self::OPTION_KEY . '[default_venue_id]') . '" id="gcal_wpem_default_venue">';
        echo '<option value="0">' . esc_html('— None —') . '</option>';

        $venues = self::get_venue_choices();
        foreach ($venues as $id => $title) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $id,
                selected($current, (int) $id, false),
                esc_html($title)
            );
        }
        echo '</select>';

        if ($venues === []) {
            echo '<p class="description">No published venues found (post type <code>event_venue</code>). Create venues in WP Event Manager first, or leave as None.</p>';
        } else {
            echo '<p class="description">Stored as an array of venue IDs (same as WP Event Manager’s multi-select).</p>';
        }
    }

    public static function render_default_organizer(): void {
        $settings = self::get_settings();
        $current  = absint($settings['default_organizer_id']);

        echo '<select name="' . esc_attr(self::OPTION_KEY . '[default_organizer_id]') . '" id="gcal_wpem_default_organizer">';
        echo '<option value="0">' . esc_html('— None —') . '</option>';

        $organizers = self::get_organizer_choices();
        foreach ($organizers as $id => $title) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $id,
                selected($current, (int) $id, false),
                esc_html($title)
            );
        }
        echo '</select>';

        if ($organizers === []) {
            echo '<p class="description">No published organizers found (post type <code>event_organizer</code>). Create organizers in WP Event Manager first, or leave as None.</p>';
        } else {
            echo '<p class="description">Maps to <code>_event_organizer_ids</code> (array of IDs, same as the admin Chosen field).</p>';
        }
    }

    /**
     * @return array<int,string> venue ID => title
     */
    protected static function get_venue_choices(): array {
        if (!post_type_exists('event_venue')) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => 'event_venue',
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $out = [];
        foreach ($posts as $post) {
            $out[(int) $post->ID] = $post->post_title;
        }

        return $out;
    }

    /**
     * @return array<int,string> organizer ID => title
     */
    protected static function get_organizer_choices(): array {
        if (!post_type_exists('event_organizer')) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => 'event_organizer',
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $out = [];
        foreach ($posts as $post) {
            $out[(int) $post->ID] = $post->post_title;
        }

        return $out;
    }

    public static function render_page(): void {
        if (self::get_query_string('backfilled') !== '') {
            $n = self::get_query_int('n');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
                sprintf('Backfill finished. Updated %d event listing(s).', $n)
            ) . '</p></div>';
        }

        $test_status = self::get_query_string('test');
        if ($test_status !== '') {
            if ($test_status === 'success') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html('Dry run finished. Preview below (no events were saved).') . '</p></div>';
            } elseif ($test_status === 'fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html('Dry run failed. Check the import log below for details.') . '</p></div>';
            }
        }

        $s = self::get_query_string('series');
        if ($s !== '') {
            if ($s === 'approved') {
                $i = self::get_query_int('i');
                $m = self::get_query_int('m');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
                    sprintf('Series approved. Imported %d occurrence(s); %d were not found in the current feed.', $i, $m)
                ) . '</p></div>';
            } elseif ($s === 'approve_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(
                    'Could not approve that series. It may have been removed from the queue, the feed may be unreachable, or WP Event Manager is inactive.'
                ) . '</p></div>';
            } elseif ($s === 'rejected') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
                    'Series rejected and added to the blacklist. Sync will not queue or import it again until you use Allow again.'
                ) . '</p></div>';
            } elseif ($s === 'reject_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html('Could not reject that series (invalid id or not in queue).') . '</p></div>';
            } elseif ($s === 'trashed') {
                $n = self::get_query_int('n');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
                    sprintf(
                        'Moved %d listing(s) to the trash and blacklisted that series so sync will not recreate them until you allow the series again.',
                        $n
                    )
                ) . '</p></div>';
            } elseif ($s === 'allowed') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
                    'Series removed from the blacklist. Run sync to import or queue it again (depending on your recurring mode).'
                ) . '</p></div>';
            } elseif ($s === 'allow_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html('Could not update the blacklist (invalid id or not listed).') . '</p></div>';
            } elseif ($s === 'media_saved') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Saved banner for that pending series.', 'gcal-to-wpem') . '</p></div>';
            } elseif ($s === 'media_save_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Could not save series banner (invalid series or not in queue).', 'gcal-to-wpem') . '</p></div>';
            } elseif ($s === 'imported_banner_ok') {
                $n = self::get_query_int('n');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
                    sprintf(
                        /* translators: %d: listings updated */
                        _n(
                            'Banner applied to %d listing that was missing a banner.',
                            'Banner applied to %d listings that were missing a banner.',
                            $n,
                            'gcal-to-wpem'
                        ),
                        $n
                    )
                ) . '</p></div>';
            } elseif ($s === 'imported_banner_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Could not update banner for that series.', 'gcal-to-wpem') . '</p></div>';
            }
        }

        $gcal_cur_tab = sanitize_key(self::get_query_string('gcal_tab'));
        if ($gcal_cur_tab === '') {
            $gcal_cur_tab = 'import';
        }
        if (!in_array($gcal_cur_tab, ['import', 'advanced', 'recurring', 'logging'], true)) {
            $gcal_cur_tab = 'import';
        }
        $gcal_tab_vis = static function (string $t) use ($gcal_cur_tab): string {
            return $t === $gcal_cur_tab ? '' : 'display:none;';
        };
        $gcal_nav_active = static function (string $t) use ($gcal_cur_tab): string {
            return $t === $gcal_cur_tab ? ' nav-tab-active' : '';
        };
        $gcal_save_style = $gcal_cur_tab === 'logging' ? 'display:none;' : '';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Google Calendar Import', 'gcal-to-wpem'); ?></h1>

            <h2 class="nav-tab-wrapper" style="margin-bottom:0;">
                <a href="#gcal-wpem-tab-import" class="nav-tab<?php echo esc_attr($gcal_nav_active('import')); ?> gcal-wpem-tab-link" data-tab="import"><?php esc_html_e('Import', 'gcal-to-wpem'); ?></a>
                <a href="#gcal-wpem-tab-advanced" class="nav-tab<?php echo esc_attr($gcal_nav_active('advanced')); ?> gcal-wpem-tab-link" data-tab="advanced"><?php esc_html_e('Advanced', 'gcal-to-wpem'); ?></a>
                <a href="#gcal-wpem-tab-recurring" class="nav-tab<?php echo esc_attr($gcal_nav_active('recurring')); ?> gcal-wpem-tab-link" data-tab="recurring"><?php esc_html_e('Recurring events', 'gcal-to-wpem'); ?></a>
                <a href="#gcal-wpem-tab-logging" class="nav-tab<?php echo esc_attr($gcal_nav_active('logging')); ?> gcal-wpem-tab-link" data-tab="logging"><?php esc_html_e('Logging', 'gcal-to-wpem'); ?></a>
            </h2>

            <form method="post" action="options.php" id="gcal-wpem-settings-form" class="gcal-wpem-settings-form" style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:16px 20px 20px;max-width:960px;">
                <?php
                settings_fields('gcal_wpem_group');
                echo '<input type="hidden" name="gcal_wpem_active_tab" id="gcal_wpem_active_tab_field" value="' . esc_attr($gcal_cur_tab) . '" />';

                echo '<div class="gcal-wpem-tab-panel" data-tab="import" style="' . esc_attr($gcal_tab_vis('import')) . '">';
                echo '<h2 style="margin-top:0;">' . esc_html__('Import settings', 'gcal-to-wpem') . '</h2>';
                echo '<table class="form-table" role="presentation"><tbody>';
                do_settings_fields('gcal-wpem', 'gcal_wpem_tab_import');
                echo '</tbody></table>';
                echo '</div>';

                echo '<div class="gcal-wpem-tab-panel" data-tab="advanced" style="' . esc_attr($gcal_tab_vis('advanced')) . '">';
                echo '<h2 style="margin-top:0;">' . esc_html__('Advanced defaults', 'gcal-to-wpem') . '</h2>';
                self::render_advanced_section_intro();
                echo '<table class="form-table" role="presentation"><tbody>';
                do_settings_fields('gcal-wpem', 'gcal_wpem_tab_advanced');
                echo '</tbody></table>';
                echo '</div>';

                echo '<div class="gcal-wpem-tab-panel" data-tab="recurring" style="' . esc_attr($gcal_tab_vis('recurring')) . '">';
                echo '<h2 style="margin-top:0;">' . esc_html__('Recurring series defaults', 'gcal-to-wpem') . '</h2>';
                self::render_recurring_tab_intro();
                echo '<table class="form-table" role="presentation"><tbody>';
                do_settings_fields('gcal-wpem', 'gcal_wpem_tab_recurring');
                echo '</tbody></table>';
                echo '</div>';

                echo '<p class="submit gcal-wpem-settings-save-row" style="' . esc_attr($gcal_save_style) . '">';
                submit_button(__('Save Settings', 'gcal-to-wpem'), 'primary', 'submit', false);
                echo '</p>';
                ?>
            </form>

            <div class="gcal-wpem-tab-panel" data-tab="import" style="max-width:960px;margin-top:16px;<?php echo esc_attr($gcal_tab_vis('import')); ?>">
                <h2><?php esc_html_e('Advanced actions', 'gcal-to-wpem'); ?></h2>
                <p><?php echo wp_kses_post(__('Apply saved defaults and listing expiry to events that were imported from Google Calendar (posts with <code>_gcal_uid</code>). Also fixes legacy all-day rows where the end date was one day too late (iCal exclusive end), clears midnight times on date-only rows, and recalculates expiry. Empty fields get defaults (including organizer).', 'gcal-to-wpem')); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
                    <?php wp_nonce_field('gcal_wpem_backfill'); ?>
                    <input type="hidden" name="action" value="gcal_wpem_backfill" />
                    <?php submit_button(__('Apply defaults to imported events', 'gcal-to-wpem'), 'secondary'); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('gcal_wpem_manual_sync'); ?>
                    <input type="hidden" name="action" value="gcal_wpem_run_sync" />
                    <?php submit_button(__('Run Sync Now', 'gcal-to-wpem'), 'secondary'); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <?php wp_nonce_field('gcal_wpem_test_sync'); ?>
                    <input type="hidden" name="action" value="gcal_wpem_test_sync" />
                    <?php submit_button(__('Test Import (Dry Run)', 'gcal-to-wpem'), 'secondary'); ?>
                </form>
            </div>

            <?php
            $recurring_mode = self::get_settings()['recurring_import_mode'] ?? 'all';
            $pending        = RecurringQueue::get_pending_list_sorted();
            $blacklisted    = RecurringQueue::get_blacklist_list_sorted();
            $defaults       = self::get_settings();
            $def_banner     = absint($defaults['recurring_approve_banner_id'] ?? 0);

            ?>
            <div class="gcal-wpem-tab-panel" data-tab="recurring" style="max-width:1100px;margin-top:16px;<?php echo esc_attr($gcal_tab_vis('recurring')); ?>">
            <?php
            if ($recurring_mode === 'pending_approval') {
                echo '<h2>' . esc_html__('Recurring series — pending approval', 'gcal-to-wpem') . '</h2>';
                echo '<p class="description">' . esc_html__(
                    'Shown when “Require approval for recurring / duplicate-title series” is saved on the Recurring events tab. Each row is one series. Choose a banner (optional), save, then approve. Approve imports every occurrence as published and fills missing banners without replacing existing ones.',
                    'gcal-to-wpem'
                ) . '</p>';
                if (empty($pending)) {
                    echo '<p><em>' . esc_html__(
                        'No series are waiting. Run “Sync now” after saving that recurring mode so the feed can populate this list.',
                        'gcal-to-wpem'
                    ) . '</em></p>';
                } else {
                    echo '<table class="widefat striped"><thead><tr>';
                    echo '<th>' . esc_html__('Series', 'gcal-to-wpem') . '</th>';
                    echo '<th>' . esc_html__('Source', 'gcal-to-wpem') . '</th>';
                    echo '<th style="min-width:220px;">' . esc_html__('Banner', 'gcal-to-wpem') . '</th>';
                    echo '<th>' . esc_html__('Actions', 'gcal-to-wpem') . '</th></tr></thead><tbody>';
                    foreach ($pending as $row) {
                        $title = (string) ($row['title'] ?? '(No title)');
                        $cnt   = isset($row['count']) ? (int) $row['count'] : count((array) ($row['occurrence_keys'] ?? []));
                        $det   = (string) ($row['detection'] ?? '');
                        $detl  = $det === 'title_cluster' ? __('Same title', 'gcal-to-wpem') : ($det === 'ical' ? __('Calendar recurrence', 'gcal-to-wpem') : $det);
                        $sid   = (string) ($row['id'] ?? '');
                        $rk    = substr(md5($sid), 0, 14);

                        $bid = absint($row['banner_id'] ?? 0);
                        if ($bid <= 0) {
                            $bid = $def_banner;
                        }

                        $ban_url = ($bid > 0 && wp_attachment_is_image($bid)) ? (wp_get_attachment_image_url($bid, 'thumbnail') ?: '') : '';

                        echo '<tr><td><strong>' . esc_html($title) . '</strong><br />';
                        $occurrence_label = sprintf(
                            /* translators: %d: occurrence count in a recurring series */
                            _n('%d occurrence', '%d occurrences', $cnt, 'gcal-to-wpem'),
                            $cnt
                        );
                        echo '<span class="description">' . esc_html($occurrence_label) . '</span></td>';
                        echo '<td>' . esc_html((string) $detl) . '</td>';

                        echo '<td><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
                        echo '<input type="hidden" class="gcal-wpem-sync-' . esc_attr($rk) . '" data-field="banner" name="series_banner_id" form="gcal-wpem-save-' . esc_attr($rk) . '" value="' . esc_attr((string) $bid) . '" />';
                        echo '<input type="hidden" class="gcal-wpem-sync-' . esc_attr($rk) . '" data-field="banner" name="series_banner_id" form="gcal-wpem-appr-' . esc_attr($rk) . '" value="' . esc_attr((string) $bid) . '" />';
                        echo '<img src="' . esc_url($ban_url) . '" alt="" class="gcal-wpem-pv-' . esc_attr($rk) . '-ban" style="' . ($ban_url !== '' ? '' : 'display:none;') . 'max-width:72px;height:auto;border:1px solid #c3c4c7;padding:2px;background:#fff;" />';
                        echo '<button type="button" class="button gcal-wpem-media-btn" data-row="' . esc_attr($rk) . '" data-target=".gcal-wpem-in-ban-' . esc_attr($rk) . '" data-preview=".gcal-wpem-pv-' . esc_attr($rk) . '-ban" data-title="' . esc_attr__('Event banner', 'gcal-to-wpem') . '">' . esc_html__('Set', 'gcal-to-wpem') . '</button>';
                        echo '<input type="hidden" class="gcal-wpem-in-ban-' . esc_attr($rk) . '" value="' . esc_attr((string) $bid) . '" />';
                        echo '</div></td>';

                        echo '<td style="vertical-align:top;">';
                        echo '<form id="gcal-wpem-save-' . esc_attr($rk) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px;">';
                        wp_nonce_field('gcal_wpem_save_series_media');
                        echo '<input type="hidden" name="action" value="gcal_wpem_save_series_media" />';
                        echo '<input type="hidden" name="series_id" value="' . esc_attr($sid) . '" />';
                        submit_button(__('Save banner', 'gcal-to-wpem'), 'secondary small', 'submit', false);
                        echo '</form>';
                        echo '<form id="gcal-wpem-appr-' . esc_attr($rk) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;margin-right:6px;">';
                        wp_nonce_field('gcal_wpem_series_action');
                        echo '<input type="hidden" name="action" value="gcal_wpem_series_action" />';
                        echo '<input type="hidden" name="series_action" value="approve" />';
                        echo '<input type="hidden" name="series_id" value="' . esc_attr($sid) . '" />';
                        submit_button(__('Approve recurring', 'gcal-to-wpem'), 'primary small', 'submit', false);
                        echo '</form>';
                        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
                        wp_nonce_field('gcal_wpem_series_action');
                        echo '<input type="hidden" name="action" value="gcal_wpem_series_action" />';
                        echo '<input type="hidden" name="series_action" value="reject" />';
                        echo '<input type="hidden" name="series_id" value="' . esc_attr($sid) . '" />';
                        submit_button(__('Reject', 'gcal-to-wpem'), 'delete small', 'submit', false);
                        echo '</form>';
                        echo '</td></tr>';
                    }
                    echo '</tbody></table>';
                }
            } else {
                echo '<p class="description">' . esc_html__(
                    'Switch “Recurring events” to “Require approval…” on the settings form above and save to manage pending series here.',
                    'gcal-to-wpem'
                ) . '</p>';
            }

            if (!empty($blacklisted)) {
                echo '<h2 style="margin-top:24px;">' . esc_html__('Blacklisted recurring series', 'gcal-to-wpem') . '</h2>';
                echo '<p class="description">' . esc_html__(
                    'Rejected or removed series stay here by stable id (calendar series or same-title hash). Sync still reads the feed but will not import these rows until you allow the series again.',
                    'gcal-to-wpem'
                ) . '</p>';
                echo '<table class="widefat striped"><thead><tr>';
                echo '<th>' . esc_html__('Title', 'gcal-to-wpem') . '</th><th>' . esc_html__('Series id', 'gcal-to-wpem') . '</th><th>' . esc_html__('Actions', 'gcal-to-wpem') . '</th></tr></thead><tbody>';
                foreach ($blacklisted as $row) {
                    $title = (string) ($row['title'] ?? '(No title)');
                    $bsid  = (string) ($row['id'] ?? '');
                    echo '<tr><td><strong>' . esc_html($title !== '' ? $title : '(No title)') . '</strong></td>';
                    echo '<td><code style="font-size:11px;word-break:break-all;">' . esc_html($bsid) . '</code></td><td>';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    wp_nonce_field('gcal_wpem_series_action');
                    echo '<input type="hidden" name="action" value="gcal_wpem_series_action" />';
                    echo '<input type="hidden" name="series_action" value="allow_series" />';
                    echo '<input type="hidden" name="series_id" value="' . esc_attr($bsid) . '" />';
                    submit_button(__('Allow again', 'gcal-to-wpem'), 'secondary small', 'submit', false);
                    echo '</form></td></tr>';
                }
                echo '</tbody></table>';
            }

            $importedSeries = RecurringQueue::get_imported_series_summary();
            if (!empty($importedSeries)) {
                echo '<h2 style="margin-top:24px;">' . esc_html__('Imported recurring series', 'gcal-to-wpem') . '</h2>';
                echo '<p class="description">' . esc_html__(
                    'Listings tagged with a series id (from “Import all” or after you approve a pending series). Set a banner to fill listings that do not have one yet; existing banners are left unchanged. Remove all trashes every listing in that series and blacklists the series.',
                    'gcal-to-wpem'
                ) . '</p>';
                echo '<table class="widefat striped"><thead><tr>';
                echo '<th>' . esc_html__('Series', 'gcal-to-wpem') . '</th><th>' . esc_html__('Listings', 'gcal-to-wpem') . '</th>';
                echo '<th>' . esc_html__('Banner', 'gcal-to-wpem') . '</th><th>' . esc_html__('Actions', 'gcal-to-wpem') . '</th></tr></thead><tbody>';
                foreach ($importedSeries as $row) {
                    $ititle = (string) ($row['title'] ?? '');
                    $cnt    = (int) ($row['cnt'] ?? 0);
                    $isid   = (string) ($row['series_id'] ?? '');
                    $irk    = substr(md5($isid), 0, 14);
                    $badge  = RecurringQueue::imported_series_shows_banner_badge($isid);
                    $ibid   = RecurringQueue::get_imported_series_banner_attachment_id($isid);
                    if ($ibid <= 0) {
                        $ibid = $def_banner;
                    }
                    $iban_url = ($ibid > 0 && wp_attachment_is_image($ibid)) ? (wp_get_attachment_image_url($ibid, 'thumbnail') ?: '') : '';

                    echo '<tr><td><strong>' . esc_html($ititle !== '' ? $ititle : '(No title)') . '</strong>';
                    if ($badge) {
                        echo ' <span class="gcal-wpem-banner-badge" style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:#d7f0d0;color:#1e4620;">' . esc_html__('Banner set', 'gcal-to-wpem') . '</span>';
                    }
                    echo '</td>';
                    echo '<td>' . esc_html((string) $cnt) . '</td>';
                    echo '<td><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
                    echo '<input type="hidden" class="gcal-wpem-sync-' . esc_attr($irk) . '" data-field="banner" name="imported_series_banner_id" form="gcal-wpem-iban-' . esc_attr($irk) . '" value="' . esc_attr((string) $ibid) . '" />';
                    echo '<img src="' . esc_url($iban_url) . '" alt="" class="gcal-wpem-pv-' . esc_attr($irk) . '-iban" style="' . ($iban_url !== '' ? '' : 'display:none;') . 'max-width:72px;height:auto;border:1px solid #c3c4c7;padding:2px;background:#fff;" />';
                    echo '<button type="button" class="button gcal-wpem-media-btn" data-row="' . esc_attr($irk) . '" data-target=".gcal-wpem-in-iban-' . esc_attr($irk) . '" data-preview=".gcal-wpem-pv-' . esc_attr($irk) . '-iban" data-title="' . esc_attr__('Event banner', 'gcal-to-wpem') . '">' . esc_html__('Edit banner', 'gcal-to-wpem') . '</button>';
                    echo '<input type="hidden" class="gcal-wpem-in-iban-' . esc_attr($irk) . '" value="' . esc_attr((string) $ibid) . '" />';
                    echo '</div>';
                    echo '<form id="gcal-wpem-iban-' . esc_attr($irk) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
                    wp_nonce_field('gcal_wpem_imported_series_banner');
                    echo '<input type="hidden" name="action" value="gcal_wpem_imported_series_banner" />';
                    echo '<input type="hidden" name="series_id" value="' . esc_attr($isid) . '" />';
                    submit_button(__('Apply banner to listings', 'gcal-to-wpem'), 'secondary small', 'submit', false);
                    echo '</form></td><td>';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('Move all listings in this series to the trash?', 'gcal-to-wpem')) . '\');" style="display:inline;">';
                    wp_nonce_field('gcal_wpem_series_action');
                    echo '<input type="hidden" name="action" value="gcal_wpem_series_action" />';
                    echo '<input type="hidden" name="series_action" value="trash_series" />';
                    echo '<input type="hidden" name="series_id" value="' . esc_attr($isid) . '" />';
                    submit_button(__('Remove all', 'gcal-to-wpem'), 'delete small', 'submit', false);
                    echo '</form></td></tr>';
                }
                echo '</tbody></table>';
            }
            ?>
            </div>

            <div class="gcal-wpem-tab-panel" data-tab="logging" style="max-width:960px;margin-top:16px;<?php echo esc_attr($gcal_tab_vis('logging')); ?>">
            <?php
            $test = get_transient('gcal_wpem_last_test');

            if (!empty($test) && is_array($test)) {
                echo '<h2>' . esc_html__('Test results (preview)', 'gcal-to-wpem') . '</h2>';
                echo '<p class="description">' . esc_html__(
                    'Showing up to 10 events that would be imported (recurring mode from settings already applied; in pending-approval mode only one-offs appear here). Nothing was written to the database.',
                    'gcal-to-wpem'
                ) . '</p>';
                echo '<div style="max-height:300px;overflow:auto;background:#fff;padding:10px;border:1px solid #c3c4c7;">';
                $preview = wp_json_encode(array_slice($test, 0, 10), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!is_string($preview) || $preview === '') {
                    $preview = '[]';
                }
                echo '<pre style="margin:0;white-space:pre-wrap;word-break:break-word;">' . esc_html($preview) . '</pre>';
                echo '</div>';
            }

            $logs = get_option('gcal_wpem_logs', []);

            echo '<h2>' . esc_html__('Import log', 'gcal-to-wpem') . '</h2>';

            echo '<div style="
                max-height:300px;
                overflow:auto;
                background:#111;
                color:#0f0;
                padding:10px;
                font-family:monospace;
                font-size:12px;
                border-radius:4px;
                white-space:pre-wrap;
                word-break:break-word;
            ">';

            if (!empty($logs) && is_array($logs)) {
                foreach (array_reverse($logs) as $log) {
                    if (!is_array($log) || !isset($log['time'], $log['message'])) {
                        continue;
                    }
                    echo esc_html('[' . $log['time'] . '] ' . $log['message']) . "\n";
                }
            } else {
                echo esc_html__('No logs yet.', 'gcal-to-wpem');
            }

            echo '</div>';

            ?>
            <form method="post" action="<?php echo esc_url(self::admin_page_url()); ?>" style="margin-top:12px;">
                <?php wp_nonce_field('gcal_wpem_clear_logs'); ?>
                <input type="hidden" name="clear_logs" value="1" />
                <?php submit_button(__('Clear Logs', 'gcal-to-wpem'), 'delete'); ?>
            </form>
            </div>
        </div>
        <?php
    }
}