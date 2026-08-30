# SSF Admin Navigation - inventering och förslag

Datum: 2026-08-30

Status: Implementerad för DEV. Meny-slugs, capabilities och publika funktioner är bevarade.

## Implementerad struktur

Den centrala navigationen registreras av `wp-content/mu-plugins/ssf-admin-navigation.php`. Befintliga plugin registrerar sina verksamhetssidor under de nya parent-sluggarna, medan tekniska System-sidor ligger kvar på samma page-slugs men presenteras som flikar.

- `SSF`: Översikt och System
- `Innehåll`: Webbinnehåll, Nyheter, Nyhetsbrev, Kalender, Stadgar & dokument
- `Medlemskap`: Ansökningar, Äldre ansökningar, Medlemsfartyg, inskickade uppgifter och fartygsverktyg
- `Årsmöten`: översikt, årsmöten, anmälningar, motioner och verksamhetsinställningar
- `Kommunikation`: Kontaktmeddelanden

Gamla GET-länkar till flyttade admin-sidor omdirigeras till motsvarande `admin.php?page=...`. POST-actions, REST/AJAX, CPT-nycklar, publika URL:er och datamodeller är oförändrade.

## Avgränsning

Inventeringen omfattar samtliga sju SSF-plugin i repot samt MU-pluginet SSF Release Controls. Den föreslagna ändringen avser enbart WordPress-admin: menyer, etiketter, gruppering, översiktssidor och adminspecifik presentation.

Följande ska inte ändras i implementationen:

- publika URL:er, rewrite-regler eller CPT-nycklar
- shortcodes, formulärflöden, notifieringar eller e-postflöden
- REST- och AJAX-endpoints
- Graph-, SharePoint- eller cronlogik
- datamodeller, metadata eller befintliga poster
- befintliga capabilities eller vilka åtgärder de ger tillgång till
- frontendmallar eller frontend-CSS

## Tidigare menyträd

Före implementationen varierade den faktiska ordningen med pluginens laddningsordning. En administratör fick i huvudsak följande SSF-relaterade toppmenyer:

```text
Webbinnehåll

SSF Årsmöten (slug: ssf)
|- Översikt
|- Årsmöten
|- Lägg till årsmöte
|- Anmälningar
|- Motioner
|- Inställningar                 (medlemsportal/årsmöten/motioner)
|- Microsoft 365                (Graph och SharePoint för motioner)
|- Systemstatus
|- Stadgar & dokument
|- Inställningar                 (stadgarsidan, samma synliga etikett)
|- Release
`- Funktioner

Kalender
|- Alla event
|- Lägg till event
`- Inställningar

Medlemsprocess
|- Alla ansökningar
|- Lägg till
|- Översikt
`- Inställningar

Medlemsfartyg
|- Alla medlemsfartyg
|- Lägg till
|- taxonomier för fartyg
|- Inskickade uppgifter
|- Mina fartyg
|- Insamlingslänkar
|- Inställningar
`- Exportera CSV

Nyhetsbrev
|- Alla nyhetsbrev
|- Lägg till nyhetsbrev
|- Importera äldre nummer
`- Inställningar

Ansökningar                     (äldre CPT: ssf_ansokan)
Kontaktmeddelanden

Inställningar
`- SSF Microsoft 365 Mailer
```

För rollen `ssf_fartygsombud`, som inte kan redigera hela fartygsregistret, skapas dessutom toppmenyn `Mina fartyg`.

WordPress standardmeny `Inlägg` är den tekniska platsen för nyheter. Den är inte kopplad till övrigt SSF-innehåll i navigationen.

## Plugininventering

### SSF Kalender

