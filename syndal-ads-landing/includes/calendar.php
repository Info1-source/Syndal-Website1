<?php
/**
 * Book a Tour (form 2) -> Google Calendar (info1@). Mani, 20 Oct 2026.
 * After each submission the booking is sent to a Google Apps Script web app running in the info1@ account,
 * which adds a 30-minute orange "Tour" event with the family's details. Staff only: the parent is not invited.
 * The web app URL and secret are set in Settings > General ("Tour calendar"), never in this public repo.
 */
if (!defined('ABSPATH')) { exit; }

const SYNDAL_CAL_FORM = 2;

add_action('admin_init', function(){
    foreach (['syndal_tour_cal_url' => 'Tour calendar web app URL', 'syndal_tour_cal_secret' => 'Tour calendar secret'] as $key => $label) {
        register_setting('general', $key, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        add_settings_field($key, $label, function() use ($key){
            printf('<input type="text" class="regular-text code" name="%1$s" id="%1$s" value="%2$s">', esc_attr($key), esc_attr(get_option($key, '')));
        }, 'general');
    }
});

add_action('gform_after_submission_' . SYNDAL_CAL_FORM, function($entry, $form){
    $url    = trim((string) get_option('syndal_tour_cal_url', ''));
    $secret = trim((string) get_option('syndal_tour_cal_secret', ''));
    if ($url === '' || $secret === '' || rgar($entry, 'status') === 'spam') { return; }

    // Every filled-in, visible field as "Label: value" for the event description (tracking fields last).
    $lines = []; $ad = []; $phone = '';
    foreach ((array) rgar($form, 'fields') as $field) {
        if (!is_object($field) || in_array($field->type, ['section', 'html', 'page', 'captcha'], true)) { continue; }
        $value = trim(wp_strip_all_tags((string) $field->get_value_export($entry, (string) $field->id, true)));
        if ($value === '') { continue; }
        if ($field->type === 'phone' && $phone === '') { $phone = $value; }
        if ((int) $field->id === 951) { $value = 'Yes'; }
        $label = trim((string) ($field->adminLabel ?: $field->label));
        if (strpos($label, 'Ad: ') === 0) { $ad[] = $label . ': ' . $value; } else { $lines[] = $label . ': ' . $value; }
    }
    $lines[] = '';
    $lines[] = 'Entry: ' . admin_url('admin.php?page=gf_entries&view=entry&id=' . SYNDAL_CAL_FORM . '&lid=' . rgar($entry, 'id'));
    if ($ad) { $lines[] = ''; $lines = array_merge($lines, $ad); }

    $child = trim((string) rgar($entry, '950'));
    $payload = [
        'secret'   => $secret,
        'entry_id' => (string) rgar($entry, 'id'),
        'date'     => (string) rgar($entry, '8'),   // Gravity Forms stores Y-m-d
        'time'     => (string) rgar($entry, '11'),  // e.g. "2:30 pm"
        'name'     => trim((string) rgar($entry, '6')),
        'child'    => $child !== '' ? $child : (rgar($entry, '951.1') ? 'expecting' : ''),
        'email'    => (string) rgar($entry, '3'),
        'phone'    => $phone,
        'details'  => implode("\n", $lines),
    ];
    $res = wp_remote_post($url, [
        'timeout' => 8, 'redirection' => 0,   // Apps Script answers with a 302 after the event is made
        'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload),
    ]);
    $code = is_wp_error($res) ? $res->get_error_message() : (int) wp_remote_retrieve_response_code($res);
    if (class_exists('GFAPI')) {
        GFAPI::add_note(rgar($entry, 'id'), 0, 'Tour calendar', in_array($code, [200, 302], true)
            ? 'Sent to Google Calendar.' : 'Google Calendar send failed: ' . $code, 'syndal-calendar', is_int($code) && $code < 400 ? 'success' : 'error');
    }
}, 10, 2);
