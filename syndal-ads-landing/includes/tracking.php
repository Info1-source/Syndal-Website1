<?php
/**
 * Step 5 — profit tracking. Carries the Google click (gclid / gbraid / wbraid), its time and the search
 * keyword from the ad landing to the "Book a Tour" form (Gravity Forms form 2), and shows them to staff.
 *
 * How it works:
 *  1. A tiny inline script on every front-end page reads the click params from the URL and keeps them in
 *     a first-party cookie (syn_ads) for 90 days, with the click time. A new ad click replaces the old one;
 *     visits without click params change nothing.
 *  2. The hidden tracking fields are added to form 2 at runtime (nothing is saved to the form in the
 *     database), so the same plugin works on local and live. Fields are always found by parameter name.
 *  3. The script fills the hidden fields from the cookie when the form renders (LiteSpeed-cache safe).
 *  4. On submit, PHP re-reads the same cookie and writes the values itself. That is what gets saved, so a
 *     value baked into a cached page can never end up on another family's entry.
 *  5. The staff notification (not the visitor confirmation) gets a compact "Ad source" block. The fields
 *     are kept out of {all_fields} so every other email and message stays exactly as it was.
 *
 * Only click IDs, click time, keyword, ad group ID, landing path and utm_source are stored. No personal data.
 */
if (!defined('ABSPATH')) { exit; }

const SYNDAL_TRK_FORM     = 2;
const SYNDAL_TRK_COOKIE   = 'syn_ads';
const SYNDAL_TRK_ID_START = 900;   // runtime field IDs start here, clear of the form's own fields

// Hidden-field parameter name => cookie key. Order is fixed: it decides each runtime field's ID.
function syndal_trk_fields(){
    return [
        'gclid'        => 'gclid',
        'gbraid'       => 'gbraid',
        'wbraid'       => 'wbraid',
        'keyword'      => 'utm_term',
        'ad_group_id'  => 'utm_content',
        'landing_page' => 'landing_page',
        'utm_source'   => 'utm_source',
        'click_time'   => 'click_time',
    ];
}

// The visitor's tracking cookie, cleaned. Missing or broken cookie = empty array.
function syndal_trk_cookie(){
    if (empty($_COOKIE[SYNDAL_TRK_COOKIE]) || !is_string($_COOKIE[SYNDAL_TRK_COOKIE])) { return []; }
    $data = json_decode(wp_unslash($_COOKIE[SYNDAL_TRK_COOKIE]), true);
    if (!is_array($data)) { return []; }
    $clean = [
        'gclid'        => '/[^A-Za-z0-9_\-]/',
        'gbraid'       => '/[^A-Za-z0-9_\-]/',
        'wbraid'       => '/[^A-Za-z0-9_\-]/',
        'utm_content'  => '/[^0-9]/',
        'landing_page' => '#[^A-Za-z0-9/_\-.]#',
        'click_time'   => '/[^0-9:+\- ]/',
    ];
    $out = [];
    foreach ($clean as $k => $re) {
        if (!empty($data[$k]) && is_string($data[$k])) { $out[$k] = substr(preg_replace($re, '', $data[$k]), 0, 200); }
    }
    foreach (['utm_term', 'utm_source', 'utm_campaign'] as $k) {
        if (!empty($data[$k]) && is_string($data[$k])) { $out[$k] = substr(sanitize_text_field($data[$k]), 0, 120); }
    }
    return array_filter($out, 'strlen');
}

// Form 2 hidden tracking fields, looked up by parameter name: name => field ID.
function syndal_trk_field_ids($form){
    $ids   = [];
    $names = syndal_trk_fields();
    foreach ((array) rgar($form, 'fields') as $field) {
        $name = is_object($field) ? (string) $field->inputName : '';
        if ($name !== '' && isset($names[$name]) && $field->type === 'hidden' && !isset($ids[$name])) { $ids[$name] = (int) $field->id; }
    }
    return $ids;
}

// 1. Add the hidden fields to form 2 whenever it's loaded (display, submission, entries, export).
//    Skipped inside the form editor and form settings, so saving there never writes them to the database.
add_filter('gform_form_post_get_meta_' . SYNDAL_TRK_FORM, function($form){
    if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'gf_edit_forms') { return $form; }
    if (!class_exists('GF_Fields') || empty($form['fields']) || !is_array($form['fields'])) { return $form; }
    $have  = syndal_trk_field_ids($form);
    $taken = [];
    foreach ($form['fields'] as $f) { $taken[(int) $f->id] = true; }
    $i = 0;
    foreach (array_keys(syndal_trk_fields()) as $name) {
        $id = SYNDAL_TRK_ID_START + $i++;
        if (isset($have[$name])) { continue; }
        if (isset($taken[$id])) {   // never collide with a real field; log so it gets noticed
            error_log("Syndal Ads Landing: form " . SYNDAL_TRK_FORM . " field ID $id is taken, tracking field '$name' not added.");
            continue;
        }
        $form['fields'][] = GF_Fields::create([
            'type' => 'hidden', 'id' => $id, 'formId' => SYNDAL_TRK_FORM, 'label' => 'Ad: ' . $name,
            'inputName' => $name, 'allowsPrepopulate' => true, 'cssClass' => 'syn-trk-' . $name,
        ]);
    }
    return $form;
});

