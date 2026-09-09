# SSF antispam

## Arkitektur

SSF:s egna publika formulär använder ett gemensamt lager i `wp-content/mu-plugins/ssf-antispam.php`:

```text
formulär
  -> WordPress nonce
  -> honeypot
  -> rate limit
  -> Cloudflare Turnstile Siteverify
  -> validering och sanering
  -> lagring, filer, SharePoint och e-post
```

Alla kontroller är server-side. En Turnstile-token verifieras endast vid slutlig submit och kan därför inte återanvändas mellan steg. Formulären använder vanlig POST till `admin-post.php`; inga SSF-formulär som skyddas här använder AJAX.

## Inventering

| Formulär | Plugin och submit | Före | Efter | Standard |
| --- | --- | --- | --- | --- |
| Kontakt | SSF Site Customizations, `ssf_contact` | Nonce, honeypot, lokalt rate limit | Nonce, central honeypot, Turnstile, centralt rate limit | Turnstile på |
| Äldre fartygsansökan | SSF Site Customizations, `ssf_application` | Nonce | Nonce, central honeypot, Turnstile, centralt rate limit | Turnstile på |
| Medlemsansökan för fartyg | SSF Medlemsprocess, `ssf_submit_application` | Nonce, honeypot, enkelt rate limit | Nonce, central honeypot, Turnstile, centralt rate limit | Turnstile på |
| Komplettering av medlemsansökan | SSF Medlemsprocess, `ssf_submit_completion` | Personlig token och nonce | Personlig token, nonce, central honeypot och rate limit; Turnstile valbart | Turnstile av |
| Fartygsuppgifter via insamlingslänk | SSF Medlemsfartyg, `ssf_submit_ship_collection` | Personlig token, nonce, honeypot | Personlig token, nonce, central honeypot och rate limit; Turnstile valbart | Turnstile av |
| Motion | SSF Medlemsportal, `ssf_member_portal_submit_motion` | Nonce, honeypot | Nonce, central honeypot, Turnstile, centralt rate limit | Turnstile på |
| Årsmötesanmälan | SSF Medlemsportal, `ssf_member_portal_submit_meeting_registration` | Nonce, honeypot, lokalt rate limit | Nonce, central honeypot, Turnstile, centralt rate limit | Turnstile på |

Avbokning, kalenderhämtning, fartygsombudets inloggade redigering och inspektörs-/adminformulär får inte Turnstile. De är autentiserade eller tokenbaserade åtgärder där CAPTCHA inte ger rimlig nytta.

## Konfiguration

`Simple CAPTCHA with Cloudflare Turnstile` är standardplugin och dess WordPress-alternativ för site key och secret key är den enda nyckelkällan. DEV och PROD har separata databaser och konfigureras därför separat. SSF-komponenten återanvänder pluginets scriptregistrering när den finns, men verifierar de egna formulären med en tunn adapter mot Cloudflares officiella Siteverify-API.

Inställningar och test finns under **SSF -> System -> Antispam**. Cloudflares officiella testnycklar kan aktiveras där endast när `wp_get_environment_type()` är `development`. Kända testnycklar blockeras alltid i `production`.

De öppna formulären använder fail closed. Om nycklar saknas eller Cloudflare inte kan verifiera en token stoppas inlämningen innan data, filer, SharePoint eller e-post behandlas. Administratörer med `manage_options` undantas i vanliga frontendflöden, men adminsidans särskilda Turnstile-test verifieras alltid.

## Logg och integritet

De senaste 50 händelserna lagras i `ssf_antispam_events`. Händelser innehåller endast UTC-tid, formulärnyckel, accepterad/blockerad, teknisk orsak och WordPress-miljö. Formulärinnehåll, e-post, token, rå IP och hemligheter loggas inte. Rate limit använder en installation-specifik HMAC av formulärnyckel och IP och lagrar inte IP-adressen.
