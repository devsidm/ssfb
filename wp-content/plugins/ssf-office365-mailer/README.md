# SSF Microsoft 365 Mailer

This plugin sends WordPress email through Microsoft Graph using OAuth 2.0. It uses the mailbox that authorizes the connection as the sender, so normal site mail does not require Exchange "Send As" delegation.

## Microsoft Entra setup

1. Open Microsoft Entra admin center > App registrations > New registration.
2. Use a single-tenant app for the SSF tenant.
3. In Authentication, add the Redirect URI shown under `Settings > SSF Microsoft 365 Mailer` in WordPress. Select platform type `Web`.
4. In API permissions, add Microsoft Graph delegated permissions `Mail.Send` and `User.Read`. Grant admin consent if the tenant requires it.
5. In Certificates & secrets, create a client secret and copy its **Value**.
6. Copy the Application (client) ID and Directory (tenant) ID from the app overview.

## WordPress setup

1. Go to `Settings > SSF Microsoft 365 Mailer`.
2. Save the Application Client ID, Tenant ID, and Client Secret Value.
3. Click `Anslut Microsoft 365` and sign in with the mailbox that should send SSF email.
4. Use the email test in the WordPress admin or send a collection link from a member vessel.
5. After testing, deactivate FluentSMTP to leave a single active mailer configuration.

The Client Secret and OAuth tokens are encrypted at rest with WordPress salts before they are stored in the WordPress database.
