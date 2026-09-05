# SSF release-, build- och deploymenthantering

`SSF_Release_Manager` i `wp-content/mu-plugins/ssf-release-controls.php` är den centrala releasehanteraren. Footer, adminfält, systemstatus och deployverktyg läser samma data.

## Begrepp och datakällor

| Begrepp | Exempel | Ansvar |
| --- | --- | --- |
| Version | `1.4.0` | Semantic Versioning och ett medvetet releasebeslut. |
| Build | `20260905.2` | Unikt, automatiskt ID för ett visst kodpaket. |
| Deployment | `SUCCESS` i Development | Resultatet av att installera en build i en miljö. |
| Miljö | Development eller Production | Kommer från serverns WordPress-konfiguration. |

Kodpaketets källa är `wp-content/mu-plugins/ssf-release-manifest.json`. Manifestet innehåller version, build, byggtid, Git-revision, källa, beskrivning och ändrade komponenter. Det skapas en gång i DEV och följer oförändrat med till production.

Deploymenthistorik lagras lokalt i varje WordPress-installation i alternativen `ssf_release_deployments` och `ssf_release_current_deployment`. Äldre poster i `ssf_release` behålls och visas som äldre historik när buildinformation saknas.

## Miljö

Miljön läses i följande ordning:

1. `SSF_ENVIRONMENT`
2. `WP_ENVIRONMENT_TYPE`
3. WordPress `wp_get_environment_type()`
4. `production` som defensivt standardvärde om WordPress-funktionen inte finns

Normal serverkonfiguration finns i [wp-config-release.example.php](wp-config-release.example.php). Deployverktyget ändrar aldrig `wp-config.php`.

## Admin

Öppna **SSF > Release** eller `wp-admin/admin.php?page=ssf-release`.

Sidan visar aktuell miljö, version, build, byggtid, deploymenttid, källa och status. Den innehåller även deploymenthistorik, releasehistorik och revisionslogg.

I DEV går det att registrera en build och förbereda en version. I production kan ingen ny build skapas och ingen version ändras. Där verifieras i stället att det installerade manifestet är exakt den build som skulle driftsättas.

## Kommandon

Med WP-CLI:

```powershell
wp ssf release status
wp ssf release build --description="Kort beskrivning"
wp ssf release prepare --patch --notes="Releaseanteckningar"
wp ssf release prepare --minor
wp ssf release prepare --major
wp ssf release prepare --version=2.0.0
wp ssf release deploy --expected-build=20260905.2
wp ssf release verify --expected-build=20260905.2
```

Lokalt finns motsvarande skript:

```powershell
.\scripts\ssf-release-build.ps1 -Description "Kort beskrivning"
.\scripts\ssf-release-prepare.ps1 -Level patch -Notes "Releaseanteckningar"
```

En första stabil version måste anges explicit, till exempel `-Version 1.0.0`. Därefter kan `patch`, `minor` eller `major` användas. Versionshöjningen utförs aldrig automatiskt utifrån kodinnehållet.

## Codexflöde till DEV

Varje färdig ändring följer samma ordning:

1. Läs status med `wp ssf release status` när WP-CLI finns.
2. Implementera och testa ändringen.
3. Committa koden så att builden får en bestämd Git-revision.
4. Registrera builden med `scripts/ssf-release-build.ps1`.
5. Committa manifestet och pusha båda commits till `origin/main`.
6. Kör deployskriptet med `-Environment development`.
7. Kontrollera att scriptet returnerar `SUCCESS` och att **SSF > Release** visar samma build.

Buildregistreringen skannar inte webbplatsen sida för sida. Komponentlistan skapas från Git-diffen sedan föregående build, och ett filbaserat lås gör samtidiga registreringar sekventiella.

## DEV-deployment via FTP

Hemligheter anges endast som processmiljövariabler och sparas inte i Git:

```powershell
$env:SSF_FTP_PASSWORD = '<ftp-lösenord>'
$env:SSF_WP_USER = '<wordpress-användare>'
$env:SSF_WP_PASSWORD = '<wordpress-lösenord>'

.\scripts\ssf-release-deploy.ps1 `
  -Environment development `
  -BaseUrl 'https://ssfb.se/dev' `
  -FtpHost '<ftp-värd>' `
  -FtpUser '<ftp-konto>'
```

Skriptet gör preflight, laddar endast upp Git-spårade filer under `wp-content`, kör smoke tests, kontrollerar miljö och build och registrerar deploymenten som lyckad först efter verifiering. Databas, `wp-config.php`, uploads och hemligheter kopieras inte.

## Förbered och flytta till production

1. Välj nästa version explicit i DEV: `patch`, `minor`, `major` eller ett angivet SemVer-nummer.
2. Kör fullständiga tester i DEV.
3. Säkerhetskopiera production-databasen och `wp-content/uploads` med värdens ordinarie backupverktyg.
4. Flytta samma commit och samma manifest. Kör ingen ny build.
5. Kör deployskriptet med `-Environment production -BackupConfirmed`.
6. Verifiera productionmiljö, build, centrala sidor och formulär.

```powershell
.\scripts\ssf-release-deploy.ps1 `
  -Environment production `
  -BackupConfirmed `
  -BaseUrl 'https://ssfb.se' `
  -FtpHost '<ftp-värd>' `
  -FtpUser '<ftp-konto>'
```

Production-deployment stoppas om manifestet inte är förberett, saknar stabil version, Git-trädet är smutsigt eller backup inte har bekräftats.

## Fel och återställning

Misslyckad verifiering registreras som `FAILED` med förväntad build, upptäckt build och orsak. Föregående lyckade build sparas i historiken.

Återställning görs genom att checka ut den tidigare Git-revisionen med dess befintliga manifest och deploya det paketet på nytt. Skapa inte en ny build av gammal kod. Återställ databas eller uploads endast genom värdens backupverktyg när ändringen faktiskt kräver det.

## Funktionsstyrning

Releasehanteringen ändrar inte funktionslägen. De hanteras separat under **SSF > Funktioner** och lagras per WordPress-installation. En deployment får därför inte automatiskt öppna formulär eller ändra publik synlighet.
