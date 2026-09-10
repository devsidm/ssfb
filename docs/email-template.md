# Gemensam SSF-mall för e-post

`wp-content/mu-plugins/ssf-email-template.php` är presentationslagret för
externa transaktionsmejl. Affärsmodulerna lämnar typad meddelandedata till
renderern, som skapar HTML och ren text. Utskicket går därefter genom befintlig
`wp_mail`, Microsoft 365 Mailer och befintliga DEV-regler.

```text
Affärsmodul -> meddelandedata -> SSF_Email_Template -> wp_mail -> aktiv transport
```

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
förhandsvisa samtliga malltyper och skicka testmejl genom aktiv transport.
Förhandsvisningen använder samma `render()` som riktiga utskick.

Namn, webbplats och postadress hämtas från `SSF_Organization_Info`, samma källa
som webbplatsens sidfot och informationssidor.

I development läggs `[DEV]` till i externa ämnesrader. Produktion får inget
prefix. När PHPMailer är aktiv sätts även `AltBody`; Microsoft 365-pluginets
Graph-transport skickar HTML eftersom dess nuvarande API-lager inte skapar
multipart MIME.
