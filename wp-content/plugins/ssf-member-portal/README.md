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

Den valda Graph v1.0-metoden använder app-only uppladdning med `PUT /drives/{drive-id}/items/{parent-id}:/{filename}:/content`. För den här implementationen ska appen ha **Microsoft Graph Application permission `Files.ReadWrite.All`** och en Global Administrator måste ge **Admin consent**.

`Files.ReadWrite.All` ger appen läs- och skrivåtkomst till filer i organisationen. `Sites.ReadWrite.All` ger en bredare åtkomst till SharePoint-webbplatser och ska inte läggas till utan ett uttryckligt behov. `Sites.Selected` är inte aktiverat automatiskt; den begränsningsmodellen behöver verifieras separat mot de Graph-endpoints som används innan den kan ersätta den valda behörigheten. Microsofts aktuella referens finns i [Graph permissions reference](https://learn.microsoft.com/en-us/graph/permissions-reference) och [Upload small files](https://learn.microsoft.com/en-us/graph/api/driveitem-put-content?view=graph-rest-1.0).

### Testa säkert

Öppna `SSF > Motioner > Microsoft 365` som administratör eller användare med motionsbehörighet.

1. **Testa anslutning** autentiserar och läser site, dokumentbiblioteket, Årsmöten-mappen och dess innehåll. Det skriver inget.
2. **Testa skrivåtkomst** hittar eller skapar `Årsmöten/{år}/Motioner/` för aktuellt kalenderår.
3. **Ladda upp testfil** lägger upp en liten `ssf-graph-test-*.txt` i den mappen.
4. **Ta bort testfil** får bara radera den senast skapade testfilen från pluginet.

Adminvyn sparar en diagnostikrapport med endpoint, HTTP-status, Graph-felkod, felmeddelande och tidsstämpel. Rapporten filtrerar bort token, secret och Authorization-data.

## Synk av riktiga motioner

Efter att en motion och dess WordPress-bilagor har sparats köas SharePoint-synken i bakgrunden. Bilagor läggs i:

```text
Årsmöten/{YEAR}/Motioner/
```

Filnamn följer `{motionsnummer}-{sanerad-rubrik}.{originaländelse}`. För varje bilaga sparas DriveItem ID, Drive ID, överordnad mapp, `webUrl`, filnamn och uppladdningstid i motionspostens metadata.

Statusar är `pending`, `syncing`, `synced` och `error`. Vid fel görs automatiska försök efter 5 minuter, 30 minuter och 2 timmar. Därefter behålls felstatusen, och administratören kan använda **Försök igen** på motionsposten.

## Felsökning

- `401` vid token-test: kontrollera nytt Client secret **value**, Tenant ID, Client ID och att secret inte har löpt ut.
- `403` från Graph: kontrollera att `Files.ReadWrite.All` är av typen **Application**, att Admin consent har givits och att rätt app registration används.
- `404` för site, drive eller rotmapp: kontrollera respektive Graph ID i `wp-config.php`.
- `409` vid mappskapande: pluginet söker om efter den befintliga mappen och fortsätter när den hittas.
