# CampusPark — Campus Parking Management System

A PHP and MySQL campus parking management project with role-based dashboards, permit applications, QR permit verification, vehicle entry/exit records, parking areas and slots, visitor records, violations, and audit logs.

## Technology

- PHP (PDO for database access)
- MySQL / MariaDB
- Tailwind CSS (local browser build in `assets/js/tailwindcss.js`)
- Font Awesome and Lucide icons
- XAMPP for local development

## Project layout

```text
parking/
├── index.php                 # Public landing page
├── login.php                 # Sign in
├── register.php              # Account registration
├── logout.php                # Sign out
├── admin/                    # Admin management pages
├── dashboards/               # Role-specific dashboard entry pages
├── parking/                  # Parking logs, current vehicles, history
├── permits/                  # Permit applications and verification
├── security/                 # QR scan, vehicle entry/exit, visitors
├── vehicles/                 # User vehicle registration and management
├── violations/               # Violation management
├── includes/                 # Shared authentication, DB, audit, sidebar
├── layouts/                  # Shared public and dashboard page layouts
├── assets/                   # CSS, JavaScript, fonts, and webfonts
├── img/                      # Project images and logo
├── uploads/vehicles/         # Uploaded vehicle photos
├── database/                 # SQL schema/seed import file (not web-accessible)
├── docs/                     # Setup notes and original design references
└── tools/                    # Development-only helper scripts
```

The PHP page paths are intentionally kept in their existing folders. This avoids breaking links, form actions, QR scan endpoints, and deployments that already expect these routes.

## Local setup (XAMPP)

1. Copy the `parking` folder into `C:\xampp\htdocs\parking`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Open phpMyAdmin and create the database named `campus_parking` (or use your configured database name).
4. Import `database/campus_parking_full.sql` into that database. Import it into a clean database unless you know the SQL file is safe to re-run.
5. Check `includes/dbconn.php`. It reads the following environment variables and falls back to local development defaults:
   - `CAMPUSPARK_DB_HOST` (default `localhost`)
   - `CAMPUSPARK_DB_NAME` (default `campus_parking`)
   - `CAMPUSPARK_DB_USER` (default `root`)
   - `CAMPUSPARK_DB_PASS` (default empty)
6. Visit `http://localhost/parking/`.

For hosting, configure the database environment variables in the hosting control panel. Do not upload real database passwords to source control or share them in screenshots.

## Shared code conventions

- Put reusable authentication and authorization checks in `includes/auth.php`.
- Put database connection settings in `includes/dbconn.php`.
- Keep shared sidebar/navigation markup in `includes/sidebar.php`.
- Keep shared page wrappers in `layouts/`.
- Use `require_once __DIR__ . '/relative/path.php'` (or the appropriate `../` path) for PHP includes so paths do not depend on the current working directory.
- Preserve CSRF tokens on state-changing POST forms and validate permissions on the server, not only by hiding navigation links.
- Store uploads only under `uploads/vehicles/`; do not put PHP scripts or executable files in upload folders.

## QR scanner notes

Camera access generally requires HTTPS on a hosted site, or `localhost` during local development. The QR scanner validates a permit server-side; the browser's camera result alone must never be treated as proof of a valid permit.

## Test checklist before deployment

- Sign in separately as admin, student/faculty, and security staff.
- Confirm each role sees only its own menu and cannot open another role's pages directly.
- Apply, approve, expire, and reject permits; confirm the correct slot state each time.
- Test QR scanning and manual vehicle entry, including expired permits and duplicate entry attempts.
- Test vehicle exit, visitor records, violations, and audit log.
- Confirm upload permissions and verify that database errors do not reveal credentials to users.

## Design references

The `docs/design-reference/` folder contains the original HTML design references for context only. The live PHP pages are the files in the feature folders above.
