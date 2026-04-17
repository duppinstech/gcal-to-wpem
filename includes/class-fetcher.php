<?php
namespace GCAL_WPEM;

if (!defined('ABSPATH')) {
    exit;
}

class Fetcher {
    public static function get_ical_data(string $url): string|\WP_Error {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 5,
            'user-agent' => 'GCAL-WPEM/' . GCAL_WPEM_VERSION,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200 || empty($body)) {
            return new \WP_Error('gcal_fetch_failed', 'Unable to fetch iCal feed.');
        }

        return $body;
    }
}