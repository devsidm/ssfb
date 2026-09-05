# WordPress Operations

## Secure credential handling

Do not commit WordPress passwords, application passwords, tokens, SMTP credentials, or API keys.

For local REST scripts, set the application password only in the current shell:

```powershell
$env:SSF_WP_APP_PASSWORD = "paste application password here"
```

Then run:

```powershell
.\scripts\wp-rest-inspect.ps1
.\scripts\wp-rest-create-pages.ps1
```

## Deployment outline

Use the controlled build and deployment flow in [release-controls.md](release-controls.md). The deploy script transfers only Git-tracked files in `wp-content`, verifies the environment and build, runs smoke tests, and records the result in WordPress.

For a new WordPress installation, activate the `SSF` theme and `SSF Site Customizations` plugin, create the pages with the listed shortcodes, and configure SMTP before relying on form email delivery.

## Live dev update notes

The WordPress dev site at `https://ssfb.se/dev` has been updated through the REST API with designed page content for:

- `/`
- `/om-ssf/`
- `/medlemskap/`
- `/ansokan/`
- `/medlemsfartyg/`
- `/stadgar/`
- `/nyheter/`
- `/kontakta-oss/`

The site title and front page setting have also been updated. The default `sample-page` was moved to draft.

The custom theme and plugin were deployed over FTP to:

- `public_html/dev/wp-content/themes/ssf`
- `public_html/dev/wp-content/plugins/ssf-site-customizations`

Active theme:

- `ssf` / `SSF` version `0.1.0`

Active project plugin:

- `ssf-site-customizations/ssf-site-customizations` version `0.1.0`

The main menu was created as `SSF huvudmeny` and assigned to the theme's `primary` location.

Verified public pages:

- `/`
- `/om-ssf/`
- `/medlemskap/`
- `/ansokan/`
- `/medlemsfartyg/`
- `/stadgar/`
- `/nyheter/`
- `/kontakta-oss/`

Forms are rendered by the project plugin. Application submissions are saved as private `ssf_ansokan` posts and contact messages as private `ssf_kontakt` posts. Their recipients are configured centrally under **SSF > Inställningar > E-postmottagare** and can differ between DEV and production.

## Shortcodes

- `[ssf_home]` renders the front page.
- `[ssf_application_form]` renders the guided fartygsombud application.
- `[ssf_contact_form]` renders the contact form.
- `[ssf_news_cards count="4"]` renders news cards.
- `[ssf_member_vessels]` renders member vessel cards.

## Submissions

Application submissions are stored as private `ssf_ansokan` posts in WordPress.

Contact messages are stored as private `ssf_kontakt` posts in WordPress.

Access should be limited to administrators. SMTP, spam protection, backup destination, and any CAPTCHA keys must be configured in WordPress admin.
