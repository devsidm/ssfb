# Central SharePoint-konfiguration

Medlemsansökningarnas workflowkolumner skapas alltid manuellt i SharePoint. WordPress läser och validerar schemat men gör inga `POST`- eller `PATCH`-anrop mot `/columns`. Fullständig fältlista och exakta Choice-värden finns i [membership-applications.md](membership-applications.md).

SSF använder två SharePoint-destinationer:

- `annual_meetings`: motioner, motionsmetadata och årsmötesanmälningsexporter.
- `membership_applications`: ansöknings-PDF, bilder, bilagor, metadata och statussynk.

## Miljöval

`SharePointDestinations::environment()` läser `wp_get_environment_type()`. Endast värdet `production` väljer produktionsprofilen; `development`, `staging` och `local` använder den säkrare development-profilen. URL eller sökväg används aldrig för miljöval.

Runtime hämtar redan upptäckta ID:n via `Configuration::value()`, som delegerar gamla fältnamn till den centrala destinationsmodellen. Discovery körs bara i administrationen.

## Konfiguration

Öppna **SSF → System → Microsoft 365**.

1. Ange SharePoint Site URL och välj **Hitta site**.
2. Välj **Hitta dokumentbibliotek** och använd rätt bibliotek.
3. Ange mappväg eller bläddra från roten och välj mapp.
4. Kör det skrivskyddade anslutningstestet.
5. Kör skrivtestet endast när en temporär fil får skapas och tas bort.
6. Spara destinationen.

Site ID, Drive ID, List ID och Folder ID finns under **Avancerat / identifierare**. SharePoint-kolumner kan läsas under **Avancerat / metadatafält**.

## Sites.Selected

Vid 403 visar administrationen appens Client ID, site och önskad roll samt färdiga Graph Explorer-anrop. WordPress försöker inte ge sig själv behörighet och kräver inga bredare Graph-rättigheter.

En administratör med rätt onboarding-behörighet använder instruktionens `POST /sites/{SITE_ID}/permissions` med rollen `write`. Därefter kan anslutningstestet köras igen.

## Migration

Första gången destinationsmodellen läses migreras gamla Graph-fält idempotent från `ssf_member_portal_graph_configuration` till den aktiva installationens miljöprofil. Inga värden kopieras till den andra miljön och gamla fält lämnas kvar för rollback och bakåtkompatibilitet.

Serverkonstanter har fortsatt företräde för installationens aktiva profil. DEV och PROD har separata WordPress-databaser, och vanliga koddeployments kopierar inte options mellan dem.
