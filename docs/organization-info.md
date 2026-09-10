# SSF organisationsuppgifter

`wp-content/mu-plugins/ssf-organization-info.php` är gemensam källa för
förbundets namn, webbplats, postadress, organisationsnummer, betalningsuppgifter
och medlemsavgifter. Säkra grundvärden följer koden och kan uppdateras under
`SSF -> System -> Microsoft 365 -> Organisationsuppgifter och e-postdesign`.

Komponenten används av:

- webbplatsens sidfot i SSF-temat
- organisationssektionen på den befintliga sidan `Förbundet`
- avgifts- och betalningssektionen på den befintliga sidan `Medlemskap`
- den gemensamma e-postmallen
- betalningsinformationen i bekräftelsen på en fartygsansökan

Sidorna kompletteras med filter på `the_content`. Befintligt redaktionellt
WordPress-innehåll skrivs inte över. Om motsvarande shortcode redan finns på
sidan läggs ingen extra sektion till.

Medlemsavgifter:

- Stödmedlem: 200 kr/år
- Fritidsfartyg: 500 kr/år per fartyg
- Handelsfartyg: 1 500 kr/år per fartyg

Betalning görs till bankgiro 332-1908 eller Swish 1236400279. Stödmedlemmar
anger namn och fartygssökande anger ansökningsnummer som betalningsreferens.
