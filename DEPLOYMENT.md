# Deployment Guide

This project is a stateful PHP/MySQL web application. It is not a static site.

## Best Fit Hosting

The best practical deployment target for the current codebase is:

- Apache or LiteSpeed hosting
- PHP 8.1+ with `pdo_mysql`
- MySQL or MariaDB
- Cron job support
- Writable filesystem access for `uploads/` and `files/faculty_papers/`
- Outbound HTTPS for Google API calls
- Outbound SMTP for Gmail app-password mail delivery

For most deployments, a quality shared hosting plan with cPanel, cron, SSL, and SSH is enough.

Use a VPS instead only if you want full server control, custom monitoring, or higher traffic headroom.

## What This App Needs

- Public entrypoint: [`index.php`](index.php)
- Frontend entry page: [`html/mainpage.html`](html/mainpage.html)
- API backend: [`api/app_state.php`](api/app_state.php) and [`api/login.php`](api/login.php)
- Database schema: [`database/datacode.txt`](database/datacode.txt)
- Seed data: [`database/datauser.txt`](database/datauser.txt)
- Composer dependencies in `vendor/`
- Writable directories:
  - `uploads/profiles`
  - `files/faculty_papers`

## Recommended Publish Structure

Put the whole project in your hosting web root, for example `public_html/`.

Result:

- `https://yourdomain.com/` -> redirects to `html/mainpage.html`
- `https://yourdomain.com/api/...` stays reachable
- file uploads and generated PDFs stay inside the same app directory

## Production Configuration

Set these environment variables in hosting if available:

- `NAAP_DB_HOST`
- `NAAP_DB_PORT`
- `NAAP_DB_NAME`
- `NAAP_DB_USER`
- `NAAP_DB_PASS`
- `NAAP_SMTP_EMAIL`
- `NAAP_SMTP_NAME`
- `NAAP_SMTP_APP_PASSWORD`

If SMTP env vars are not set, the app will use the saved `credentialDistributorConfig` value from `system_settings`.

## Publish Steps

1. Upload the project files to hosting.
2. Run `composer install --no-dev --optimize-autoloader` if the host supports Composer.
3. Create a MySQL database.
4. Import [`database/datacode.txt`](database/datacode.txt).
5. Import [`database/datauser.txt`](database/datauser.txt).
6. Set database and SMTP configuration.
7. Ensure these directories are writable:
   - `uploads/`
   - `uploads/profiles/`
   - `files/faculty_papers/`
8. Enable SSL for the domain.
9. Visit `/` and test login, uploads, PDF generation, and mail sending.

## Cron Job

This app has a CLI reminder job:

- [`api/scheduled_student_eval_reminder.php`](api/scheduled_student_eval_reminder.php)

On Linux hosting, the cron command should look like:

```bash
/usr/bin/php /home/USERNAME/public_html/api/scheduled_student_eval_reminder.php
```

Run it daily at `07:00` Asia/Manila if you want automated reminder emails.

## Important Notes

- Do not publish old SMTP secrets in SQL dumps or source files.
- Keep HTTPS enabled so session cookies are marked secure.
- The app already uses relative API paths, so it can run from the domain root without hardcoded localhost URLs.
