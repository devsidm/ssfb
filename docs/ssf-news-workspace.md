# Nyheter i SSF Arbetsyta

Nyheter är en modul i `ssf-arbetsyta`. Publicerade nyheter och utkast är fortsatt vanliga WordPress-inlägg; `_ssf_news_type` skiljer SSF (`ssf`), medlemsfartyg (`ship`) och extern journalistik (`media`). Förslag och bevakningskällor använder två privata CPT:er utan admin-UI eller publika REST-rutter.

Den befintliga SSF-gruppen `nyheter` visas som **Webbredaktör**. `nyhetsutkast` visas som **Nyhetsskribent** och saknar publicering och bevakningsadministration. `manage_options` ger full åtkomst. Autentiserade aktiva fartygsombud med befintlig roll eller canonical fartygsrelation kan tipsa; ingen anonym tipsroute finns.

## Redaktionens flöde

`/arbetsyta/nyheter/` innehåller Översikt, Egna nyheter, I medierna, Artikelförslag och Omvärldsbevakning. Manuell URL, medlemstips och bevakning skapar samma förslag. Canonical URL utan spårningsparametrar ger deduphash. Dismiss behåller posten och hashvärdet. Konvertering återanvänder ett redan skapat utkast och kopierar inte metadata-beskrivningen till SSF:s egen sammanfattning.

Spara och förhandsgranska sparar utkastet och visar samma kort som frontend, inne i Arbetsytan. Publicering och avpublicering kräver `ssf_news_publish` eller `manage_options`. Normal redaktionell hantering kräver ingen wp-admin-sida; formulär använder endast WordPress befintliga skyddade admin-post-transport.

## Bevakning och metadata

WP-Cron kör `ssf_news_monitor_sources` två gånger per dygn och köar enskilda källor med `ssf_news_check_source`. Kontrollera nu köar samma bakgrundsarbete. RSS/Atom prioriteras; HTML-bevakning läser en konfigurerad sida och högst 25 länkar på samma värd. Ingen JavaScript-crawler används. Enkel matchning använder publicerade canonical medlemsfartyg och ämnen/nyckelord. Källor felisoleras och visar senast kontrollerad och begriplig resultatstatus.

URL-hämtning använder `wp_safe_remote_get`, förbjuder privata/reserverade adresser, validerar varje redirect och begränsar redirects, timeout, Content-Type och svarsstorlek. Endast metadata tolkas; extern HTML renderas inte.

Utökningspunkter: `ssf_news_metadata`, `ssf_news_relevance_match` och `ssf_news_suggestion_created`. En framtida AI-provider kan lämna redaktionella förslag via dessa hooks. Ingen AI-provider eller automatisk publicering är aktiverad.

## Externa bilder och integritet

`external_preview` lagrar endast bild-URL; ingen nedladdning till mediabibliotek eller bildproxy/cache görs. Besökarens webbläsare kontaktar den externa bildservern, med `no-referrer` och lazy loading. En matchande källa måste uttryckligen tillåta detta. Källa avstängd, bild saknad eller nätverksfel ger textkort. `ssf_image` använder WordPress egna bildbilagor och fotograf/källa/rättighetsnotering. `none` visar textkort.

## Riktade lokala tester

`php scripts/tests/ssf-workspace-tests.php` testar service-registry och åtkomst. `php scripts/tests/ssf-news-tests.php` testar URL-skydd, redirects, storleksgräns, metadata, dedup, dismissed-history, schemaläggning och felisolering med en kontrollerad transport och in-memory datalager. Browser-/DEV-tester behövs dessutom för publicering och mobil visuell QA.