- Mapp: `ssf-calendar`
- Huvudfunktion: manuella kalenderhändelser och sammanställning med publicerade årsmöten.
- CPT: `ssf_event`, publik, REST-aktiverad, rewrite `kalender`, men dold som automatisk CPT-meny.
- Nuvarande admin: egen toppmeny `Kalender` med `Alla event`, `Lägg till event` och `Inställningar`.
- Slugs: `ssf-calendar-events`, `post-new.php?post_type=ssf_event`, `ssf-calendar-settings`.
- Capability: `edit_posts` och vanliga post-capabilities.
- Frontendberoenden: publik kalendersida, eventpresentation och läsning av `ssf_annual_meeting`.
- Föreslagen placering: `Innehåll > Kalender`, med inställningar som flik på kalendersidan.

### SSF Medlemsfartyg

- Mapp: `ssf-medlemsfartyg`
- Huvudfunktion: medlemsfartyg, fartygsombud, insamling av fartygsuppgifter och export.
- CPT: `medlemsfartyg` (publik) och `ssf_ship_submission` (privat).
- Taxonomier: `fartygstyp`, `fartygsstatus`, `fartygsanvandning`, `fartygsregion`.
- Nuvarande admin: automatisk toppmeny `Medlemsfartyg`; submissions, Mina fartyg, insamlingslänkar, inställningar och CSV-export ligger under den.
- Slugs: `edit.php?post_type=medlemsfartyg`, `ssf-mina-fartyg`, `ssf-insamlingslankar`, `ssf-medlemsfartyg-settings`, `ssf-medlemsfartyg-export`.
- Capabilities: egna `ssf_ship`-capabilities, `manage_ssf_ships`, `export_ssf_ships`, `manage_options` och `read` beroende på sida.
- Roller: administratör och `ssf_fartygsombud`; ombud får endast arbeta med egna kopplade fartyg.
- Frontendberoenden: arkiv/singel, kort, ägarpanel, publik insamling, REST och shortcodes.
- Föreslagen placering: `Medlemskap > Medlemsfartyg`; Mina fartyg ska fortfarande vara synligt för fartygsombud utan bredare access.

### SSF Medlemsprocess

- Mapp: `ssf-medlemsprocess`
- Huvudfunktion: ansökan, granskning, komplettering, inspektion och beslut.
- CPT: `ssf_application`, privat.
- Nuvarande admin: automatisk toppmeny med tekniskt menylabel `Medlemsprocess`, plus `Översikt` och `Inställningar`.
- Slugs: `edit.php?post_type=ssf_application`, `ssf-medlemsprocess-overview`, `ssf-medlemsprocess-settings`.
- Capabilities: egna ansöknings-capabilities samt bland annat `ssf_view_applications`, `ssf_decide_applications`, `ssf_manage_application_settings` och inspektörs-capabilities.
- Roller: administratör, `ssf_inspector`, legacyrollen `ssf_inspektor` och `ssf_beslutsfattare`.
- Frontendberoenden: `/ansokan/`, `/ansokan-status/`, `/mina-inspektioner/`, uppladdningar, e-post och tokenbaserade flöden.
- Föreslagen placering: `Medlemskap > Översikt`, `Ansökningar` och `Inställningar`. Inspektion är en del av ärendet och ska inte bli en ny datamodell eller tom adminsektion.

### SSF Medlemsportal

- Mapp: `ssf-member-portal`
- Huvudfunktion: årsmöten, årsmötesanmälningar, motioner och Microsoft 365/SharePoint-integration för dessa flöden.
- CPT: `ssf_annual_meeting`, `ssf_meeting_registration` och `ssf_motion`, samtliga med dolda automatiska menyer.
- Nuvarande admin: skapar toppmenyn `SSF Årsmöten` med slug `ssf` och fungerar samtidigt som parent för flera andra plugin.
- Slugs: `ssf`, `edit.php?post_type=ssf_annual_meeting`, `post-new.php?post_type=ssf_annual_meeting`, `ssf-member-portal-meeting-registrations`, `edit.php?post_type=ssf_motion`, `ssf-member-portal-settings`, `ssf-member-portal-microsoft365`, `ssf-member-portal-status`.
- Capabilities: `ssf_manage_member_portal`, `ssf_manage_motions`, `manage_ssf_annual_meetings`, `manage_ssf_features`.
- Frontendberoenden: årsmötessidor, anmälan, motionsflöden, REST-diagnostik, SharePoint, Power Automate, e-post och cron.
- Föreslagen placering: egen toppmeny `Årsmöten`. Microsoft 365 och systemstatus flyttas visuellt till `SSF > System`; funktion och slugs bevaras.

