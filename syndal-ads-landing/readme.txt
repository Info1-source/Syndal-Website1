=== Syndal Ads Landing ===
Google Ads landing-page support for syndalchildcare.com. Installed on the live site with WP Pusher
(repository Info1-source/Syndal-Website1, subdirectory: syndal-ads-landing).

- /visit-syndal/?kw=<slug> swaps the H1 and intro to match the ad group's keyword (whitelisted slugs only).
- $50 off the first gap fee offer card + T&Cs on /fees/, /kinder/ and /visit-syndal/. Deactivate to end the offer.
- SYNDAL CHILDCARE site icon (favicon / Google result icon) at 32/180/192/270/512px.

Facts in the copy come from the site and the ads agent's offer.facts. Australian English.
- Google Ads click tracking (v1.0.5): gclid/gbraid/wbraid, click time, keyword (utm_term), ad group (utm_content)
  and landing page kept in a 90-day first-party cookie (syn_ads). Eight hidden fields are added to Book a Tour
  (form 2) at runtime (IDs 900-907, found by parameter name; nothing saved to the form in the database), filled
  from the cookie, and shown to staff as an "Ad source" block in the staff notification. Visitor emails unchanged.
