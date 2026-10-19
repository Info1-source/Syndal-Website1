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
    return syndal_tour_add_child_field($form);
});

/**
 * "Child's name" (required) plus a "We're expecting" box that hides it for families still expecting. Added at runtime like the tracking fields, so nothing
 * is saved to the form in the database. Used to match a tour booking to the Xplor enrolment even when the other
 * parent enrols or uses a different email. Placed straight after the Mobile/phone field.
 */
const SYNDAL_TOUR_CHILD_FIELD     = 950;
const SYNDAL_TOUR_EXPECTING_FIELD = 951;

function syndal_tour_add_child_field($form){
    if (!class_exists('GF_Fields')) { return $form; }
    $after = null;
    foreach ($form['fields'] as $i => $f) {
        if (!is_object($f)) { continue; }
        if ((int) $f->id === SYNDAL_TOUR_CHILD_FIELD || (int) $f->id === SYNDAL_TOUR_EXPECTING_FIELD || stripos((string) $f->label, 'child') !== false) {
            return $form;   // already there (or a real child field was added in the editor)
        }
        if ($f->type === 'phone' || stripos((string) $f->label, 'mobile') !== false) { $after = $i; }
        if ($after === null && $f->type === 'email') { $after = $i; }
    }
    // Required, but hidden (and so not required) when the "We're expecting" box is ticked.
    $child = GF_Fields::create([
        'type' => 'text', 'id' => SYNDAL_TOUR_CHILD_FIELD, 'formId' => SYNDAL_TOUR_FORM,
        'label' => "Child's name", 'isRequired' => true, 'maxLength' => 80, 'autocomplete' => 'off',
        'conditionalLogic' => [
            'actionType' => 'show', 'logicType' => 'all',
            'rules' => [['fieldId' => (string) SYNDAL_TOUR_EXPECTING_FIELD, 'operator' => 'isnot', 'value' => 'Expecting']],
        ],
    ]);
    $expecting = GF_Fields::create([
        'type' => 'checkbox', 'id' => SYNDAL_TOUR_EXPECTING_FIELD, 'formId' => SYNDAL_TOUR_FORM,
        'label' => 'Expecting', 'labelPlacement' => 'hidden_label', 'isRequired' => false,
        'conditionalLogicFields' => [SYNDAL_TOUR_CHILD_FIELD],   // tells Gravity Forms to re-check the name field on click
        'choices' => [['text' => "We're expecting (baby not born yet)", 'value' => 'Expecting']],
        'inputs'  => [['id' => SYNDAL_TOUR_EXPECTING_FIELD . '.1', 'label' => "We're expecting (baby not born yet)"]],
    ]);
    $pos = $after === null ? count($form['fields']) : $after + 1;
    array_splice($form['fields'], $pos, 0, [$child, $expecting]);
    return $form;
}

/**
 * Parent confirmation email ("Submission acknowledgement", the notification sent to the parent's email field).
 * Wording approved by Mani on 20 Oct 2026. Kept in code so it deploys with WP Pusher; sender settings are unchanged.
 * Merge tags: 6 = Full name, 8 = Tour date, 11 = Tour time.
 */
add_filter('gform_notification_' . SYNDAL_TOUR_FORM, function($notification, $form, $entry){
    $is_parent = rgar($notification, 'toType') === 'field' || stripos((string) rgar($notification, 'name'), 'acknowledg') !== false;
    if (!$is_parent) { return $notification; }

    $notification['subject'] = 'Your tour at Syndal Childcare is booked – {Tour date:8}, {Tour time:11}';
    $notification['disableAutoformat'] = true;
    // "Just reply to this email" must reach the centre, whatever the sender address is.
    if (trim((string) rgar($notification, 'replyTo')) === '') { $notification['replyTo'] = 'info1@syndalchildcare.com'; }
    $notification['message'] =
        '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#24263A;max-width:560px">'
      . '<p>Hi {Full name:6},</p>'
      . '<p>Thanks for booking a tour of Syndal Childcare &amp; ELC. We’re looking forward to meeting you.</p>'
      . '<p style="margin:20px 0 4px;font-weight:bold">Your tour</p>'
      . '<p style="margin:0">📅 {Tour date:8} at {Tour time:11}<br>📍 6 Huxtable St, Mount Waverley VIC 3149</p>'
      . '<p style="margin:20px 0 4px;font-weight:bold">What to expect</p>'
      . '<ul style="margin:0;padding-left:20px">'
      . '<li>A relaxed 15–20 minute visit. See our rooms, meet our educators, and ask anything you like.</li>'
      . '<li>Children are very welcome to come along.</li>'
      . '<li>It’s free, with no obligation to enrol.</li>'
      . '</ul>'
      . '<p style="margin-top:20px"><strong>Need to change your time?</strong> Just reply to this email or call us on '
      . '<a href="tel:0398027453" style="color:#2F7DF6">(03) 9802 7453</a>.</p>'
      . '<p style="margin-top:20px">See you soon,<br>The Syndal Childcare team<br>'
      . '<a href="https://syndalchildcare.com" style="color:#2F7DF6">syndalchildcare.com</a></p>'
      . '</div>';
    return $notification;
}, 20, 3);