### SSF Microsoft 365 Mailer

- Mapp: `ssf-office365-mailer`
- Huvudfunktion: ersätter WordPress e-posttransport med Microsoft Graph och OAuth 2.0.
- CPT: ingen.
- Nuvarande admin: `Inställningar > SSF Microsoft 365 Mailer`.
- Slug: `ssf-office365-mailer`.
- Capability: `manage_options`.
- Tekniska ytor: OAuth callback, status-endpoint, anslut/koppla från och senaste leveransresultat.
- Frontendberoenden: filtret `pre_wp_mail`; alla e-postflöden kan passera pluginet.
- Föreslagen placering: `SSF > System > E-post`, presenterad som en Microsoft 365-integration. Slug och capability bevaras.

### SSF Site Customizations

- Mapp: `ssf-site-customizations`
- Huvudfunktion: webbinnehåll, nyhetsbrev, enklare legacyformulär, kontaktmeddelanden, informationsarkitektur, shortcodes och frontendpresentation.
- CPT: `ssf_ansokan`, `ssf_kontakt` och `ssf_newsletter`.
- Nuvarande admin: toppmenyerna `Webbinnehåll`, `Ansökningar`, `Kontaktmeddelanden` och `Nyhetsbrev`.
- Slugs: `ssf-webbinnehall`, `edit.php?post_type=ssf_ansokan`, `edit.php?post_type=ssf_kontakt`, `edit.php?post_type=ssf_newsletter`, `ssf-newsletter-import`, `ssf-newsletter-settings`.
- Capabilities: `manage_options` för Webbinnehåll; vanliga post-capabilities för legacyansökningar och kontakt; egna newsletter-capabilities inklusive `manage_ssf_newsletters` för administratör och editor.
- Frontendberoenden: startsida, kontakt, ansökan, formulär, nyhetsarkiv, nyhetsbrevsarkiv, shortcodes och frontend-CSS.
- Föreslagen placering: Webbinnehåll och Nyhetsbrev under `Innehåll`; Kontaktmeddelanden under `Kommunikation`; `ssf_ansokan` under `Medlemskap` med tydlig legacyetikett tills aktivt formulärflöde har verifierats i drift.

### SSF Stadgar & Dokument

- Mapp: `ssf-stadgar`
- Huvudfunktion: stadgar, versionshistorik, PDF, relaterade styrdokument och publik presentation.
- CPT: `ssf_document`, privat admin-CPT med REST-stöd.
- Nuvarande admin: CPT:n och en separat `Inställningar` läggs under parent-sluggen `ssf`.
- Slugs: `edit.php?post_type=ssf_document`, `ssf-stadgar-settings`.
- Capability: vanliga post-capabilities för dokument och `manage_options` för inställningar.
- Frontendberoenden: stadgesida, PDF-länkar, dokumentversioner och AJAX-baserad PDF-analys i admin.
- Föreslagen placering: `Innehåll > Stadgar & dokument`, med inställningar som flik eller sekundär åtgärd.

### SSF Release Controls (MU-plugin)

- Fil: `wp-content/mu-plugins/ssf-release-controls.php`
- Huvudfunktion: miljö, central releasehistorik, releasevisning, feature controls, route guards och systemkontroller.
- CPT: `ssf_release`, privat och dolt från normal CPT-navigation.
- Nuvarande admin: `Release` och `Funktioner` under parent-sluggen `ssf`, samt två widgets på WordPress Dashboard.
- Slugs: `ssf-release`, `ssf-features`.
- Capabilities: `manage_ssf_releases` respektive `manage_ssf_features`, tilldelade administratör.
- Frontendberoenden: feature guards, menyfiltrering, miljövisning och releaseinformation i sidfoten.
- Föreslagen placering: `SSF > System`, som flikarna `Funktioner` och `Release`. MU-pluginets verksamhetslogik ska inte ändras.

