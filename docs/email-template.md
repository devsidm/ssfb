# Gemensam SSF-mall för e-post

`wp-content/mu-plugins/ssf-email-template.php` är presentationslagret för
externa transaktionsmejl. Affärsmodulerna lämnar typad meddelandedata till
renderern, som skapar HTML och ren text. Utskicket går därefter genom befintlig
`wp_mail`, Microsoft 365 Mailer och befintliga DEV-regler.

## Gemensam header

Alla HTML-mail från den centrala renderern använder samma `render_header()`. Headern har den befintliga marinblå profilfärgen `#12324a`, en tabellbaserad tvåkolumnslayout och textidentiteten **SVERIGES SEGELFARTYGSFÖRBUND** samt **SVERIGES SEGLANDE KULTURARV**. En egen tabellcell bildar separatorn mellan logga och text för stabil rendering i Outlook. Under 480 px staplas logga och text och separatorn döljs.

En särskild e-postlogga väljs under **SSF -> System -> Microsoft 365 -> Organisationsuppgifter och e-postdesign -> Visuell identitet**. Attachment-ID sparas miljöspecifikt i WordPress. Renderern använder den publika HTTPS-adressen till originalbilden, avsedd för en vit transparent PNG på minst 500 px bredd som visas med 145 px HTML-bredd.

Ingen SVG eller CSS-filter används som fallback. Om ingen logga är vald, eller om attachment-filen har raderats, visas organisationens namn och tagline centrerade utan bild. Det ger ingen broken-image-symbol och utskicket fortsätter; en administrativ varning och en logghändelse skapas för ett saknat valt attachment.

```text
Affärsmodul -> malltyp -> e-postkategori -> sidfotskontakt -> SSF_Email_Template -> wp_mail -> aktiv transport
```

## Kategorier och kontakt

Varje extern malltyp har en kategori i den centrala `templates()`-definitionen.
Renderern använder kategorin för både den synliga kontakten i HTML- och
textsidfoten och för `Reply-To`. Ett `Reply-To` som uttryckligen skickats in av
anroparen bevaras. Teknisk avsändare och aktiv Microsoft 365-transport ändras
inte.

| Kategori | Standardkontakt | Migrerade malltyper |
| --- | --- | --- |
| Årsmöte (`annual_meeting`) | `styrelsen@ssfb.se` | Årsmötesanmälan, ändrad anmälan, motion mottagen och motionsstatus |
| Medlem (`membership`) | `medlem@ssfb.se` | Ansökan, status, komplettering, fartygsuppgifter och inspektörsuppdrag |
| Allmänt (`general`) | `info@ssfb.se` | Kontaktbekräftelse och okända framtida typer |

En okänd malltyp renderas som ett allmänt meddelande och skickas vidare med
den allmänna kontakten. Händelsen sparas som administrativ varning och loggas
utan mottagaradress eller meddelandeinnehåll.

## Inventering

| Område | Trigger och mottagare | Före | Central mall |
| --- | --- | --- | --- |
| Motion mottagen | Inlämning, motionär | Enkel HTML | Ja |
| Motion status | Ändring från SharePoint, motionär | Enkel HTML | Ja |
| Medlemsansökan | Inlämning, sökande | Ren text | Ja |
| Ansökningsstatus och beslut | Statusändring, sökande | Ren text | Ja |
| Komplettering | Begäran eller kvittens, sökande | Ren text | Ja |
| Bokning och inspektion | Handläggningssteg, sökande/inspektör | Ren text | Ja |
| Årsmötesaktivitet | Ny eller ändrad anmälan, deltagare | Egen HTML/multipart | Ja |
| Kontaktformulär | Inskickat formulär, avsändare | Ingen kvittens | Ja |
| Fartygsuppgifter | Inbjudan eller inskickat material, fartygsombud | Ren text | Ja |
| Interna notifieringar | Routerstyrda funktionsadresser | Ren text/enkel HTML | Nej, avsiktligt |
| Microsoft 365-test | Manuell transportdiagnostik | Ren text | Nej, avsiktligt |

Årsmötesmejlets tabellbaserade 620-pixelslayout, färgprinciper, inline CSS och
textalternativ återanvändes som grund. Den tidigare separata PHP-mallen har
ersatts av den centrala renderern.

## Säkerhet

- Innehåll, rubriker och statusvärden saneras och HTML-escapas centralt.
- Endast `public_status_comment` får visas som statusmeddelande för sökande.
- Token finns endast i CTA-länken och eventuell synlig länkfallback.
- Interna WordPress-, Graph-, DriveItem- och ListItem-ID:n skickas inte.
- Renderern loggar malltyp och resultat, aldrig mottagare eller tokenlänk.

## Administration

Under `SSF -> System -> Microsoft 365 -> Organisationsuppgifter och e-postdesign`
kan administratören välja logotyp från mediabiblioteket, kontrollera de centrala organisationsuppgifterna,
redigera sidfotskontakt per e-postkategori, se vilka malltyper som hör till
respektive kategori, förhandsvisa samtliga malltyper och skicka testmejl genom aktiv transport.
Förhandsvisningen använder samma `render()` som riktiga utskick.

Preview och testmejl har ett separat kategorival och visar den sidfotskontakt
och `Reply-To` som kommer att användas. Interna notifieringsmottagare fortsätter
att administreras separat under E-postmottagare via `SSF_Email_Router`.

Namn, webbplats och postadress hämtas från `SSF_Organization_Info`, samma källa
som webbplatsens sidfot och informationssidor.

I development läggs `[DEV]` till i externa ämnesrader. Produktion får inget
prefix. När PHPMailer är aktiv sätts även `AltBody`; Microsoft 365-pluginets
Graph-transport skickar HTML eftersom dess nuvarande API-lager inte skapar
multipart MIME.
