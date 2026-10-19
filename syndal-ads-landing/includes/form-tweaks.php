<?php
/**
 * Book a Tour form (Gravity Forms form 2) wording fixes, kept in code so they deploy with WP Pusher.
 *  - Tour time (field 11): each choice's saved value = the label parents see, so entries and the
 *    staff email show the start time "2:30 pm" instead of "14:30 - 15:00".
 *  - How did you hear about us? (field 12): placeholder "Please select" (still optional).
 */
if (!defined('ABSPATH')) { exit; }

const SYNDAL_TOUR_FORM       = 2;
const SYNDAL_TOUR_TIME_FIELD = 11;
const SYNDAL_TOUR_HEAR_FIELD = 12;

add_filter('gform_form_post_get_meta_' . SYNDAL_TOUR_FORM, function($form){
    // Leave the form editor alone so saving there never writes these changes to the database.
    if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'gf_edit_forms') { return $form; }
    if (empty($form['fields']) || !is_array($form['fields'])) { return $form; }

    foreach ($form['fields'] as $field) {
        if (!is_object($field) || $field->type !== 'select') { continue; }
        $id = (int) $field->id;

        if ($id === SYNDAL_TOUR_TIME_FIELD && is_array($field->choices)) {
            $choices = $field->choices;
            foreach ($choices as $k => $choice) {
                // Show and save the start time only, e.g. stored "14:30 - 15:00" -> "2:30 pm".
                $raw = isset($choice['value']) ? (string) $choice['value'] : '';
                if (preg_match('/^\s*(\d{1,2}):(\d{2})/', $raw, $m)) {
                    $h = (int) $m[1];
                    $label = (($h % 12) ?: 12) . ':' . $m[2] . ($h >= 12 ? ' pm' : ' am');
                    $choices[$k]['text']  = $label;
                    $choices[$k]['value'] = $label;
                } elseif (isset($choice['text']) && $choice['text'] !== '') {
                    $choices[$k]['value'] = $choice['text'];
                }
            }
            $field->choices = $choices;
        }

        if ($id === SYNDAL_TOUR_HEAR_FIELD && is_string($field->placeholder)) {
            $field->placeholder = trim(str_ireplace('(optional)', '', $field->placeholder));
        }
    }
    return $form;
});
