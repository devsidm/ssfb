# SSF identity, access and Microsoft configuration inventory

This inventory is based on the code before the access/configuration refactor. It
contains storage names and override names, never credential values. DEV and PROD
are separate WordPress databases. No PROD data migration is part of this work.

| Setting | Canonical owner | Current storage | Runtime consumers | Admin writer | Server overrides | Legacy sources / classification | Action |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Tenant ID, organisation, domain, cloud | `SSF_Microsoft365_Config` | `ssf_microsoft365_tenant_configuration`, active environment profile | Login OIDC, SharePoint Graph, Mailer OAuth | Central directory form | `SSF_MICROSOFT365_TENANT_ID`, `SSF_MICROSOFT365_AUTHORITY_HOST` | Login profile `tenant_id`, Graph `tenant_id`, Mailer `tenant_id`, `SSF_GRAPH_TENANT_ID`, `SSF_M365_LOGIN_TENANT_ID`: **MIGRATION_ONLY** | Retain central resolver; migrate only from current environment if central value is empty; leave legacy DB values intact. |
| Login enabled, client ID, client secret | Microsoft Login | `ssf_microsoft_login_settings.profiles[active environment]` | OAuth authorization, callback, configuration checks | Login settings form | `SSF_M365_LOGIN_ENABLED`, `SSF_M365_LOGIN_CLIENT_ID`, `SSF_M365_LOGIN_CLIENT_SECRET` | Other environment profile in same option: **COMPATIBILITY** data, not active runtime | Keep active-environment credentials and overrides; remove profile-selector terminology/UI. Preserve `getenv() === false` fallback and GUID validation. |
| SharePoint client ID, encrypted secret | SharePoint `Configuration` | `ssf_member_portal_graph_configuration` | Graph token and destinations | SharePoint admin | `SSF_GRAPH_CLIENT_ID`, `SSF_GRAPH_CLIENT_SECRET` | Graph `tenant_id`: **MIGRATION_ONLY**; destination fields: **CANONICAL** | Preserve distinct Graph app and Sites.Selected. Stop presenting/writing duplicate tenant field. |
| SharePoint sites, drives, folders, metadata names | SharePoint destinations/configuration | Graph option plus destination option | Motions, applications, annual meetings, migration | SharePoint admin | Corresponding `SSF_GRAPH_*` destination overrides | Existing values: **CANONICAL/COMPATIBILITY**, not disposable | Do not change business destinations or schema behaviour. |
| Mailer client ID, encrypted secret, enabled, review recipient | `ssf-office365-mailer` | `ssf_office365_mailer_settings` | Mailer OAuth and mail routing | Mailer settings form | None found | Mailer `tenant_id`: **MIGRATION_ONLY** | Keep distinct mailer app; use central tenant and preserve settings/tokens. |
| Mailer OAuth tokens and connected email | `ssf-office365-mailer` | `ssf_office365_mailer_tokens` | Mail sending and token refresh | OAuth callback/connect/disconnect | None found | None confirmed unused | Preserve; never render token values. |
| Microsoft identity binding and last login | Microsoft Login | User meta `_ssf_m365_tid`, `_ssf_m365_oid`, `_ssf_m365_email`, `_ssf_m365_last_login` | Login, account linking, invitation activation | OAuth callback/unlink | None | **CANONICAL** | Keep distinct from SSF access and move admin view to account links. |
| SSF permission groups | SSF Access Control (target) | User meta `_ssf_permission_groups` | `user_has_cap`, case handling and other SSF capability checks | Currently Microsoft Login user page/profile | None | Current group values: **CANONICAL** | Preserve meta; move definitions, validation and capability resolver to central SSF component. |
| Invitations | SSF user administration (target), Microsoft Login activation backend | `ssf_microsoft_login_invitations` | Invitation token, activation callback | Currently Microsoft Login user page | None | Existing open tokens: **COMPATIBILITY** | Preserve option and secure activation flow; move management UI, not token format. |
| Permission audit | SSF Access Control (target) | `ssf_microsoft_login_permission_audit` | Admin audit | Currently Microsoft Login group writer | None | Existing audit records: **COMPATIBILITY** | Preserve records; include before/after and access status in new events. |

## Legacy and UI classification

- **CANONICAL:** central tenant resolver; each integration's own app credentials;
  SharePoint destinations; Microsoft TID/OID binding; existing SSF permission meta.
- **COMPATIBILITY:** invitation token storage/activation methods; inactive profile
  data in Login settings; existing permission audit; old admin URLs while links
  are migrated.
- **MIGRATION_ONLY:** tenant candidates in old Login/Graph/Mailer options and
  old tenant constants. They are not normal runtime sources.
- **STALE_ADMIN_UI:** permission-group editor inside Microsoft Login; generic
  Integrationer tab; separate Login/Mailer menu entries; active-profile framing.
- **STALE_DOCUMENTATION:** old admin paths and cross-environment profile
  instructions. Update after the new pages are stable.
- **LEGACY_UNUSED:** no runtime code confirmed safe for immediate deletion yet.

The current Microsoft overview/diagnostics reads some raw Login and Mailer
options instead of the effective runtime resolvers. That can misreport
server-overridden credentials. Treat its status as informational until it is
rewired to the same resolver used by OAuth/Graph/Mailer.
