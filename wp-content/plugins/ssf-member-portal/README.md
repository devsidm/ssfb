# SSF Medlemsportal

WordPress-grund för SSF:s medlemsfunktioner. Modulen **Motioner** hanterar motionsperiod, inlämningar, kvitton, administrativa statusar och en valfri asynkron arkivering i SharePoint.

## Motioner

1. Aktivera tillägget och öppna `SSF > Motioner > Motionsperiod`.
2. Skapa ett årsmöte med år, mötesdatum samt öppnings- och stängningstid.
3. Välj mötet under `SSF > Motioner > Inställningar`.
4. Sidan `Lämna motion` skapas automatiskt med kortkoden `[ssf_member_portal_motions]`.

En motion sparas alltid i WordPress före e-post och SharePoint-synk. Ett fel hos Microsoft kan därför inte göra att en inlämnad motion försvinner.

## Microsoft Graph och SharePoint

Konfigurationen kan läggas in under `SSF > Motioner > Microsoft 365`. Client secret anges där endast vid sparning, lagras krypterat med WordPress serverhemligheter och visas aldrig igen. Serverkonfiguration i `wp-config.php` eller miljövariabler har alltid företräde, vilket rekommenderas för produktion. Utgå vid behov från [docs/microsoft-graph-wp-config.example.php](docs/microsoft-graph-wp-config.example.php) och lägg in motsvarande konstanter utanför Git.

`SSF_GRAPH_CLIENT_SECRET` ska vara **Client secret value**, inte Secret ID. Det ska helst vara en miljövariabel. Spara aldrig ett client secret i plugin-kod, databasexporter eller Git.

Krävda konstanter:

```php
SSF_GRAPH_TENANT_ID
SSF_GRAPH_CLIENT_ID
SSF_GRAPH_CLIENT_SECRET
SSF_GRAPH_SITE_ID
SSF_GRAPH_DRIVE_ID
SSF_GRAPH_ANNUAL_MEETING_FOLDER_ID
SSF_GRAPH_ANNUAL_MEETING_FOLDER_NAME
```

`SSF_GRAPH_SITE_HOSTNAME` och `SSF_GRAPH_SITE_PATH` är frivilliga, men rekommenderas för att diagnostiken också ska verifiera siten via dess SharePoint-sökväg.

### Entra-konfiguration

Skapa en app registration för **Single tenant** och använd OAuth 2.0 Client Credentials Flow. Ingen Redirect URI och ingen användarinloggning behövs. Token hämtas från:

```text
POST https://login.microsoftonline.com/{tenant-id}/oauth2/v2.0/token
scope=https://graph.microsoft.com/.default
grant_type=client_credentials
```

Pluginet cachelagrar token med WordPress Transients API fram till `expires_in - 300` sekunder. Token, Authorization-header och secret loggas eller visas aldrig.

### Microsoft Graph-behörighet

Den valda Graph v1.0-metoden använder app-only uppladdning med `PUT /drives/{drive-id}/items/{parent-id}:/{filename}:/content`. SSF använder **Microsoft Graph Application permission `Sites.Selected`** med en explicit `write`-grant till enbart styrelsens SharePoint-site. Admin consent krävs för permissionen och site-granten krävs dessutom för den valda siten.

