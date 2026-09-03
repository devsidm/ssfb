# Årsmötesmodulen

## Datamodell

`ssf_annual_meeting` är huvudobjekt och enda källa för titel, beskrivning, bild, helgens datum, plats, program och kalenderinformation. WordPress poststatus används för utkast/publicering.

Modulerna lagras i `_ssf_am_modules` med nycklarna `invitation`, `meeting`, `dinner`, `day2`, `motions`, `documents` och `calendar`. `meeting` är alltid aktiv. Avstängda moduler behåller sin data men visas inte i frontend.

- Kallelse: `_ssf_am_invitation` med rubrik, text, publiceringstid, PDF-ID och synlighet.
- Middag: `_ssf_am_dinner` med tider, plats, beskrivning, pris, deadline, kapacitet och matinställningar.
- Dag 2: `_ssf_am_program`. En programpunkt blir en aktivitet när `requires_registration` är satt. Samma post innehåller deadline, kapacitet och manuell status.
- Handlingar: `_ssf_am_documents`, en ordnad lista med attachment-ID, titel, dokumenttyp och synlighet.
- Program-PDF: `_ssf_am_program_pdf_id`.

Ingen separat CPT eller kalenderpost skapas för modulerna.

## Anmälningar och motioner

Anmälningar använder befintlig CPT `ssf_meeting_registration` och har årsmötet som `post_parent` samt `_ssf_am_annual_meeting_id`. Val sparas gemensamt i `_ssf_am_program`, exempelvis `dinner` och `program_1`.

Själva årsmötet kräver aldrig anmälan. Formuläret gäller endast middag och aktiviteter. E-postadressen är unik per årsmöte; en befintlig aktiv anmälan ändras via dess personliga tokenlänk. Avbokning, e-postbekräftelse, Office 365-flöde, Excel-export och SharePoint-synk använder den befintliga arkitekturen.

Motioner behåller `_ssf_mp_annual_meeting_id` och befintlig motions-, behörighets- och SharePoint-logik.

## Kapacitet och deadline

Middag och varje aktivitet räknas separat. Avbokade och reservlistade poster tar inte en plats. När kapaciteten är nådd visas fullbokat och nya val stoppas även i servervalideringen.

Deadline stänger respektive val automatiskt. Admin kan hålla ett val öppet efter deadline. Den äldre gemensamma kapaciteten och reservlistan finns kvar enbart för bakåtkompatibilitet.

## iCal och SSF-kalendern

Den publika iCal-filen genereras från årsmötesobjektet via `admin-post.php?action=ssf_member_portal_annual_meeting_calendar_public&meeting={ID}`. Den innehåller hela helgens tider, plats, beskrivning och publik URL. UID är stabilt: `annual-meeting-{ID}@ssfb.se`. Ingen token eller persondata ingår.

`ssf-calendar` läser publicerade `ssf_annual_meeting` direkt. Kalendermodulen kan stängas av på årsmötet. Ingen duplicerad manuell eventpost behövs.

## Admin

Huvudmenyn använder befintlig SSF-struktur:

- `admin.php?page=ssf` - översikt
- `edit.php?post_type=ssf_annual_meeting` - alla årsmöten
- `post-new.php?post_type=ssf_annual_meeting` - nytt årsmöte
- `admin.php?page=ssf-member-portal-meeting-registrations` - anmälningsöversikt
- `edit.php?post_type=ssf_meeting_registration` - deltagarlista och filter

Redigeraren för ett årsmöte har flikarna Översikt, Kallelse, Tider & plats, Middag, Dag 2, Motioner, Handlingar samt Kalender & publicering. WordPress egna titel-, innehålls-, bild-, förhandsgransknings- och publiceringsfunktioner används.

Befintlig capability `manage_ssf_annual_meetings` används. Inga nya rättigheter eller Graph-behörigheter har införts.

## Frontend

Sid-ID:n och fallback-URL:erna är oförändrade:

- `/arsmote/` - aktivt årsmöte; ett arkiverat möte nås med `?meeting={ID}`
- `/arsmote/anmalan/` - gemensamt formulär för middag och aktiviteter
- `/arsmoten/` - arkiv
- `/lamna-motion/` - befintligt motionsformulär

Äldre länkar fortsätter därför att fungera. Frontend visar bara aktiva moduler med innehåll och staplas till en kolumn på mobil.

## Migration och avgränsningar

Läsningen är bakåtkompatibel: äldre programpunkter med `ask` tolkas som `requires_registration`, och modulstatus härleds från befintligt innehåll tills posten sparas i den nya redigeraren. Inga befintliga poster, anmälningar, motioner, dokument eller SharePoint-ID:n raderas.

Den befintliga säkra migreringsknappen kan fortsatt koppla äldre motioner och anmälningar till ett årsmöte. Duplicering av föregående års struktur och valfri omordning av huvudmoduler ingår inte i denna release; program och handlingar kan ordnas om.
