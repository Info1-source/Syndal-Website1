<?php
/**
 * Plugin Name: Syndal Ads Landing
 * Description: Google Ads landing-page support — search-matched headlines on /visit-syndal/?kw=…, the $50 first-gap-fee offer on /fees/, /kinder/ and /visit-syndal/, the SYNDAL CHILDCARE site icon, and Google Ads click tracking on the Book a Tour form. Deployed with WP Pusher.
 * Version: 1.0.19
 * Author: Syndal Childcare & ELC
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }

// Step 5 profit tracking: gclid + keyword from ad click -> Book a Tour form 2 -> staff email. Added 2026-10-20.
require_once __DIR__ . '/includes/tracking.php';
require_once __DIR__ . '/includes/form-tweaks.php';
require_once __DIR__ . '/includes/calendar.php';   // Book a Tour -> Google Calendar (info1@) via Apps Script, 2026-10-20

// Site icon (favicon / Google result icon): serve the plugin's SYNDAL CHILDCARE icon at every size WordPress asks for.
add_filter('get_site_icon_url', function($url, $size){
    $sizes = [32, 180, 192, 270, 512];
    $pick = 512;
    foreach ($sizes as $s) { if ($s >= (int)$size) { $pick = $s; break; } }
    return plugins_url('assets/site-icon-' . $pick . '.png', __FILE__);
}, 10, 2);
add_filter('site_icon_meta_tags', function($tags){
    if (!has_site_icon()) {   // no icon chosen in Customizer yet: still output ours
        $tags[] = '<link rel="icon" href="' . esc_url(plugins_url('assets/site-icon-32.png', __FILE__)) . '" sizes="32x32" />';
        $tags[] = '<link rel="icon" href="' . esc_url(plugins_url('assets/site-icon-192.png', __FILE__)) . '" sizes="192x192" />';
        $tags[] = '<link rel="apple-touch-icon" href="' . esc_url(plugins_url('assets/site-icon-180.png', __FILE__)) . '" />';
    }
    return $tags;
});

// Message match (Google Ads SKAG rule): /visit-syndal/?kw=<slug> swaps the H1 + intro to echo the search.
// Whitelisted slugs only; anything else keeps the normal page. Facts only from the site / offer.facts. Added 2026-10-19.
if (!function_exists('syndal_landing_headlines')) { function syndal_landing_headlines(){
    return [
        'childcare-near-me' => ['Childcare near you<span class="syn-ads-h1-sub">Family-owned in Mount Waverley for 25+ years.</span>',
            'A family-owned centre at 6 Huxtable St, Mount Waverley. Meals cooked on site, nappies included, open 7am–6pm weekdays.'],
        'daycare-near-me' => ['Daycare near you<span class="syn-ads-h1-sub">Meals and nappies included.</span>',
            'Fresh meals cooked on site and nappies supplied, all in your fees. Open 7am–6pm weekdays in Mount Waverley.'],
        'early-learning-centre-near-me' => ['Early learning centre near you<span class="syn-ads-h1-sub">Rated 4.9 on Google by local families.</span>',
            'Care and learning from 6 weeks to school age, with a school readiness program and kinder led by bachelor-qualified teachers.'],
        'long-day-care-near-me' => ['Long day care near you<span class="syn-ads-h1-sub">Open 7am to 6pm, 52 weeks a year.</span>',
            'An 11-hour day, 52 weeks a year, with four meals cooked on site and nappies included. Family-owned in Mount Waverley.'],
        'best-childcare-near-me' => ['The best childcare near you?<span class="syn-ads-h1-sub">Parents rate us 4.9 on Google.</span>',
            'Family-owned for 25+ years in Mount Waverley. Come and see the rooms, meet our educators and decide for yourself.'],
        'childcare-mount-waverley' => ['Childcare in Mount Waverley<span class="syn-ads-h1-sub">From 6 weeks to school age, family-owned for 25+ years.</span>',
            'Baby, toddler and kinder rooms at 6 Huxtable St. Family-owned for 25+ years, with meals cooked on site and nappies included.'],
        'childcare-glen-waverley' => ['Childcare near Glen Waverley<span class="syn-ads-h1-sub">Just next door, at 6 Huxtable St, Mount Waverley.</span>',
            'A family-owned centre at 6 Huxtable St, rated 4.9 on Google. Meals cooked on site, nappies included, open 7am–6pm weekdays.'],
    ];
} }
add_filter('the_content', function($content){
    if (!is_page('visit-syndal') || empty($_GET['kw']) || !is_string($_GET['kw'])) { return $content; }
    $map = syndal_landing_headlines();
    $kw  = sanitize_key(wp_unslash($_GET['kw']));
    if (!isset($map[$kw])) { return $content; }
    [$h1, $lead] = $map[$kw];
    $h1 .= '<span class="syn-ads-h1-offer">Enrol now and get $50 off your first gap fee.</span>';
    $content = preg_replace_callback('#(<h1 id="syn-ads-title">).*?(</h1>)#s', function($m) use ($h1){ return $m[1] . $h1 . $m[2]; }, $content, 1);
    return preg_replace_callback('#(<p class="syn-ads-lead">).*?(</p>)#s', function($m) use ($lead){ return $m[1] . esc_html($lead) . $m[2]; }, $content, 1);
}, 20);

// Fee calculator (replaces the mu-plugin copy): extra care on the sessional kinder tab now shows its real gap instead of "FREE".
add_action('init', function(){
    add_shortcode('syndal_fee_calculator', function(){
        $f = __DIR__ . '/assets/fee-calculator.html';
        return file_exists($f) ? file_get_contents($f) : '';
    });
}, 20);

// $50 enrolment offer (Google requires an ad's offer to appear on its landing page). Deactivate the plugin to end it.
function syndal_offer_terms(){
    return '<p class="syn-offer-terms"><strong>$50 offer terms:</strong> $50 credit applies to the gap fee (the amount payable after Child Care Subsidy) on the first invoice after a new enrolment commences. One credit per enrolment (per child). New enrolments only; not available to children already enrolled. Not redeemable for cash or transferable. Syndal Childcare &amp; ELC may change or end this offer at any time; credits for enrolments already confirmed will be honoured.</p>';
}

function syndal_offer_card($heading, $text, $button = true){
    $btn = $button ? '<a class="syn-offer-btn" href="' . esc_url(home_url('/visit-syndal/')) . '">Book a free tour</a>' : '';
    return '<aside class="syn-offer" aria-label="New enrolment offer"><p class="syn-offer-tag">New families</p><h2 class="syn-offer-title">' . $heading . '</h2><p class="syn-offer-text">' . $text . '</p>' . $btn . syndal_offer_terms() . '</aside>';
}

add_filter('the_content', function($c){
    if (is_page('fees')) {
        $card = syndal_offer_card('Enrol and get <span>$50 off</span> your first gap fee',
            'Use the calculator to see your gap fee, then book a free tour. If Syndal feels right, $50 comes off your first gap fee.');
        $at = strpos($c, '<div class="wp-block-group syn-sec syn-included');
        return $at === false ? $card . $c : substr($c, 0, $at) . '<div class="syn-offer-wrap">' . $card . '</div>' . substr($c, $at);
    }
    if (is_page('kinder')) {
        $card = syndal_offer_card('Joining long day care? Get <span>$50 off</span> your first gap fee',
            'Free Sessional Kinder is already free. If your child joins our long day care, integrated kinder or before and after kinder care, $50 comes off your first gap fee.');
        $at = strpos($c, '<div class="wp-block-group syn-sec syn-sessional');
        return $at === false ? $card . $c : substr($c, 0, $at) . '<div class="syn-offer-wrap syn-offer-wrap-kinder">' . $card . '</div>' . substr($c, $at);
    }
    if (is_page('visit-syndal') && strpos($c, '$50') === false) {   // live page already has it; this keeps local in step
        $strip = '<p class="syn-offer-strip">Enrol at Syndal and get <strong>$50 off your first gap fee</strong>. Book a free tour, meet our educators, and if Syndal feels right, $50 comes off your first gap fee.</p>';
        $h = '<h2 id="syn-booking-title">Book a tour</h2>';
        if (($at = strpos($c, $h)) !== false) { $c = substr_replace($c, $h . $strip, $at, strlen($h)); }
        $pv = '<p class="syn-ads-privacy">';
        if (($at = strpos($c, $pv)) !== false) { $c = substr_replace($c, syndal_offer_terms() . $pv, $at, strlen($pv)); }
    }
    return $c;
}, 20);

add_action('wp_head', function(){
    if (!is_page(['fees', 'kinder', 'visit-syndal'])) { return; }
    echo '<style id="syndal-offer">
.syn-ads-h1-offer{display:block;margin-top:.5em;font-size:.4em;line-height:1.3;font-weight:700;color:#B85A00}
.syn-ads-h1-sub{display:block;margin-top:.45em;font-size:.5em;line-height:1.25;font-weight:500;color:var(--syn-ink,inherit);opacity:.85}
.syn-offer-wrap{--syn-offer-bg:#E7F0FF;background:var(--syn-offer-bg);box-shadow:0 0 0 100vmax var(--syn-offer-bg);clip-path:inset(0 -100vmax);padding:0 0 56px;margin-top:0!important;margin-bottom:0!important}
.syn-offer-wrap-kinder{--syn-offer-bg:#FFFBF5}
.syn-offer-wrap .syn-offer{max-width:1076px;margin:0 auto}
.syn-offer{background:#FFF1E3;border:2px solid #F28C28;border-radius:22px;padding:28px 30px;margin:8px 0 40px;color:#24263a}
.syn-offer p.syn-offer-tag,.syn-offer-tag{margin:0 0 6px;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#8A4500}
.syn-offer h2.syn-offer-title,.syn-offer-title{margin:0 0 10px;font-family:var(--syn-head,inherit);font-size:clamp(24px,2.6vw,32px)!important;line-height:1.15;color:#24263a}
.syn-offer-title span{color:#B85A00}
.syn-offer p.syn-offer-text,.syn-offer-text{margin:0 0 18px;font-size:17px;line-height:1.55;max-width:720px}
.syn-offer a.syn-offer-btn{color:#fff!important}
.syn-offer-btn{display:inline-flex;align-items:center;min-height:48px;padding:0 24px;border-radius:999px;background:#2347A8;color:#fff;font-weight:700;text-decoration:none}
.syn-offer-btn:hover,.syn-offer-btn:focus-visible{background:#1A3680;color:#fff}
.syn-offer-btn:focus-visible{outline:3px solid #24263a;outline-offset:3px}
.syn-offer p.syn-offer-terms,.syn-offer-terms{margin:18px 0 0;font-size:13px;line-height:1.5;color:#4a4c5e}
.syn-offer-strip{background:#FFF1E3;border-left:4px solid #F28C28;border-radius:10px;padding:12px 14px;margin:10px 0 14px;font-size:15px;line-height:1.5;color:#24263a}
.syn-ads-booking .syn-offer-terms{font-size:12px;margin:14px 0 6px}
@media(max-width:781px){.syn-offer{padding:22px 20px;border-radius:18px}.syn-offer-text{font-size:16px}}
</style>';
});