`Files.ReadWrite.All`, `Sites.ReadWrite.All` och `Sites.FullControl.All` ska inte läggas till när `Sites.Selected` med site-grant fungerar. Microsofts aktuella referens finns i [Graph permissions reference](https://learn.microsoft.com/en-us/graph/permissions-reference) och [Upload small files](https://learn.microsoft.com/en-us/graph/api/driveitem-put-content?view=graph-rest-1.0).

### Testa säkert

Öppna `SSF > Motioner > Microsoft 365` som administratör eller användare med motionsbehörighet.

1. **Testa autentisering** hämtar en app-only token utan att visa den.
2. **Testa läsåtkomst** läser site, dokumentbiblioteket, Årsmöten-mappen och dess innehåll.
3. **Testa skrivåtkomst** skapar och tar bort en unik temporär mapp under Årsmöten.
4. **Förbered motionsmapp** hittar eller skapar `Årsmöten/{år}/Motioner/` för aktuellt kalenderår.
5. **Testa filuppladdning** lägger upp en liten `ssf-graph-test-*.txt`; **Ta bort testfil** raderar endast den filen.

Adminvyn sparar en diagnostikrapport med endpoint, HTTP-status, Graph-felkod, felmeddelande och tidsstämpel. Rapporten filtrerar bort token, secret och Authorization-data.

## Synk av riktiga motioner

Efter att en motion och dess WordPress-bilagor har sparats köas SharePoint-synken i bakgrunden. Bilagor läggs i:

```text
Årsmöten/{YEAR}/Motioner/
```

Filnamn följer `{motionsnummer}-{sanerad-rubrik}.{originaländelse}`. För varje bilaga sparas DriveItem ID, Drive ID, överordnad mapp, `webUrl`, filnamn och uppladdningstid i motionspostens metadata.

Varje dokument får dessutom SharePoint-metadata för `WordPressMotionID`, `Motionnummer`, `Status`, `Fartyg` och `InkommenDatum`. Standardnamnen kan ändras under Microsoft 365 om SharePoints interna kolumnnamn skiljer sig från de synliga kolumnrubrikerna.

Statusar är `pending`, `syncing`, `synced` och `error`. Vid fel görs automatiska försök efter 5 minuter, 30 minuter och 2 timmar. Därefter behålls felstatusen, och administratören kan använda **Försök igen** på motionsposten.

## Power Automate-statussynk

En komplett Solution-specifikation med trigger, villkor, expressions,
environment variables, felhantering och driftsättning finns i
[docs/power-automate-motion-status-flow.md](docs/power-automate-motion-status-flow.md).

Under `SSF > Motioner > Microsoft 365` finns webhook-URL, status för secret och en kontroll för inbound-synk. Ett secret sparas krypterat, visas aldrig igen och kan alternativt sättas på servern med `SSF_MOTIONS_WEBHOOK_SECRET`.

Power Automate ska skicka `POST` med `Content-Type: application/json` till den URL som visas i admin (på utvecklingsmiljön innehåller den automatiskt `/dev/`) och HTTP-headern `X-SSF-Webhook-Secret`.

```json
{
  "wordpress_motion_id": "1842",
  "motion_number": "2027-004",
  "status": "Under behandling",
  "sharepoint_list_item_id": "123",
  "sharepoint_file_url": "https://tenant.sharepoint.com/...",
  "changed_at": "2027-03-10T12:30:00Z"
}
```

Tillåtna SharePoint-statusar är `Inkommen`, `Under behandling`, `Begär komplettering`, `Färdigbehandlad`, `Till årsmötet` och `Avslutad`. Webhooken verifierar både WordPress-ID och motionsnummer, är idempotent och returnerar `401` vid fel secret, `404` om motionen saknas, `409` om identiteten inte matchar och `400` vid ogiltig status eller payload.

Statusuppdateringar från Power Automate får källan `power_automate`, sparas i statushistoriken och skrivs inte tillbaka till SharePoint. Manuella statusändringar i WordPress får källan `wordpress` och köas för uppdatering av SharePoints statusmetadata.

## Felsökning

- `401` vid token-test: kontrollera nytt Client secret **value**, Tenant ID, Client ID och att secret inte har löpt ut.
- `403` från Graph: kontrollera att `Sites.Selected` är av typen **Application**, att Admin consent har givits och att appen har en explicit `write`-grant till styrelsens site.
- `404` för site, drive eller rotmapp: kontrollera respektive Graph ID i `wp-config.php`.
- `409` vid mappskapande: pluginet söker om efter den befintliga mappen och fortsätter när den hittas.