## Problem och dubletter

1. Toppnivån beskriver plugin och datatyper i stället för arbetsområden. En administratör måste känna till skillnaden mellan Medlemsprocess, Medlemsportal och Site Customizations.
2. Sluggen `ssf` ägs av Medlemsportalen men används som gemensam parent av Stadgar och Release Controls. Om Medlemsportalen är inaktiv försvinner deras naturliga navigation trots att pluginen fortfarande laddas.
3. SSF-menyn blandar årsmöten, motioner, dokument, systemstatus, Microsoft 365, release och funktionsflaggor.
4. Två synliga undermenyer heter `Inställningar` under samma parent men leder till helt olika områden.
5. Microsoft 365 finns på två platser: SharePoint/Graph under SSF och e-posttransport under WordPress Inställningar.
6. Nyheter, nyhetsbrev, kalender, dokument och redigerbart webbinnehåll ligger på fem olika navigationsnivåer.
7. `ssf_ansokan` och `ssf_application` är parallella datatyper för ansökningar. Det äldre formuläret skriver fortfarande till `ssf_ansokan`, medan Medlemsprocessen skriver till `ssf_application`. Ingen av dem får tas bort eller migreras i detta arbete.
8. Kortkoden `ssf_application_form` registreras av både Site Customizations och Medlemsprocess. Vilken callback som är aktiv beror på laddningsordning. Detta är en befintlig frontendrisk och ska utredas separat, inte lösas som en del av adminnavigationen.
9. Flera viktiga admin-assetkontroller bygger på nuvarande page hook, exempelvis Webbinnehåll och Nyhetsbrevsimport. De måste justeras när en sida flyttas, annars kan media picker eller admin-CSS/JS utebli.
10. Kalender och den villkorliga toppmenyn Mina fartyg begär båda menyposition 26.
11. Systemtesterna för Graph och SharePoint ligger direkt i Microsoft 365-sidan. De bör ligga bakom fliken Diagnostik eller ett expanderbart block, men endpoints och actions ska vara oförändrade.
12. Årsmötets migrationsverktyg visas på dess ordinarie översikt. Det är en sällan använd teknisk åtgärd och bör ligga under Avancerat/Diagnostik utan att tas bort.

## Föreslaget menyträd

Fem verksamhetsorienterade toppmenyer rekommenderas. WordPress egna Dashboard, Media, Sidor, Användare och liknande ligger kvar.

```text
SSF
|- Översikt
`- System
   Flikar: Översikt | Funktioner | Microsoft 365 | E-post | Release | Systemstatus

Innehåll
|- Översikt
|- Webbinnehåll
|- Nyheter
|- Nyhetsbrev
|  Flikar: Alla | Lägg till | Importera äldre | Inställningar
|- Kalender
|  Flikar: Alla event | Lägg till event | Inställningar
`- Stadgar & dokument
   Flikar: Alla dokument | Lägg till dokument | Inställningar

Medlemskap
|- Översikt
|- Ansökningar
|- Äldre ansökningar
|- Medlemsfartyg
|- Inskickade fartygsuppgifter
|- Mina fartyg
|- Insamlingslänkar
`- Inställningar
   Sekundär åtgärd: Exportera CSV

