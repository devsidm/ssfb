=== SSF Medlemsfartyg ===
Contributors: sidm
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Hanterar och visar Sveriges Segelfartygsförbunds medlemsfartyg.

== Installation ==

1. Ladda upp katalogen `ssf-medlemsfartyg` till `wp-content/plugins/`.
2. Aktivera pluginet i WordPress admin.
3. Gå till Medlemsfartyg > Inställningar och kontrollera standardvärden.
4. Lägg shortcode `[ssf_medlemsfartyg]` på sidan Medlemsfartyg om den inte redan finns.

== Shortcodes ==

* `[ssf_medlemsfartyg]` visar samlingssidan med filter och fartygskort.
* `[ssf_utvalt_fartyg id="123"]` visar ett utvalt fartyg.
* `[ssf_fartyg_grid antal="4" status="anslutet"]` visar en grid med fartyg.

== Roller ==

Pluginet skapar rollen Fartygsombud (`ssf_fartygsombud`).
Administratören kopplar ombud till fartyg i metaboxen "Fartygsombud med åtkomst".

== Ombudsredigering ==

Fartygsombud går till Medlemsfartyg > Mina fartyg. Där visas bara fartyg som användaren är kopplad till.
Inställningen "Ändringar från fartygsombud kräver granskning" styr om ändringen markeras som väntande.

== Templates ==

Temat kan override:a templates genom att skapa:

* `theme/ssf-medlemsfartyg/archive-medlemsfartyg.php`
* `theme/ssf-medlemsfartyg/single-medlemsfartyg.php`
* `theme/ssf-medlemsfartyg/card-medlemsfartyg.php`

== GDPR ==

Kontaktuppgifter visas bara publikt när fältet "Visa kontaktuppgifter publikt" är aktiverat.
CSV-export med kontaktuppgifter är bara tillgänglig för administratörer.

== Export ==

Gå till Medlemsfartyg > Exportera CSV för att exportera grunddata och kontaktuppgifter.
