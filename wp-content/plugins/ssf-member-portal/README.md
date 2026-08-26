# SSF Medlemsportal

Gemensam WordPress-grund för SSF:s medlemsfunktioner. Version 1 innehåller modulen **Motioner**.

## Administrera en motionsperiod

1. Aktivera tillägget och öppna `SSF > Motioner > Motionsperiod`.
2. Skapa ett årsmöte och ange år, mötesdatum samt öppnings- och stängningstid för motioner.
3. Välj årsmötet som aktivt under `SSF > Motioner > Inställningar`.
4. Publicera eller länka till sidan `Lämna motion`. Den skapas med kortkoden `[ssf_member_portal_motions]`.

Tidpunkter tolkas i WordPress konfigurerade tidszon. Varje inskickad motion sparar en permanent kopia av den motionsfrist som gällde vid inskickningen.

## E-post och SharePoint

Motioner skickas med `wp_mail()`. När SSF Microsoft 365 Mailer är aktiv tar den automatiskt hand om leveransen via Microsoft 365.

SharePoint-synk är valfri och asynkron. WordPress är alltid systemet för motionsärendet; en synkstörning kan aldrig blockera en inskickad motion. Konfigurationen finns under `SSF > Motioner > Microsoft 365` och använder en separat Graph-app med minsta nödvändiga platsbehörighet.

Appen behöver **Microsoft Graph Application permissions**. Rekommenderad minsta behörighet är `Sites.Selected`, tillsammans med en separat skrivbehörighet till SSF:s Teams-webbplats. `Sites.ReadWrite.All` fungerar men ger bredare åtkomst och bör undvikas om `Sites.Selected` kan användas. Lägg in Graph-värdena för **Site ID** och **Document Library / Drive ID** – inte en Teams-URL. E-postapplikationen med delegerad `Mail.Send` kan inte återanvändas för denna synk.

Kör `Testa SharePoint-anslutningen` på samma adminsida efter varje konfigurationsändring. Vid godkänt test laddas en tidsstämplad kontrollfil upp till `Årsmöten/{år}/Motioner/`.