Årsmöten
|- Översikt
|- Alla årsmöten
|- Lägg till årsmöte
|- Anmälningar
|- Motioner
`- Inställningar
   Årsmötets program, handlingar och tider hanteras fortsatt i respektive årsmöte.

Kommunikation
|- Översikt
`- Kontaktmeddelanden
```

`E-post` ligger under System eftersom den befintliga sidan är teknisk konfiguration av transport, OAuth och leveransstatus. Kontaktmeddelanden ligger under Kommunikation eftersom de är verksamhetsinnehåll.

Nyheter flyttas navigationsmässigt från WordPress toppmeny `Inlägg` till `Innehåll > Nyheter`. Posttypen `post`, dess URL:er och funktion ändras inte.

## SSF Översikt

Den nya startsidan ska vara en lugn, capability-filtrerad orienteringsyta med högst fyra huvudblock:

- Medlemskap: nya/aktiva ansökningar och antal medlemsfartyg.
- Årsmöten: nästa årsmöte, anmälningar och motioner.
- Innehåll: direkta länkar till Nyheter, Nyhetsbrev, Kalender och Stadgar.
- System: miljö, aktuell release, featurestatus och begriplig Microsoft 365-status.

Snabbåtgärder begränsas till tre till fem relevanta kommandon och visas bara när användaren har respektive befintlig capability. Översikten får inte exponera Graph-ID:n, tokens, hemligheter eller andra tekniska detaljer.

## Central navigationsarkitektur

En separat MU-pluginfil, exempelvis `wp-content/mu-plugins/ssf-admin-navigation.php`, rekommenderas för den gemensamma adminstrukturen. Den ska vara oberoende av enskilda verksamhetsplugin och endast arbeta i `wp-admin`.

Den centrala klassen bör:

- registrera stabila toppnoder och översiktssidor med explicita prioriteter
- erbjuda konstanter eller helpers för parent-sluggar
- låta varje plugin registrera sina egna callbacks och behålla sina capabilities
- endast visa länkar och statuskort som användaren har behörighet till
- tillåta att ett plugin avaktiveras utan att övrig navigation slutar fungera
- samla adminspecifik CSS under de nya SSF-sidorna
- inte anropa eller flytta frontendlogik

Föreslagna toppslugs:

| Område | Slug | Kommentar |
| --- | --- | --- |
| SSF | `ssf-overview` | Ny slug för gemensam översikt och System. |
| Innehåll | `ssf-content` | Ny stabil parent för innehållsfunktioner. |
| Medlemskap | `ssf-membership` | Ny stabil parent; capability-filtrerad. |
| Årsmöten | `ssf` | Återanvänder befintlig slug och bevarar gammal översiktslänk. |
| Kommunikation | `ssf-communication` | Ny stabil parent för inkommande kommunikation. |

Att låta `Årsmöten` behålla sluggen `ssf` gör att befintliga länkar till `admin.php?page=ssf` fortsatt leder till årsmötesöversikten. Den nya generella SSF-översikten får i stället den nya sluggen `ssf-overview`.

## URL- och slugkompatibilitet

Följande befintliga page-slugs ska bevaras:

- `ssf-webbinnehall`
- `ssf-calendar-events`
- `ssf-calendar-settings`
- `ssf-medlemsprocess-overview`
- `ssf-medlemsprocess-settings`
- `ssf-mina-fartyg`
- `ssf-insamlingslankar`
- `ssf-medlemsfartyg-settings`
- `ssf-medlemsfartyg-export`
- `ssf-member-portal-meeting-registrations`
- `ssf-member-portal-settings`
- `ssf-member-portal-microsoft365`
- `ssf-member-portal-status`
- `ssf-newsletter-import`
- `ssf-newsletter-settings`
- `ssf-stadgar-settings`
- `ssf-office365-mailer`
- `ssf-release`
- `ssf-features`

CPT-URL:erna ska vara oförändrade, inklusive alla `edit.php?post_type=...` och `post-new.php?post_type=...`.

När en custom page byter parent ändras WordPress bas-URL ibland från `edit.php?...&page=slug` till `admin.php?page=slug`. Implementation ska därför antingen registrera en dold kompatibilitetsroute eller göra en capability- och nonce-säker redirect från den gamla admin-URL:en. Inga publika redirects behövs.

## Capabilitymatris

| Område | Befintlig capability som ska återanvändas |
| --- | --- |
| Webbinnehåll | `manage_options` |
| Nyheter, Kalender, Stadgar/dokument | `edit_posts` eller WordPress postens meta-cap |
| Nyhetsbrev | newsletter-meta-caps och `manage_ssf_newsletters` |
| Legacyansökningar och Kontaktmeddelanden | vanliga post-capabilities |
| Medlemsprocess översikt | `ssf_view_applications` |
| Medlemsprocess inställningar | `ssf_manage_application_settings` |
| Medlemsfartyg | `edit_ssf_ships` / postens meta-cap |
| Mina fartyg | `read`, med befintlig ägarkontroll |
| Insamlingslänkar och fartygsinställningar | `manage_options` |
| Fartygsexport | `export_ssf_ships` |
| Årsmöten | `manage_ssf_annual_meetings` |
| Motioner | `ssf_manage_motions` |
| Årsmötesinställningar och systemstatus | `ssf_manage_member_portal` |
| Microsoft 365 för motioner | `ssf_manage_motions` |
| Microsoft 365 e-post | `manage_options` |
| Release | `manage_ssf_releases` |
| Funktioner | `manage_ssf_features` |

En parent/översikt kan ha en låg behörighet för att bli synlig, men varje länk, statusuppgift och callback måste fortfarande kontrolleras med den ursprungliga capabilityn. Detta får aldrig användas för att ge bredare åtkomst till underliggande funktioner.

## Planerad implementation efter godkännande

1. Skapa central, admin-only navigation i ett separat MU-plugin.
2. Skapa de fem toppnoderna och capability-filtrerade översiktssidorna.
3. Flytta varje plugins adminregistrering till rätt parent utan att ändra callback, page slug eller capability.
4. Flytta CPT-menyer med `show_in_menu` eller manuella submenu-länkar; behåll alla CPT-nycklar.
5. Ta bort enbart de gamla synliga toppmenyposterna efter att motsvarande nya länk har registrerats.
6. Behåll eller redirecta gamla admin-URL:er.
7. Uppdatera admin-assetdetektering så att Webbinnehåll, Nyhetsbrevsimport och andra flyttade sidor fortsatt får rätt JS/CSS.
8. Bygg SSF Översikt och områdesöversikter med native WordPress-komponenter och capability-filtrerade data.
9. Samla Systemsidor med nav-tabs och progressive disclosure; anropa befintliga render-callbacks och actions.
10. Verifiera menyträd och direktlänkar för administratör, editor, fartygsombud, inspektör och beslutsfattare.
11. Kör backendtestplan samt frontend regressionstest utan att ändra frontendfiler.
12. Dokumentera registreringsmönstret för framtida SSF-plugin.

## Testplan för implementationsfasen

Backend:

- kontrollera hela menyträdets ordning och labels för samtliga relevanta roller
- öppna varje bevarad page slug och CPT-lista direkt
- skapa/redigera nyhet, nyhetsbrev, event, dokument, ansökan, fartyg, årsmöte och motion
- verifiera årsmötesanmälningar, exportsidor och Mina fartyg
- verifiera Microsoft 365-konfiguration, OAuth-länkar och diagnostikåtgärder utan att ändra endpoints
- verifiera Release, Funktioner och Systemstatus
- verifiera att admin-CSS/JS och Media Library-dialoger laddas på flyttade sidor
- verifiera att inaktivering av ett enskilt verksamhetsplugin endast tar bort dess egna länkar

Frontend:

- jämför `/`, `/nyheter/`, `/nyhetsbrev/`, `/kalender/`, `/stadgar/`, `/medlemsfartyg/`, `/ansokan/` och `/arsmoten/`
- prova motions-, kontakt-, ansöknings- och årsmötesanmälningsflöden
- kontrollera shortcodes, REST-routes, cron-hooks, e-post och SharePointintegration
- bekräfta att inga frontendmallar, stylesheets, rewrite-regler eller publika URL:er har ändrats

## Beslutspunkt

Ingen menyimplementation har gjorts i denna fas. Rekommendationen är att godkänna eller justera menyträdet ovan innan fas 6 startar.