/**
 * After-tour follow-up: a button in the STAFF notification that opens a ready-written Gmail draft (info1@) to the parent
 * with the Xplor enrolment form, Xplor waiting list and parent handbook links (Mani, 20 Oct 2026). Gmail adds her own signature. Nothing sends automatically.
 * Fields: 6 = Full name, 3 = Email, 950 = Child's name.
 */
const SYNDAL_XPLOR_ENROL_URL    = 'https://prodadmin.myxplor.com/enrollment_v2/centre/qyTCY47JXdXdivzaJH1b24356A';
const SYNDAL_XPLOR_WAITLIST_URL = 'https://prodadmin.myxplor.com/enrollment/index/cXlUQ1k0N0pYZFhkaXZ6YUpIMWIvQT09';
const SYNDAL_CENTRE_INBOX       = 'info1@syndalchildcare.com';

function syndal_followup_email($entry){
    $first = trim((string) (preg_split('/\s+/', trim((string) rgar($entry, '6')))[0] ?? ''));
    $subject = 'Thank you for visiting Syndal Childcare & ELC – next steps';
    $body = 'Dear ' . ($first !== '' ? $first : 'there') . ",\n\n"
          . "Thank you for visiting our centre. It was lovely to meet you.\n\n"
          . "Here are the links you'll need:\n\n"
          . "Enrolment form (if we've offered you a place):\n" . SYNDAL_XPLOR_ENROL_URL . "\n\n"
          . "Waiting list (if you'd like a place and haven't registered yet), with your preferred days and start date:\n" . SYNDAL_XPLOR_WAITLIST_URL . "\n\n"
          . "Parent handbook:\nhttps://syndalchildcare.com/parents-hand-book/\n\n"
          . "For the enrolment form, it helps to have your child's birth certificate and Immunisation History Statement ready to attach. "
          . "Enrolment needs to be completed before orientation: three free 1-hour sessions with your child before their first day. "
          . "Please make sure your Child Care Subsidy is set up in myGov before your child starts.\n\n"
          . "New enrolments currently receive \$50 off the first gap fee (terms: syndalchildcare.com/fees/).\n\n"
          . "If you have any questions, just reply to this email or call us on (03) 9802 7453.\n\n"
          . "Kind regards,\nThe Syndal Childcare team\nSyndal Childcare & ELC\n6 Huxtable St, Mount Waverley VIC 3149\n(03) 9802 7453\n";
    return [$subject, $body];
}

function syndal_followup_links($to, $subject, $body){
    $gmail = 'https://mail.google.com/mail/?' . http_build_query([
        'view' => 'cm', 'fs' => '1', 'authuser' => SYNDAL_CENTRE_INBOX, 'to' => $to, 'su' => $subject, 'body' => $body,
    ], '', '&', PHP_QUERY_RFC3986);
    $mailto = 'mailto:' . rawurlencode($to) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
    return [$gmail, $mailto];
}

add_filter('gform_notification_' . SYNDAL_TOUR_FORM, function($notification, $form, $entry){
    if (rgar($notification, 'toType') === 'field' || stripos((string) rgar($notification, 'name'), 'acknowledg') !== false) {
        return $notification;   // parent email: leave alone
    }
    $to = sanitize_email((string) rgar($entry, '3'));
    if ($to === '') { return $notification; }
    [$subject, $body] = syndal_followup_email($entry);
    [$gmail, $mailto] = syndal_followup_links($to, $subject, $body);
    $block = '<div style="margin-top:24px;padding:14px;border:1px solid #F28C28;border-radius:6px;font-family:Arial,sans-serif;font-size:14px">'
           . '<p style="margin:0 0 8px;font-weight:bold">After the tour</p>'
           . '<p style="margin:0 0 12px;color:#555">Opens a ready-written email to this family in Gmail with the enrolment form, waiting list and parent handbook links. Check it, then send.</p>'
           . '<a href="' . esc_url($gmail) . '" style="display:inline-block;background:#2F7DF6;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;font-weight:bold">✉️ Send follow-up email (Gmail)</a>'
           . '<p style="margin:10px 0 0;font-size:12px"><a href="' . esc_url($mailto, ['mailto']) . '" style="color:#555">or open in your email app</a></p>'
           . '</div>';
    $notification['message'] = (string) rgar($notification, 'message') . "\n" . $block;
    return $notification;
}, 30, 3);
