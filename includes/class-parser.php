<?php
namespace GCAL_WPEM;

use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

if (!defined('ABSPATH')) {
    exit;
}

class Parser
{
    /**
     * Parse ICS and return normalized event occurrences.
     *
     * @param string $ics
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public static function parse(string $ics, array $args = []): array
    {
        $args = wp_parse_args($args, [
            'range_start'         => current_time('timestamp'),
            'range_end'           => strtotime('+12 months', current_time('timestamp')),
            'include_past_events' => false,
            'max_occurrences'     => 500,
        ]);

        try {
            /** @var VCalendar $vcalendar */
            $vcalendar = VObject\Reader::read($ics);
        } catch (\Throwable $e) {
            Plugin::log('Parser read error: ' . $e->getMessage());
            return [];
        }

        $rangeStart = self::toDateTime($args['range_start']);
        $rangeEnd   = self::toDateTime($args['range_end']);

        if (!$rangeStart || !$rangeEnd) {
            return [];
        }

        // Expand recurrences into actual VEVENT instances inside the requested window.
        try {
            $expanded = $vcalendar->expand($rangeStart, $rangeEnd);
        } catch (\Throwable $e) {
            Plugin::log('Recurrence expansion error: ' . $e->getMessage());
            $expanded = $vcalendar;
        }

        $events = [];
        $count  = 0;

        foreach ($expanded->getComponents() as $component) {
            if ($component->name !== 'VEVENT') {
                continue;
            }

            /** @var VEvent $component */
            $normalized = self::normalize_event($component);

            if (empty($normalized['uid'])) {
                continue;
            }

            if (
                !$args['include_past_events'] &&
                !empty($normalized['start_timestamp']) &&
                $normalized['start_timestamp'] < current_time('timestamp')
            ) {
                continue;
            }

            $events[] = $normalized;
            $count++;

            if ($count >= (int) $args['max_occurrences']) {
                break;
            }
        }

        usort($events, static function (array $a, array $b): int {
            return ($a['start_timestamp'] ?? 0) <=> ($b['start_timestamp'] ?? 0);
        });

        return $events;
    }

    /**
     * Normalize one VEVENT occurrence into importer-friendly fields.
     *
     * @param VEvent $event
     * @return array<string,mixed>
     */
    protected static function normalize_event(VEvent $event): array
    {
        $uid         = isset($event->UID) ? trim((string) $event->UID) : '';
        $title       = isset($event->SUMMARY) ? wp_strip_all_tags((string) $event->SUMMARY) : '(No title)';
        $description = isset($event->DESCRIPTION) ? self::normalize_text((string) $event->DESCRIPTION) : '';
        $location    = isset($event->LOCATION) ? self::normalize_text((string) $event->LOCATION) : '';

        $startDt = isset($event->DTSTART) ? $event->DTSTART : null;
        $endDt   = isset($event->DTEND) ? $event->DTEND : null;

        $start = self::parse_date($startDt);
        $end   = self::parse_date($endDt);

        $isAllDay = self::is_all_day($startDt);

        // Google all-day recurring instances often expand with midnight boundaries.
        // If DTEND is missing, or is empty, infer a sensible end.
        if (empty($end['date'])) {
            $end = self::infer_end_from_start($start, $isAllDay);
        }

        if ($isAllDay) {
            $start['time'] = '';
            $end['time']   = '';

            if (!empty($start['date']) && !empty($end['date'])) {
                // iCal DATE uses exclusive DTEND: the day after the last day of the event.
                // Convert to inclusive last calendar day so WP Event Manager shows one day, not two.
                if (strcmp($end['date'], $start['date']) > 0) {
                    try {
                        $tz           = wp_timezone();
                        $exclusiveEnd = new \DateTimeImmutable($end['date'] . ' 00:00:00', $tz);
                        $inclusiveLast = $exclusiveEnd->modify('-1 day')->format('Y-m-d');

                        if (strcmp($inclusiveLast, $start['date']) >= 0) {
                            $end['date'] = $inclusiveLast;
                        } else {
                            $end['date'] = $start['date'];
                        }
                    } catch (\Throwable $e) {
                        $end['date'] = $start['date'];
                    }
                } else {
                    $end['date'] = $start['date'];
                }
            } elseif (!empty($start['date'])) {
                $end['date'] = $start['date'];
                $end['time'] = '';
            }

            if (!empty($start['date'])) {
                $start['timestamp'] = self::timestamp_start_of_day($start['date']);
            }
            if (!empty($end['date'])) {
                $end['timestamp'] = self::timestamp_end_of_day($end['date']);
            }
        }

        // Unique occurrence key:
        // UID is the series identifier; recurrence_id distinguishes instances.
        $recurrenceId = isset($event->{'RECURRENCE-ID'})
            ? self::parse_date($event->{'RECURRENCE-ID'})
            : null;

        $occurrenceKey = $uid;
        if (!empty($recurrenceId['date'])) {
            $occurrenceKey .= '::' . $recurrenceId['date'] . ' ' . ($recurrenceId['time'] ?: '00:00:00');
        } elseif (!empty($start['date'])) {
            $occurrenceKey .= '::' . $start['date'] . ' ' . ($start['time'] ?: '00:00:00');
        }

        $lastModified = isset($event->{'LAST-MODIFIED'})
            ? self::parse_date($event->{'LAST-MODIFIED'})
            : null;

        $googleMeetUrl = self::extract_meeting_url($location . "\n" . $description);

        $statusRaw = isset($event->STATUS) ? strtoupper(trim((string) $event->STATUS)) : '';

        $isRecurring = self::is_recurring_vevent($event);

        return [
            'uid'              => $uid,
            'occurrence_key'   => $occurrenceKey,
            'title'            => $title,
            'description'      => $description,
            'location'         => $location,
            'start_date'       => $start['date'],
            'end_date'         => $end['date'],
            'start_time'       => $start['time'],
            'end_time'         => $end['time'],
            'start_timestamp'  => $start['timestamp'],
            'end_timestamp'    => $end['timestamp'],
            'is_all_day'       => $isAllDay,
            'is_recurring'     => $isRecurring,
            'is_online'        => self::detect_online($location, $description),
            'meeting_url'      => $googleMeetUrl,
            'status'           => $statusRaw,
            'is_cancelled'     => $statusRaw === 'CANCELLED',
            'sequence'         => isset($event->SEQUENCE) ? (int) $event->SEQUENCE : 0,
            'last_modified'    => !empty($lastModified['timestamp']) ? gmdate('c', $lastModified['timestamp']) : '',
            'raw_uid'          => $uid,
        ];
    }

    /**
     * True if this VEVENT is part of a recurrence (expanded instance or has RRULE).
     */
    protected static function is_recurring_vevent(VEvent $event): bool
    {
        if (isset($event->{'RECURRENCE-ID'})) {
            return true;
        }

        if (isset($event->RRULE)) {
            $rrule = trim((string) $event->RRULE);

            return $rrule !== '';
        }

        return false;
    }

    /**
     * @param mixed $dt
     * @return array<string,mixed>
     */
    protected static function parse_date($dt): array
    {
        if (!$dt) {
            return [
                'date'      => '',
                'time'      => '',
                'timestamp' => 0,
            ];
        }

        try {
            // Pass site TZ so floating DATE-TIME (no TZID, no "Z") is interpreted in WordPress time,
            // not as UTC. When TZID is set on the property, Sabre still resolves that zone first.
            $wpTimezone = wp_timezone();
            $dateTime   = $dt->getDateTime($wpTimezone);
            if (!$dateTime instanceof \DateTimeInterface) {
                return [
                    'date'      => '',
                    'time'      => '',
                    'timestamp' => 0,
                ];
            }
            // DateTimeImmutable::setTimezone returns a new instance; must assign.
            if ($dateTime instanceof \DateTimeImmutable) {
                $dateTime = $dateTime->setTimezone($wpTimezone);
            } else {
                $dateTime->setTimezone($wpTimezone);
            }
        } catch (\Throwable $e) {
            return [
                'date'      => '',
                'time'      => '',
                'timestamp' => 0,
            ];
        }

        $isAllDay = self::is_all_day($dt);

        return [
            'date'      => $dateTime->format('Y-m-d'),
            'time'      => $isAllDay ? '' : $dateTime->format('H:i:s'),
            'timestamp' => $dateTime->getTimestamp(),
        ];
    }

    /**
     * @param mixed $dt
     */
    protected static function is_all_day($dt): bool
    {
        if (!$dt) {
            return false;
        }

        return isset($dt['VALUE']) && strtoupper((string) $dt['VALUE']) === 'DATE';
    }

    /**
     * @param array<string,mixed> $start
     * @return array<string,mixed>
     */
    protected static function infer_end_from_start(array $start, bool $isAllDay): array
    {
        if (empty($start['date'])) {
            return [
                'date'      => '',
                'time'      => '',
                'timestamp' => 0,
            ];
        }

        if ($isAllDay) {
            return [
                'date'      => $start['date'],
                'time'      => '',
                'timestamp' => self::timestamp_start_of_day($start['date']),
            ];
        }

        try {
            return [
                'date'      => $start['date'],
                'time'      => $start['time'],
                'timestamp' => (int) $start['timestamp'],
            ];
        } catch (\Throwable $e) {
            return [
                'date'      => $start['date'],
                'time'      => $start['time'],
                'timestamp' => (int) ($start['timestamp'] ?? 0),
            ];
        }
    }

    protected static function timestamp_start_of_day(string $ymd): int
    {
        try {
            $dt = new \DateTimeImmutable($ymd . ' 00:00:00', wp_timezone());

            return $dt->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected static function timestamp_end_of_day(string $ymd): int
    {
        try {
            $dt = new \DateTimeImmutable($ymd . ' 23:59:59', wp_timezone());

            return $dt->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected static function detect_online(string $location, string $description): bool
    {
        return !empty(self::extract_meeting_url($location . "\n" . $description));
    }

    protected static function extract_meeting_url(string $text): string
    {
        $patterns = [
            '/https:\/\/meet\.google\.com\/[a-z\-]+/i',
            '/https:\/\/[\w\-\.]*zoom\.us\/[^\s]+/i',
            '/https:\/\/teams\.microsoft\.com\/[^\s]+/i',
            '/https:\/\/[\w\-\.]*webex\.com\/[^\s]+/i',
            '/https:\/\/[\w\-\.]*gotomeeting\.com\/[^\s]+/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return esc_url_raw($matches[0]);
            }
        }

        return '';
    }

    protected static function normalize_text(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace('\n', "\n", $text);
        return trim($text);
    }

    /**
     * @param mixed $value
     */
    protected static function toDateTime($value): ?\DateTime
    {
        try {
            if ($value instanceof \DateTime) {
                return clone $value;
            }

            if ($value instanceof \DateTimeImmutable) {
                return new \DateTime($value->format('c'));
            }

            if (is_numeric($value)) {
                $dt = new \DateTime('@' . (int) $value);
                $dt->setTimezone(wp_timezone());
                return $dt;
            }

            if (is_string($value) && $value !== '') {
                return new \DateTime($value, wp_timezone());
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }
}