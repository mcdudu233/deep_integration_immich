<div align="center">

<img src="img/app-dark.svg" width="80" alt="Immich Integration">

# Immich Orchestration for Nextcloud

**Provision Immich users from Nextcloud, expose Immich-owned photo libraries as read-only Nextcloud mounts, and keep the existing Immich browsing UI inside Nextcloud.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](COPYING)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-27--34-0082C9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![Immich](https://img.shields.io/badge/Immich-admin%20API-4AC37C)](https://immich.app)

</div>

---

## Architecture Overview: Scheme B

This fork is a Nextcloud-side Immich orchestration app. It keeps Immich as the system of record for photo originals while Nextcloud exposes each user's Immich personal library folder as a read-only mirror.

```text
Immich owns photo originals and applies its storage template
        -> Immich writes each user's library folder
        -> Nextcloud sees that folder through read-only Local External Storage
        -> The app coordinates provisioning, quota sync, and the browsing UI
```

The important invariant is that **Immich is the only writer of photo originals**. Nextcloud users may browse the mirrored files, but Nextcloud must not write, delete, move, rename, or reorganize media inside an Immich personal library folder.

Terminology used in this document:

- **NC user**: a Nextcloud account. The Nextcloud user ID is the canonical identity key.
- **Immich user**: the Immich account mapped to an NC user. Immich assigns its own UUID; do not assume it can equal the NC user ID.
- **Storage label**: the Immich user `storageLabel`, preferably the sanitized NC user ID.
- **Immich personal library folder**: the folder produced by Immich upload libraries and storage templates, such as `/srv/immich/originals/library/<storageLabel>` on the host.
- **NC mirror mount**: a read-only, user-scoped Nextcloud Local External Storage mount pointing to that user's Immich personal library folder.

Safety warning: use read-only bind mounts and Local External Storage for library exposure.
Safety warning: maintenance commands are operator actions only; controllers, jobs, and services must not launch command-line tools.
Safety warning: direct database writes are forbidden; use official Nextcloud services and Immich REST APIs.

---

## Screenshots

<table>
  <tr>
    <td align="center"><strong>Timeline</strong></td>
    <td align="center"><strong>Albums</strong></td>
    <td align="center"><strong>People</strong></td>
  </tr>
  <tr>
    <td><img src="screenshots/allmedia.png" alt="Timeline view" width="280"></td>
    <td><img src="screenshots/album.png" alt="Albums view" width="280"></td>
    <td><img src="screenshots/people.png" alt="People view" width="280"></td>
  </tr>
</table>

---

## Features

| Feature | Description |
| --- | --- |
| Immich browsing UI | Timeline, albums, people, map, explore, favorites, lightbox, and metadata views inside Nextcloud. |
| Admin provisioning | Mirror scoped Nextcloud users into Immich users through Immich admin APIs. |
| Read-only library mirror | Expose each Immich personal library folder through a per-user Local External Storage mount. |
| Storage-label mapping | Use a sanitized NC user ID as the default Immich storage label for predictable per-user folders. |
| Quota coordination | Set Immich upload quota from Nextcloud quota and non-Immich Nextcloud usage. |
| Reconciliation tools | Test the admin connection, dry-run planned changes, reconcile users, recompute quotas, and inspect sync status. |
| Safe mutation policy | Destructive Immich operations are admin-controlled; exports/imports are opt-in and must not target the read-only mirror mount. |

---

## Prerequisites

- **Nextcloud 27-34** with the External Storage app enabled.
- **PHP 8.2 or newer** for the Nextcloud app runtime.
- A running **Immich instance** reachable from the Nextcloud server.
- An **Immich admin API key** owned by an Immich administrator. Prefer a scoped admin API key over a session token.
- A filesystem/container layout where the Immich library root is visible to the Nextcloud container or host as a read-only path.
- Nextcloud background jobs configured through cron or an equivalent production background-job runner.

For quota coordination, administrators should enable external-storage quota inclusion in Nextcloud configuration:

```php
'quota_include_external_storage' => true,
```

Nextcloud documents this setting as experimental. Quota values can lag because external-storage scans, filecache updates, trash, versions, and preview storage do not provide perfect real-time accounting.

---

## Installation

### Via Nextcloud App Store

1. Open **Nextcloud -> Apps**.
2. Search for `Immich Integration` or the packaged app name used by this fork.
3. Install and enable the app.

### Via Release Tarball

1. Download `integration_immich.tar.gz` from the release page.
2. Extract it into your Nextcloud `apps/` directory:
   ```bash
   tar -xzf integration_immich.tar.gz -C /path/to/nextcloud/apps/
   ```
3. Enable the app from an administrator console:
   ```bash
   php occ app:enable integration_immich
   ```

---

## Immich Storage Template Setup

Scheme B depends on Immich upload libraries and Immich storage templates. Mobile uploads and other Immich uploads must become Immich-owned assets, and Immich must place originals under a stable per-user library folder derived from the user's `storageLabel`.

Recommended storage layout:

```text
Host Immich library root: /srv/immich/originals/library
User storage label:       alice
User library folder:      /srv/immich/originals/library/alice
Nextcloud-visible path:   /mnt/immich-library/alice
```

Operator checklist:

1. Configure Immich storage templates so uploaded originals are organized below the user library folder identified by `storageLabel`.
2. Set or verify each Immich user's `storageLabel` before large uploads begin.
3. Keep the storage label stable after assets exist. Changing it later can require Immich-side storage migration and a large Nextcloud rescan.
4. Use Immich's own migration tools and maintenance process if you enable or change storage templates for existing assets.
5. Confirm uploads through Immich land under the expected storage-template path before enabling broad Nextcloud mirroring.

This app does not reorganize Immich originals and does not write media into the mirror mount.

---

## Admin Settings

Open **Nextcloud -> Administration Settings -> Immich Orchestration**. Personal settings may still exist for optional user browsing preferences, but provisioning is controlled by system-level admin settings.

| Setting | Description |
| --- | --- |
| Immich base URL | Public or internal URL used by the Nextcloud server, for example `https://photos.example.com`. If the URL is private or local, see the SSRF troubleshooting section. |
| Immich admin API key | Scoped key owned by an Immich admin user. It is stored encrypted and is never returned to the browser after saving. |
| Optional admin token | Compatibility fallback only for Immich API versions that require it. API-key authentication is the default. |
| Provisioning enabled | Global switch for user provisioning and reconciliation. |
| User scope | Provision all Nextcloud users or only users in selected Nextcloud groups. |
| Storage label template | Default `{uid}` after sanitization. Supported variables include `{uid}` and `{storageLabel}` where appropriate. |
| Email fallback template | Used when an NC user has no email address, for example `{uid}@immich.local`. |
| Initial password policy | Random generated password that is not shown after creation, or SSO/OIDC mode when Immich and Nextcloud share authentication. |
| Host library path template | Host-side Immich personal library path, for example `/srv/immich/originals/library/{storageLabel}`. |
| Nextcloud-visible path template | Path visible inside the Nextcloud runtime, for example `/mnt/immich-library/{storageLabel}`. |
| Mount name template | User-facing mount name such as `Immich Photos` or `Immich/{uid}`. |
| Create missing directories | Disabled by default. If enabled, the app may create only empty per-user directories under the configured base path after strict validation. |
| Quota sync mode | Disabled, manual only, or event plus scheduled job. |
| Safety reserve bytes | Space withheld before setting Immich quota, for example 256 MiB. |
| Delete/disable policy | Default is non-destructive: disable or suspend the Immich user when supported, and never delete assets automatically. Destructive deletion requires explicit admin opt-in. |
| Admin actions | Test Immich admin connection, list planned user changes, reconcile one user, reconcile all scoped users, recompute quotas, and verify mount health. |

Admin settings should show redacted credential state only. They should also expose provisioning status, mount health, quota sync status, last sync time, and clear warnings when external-storage quota inclusion or scans are stale.

---

## External Storage Setup

Each provisioned NC user should receive one read-only Local External Storage mount that points only to that user's Immich personal library folder.

Example mapping:

```text
NC user:                  alice
Immich user ID:           generated by Immich and stored in app mapping
Immich storage label:     alice
Host path:                /srv/immich/originals/library/alice
Nextcloud container path: /mnt/immich-library/alice
Mount shown to alice:     /Immich Photos
Available for:            alice only
Read-only:                true
```

Operator requirements:

1. Bind the Immich library root into the Nextcloud runtime as a read-only path.
2. Enable Nextcloud's External Storage app and the Local backend.
3. Configure or allow provisioning of a Local External Storage mount for each scoped user.
4. Scope each mount to the matching NC user, not all users.
5. Keep the mount outside the Nextcloud `data/` directory and never configure it as root storage.
6. Mark the mount read-only in Nextcloud storage options and enforce read-only access at the container/filesystem layer when possible.
7. If the Immich personal library folder does not exist yet, leave the mount pending until the first Immich upload creates it unless the admin has explicitly enabled empty directory creation.

If the running Nextcloud version does not expose a stable external-storage provisioning API, administrators can preconfigure matching template mounts and use the app only to verify path, scope, and read-only status.

---

## Operational Commands

The following commands are **administrator-run maintenance commands**. Run them from your Nextcloud host/container shell, not from app code.

List external storage mounts and note the mount ID:

```bash
docker exec -u www-data nextcloud php occ files_external:list
```

Refresh a user's Immich mirror mount after Immich has added, moved, or removed files:

```bash
docker exec -u www-data nextcloud php occ files_external:scan <mount_id>
```

Run the normal Nextcloud background-job cycle if your deployment uses cron mode:

```bash
docker exec -u www-data nextcloud php -f /var/www/html/cron.php
```

Inspect or trigger a specific background job only when supported by your Nextcloud version:

```bash
docker exec -u www-data nextcloud php occ background-job:list
docker exec -u www-data nextcloud php occ background-job:execute <job_id>
```

Recommended operations:

- Run Nextcloud cron about every 5 minutes in production.
- Schedule practical `files_external:scan` runs for Immich mirror mounts when prompt file visibility matters.
- Monitor Immich thumbnails, encoded videos, machine-learning data, profile files, backups, and database storage separately from user quota sync.

---

## Quota Synchronization

The target invariant is:

```text
ordinary Nextcloud files + Immich photo originals <= Nextcloud user quota
```

Immich has its own upload quota, so the app sets Immich `quotaSizeInBytes` to the remaining Nextcloud capacity after subtracting non-Immich Nextcloud usage.

Definitions:

```text
Q = finite Nextcloud user quota bytes
T = Nextcloud total used bytes, including the Immich read-only external mount after scan
I = Immich quota usage bytes for that Immich user
N = non-Immich Nextcloud used bytes
R = admin-configured safety reserve bytes
L = Immich quotaSizeInBytes to set
```

When `T` includes the Immich mirror and `I` matches the mounted originals:

```text
N = max(0, T - I)
L = max(I, Q - N - R)
```

Why `max(I, ...)` matters: if a user is already over the combined quota, setting `L = I` blocks further Immich uploads without pretending current usage disappeared or setting a confusing limit below current Immich usage.

Quota caveats:

- `quota_include_external_storage` is experimental in Nextcloud and can be stale until filecache or external-storage scans run.
- Quota sync is coordination, not exact real-time accounting.
- If Nextcloud quota is unlimited, Immich quota can remain unlimited unless an admin-configured global cap is used.
- If Nextcloud quota is unavailable or invalid, the app should leave Immich quota unchanged and mark sync status as failed.
- Immich `quotaUsageInBytes` should be fetched before recomputation.
- Immich `quotaSizeInBytes` is an absolute cap; empty or `null` means unlimited in Immich UI semantics, while `0` should not be used unless verified against the target Immich version.

---

## Provisioning Behavior

Nextcloud user ID is canonical. Display names, email addresses, group membership, and quota can change; the mapping key remains the NC user ID.

On first provisioning the app creates or finds an Immich user, then stores an app-owned mapping:

```text
nc_uid -> immich_user_id
nc_uid -> immich_email
nc_uid -> storage_label
nc_uid -> nc_mount_id
nc_uid -> last_sync_status
```

Provisioning rules:

- Immich user IDs are generated by Immich and must be stored; do not force them to NC user IDs.
- The default storage label is the sanitized NC user ID.
- The storage label sanitizer allows `[A-Za-z0-9._-]` only, trims leading/trailing dots and spaces, rejects empty output, rejects `.` and `..`, and prevents collisions through stored mappings and Immich user checks.
- If an NC user has no email, the configured fallback email template is used.
- Random generated initial passwords are not displayed after creation.
- In SSO/OIDC deployments, use the SSO/OIDC policy rather than distributing generated passwords.
- Lifecycle and group events should enqueue provisioning or sync jobs; listeners should not call Immich synchronously.
- Default user deletion handling is non-destructive: disable or suspend the Immich user when supported and leave assets intact.

Expected background jobs include Immich user provisioning, Nextcloud mount provisioning, user sync, quota sync, user reconciliation, and provisioning verification.

---

## Upload Libraries vs External Libraries

This app's quota design assumes **Immich upload libraries plus storage templates**:

- Immich owns the uploaded files.
- Storage templates manage original placement.
- Immich user quota applies.
- Mobile upload and user upload flows can be coordinated with Nextcloud quota.

Immich **External Libraries** are different:

- Files live outside Immich ownership.
- Storage templates do not manage those originals.
- External Libraries do not count against Immich user quota.
- They are suitable only for admin-managed imports of existing folders, not for this quota-sync design.

Do not use External Libraries as a quota-sync solution unless you explicitly accept that Immich quota will not count those originals.

---

## Security Requirements

- Store Immich admin API keys and compatibility tokens encrypted with Nextcloud crypto services.
- Never expose raw admin credentials to the browser after saving.
- Log only redacted credential state and auditable provisioning outcomes.
- User-facing routes must not use the admin key to expose another user's assets.
- If a backend route uses admin credentials for user-facing browsing, enforce `NC uid -> Immich user id` filtering on every request.
- Prefer per-user Immich API keys for browsing when the target Immich version supports creating and storing them safely.
- Validate every expanded path template: no parent traversal, no NUL bytes, normalized slashes, and the final path must stay under the configured base path.
- Do not write directly to Nextcloud or Immich databases for user sync, quota, library paths, mounts, or filecache state.
- No `occ` shelling: administrators run maintenance commands from outside the runtime.
- Do not write media files into the mirror mount from Nextcloud.
- Protect mutating routes with CSRF checks; do not mark POST, PUT, or DELETE provisioning routes as `NoCSRFRequired`.
- Destructive Immich actions must be disabled by default and require explicit admin enablement.

---

## Troubleshooting

### Immich URL is blocked or validation fails for a private address

Nextcloud may block local or private URLs through SSRF protection. Use the admin UI's exact remediation message for your deployment, such as trusted domain/proxy/DNS changes. Do not bypass SSRF protection in app code.

### Mount is not visible to a user

Check that provisioning is enabled, the user is in scope, the mapping contains an Immich user ID and mount ID, the Local External Storage backend is enabled, the mount is scoped to that one NC user, and the Immich personal library folder exists or is intentionally pending.

### Mount appears writable

Disable the mount immediately. Verify Nextcloud storage options, container volume flags, filesystem permissions, and that the mount path is not the user's root storage.

### New Immich uploads do not appear in Nextcloud Files

Confirm Immich wrote files under the expected storage-template path, then run the administrator `files_external:scan` command for the affected mount ID. Nextcloud filecache updates may lag normal Immich writes.

### Quota is not updating

Verify `quota_include_external_storage`, run or wait for external-storage scans, confirm Immich `quotaUsageInBytes` can be fetched, check the configured safety reserve, and inspect the quota sync job status. Remember that the experimental Nextcloud setting and scans can delay accounting.

### Provisioning creates no Immich user

Test the Immich admin connection, verify the API key belongs to an admin user with the required user-management permissions, check for API-version incompatibilities, and review redacted app logs for duplicate-email or storage-label collision errors.

### Generated password is unknown

This is expected. Generated initial passwords are not shown after creation. Reset the Immich user's password through Immich admin tools or use the configured SSO/OIDC flow.

---

## Development

```bash
npm install
npm run dev      # development build, unminified
npm run watch    # watch mode with hot rebuild
npm run build    # production build
```

The app uses **Vue 3** + **Pinia** for state management and official `@nextcloud/*` component libraries.

### Local verification

Run the full local verification sequence before opening a pull request. The verification wrappers fail with a non-zero exit code when a check fails and do not write tracked source files; build commands may regenerate frontend/l10n build outputs in the working tree:

```bash
composer install
npm install --legacy-peer-deps
npm run verify:php-syntax
vendor/bin/phpunit -c tests/phpunit.xml
npm run build-l10n
npm run build
npm run verify:safety:scan
vendor/bin/phpunit -c tests/phpunit.xml --filter SafetyGuardrailTest
```

Use `npm run verify:safety:scan` as the executable guardrail wrapper. It checks for forbidden command execution, unsafe process creation, unsafe path indirection, and mount-mutation patterns. It fails on matches outside intentional inline fixtures in `tests/unit/SafetyGuardrailTest.php` and documented Nextcloud file-action callback names, and keeps the PHPUnit safety check available through `npm run verify:safety:phpunit`.

For a non-build npm entry point after dependencies are installed, run:

```bash
npm run verify
```

`npm run verify` intentionally excludes `npm run build` so the default local wrapper stays non-mutating for tracked source files. CI and the command sequence above run `npm run build` explicitly.

---

## Contributing

Pull requests and bug reports are welcome. Please open an issue for feature requests or bug reports.

---

## License

This project is licensed under the [AGPL-3.0-or-later](COPYING) license.
