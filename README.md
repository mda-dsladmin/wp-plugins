# MD Anderson WordPress plugins

WordPress plugins built and maintained by Montez French (Senior Web Developer, MD Anderson). Every site running these plugins gets its updates from this repository's GitHub Releases.

| Folder | Plugin | Docs |
|---|---|---|
| `beacon-analytics/` | Beacon Analytics: private, first-party analytics and site scans | `beacon-analytics/README.md`, `beacon-analytics/docs/SECURITY-AND-PRIVACY.md` |
| `mda-media-player-fit/` | MDA Media Player Fit: fits embedded videos to the page at 16:9 | `mda-media-player-fit/readme.txt` |

For leadership and IT: **`docs/WP-PLUGINS-GUIDE.md`** (also as Word and PDF in `docs/`) explains what each plugin is and is not, compatibility, how updates work, the security review, and the GitHub settings that keep releases safe.

## Requirements

WordPress 6.0 or newer, PHP 7.0 through 8.4.

## Publishing a release

No terminal needed:

1. Bump the version in the plugin's main file: the `Version:` header line and the version constant (`BEACON_VERSION` or `MDAMPF_VERSION`). Add a changelog line. (On GitHub: open the file, click the pencil, edit, commit to `main`. Or use **Add file > Upload files**.)
2. Make sure the change is on `main`.
3. **Actions > Release plugin > Run workflow**, branch `main`, pick the plugin, **Run workflow**.
4. The action creates the tag `<plugin-folder>-v<version>`, builds `<plugin-folder>.zip`, and publishes the release. It stops with an error if the version is not plain (1.2.3 or 1.2.3.4), the versions in the file do not match, that version already exists, the commit is not on `main`, or the folder contains a symbolic link.

Also supported, with the same checks: publishing a release on the Releases page with a new tag `<plugin-folder>-v<version>`, or `git push` of that tag.

Do not attach zips to releases by hand; the action builds them.

When you add a plugin folder, add its name to the `options` list in `.github/workflows/release.yml`.

Sites see the update within about 12 hours, or right away with **Check for updates** under the plugin on the Plugins screen.

## On each site

- Updates are checked about twice a day. Turn on **Enable auto-updates** for each plugin on the Plugins screen to install them without clicking.
- **Check for updates** under each plugin checks right away.
- Copies older than Beacon Analytics 1.17.0 or MDA Media Player Fit 1.1.0 must be updated once by hand (Upload Plugin, then Replace current with uploaded). After that they update from GitHub.
- The server must reach `api.github.com`, `github.com` and `release-assets.githubusercontent.com` over HTTPS.

## Optional token for busy servers

GitHub allows 60 anonymous API requests per hour per server IP. If a shared server hits that limit, add a read-only, fine-grained token (public repositories, read access only) to that site's `wp-config.php`:

```
define( 'MDA_WP_PLUGINS_GITHUB_TOKEN', 'github_pat_...' );
```

Never commit a token to this repository.

## Adding a plugin

See section 8 of `docs/WP-PLUGINS-GUIDE.md`.
