# C.I.H.R.M. — Hotel Reservation Management System

Educational hotel front-desk simulator for Hospitality Management students.

## Stack
- Frontend: HTML5, CSS3, Vanilla JavaScript
- Backend: PHP 8 with PDO
- Database: MySQL / InnoDB
- Authentication: PHP sessions and password hashing

## Requirements
XAMPP with PHP 8+, Apache, and MySQL. Node.js is not required.

## Setup with XAMPP
1. Start MySQL in the XAMPP Control Panel.
2. Import `database/cihrm_database.sql` into phpMyAdmin or MySQL Workbench if the schema has not been created.
3. Check `backend/.env`; the default XAMPP settings are host `127.0.0.1`, port `3306`, user `root`, blank password, database `cihrm_db`.
4. Apply the workflow database migration (safe to rerun), then check the connection:

   ```powershell
   & 'C:\xampp\php\php.exe' database\workflow_migration.php
   & 'C:\xampp\php\php.exe' backend\test-db.php
   ```

5. Seed the demo accounts (this resets their demo passwords):

   ```powershell
   & 'C:\xampp\php\php.exe' backend\seed.php
   ```

6. Start the PHP server from the project root. In VS Code, you can also run **Terminal → Run Task → Run C.I.H.R.M. (PHP)**:

   ```powershell
   & 'C:\xampp\php\php.exe' -S 127.0.0.1:3000 -t .
   ```

7. Open `http://127.0.0.1:3000`.

The PHP server command serves the current project folder directly, so you do not need to copy it into XAMPP's `htdocs`. Keep the PowerShell window open while using the app.

To use VS Code Live Server, first start MySQL and the PHP task above, then open `frontend/index.html` with Live Server. The frontend automatically sends API requests to the PHP server on port 3000, and the PHP API allows credentialed requests only from localhost development origins. Keep both servers running. A `file://` URL cannot connect to the API.

## Publishing
For production hosting requirements and a deployment checklist, see [DEPLOYMENT.md](./DEPLOYMENT.md). Production requires a PHP/MySQL host; static-only hosting is not supported.

Railway deployment is also supported through the included `Dockerfile`. See the Railway section in [DEPLOYMENT.md](./DEPLOYMENT.md) for setup steps.

## Demonstration accounts
All three accounts use `Password123!` after running the seed script:
- admin@cihrm.local
- instructor@cihrm.local
- student@cihrm.local

These are local demonstration accounts only. Change credentials before any real deployment.
New users can create student accounts from the sign-in page; instructor and administrator accounts must be created by an administrator.

## Included in this starter build
- Secure password hashing and session-based login/logout
- Public student sign-up with duplicate-email validation
- Persistent light/dark theme toggle
- Server-side role checks
- Role-specific dashboards and navigation
- Sequential numeric display IDs in user profiles and account directories (internal database IDs remain unchanged)
- Room and guest CRUD
- Reservation creation/cancellation with date-overlap prevention and MySQL transactions
- Reservation check-in/check-out with room occupancy and housekeeping updates
- Folios with room charges, extra charges, discounts, tax totals, and simulated payment status
- Housekeeping status workflow with occupied-room safeguards
- Scenario starts and written submissions, instructor scoring and feedback, student progress summaries
- Instructor/admin scenario authoring and deletion of ungraded submissions
- Instructor-only student member records with profiles, learning submissions, activity, and reservations
- Combined instructor/admin activity and learning reports with CSV export
- Safe workflow schema migration for written scenario submissions
- Dashboard statistics from MySQL
- Activity logging
- Responsive maroon/gold UI

## Frontend file layout
- `frontend/index.html` contains the page structure and loads the stylesheet and scripts in order.
- `frontend/css/tokens.css` defines shared theme colors and shadows.
- `frontend/css/style.css` contains layout, components, typography, and responsive rules.
- `frontend/css/theme.css` contains dark-mode overrides and theme-toggle placement.
- `frontend/js/app.js` contains shared state, authentication, navigation, dashboard, and helpers.
- `frontend/js/operations.js` contains room, guest, reservation, and front-desk pages.
- `frontend/js/hotel-services.js` contains housekeeping and billing pages.
- `frontend/js/learning.js` contains scenarios, submissions, assessments, and progress.
- `frontend/js/reports.js` contains activity and report views.
- `frontend/js/accounts.js` contains profile and member pages.
- `frontend/js/init.js` checks the existing login session after feature scripts load.

## ERD alignment
All nine ERD entities are retained: USER, GUEST, ROOM, RESERVATION, FOLIO, FOLIO_ITEM, ACTIVITY_LOG, SCENARIO, and STUDENT_ASSESSMENT. Their eight ERD foreign-key relationships are enforced by MySQL and used by the PHP workflows. Supporting columns are additive. Run `php backend/test-db.php` to verify the database connection, required tables and foreign keys, and check for orphaned records.

## Important scope note
This is the runnable foundation (Part 1) of the larger research system. Only instructors can view the Members directory; students can view only their own activity and submissions. Graded submissions are retained and cannot be deleted. Folio payment status is simulated; no real payments are processed.
