# Årsmötesmodulen

## Datamodell

`ssf_annual_meeting` är huvudobjekt och enda källa för titel, beskrivning, bild, helgens datum, plats, program och kalenderinformation. WordPress poststatus används för utkast/publicering.

Helgen konfigureras med ett startdatum och en längd på en, två eller tre dagar. Klockslag hör till enskilda programpunkter och är alltid valfria. Varje programpunkt väljer en dag samt typ: årsmöte, middag, presentation, aktivitet eller övrigt.

Modulerna lagras i `_ssf_am_modules` med nycklarna `invitation`, `meeting`, `dinner`, `day2`, `motions`, `documents` och `calendar`. `meeting` är alltid aktiv. Avstängda moduler behåller sin data men visas inte i frontend.

- Kallelse: `_ssf_am_invitation` med rubrik, text, publiceringstid, PDF-ID och synlighet.
- Program och aktiviteter: `_ssf_am_program`. En programpunkt blir en aktivitet när `requires_registration` är satt och kan vara middag eller ett annat arrangemang. Kapacitet och manuell stängning ligger på aktiviteten.
- Handlingar: `_ssf_am_documents`, en ordnad lista med attachment-ID, titel, dokumenttyp och synlighet.
- Program-PDF: `_ssf_am_program_pdf_id`.
- Plats: `_ssf_am_location`, `_ssf_am_address`, `_ssf_am_postal_code`, `_ssf_am_city` och valfri `_ssf_am_maps_url`. Saknas explicit kartlänk byggs en Google Maps-sökning från sparad plats och adress.

Ingen separat CPT eller kalenderpost skapas för modulerna.

## Anmälningar och motioner

Anmälningar använder befintlig CPT `ssf_meeting_registration` och har årsmötet som `post_parent` samt `_ssf_am_annual_meeting_id`. Val sparas gemensamt i `_ssf_am_program`, exempelvis `dinner` och `program_1`.

Själva årsmötet kräver aldrig anmälan. Formuläret gäller endast middag och aktiviteter. E-postadressen är unik per årsmöte; en befintlig aktiv anmälan ändras via dess personliga tokenlänk. Avbokning, e-postbekräftelse, Office 365-flöde, Excel-export och SharePoint-synk använder den befintliga arkitekturen.

Motioner behåller `_ssf_mp_annual_meeting_id` och befintlig motions-, behörighets- och SharePoint-logik.

## Kapacitet och deadline

Middag och varje aktivitet räknas separat. Avbokade och reservlistade poster tar inte en plats. När kapaciteten är nådd visas fullbokat och nya val stoppas även i servervalideringen.

Anmälans öppnings- och stängningsdatum styrs en gång per årsmöte och gäller alla aktiviteter, inklusive middag. Den äldre gemensamma kapaciteten och reservlistan finns kvar enbart för bakåtkompatibilitet.

## Statuslogik för anmälan

Anmälningsstatus beräknas centralt i `RegistrationService::registration_state()` och används av CTA-knappar, anmälningsformulär, servervalidering och adminstatus. Statusarna är `not_started`, `open`, `closed`, `meeting_passed`, `sold_out`, `disabled` och `no_choices`.

All tidslogik använder WordPress tidszon via `current_datetime()` och `wp_timezone()`. Exakt öppningstid är öppen, exakt stängningstid är fortfarande öppen och först efter stängningstid räknas anmälan som stängd. Årsmötets sluttid är en yttre spärr: när årsmöteshelgen har passerat stoppas anmälan även om en generell deadline råkar ligga senare.

Middag och aktiviteter har egen kapacitet, men delar alltid årsmötets gemensamma anmälningsperiod. Om ingen särskild period är angiven avslutas anmälan när årsmöteshelgen passerat.

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

Redigeraren för ett årsmöte har flikarna Översikt, Kallelse, Helg & plats, Program & aktiviteter, Motioner, Handlingar samt Anmälan & publicering. Kontakt går alltid via det publika kontaktformuläret och kräver därför ingen separat kontaktperson i årsmötesadministrationen. WordPress egna titel-, innehålls-, bild-, förhandsgransknings- och publiceringsfunktioner används.

Befintlig capability `manage_ssf_annual_meetings` används. Inga nya rättigheter eller Graph-behörigheter har införts.

## Frontend

Sid-ID:n och fallback-URL:erna är oförändrade:

- `/arsmote/` - aktivt årsmöte; ett arkiverat möte nås med `?meeting={ID}`
- `/arsmote/anmalan/` - gemensamt formulär för middag och aktiviteter
- `/arsmoten/` - arkiv
- `/lamna-motion/` - befintligt motionsformulär

Äldre länkar fortsätter därför att fungera. Frontend visar bara aktiva moduler med innehåll och staplas till en kolumn på mobil.

Årsmötessidan visar en enda H1-rubrik, normalt `Årsmöte {år}`. Den lokala ankarmenyn ligger direkt under rubriken och använder ankare för översikt, program, anmälan och motioner. Äldre ankare som `#ssf-am-meeting` och `#ssf-am-day2` finns kvar som kompatibilitetspunkter.

Publik kontakt sker via `/kontakta-oss/?annual_meeting_id={ID}` och knappen heter `Kontakta styrelsen`. Inga mottagaradresser eller mailto-länkar skrivs ut på årsmötessidan. Kontaktformuläret skickar endast möteskontext, medan mottagare väljs server-side och mailet skickas via befintlig `wp_mail`-arkitektur.

## Migration och avgränsningar

Läsningen är bakåtkompatibel: äldre programpunkter med `ask` tolkas som `requires_registration`, och modulstatus härleds från befintligt innehåll tills posten sparas i den nya redigeraren. Inga befintliga poster, anmälningar, motioner, dokument eller SharePoint-ID:n raderas.

Den befintliga säkra migreringsknappen kan fortsatt koppla äldre motioner och anmälningar till ett årsmöte. Duplicering av föregående års struktur och valfri omordning av huvudmoduler ingår inte i denna release; program och handlingar kan ordnas om.
