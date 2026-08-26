# SSF Stadgar & Dokument

`ssf-stadgar` är en avgränsad dokumentplattform i WordPress för SSF:s stadgar och styrdokument. Den är byggd som ett eget plugin eftersom den har en egen datamodell, versionshantering, dokumentrelationer och PDF-analys. Det befintliga temat ansvarar fortsatt för header, navigation, typografi och grundkomponenter; pluginet levererar funktion och innehåll på sidan `/stadgar/` via kortkoden `[ssf_stadgar]`.

## Arkitektur och filer

- `wp-content/plugins/ssf-stadgar/includes/class-ssf-stadgar-document.php`: custom post type, metadata, versionsregler och dokumentrelationer.
- `wp-content/plugins/ssf-stadgar/includes/class-ssf-stadgar-admin.php`: WordPress-admin, PDF-val, granskning och sparlogik.
- `wp-content/plugins/ssf-stadgar/includes/class-ssf-stadgar-extractor.php`: försiktig extraktion av PDF-text och paragrafrubriker.
- `wp-content/plugins/ssf-stadgar/includes/class-ssf-stadgar-public.php`: publik struktur, ankarlänkar, relaterade dokument och versionshistorik.
- `wp-content/plugins/ssf-stadgar/assets/`: lokala admin- och frontendstilar samt admininteraktion.

## Datamodell

Varje `ssf_document` använder WordPress titel, redigerare, revisionshistorik och mediabibliotek. Metadata lagrar dokumenttyp, version, antagningsdatum, antagen av, kort beskrivning, ändringsanteckning, PDF-id, extraherad text, snabböversikt, relationer, sorteringsordning och markering för gällande version. WordPress sköter själv skapat och ändrat datum.

Dokument har status `Utkast`, `Publicerad` eller `Arkiverad`. Bara ett publicerat dokument av typen `Stadgar` kan vara gällande. När en ny stadga publiceras som gällande markeras den tidigare som arkiverad och ligger kvar i versionshistoriken.

## PDF-analys och fallback

När en PDF väljs försöker pluginet läsa dokumentets textlager och identifiera paragrafrubriker. Resultatet visas som en preliminär snabböversikt och den extraherade texten visas i admin som ett granskningsunderlag. Administratören måste själv kontrollera och spara snabböversikten; analysen publicerar aldrig innehåll eller ersätter webbtext automatiskt.

Om PDF:en saknar läsbart textlager kan PDF:en fortfarande publiceras. Administratören skriver då webbversionen i redigeraren och snabböversikten manuellt. Det här är avsiktligt: publicering får aldrig vara beroende av OCR eller automatisk tolkning.

## Så publicerar du nya stadgar

1. Öppna **Stadgar & dokument** i WordPress-admin och välj **Lägg till dokument**.
2. Ange titel, typ `Stadgar`, version samt uppgifter om antagande.
3. Välj original-PDF från mediabiblioteket och klicka **Analysera PDF** efter att dokumentet sparats.
4. Granska den extraherade texten och korrigera snabböversikten. Varje rad har formatet `Rubrik | ankare`.
5. Skriv den tillgängliga webbversionen i redigeraren. Använd en rubrik för varje paragraf så att snabböversikten får stabila direktlänkar.
6. Markera **Detta är gällande version av stadgarna** och bekräfta ändringen.
7. Välj **Publicerad** och uppdatera dokumentet. Den tidigare gällande versionen arkiveras automatiskt.

## Publik sida

Sidan visar alltid gällande stadgar först med status, version, antagningsuppgifter, tillgänglig webbversion och en tydligt namngiven PDF-länk. Därefter visas snabböversikt, stadgetext med direkta ankarlänkar, relaterade dokument och tidigare versioner. Ingressen och texten i kortet för gällande version redigeras under **Stadgar & dokument > Inställningar**.