// 2. Make sure every tracking field's wrapper carries its class, so the script can find it.
add_filter('gform_pre_render_' . SYNDAL_TRK_FORM, 'syndal_trk_tag_fields');
add_filter('gform_pre_validation_' . SYNDAL_TRK_FORM, 'syndal_trk_tag_fields');
function syndal_trk_tag_fields($form){
    $ids = array_flip(syndal_trk_field_ids($form));
    foreach ($form['fields'] as $field) {
        if (isset($ids[$field->id])) {
            $class = 'syn-trk-' . $ids[$field->id];
            if (strpos((string) $field->cssClass, $class) === false) { $field->cssClass = trim($field->cssClass . ' ' . $class); }
        }
    }
    return $form;
}

// 3. Capture script (front end only). Kept inline and under 2 KB.
add_action('wp_footer', function(){
    if (is_admin()) { return; }
    $map = wp_json_encode(syndal_trk_fields());
    ?>
<script id="syndal-trk">(function(){var C='<?php echo SYNDAL_TRK_COOKIE; ?>',M=<?php echo $map; ?>,I=['gclid','gbraid','wbraid'],K=I.concat(['utm_source','utm_term','utm_content','utm_campaign']),q=new URLSearchParams(location.search),c={},n=0;
function p(x){return('0'+Math.abs(x)).slice(-2)}
function T(){var d=new Date(),o=-d.getTimezoneOffset();return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds())+(o<0?'-':'+')+p(o/60|0)+':'+p(o%60)}
try{var m=document.cookie.match(new RegExp('(?:^|; )'+C+'=([^;]*)'));c=m?JSON.parse(decodeURIComponent(m[1]))||{}:{}}catch(e){c={}}
if(I.some(function(k){return q.get(k)})){c={};c.click_time=T()}
K.forEach(function(k){var v=(q.get(k)||'').slice(0,200);if(v){c[k]=v;n=1}});
if(n){c.landing_page=location.pathname;document.cookie=C+'='+encodeURIComponent(JSON.stringify(c))+';max-age=7776000;path=/;SameSite=Lax'+(location.protocol=='https:'?';Secure':'')}
function f(){for(var k in M){var e=document.querySelectorAll('.syn-trk-'+k+' input');for(var i=0;i<e.length;i++)e[i].value=c[M[k]]||''}}
f();document.addEventListener('DOMContentLoaded',f);document.addEventListener('gform/post_render',f);if(window.jQuery)jQuery(document).on('gform_post_render',f)})();</script>
    <?php
}, 5);

// 4. On submit, the server-side cookie is the source of truth (overwrites anything a cached page carried).
add_action('gform_pre_submission_' . SYNDAL_TRK_FORM, function($form){
    $cookie = syndal_trk_cookie();
    $names  = syndal_trk_fields();
    foreach (syndal_trk_field_ids($form) as $name => $id) {
        $_POST['input_' . $id] = isset($cookie[$names[$name]]) ? $cookie[$names[$name]] : '';
    }
});

// 5a. Keep the tracking fields out of {all_fields}, so existing emails and messages don't change.
add_filter('gform_merge_tag_filter', function($value, $merge_tag, $modifier, $field){
    if ($merge_tag === 'all_fields' && is_object($field) && (int) $field->formId === SYNDAL_TRK_FORM
        && $field->type === 'hidden' && isset(syndal_trk_fields()[(string) $field->inputName])) {
        return false;
    }
    return $value;
}, 10, 4);

// 5b. "Ad source" block on the staff notification only. Visitor emails (sent to the form's email field) are untouched.
add_filter('gform_notification_' . SYNDAL_TRK_FORM, function($notification, $form, $entry){
    if (rgar($notification, 'toType') === 'field') { return $notification; }
    $ids = syndal_trk_field_ids($form);
    if (!$ids) { return $notification; }
    $labels = ['gclid' => 'gclid', 'gbraid' => 'gbraid', 'wbraid' => 'wbraid', 'click_time' => 'Click time', 'keyword' => 'Keyword',
               'ad_group_id' => 'Ad group ID', 'landing_page' => 'Landing page', 'utm_source' => 'Source'];
    $rows = '';
    foreach ($labels as $name => $label) {
        $value = isset($ids[$name]) ? (string) rgar($entry, (string) $ids[$name]) : '';
        if ($value !== '') { $rows .= '<tr><td style="padding:2px 12px 2px 0;color:#555">' . esc_html($label) . '</td><td style="padding:2px 0;font-family:monospace">' . esc_html($value) . '</td></tr>'; }
    }
    $body = $rows === ''
        ? '<p style="margin:0;color:#555">No Google Ads click recorded (organic, direct or another source).</p>'
        : '<table style="border-collapse:collapse;font-size:13px">' . $rows . '</table>';
    $block = '<div style="margin-top:24px;padding:12px 14px;border:1px solid #ddd;border-radius:6px;font-size:13px">'
           . '<p style="margin:0 0 6px;font-weight:bold">Ad source</p>' . $body . '</div>';
    $notification['message'] = (string) rgar($notification, 'message') . "\n" . $block;
    return $notification;
}, 10, 3);
