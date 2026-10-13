# Database Export — Campus Parking

This folder contains a complete, self-contained, and error-free SQL dump of the **`campus_parking`** database.

## File Information
- **File:** `campus_parking.sql`
- **Database Name:** `campus_parking`
- **Character Set:** `utf8mb4`
- **Collation:** `utf8mb4_unicode_ci`
- **Default Timezone:** `Asia/Manila` (`+08:00`)

---

## How to Import

### Method 1: Via phpMyAdmin (Recommended for Browser)
1. Open your browser and go to [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/).
2. Click on the **Import** tab at the top.
3. Click **Choose File** and select `campus_parking.sql` from this `extra` folder.
4. Scroll down and click **Import** (or **Go**).
5. The database and all 11 tables will be imported with zero errors.

---

### Method 2: Via XAMPP Command Line (Shell)
Open the **XAMPP Control Panel**, click the **Shell** button, and run:
```bash
mysql -u root < "C:\xampp\htdocs\parking\extra\campus_parking.sql"
```
Or in Windows Command Prompt:
```cmd
C:\xampp\mysql\bin\mysql.exe -u root < "C:\xampp\htdocs\parking\extra\campus_parking.sql"
```
Or in PowerShell:
```powershell
Get-Content "C:\xampp\htdocs\parking\extra\campus_parking.sql" | C:\xampp\mysql\bin\mysql.exe -u root
```

---

## Included Tables & Data
1. **`users`** — Accounts:
   - **Admin:** `admin@gmail.com`
   - **Security:** `cardo@gmail.com`
   - **Student:** `trump@gmail.com`
2. **`vehicles`** — Registered campus vehicles and plate numbers table.
3. **`parking_areas`** — Campus parking areas and zones (Student, Faculty, Visitor, Motorcycle).
4. **`parking_slots`** — 40 parking slot codes, allocations, and statuses.
5. **`parking_permits`** — Parking permits table (structure, tokens, and foreign keys).
6. **`visitors`** — Guest visitor entries, purposes, and contact records table.
7. **`parking_logs`** — Vehicle entry/exit logs and active sessions table.
8. **`violations`** — Parking violations and penalty tracking table.
9. **`system_settings`** — Operating hours, default permit durations, system name, and timezone (`Asia/Manila`).
10. **`audit_logs`** — Administrative security telemetry and action logs.
11. **`password_resets`** — Secure password reset tokens, expiration timestamps, and tracking.

## Error-Prevention Features
- `CREATE DATABASE IF NOT EXISTS campus_parking` automatically creates the database if it doesn't already exist.
- `SET FOREIGN_KEY_CHECKS = 0;` prevents foreign key ordering conflicts during table creation and data insertion.
- Individual `DROP TABLE IF EXISTS` ensures clean imports over existing tables.
- Restores `SET FOREIGN_KEY_CHECKS = 1;` upon completion.
