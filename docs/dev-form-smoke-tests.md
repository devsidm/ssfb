# Dev form smoke tests

Det här dokumentet beskriver hur dev-flödena testas utan handarbete i webbläsaren. Syftet är att snabbt skapa riktiga testärenden via WordPress formulärhantering, men med ett enda kommando så att vi sparar tid och Codex-krediter.

Skript:

```powershell
scripts\local\run-dev-form-smoke.ps1
```

Skriptet loggar in i dev med WordPress-konto, hämtar formulärsidorna, återanvänder formulärens egna nonce-fält och postar till `wp-admin/admin-post.php`. Det hårdkodar inte lösenord. Cookie jar, temporära HTML-svar och test-PDF för motioner skapas i `%TEMP%` och rensas efter körning.

## Snabb körning

Kör alla flöden:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\local\run-dev-form-smoke.ps1 `
  -BaseUrl "https://ssfb.se/dev" `
  -Username "WORDPRESS_USER" `
  -Password "WORDPRESS_PASSWORD" `
  -TargetEmail "linde@kent.nu" `
  -Flow all
```

Kör bara ett flöde:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\local\run-dev-form-smoke.ps1 -Username "WORDPRESS_USER" -Password "WORDPRESS_PASSWORD" -TargetEmail "linde@kent.nu" -Flow membership
powershell -ExecutionPolicy Bypass -File scripts\local\run-dev-form-smoke.ps1 -Username "WORDPRESS_USER" -Password "WORDPRESS_PASSWORD" -TargetEmail "linde@kent.nu" -Flow motion
powershell -ExecutionPolicy Bypass -File scripts\local\run-dev-form-smoke.ps1 -Username "WORDPRESS_USER" -Password "WORDPRESS_PASSWORD" -TargetEmail "linde@kent.nu" -Flow contact
```

`-Flow membership` tar cirka sex minuter eftersom medlemsansökningsformuläret har en inbyggd spärr mellan inskickningar. Justera med `-WaitSeconds`, men använd normalt inte mindre än 125 sekunder.

## Medlemsansökningar

`membership` skapar fyra ansökningar, en för varje medlemsväg:

| Route | Visningsnamn |
| --- | --- |
| `normal` | Normalfallet |
| `small_registered` | Mindre registrerat fartyg |
| `restoration` | Fartyg under restaurering |
| `new_traditional` | Nybyggt traditionsfartyg |

Varje ansökan går genom det riktiga formuläret på `/ansokan/`:

1. Skriptet hämtar formuläret inloggat som admin.
2. Dolda fält läses ut: `action`, `_wpnonce`, `_wp_http_referer`, `submission_key` och eventuella antispamfält.
3. Gemensamma fartygs- och ombudsdata fylls i.
4. Route-specifika obligatoriska fält sätts:
   - `small_registered`: registertyp, registreringsnummer och registerbekräftelse.
   - `restoration`: nuvarande skick, mål och bevarandeplan.
   - `new_traditional`: förebild, traditioner och konstruktion.
5. Formuläret postas till `admin-post.php?action=ssf_submit_application`.
6. Resultat godkänns bara om redirect innehåller `ssf_application_sent=1`.

Sökandens e-post och faktura-e-post sätts till `-TargetEmail`. Resultatet JSON-loggar redirect-URL och mailflaggan från formuläret, till exempel `ssf_mail=sent`.

## Motioner

`motion` testar motionsmodulens frontendflöde:

1. Skriptet hämtar `/lamna-motion/`.
2. Det kräver att formuläret är öppet och att sidan innehåller `ssf_member_portal_submit_motion`.
3. Dolda fält läses ut, inklusive `meeting_id` och `ssf_member_portal_motion_nonce`.
4. En minimal PDF skapas i `%TEMP%`.
5. Formuläret postas multipart till `admin-post.php?action=ssf_member_portal_submit_motion` med fält för namn, e-post, telefon, rubrik, beskrivning och `ssf_motion_files[]`.
6. Resultat godkänns bara om redirect innehåller `confirmation=1`.

Motioner kräver en öppen motionsperiod. Om perioden är stängd avbryter skriptet med ett tydligt felmeddelande i stället för att skapa testdata på en sidoväg.

## Kontaktformulär

`contact` testar `/kontakta-oss/`:

1. Skriptet hämtar kontaktformuläret.
2. `ssf_contact_nonce` och övriga dolda fält läses ut.
3. Namn, e-post, telefon, ämne och meddelande fylls i.
4. Formuläret postas till `admin-post.php?action=ssf_contact`.
5. Resultat godkänns bara om redirect innehåller `ssf_status=contact_sent`.

Sökandens e-post sätts till `-TargetEmail`, vilket gör att bekräftelsemejlet går till samma testmottagare.

## Antispam och inloggning

Skriptet kör inloggat som WordPress-admin. `SSF_Antispam` bypassar Turnstile för inloggad administratör, men formulärens egna honeypotfält lämnas fortfarande tomma. Medlemsansökan har dessutom en separat tvåminutersspärr per IP efter lyckad submission, därför väntar skriptet mellan de fyra medlemsvägarna.

## Senast verifierat

2026-09-14 skapades följande medlemsansökningar i dev till `linde@kent.nu`:

| Nummer | Flöde |
| --- | --- |
| `SSF-2026-0018` | Normalfallet |
| `SSF-2026-0019` | Mindre registrerat fartyg |
| `SSF-2026-0020` | Fartyg under restaurering |
| `SSF-2026-0021` | Nybyggt traditionsfartyg |

Alla fyra returnerade `ssf_mail=sent`.

Samma datum kördes även:

| Flöde | Resultat |
| --- | --- |
| Motion | `2026-004`, redirect till `/motion-status/?...&confirmation=1` |
| Kontaktformulär | redirect till `/kontakta-oss/?ssf_status=contact_sent` |
