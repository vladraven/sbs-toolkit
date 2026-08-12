# SBS Toolkit

**Version:** 1.0.4  
**Requires:** WordPress 6.0+, PHP 8.1+  
**License:** Proprietary (site license)

Modular all-in-one toolkit: **Backup**, **Performance**, **Security**, **Deception**, **Analytics**, **DevOps**.

Designed for a freemium model:

| Plan | Runtime | Limits |
|------|---------|--------|
| **Trial** (90 days) | All modules | Full features |
| **Pro** | All modules | Schedule, remote storage, multi-retention |
| **Free** | **One** active module | Soft-Lock on the rest; Backup = 1 local zip, no cron, no remote |

Soft-Lock: previously applied frontend/runtime effects stay active; admin mutation UI is read-only until the module is active again or Pro is enabled.

---

## Install

1. Copy the plugin folder to `wp-content/plugins/sbs-toolkit/`
2. Activate in **Plugins**
3. On activation the plugin:
   - Creates custom DB tables
   - Creates `wp-content/sbs-storage/` (with `.htaccess` deny)
   - Generates a **site-unique** license HMAC secret (`sbs_license_secret` option + `.license_secret` file)

Optional override in `wp-config.php`:

```php
define( 'SBS_LICENSE_SECRET', 'your-long-random-secret' );


Modules
Backup (backup)

One complete .zip per run: full site + database.sql inside
Atomic write: *.zip.building → rename on success
Manual run is synchronous (AJAX)
Pro: schedule off|daily|weekly, retention 1–90, FTP/S3 after backup
Free: max 1 archive, no schedule, no remote
Actions: download, rename, delete, restore (skips wp-config.php)

Storage: wp-content/sbs-storage/backup-YYYYMMDD-HHMMSS.zip
Performance

Head cleanup, HTML minify, image format options, custom CSS/JS hooks
Optional page cache drop-in (WP_CACHE)

Security

Hardening options, CVE scan helper, session tools
Custom login slug via LoginGuard

Emergency unlock if custom login locks you out:

define( 'SBS_DISABLE_CUSTOM_LOGIN', true ); in wp-config.php, or
Visit: /wp-login.php?sbs_emergency=TOKEN
Token is stored in option sbs_login_emergency_token (generated when custom login is enabled)

Deception

Default OFF (module_enabled, honeypot_enabled)
Honeypot paths + IP ban (.htaccess markers + PHP fallback)
Soft-Lock still allows unban (anti self-lockout)

Analytics

Default OFF
REST tracker with per-IP rate limit (60/min)
Dashboard aggregates in admin

DevOps

White-label name, remote token helpers, uptime hooks


License keys
Pro keys are HMAC-signed against the site secret and domain hash.
Activate under SBS Toolkit → License.
Trial starts on first activation (90 days). After expiry the site falls back to Free (one active module).
Free module switch cooldown: 30 days.

Development notes

No Laravel / heavy frameworks — plain WP hooks
Job queue only allows whitelisted task classes
Prefer ZipArchive; large sites may hit PHP max execution time on full zip — raise limits or run off-peak
Do not commit sbs-storage/ or generated secrets

Uninstall
Use WordPress “Delete” plugin: uninstall.php drops tables/options where implemented.
sbs-storage backups on disk may remain — remove manually if needed.

Changelog (recent)
1.0.4

Single-zip backup with embedded database.sql and atomic .building finalize
Manual backup runs inline (no broken part-file queues)
Restore / download / rename / delete
License secret generated on activation (not hardcoded in git)
Honeypot & analytics default off; analytics rate-limited
Free limits: 1 backup, no schedule/remote
Schedule wiring via BackupModule::sync_schedule() after settings save
Login emergency token + SBS_DISABLE_CUSTOM_LOGIN
Media cleaner path confinement via realpath
Admin UI: schedule, retention, version from SBS_VERSION


Support
Author: Vlad
Issues: use your private tracker / GitHub issues on this repository.