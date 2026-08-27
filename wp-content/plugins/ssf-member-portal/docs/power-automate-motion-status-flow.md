# SSF Motioner - Power Automate statussynk

Den här specifikationen är avsedd för en **Automated cloud flow** i en
unmanaged Solution. Flödet läser endast från SharePoint och postar en
statusändring till WordPress. Det får aldrig skriva tillbaka till biblioteket.

## Förutsättningar

1. Skapa en Power Platform-miljö med Dataverse. En Solution kan inte skapas i
   en tenant utan en sådan miljö.
2. Aktivera versionshantering för biblioteket `Dokument`. SharePoints action
   **Get changes for an item or a file (properties only)** behöver versioner
   för att avgöra om en kolumn har ändrats.
3. Skapa följande kolumner i `Dokument` och använd samma interna fältnamn i
   `SSF > Microsoft 365 > SharePoint-metadata` i WordPress:

   | Visningsnamn | Typ | Värde / anmärkning |
   | --- | --- | --- |
   | `WordPressMotionID` | En rad text | WordPress post-ID |
   | `Motionnummer` | En rad text | Exempel: `2027-004` |
   | `Status` | Val | Statusvärden enligt nedan |
   | `Fartyg` | En rad text | Kan vara tomt |
   | `Inkommen datum` | Datum | Notera SharePoints interna fältnamn |

   Skapa gärna kolumnerna innan de första filerna synkas. SharePoint kan ge
   ett annat internt namn för `Inkommen datum`, exempelvis
   `Inkommen_x0020_datum`; ange då det namnet i WordPress.

4. Statuskolumnens tillåtna val är exakt:

   ```text
   Inkommen
   Under behandling
   Begär komplettering
Färdigbehandlad av styrelsen
Till årsmötet
Beslutad på årsmötet
Avslutad
   ```

## Solution

| Inställning | Värde |
| --- | --- |
| Publisher | `SSF` |
| Publisher prefix | `ssf` |
| Solution display name | `SSF Motioner Integration` |
| Solution name | `ssf_motioner_integration` |
| Flow display name | `SSF Motioner - SharePoint status till WordPress` |
| Flow schema name | `ssf_motioner_sharepoint_status_to_wordpress` |
| Typ | Automated cloud flow |
| Delad SharePoint-anslutning | `ssf_sharepoint` |

Använd en dedikerad tjänsteanvändare eller ett styrelsekonto som har minst
redigeringsbehörighet på styrelsens SharePoint-site. Lägg anslutningen i en
connection reference i Solution, inte direkt i flödet.

## Environment variables

| Display name | Schema name | Typ | Dev-värde | Produktionsvärde |
| --- | --- | --- | --- | --- |
| WordPress webhook URL | `ssf_WordPressWebhookUrl` | Text | `https://ssfb.se/dev/wp-json/ssf-motions/v1/status` | `https://ssfb.se/wp-json/ssf-motions/v1/status` |
| WordPress webhook secret | `ssf_WordPressWebhookSecret` | Secret | Värde från dev-admin | Värde från produktions-admin |
| SharePoint site URL | `ssf_SharePointSiteUrl` | Text | `https://tradtionsfartyg.sharepoint.com/sites/styrelsen9` | Samma |
| SharePoint library | `ssf_SharePointLibrary` | Text | `Dokument` | Samma |

Secret-värdet sparas vid miljökonfigurationen och får inte skrivas i en
flow-definition, export, run history, Compose-action eller notifiering.

## Trigger och action

### 1. Trigger

Lägg till SharePoint-triggern **When a file is created or modified
(properties only)**.

| Fält | Värde |
| --- | --- |
| Site Address | Environment variable `ssf_SharePointSiteUrl` |
| Library Name | Environment variable `ssf_SharePointLibrary` |
| Folder | Lämna tomt |

Tom mapp är avsiktligt. Då bevakar triggern hela biblioteket och fungerar
automatiskt för `Årsmöten/2026/Motioner/`, `Årsmöten/2027/Motioner/` och
framtida år. I triggerns Settings aktiveras **Concurrency Control** med
parallellism `1` för att hålla statusändringar för samma dokument ordnade.

### 2. Get changes

Lägg direkt efter triggern till SharePoint-action **Get changes for an item
or a file (properties only)** och döp den till `Get_changes`.

| Fält | Värde |
| --- | --- |
| Site Address | Samma site som triggern |
| Library Name | `Dokument` |
| Id | Triggerns dynamiska värde `ID` |
| Since | Triggerns dynamiska värde `Trigger Window Start Token` |
| Until | Triggerns dynamiska värde `Trigger Window End Token` |
| Include Minor Versions | `No` |

Använd de dynamiska Trigger Window-tokenvärdena, inte ett manuellt beräknat
versionsnummer.

## Villkor

Varje Nej-gren avslutas med **Terminate**: `Succeeded` och ett tydligt
meddelande. Det gör avsiktligt ignorerade körningar lätta att känna igen utan
att märkas som driftfel.

### A. Status har ändrats

Skapa villkoret `Status_changed`.

| Vänster | Operator | Höger |
| --- | --- | --- |
| Dynamiskt värde `Has Column Changed: Status` från `Get_changes` | is equal to | Expression `true` |

Välj token via Dynamic content i stället för att skriva ett eget JSON-fältnamn;
Power Automate skapar tokennamnet från bibliotekets interna kolumnnamn.

**Nej:** Terminate med meddelandet `Status ändrades inte.`

### B. Filen ligger i korrekt motionsmapp

I Ja-grenen, skapa villkoret `Is_motion_folder`. Använd triggerns dynamiska
värde **Folder path**. Med en vanlig bibliotekssökväg som börjar vid
`Dokument/` används följande expression:

```text
@and(
  startsWith(triggerOutputs()?['body/{Path}'], 'Dokument/Årsmöten/'),
  endsWith(triggerOutputs()?['body/{Path}'], '/Motioner/')
)
```

Om run history visar att `Folder path` börjar direkt med `Årsmöten/`, byt bara
prefixet till `Årsmöten/`. Använd alltid det exakta värdet från triggerns
output; suffixet `/Motioner/` gör att andra mappar under årsmötesstrukturen
ignoreras.

**Nej:** Terminate med meddelandet `Filen ligger inte i en motionsmapp.`

### C. Obligatorisk metadata finns

I Ja-grenen, skapa villkoret `Has_required_metadata` med avancerat läge.
Anpassa fältreferenserna från triggerns Dynamic content om SharePoint har
ett annat internt namn.

```text
@and(
  not(empty(triggerOutputs()?['body/WordPressMotionID'])),
  not(empty(triggerOutputs()?['body/Motionnummer'])),
  not(empty(triggerOutputs()?['body/Status/Value']))
)
```

För en Statuskolumn av typen Val används värdet **Status Value**. Om kolumnen
är en textkolumn används i stället `triggerOutputs()?['body/Status']`.

**Nej:** Terminate med meddelandet `Motionen saknar obligatorisk metadata.`

## HTTP POST till WordPress

I Ja-grenen från `Has_required_metadata`, lägg till action **HTTP** och döp
den till `Post_status_to_WordPress`.

| Fält | Värde |
| --- | --- |
| Method | `POST` |
| URI | Environment variable `ssf_WordPressWebhookUrl` |
| Header `Content-Type` | `application/json` |
| Header `X-SSF-Webhook-Secret` | Secret environment variable `ssf_WordPressWebhookSecret` |

Body. Sätt dynamiska token i editorn för `WordPressMotionID`, `Motionnummer`,
`Status Value`, `ID` och `Link to item`:

```json
{
  "wordpress_motion_id": "@{triggerOutputs()?['body/WordPressMotionID']}",
  "motion_number": "@{triggerOutputs()?['body/Motionnummer']}",
  "status": "@{triggerOutputs()?['body/Status/Value']}",
  "sharepoint_list_item_id": "@{triggerOutputs()?['body/ID']}",
  "sharepoint_file_url": "@{triggerOutputs()?['body/{Link}']}",
  "changed_at": "@{utcNow()}"
}
```

För `sharepoint_file_url` används i första hand triggerns token **Link to
item**. Om miljön visar ett annat internt fältnamn, välj fortfarande token via
Dynamic content i stället för att ändra URL-strukturen manuellt.

WordPress validerar secret, post-ID, motionsnummer och status. Ett `200`
med `result: updated` eller `result: no_change` är lyckat. Inga SharePoint
actions får placeras efter HTTP-actionen.

## Felhantering och run-after

1. Lägg HTTP-actionen i Scope `Try_post_to_WordPress`.
2. Skapa Scope `Handle_post_failure` efteråt. Configure run after på denna
   scope: **has failed** och **has timed out** för `Try_post_to_WordPress`.
3. I `Handle_post_failure`, skapa en kort felpost i en separat integrationslogg
   eller skicka en Teams-notis. Logga endast fil-ID, motionsnummer, HTTP-status
   och correlation/run ID. Logga aldrig webhook-secret.
4. Lägg till **Terminate** med status `Failed` efter notifieringen så att
   administratören kan se att uppdateringen inte nådde WordPress.
5. Behåll HTTP-actionens retry policy `Exponential`. Rekommenderat: högst fyra
   försök med startintervall `PT10S`. Återförsök ska endast användas för
   transportfel, `408`, `429` och `5xx`. Värdena `400`, `401`, `404` och `409`
   är konfigurations- eller dataproblem och ska notifieras utan att försöka om.

WordPress är idempotent. En upprepad `200` med `no_change` ska räknas som
lyckad och behöver ingen åtgärd.

## Deployment

1. Bygg och testa först i en utvecklingsmiljö med dev-webhookens URL.
2. Exportera Solution som unmanaged för källkontroll och som managed för
   produktionsimport.
3. Vid import till produktion väljs befintlig `ssf_sharepoint` connection
   reference och produktionsvärden för alla environment variables.
4. Skapa produktionssecret i `SSF > Microsoft 365`, registrera det som Secret
   environment variable och testa med en avsedd testmotion.
5. Kontrollera efter testet i WordPress att statuskällan är
   `power_automate`, att statushistorik skapats och att ingen ny SharePoint
   uppdatering har köats från den inkommande ändringen.
6. Slå på flödet först när testet har lyckats. Dokumentera flow owner och en
   sekundär co-owner så att flödet inte blir personberoende.

## Verifieringsfall

| Test | Förväntat resultat |
| --- | --- |
| Ändra bara filnamn | Körning avslutas i `Status_changed` Nej |
| Ändra Status på fil utanför Motioner | Körning avslutas i `Is_motion_folder` Nej |
| Ändra Status utan WordPressMotionID | Körning avslutas i `Has_required_metadata` Nej |
| Ändra Inkommen till Under behandling | HTTP `200`, WordPress visar ny status och historik |
| Skicka samma status igen | HTTP `200`, `result: no_change` |
| Fel secret | HTTP `401`, Scope `Handle_post_failure` notifierar |
| Fel motionsnummer | HTTP `409`, Scope `Handle_post_failure` notifierar |

## Referens

- [SharePoint connector: Get changes for an item or a file](https://learn.microsoft.com/en-us/sharepoint/dev/business-apps/power-automate/sharepoint-connector-actions-triggers)
- [Power Platform CLI: install and environment commands](https://learn.microsoft.com/en-us/power-platform/developer/cli/introduction)
