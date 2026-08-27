# SharePoint Test Report

Environment: `https://ssfb.se/dev/`  
Test date: 2026-08-27

| Test | Result | Notes |
| --- | --- | --- |
| Client Credentials authentication | PASS | Microsoft Entra returned HTTP 200 and a Bearer token. The token was not stored in diagnostics. |
| Sites.Selected access | PASS | The app read the configured site and performed a scoped create/delete operation under Årsmöten. |
| SharePoint site | PASS | `styrelsen` was returned from the configured hostname and site path. |
| Document library | PASS | `Dokument` with `documentLibrary` drive type was returned. |
| Root folder | PASS | The existing Årsmöten folder was found by DriveItem ID. |
| Temporary write test | PASS | A uniquely named test folder was created and deleted. |
| Year folder | PASS | The 2026 folder was found. |
| Motion folder | PASS | `Motioner` was ensured below Årsmöten/2026. |
| Test file upload | PASS | A small `ssf-graph-test-*.txt` file was uploaded and Graph returned DriveItem ID and web URL. |
| Test file cleanup | PASS | The test file was deleted. |
| Real motion upload | Pending live submission | The same asynchronous upload path is implemented; verify with the next submitted motion. |

No client secret, access token or Authorization header is stored in this report.
