# Production deployment

This project requires a PHP web server and MySQL. Live Server and static-only hosts cannot run the PHP API.

## Recommended hosting

Choose a reputable PHP hosting provider with:

- PHP 8.1 or newer, with PDO MySQL enabled
- MySQL 8.0 or a compatible managed MySQL server
- Apache with `.htaccess` support, or equivalent web-server access rules
- HTTPS certificates and automatic renewal
- Private database credentials and regular backups

A cPanel PHP/MySQL plan is usually the simplest first deployment. Ask your school or organization whether it already provides approved web hosting before purchasing a service. Do not publish real student or guest records until the school approves the host and its privacy/security arrangements.

## Railway deployment

The repository includes a Dockerfile for Railway's PHP/Apache deployment. Railway and its MySQL service may have usage charges; check current pricing before deploying. Do not use real student or guest data unless your institution has approved the provider and its privacy arrangements.

1. In Railway, create a project from the GitHub repository and add a MySQL service.
2. Before the first MySQL deployment, set the MySQL service's `MYSQLDATABASE` variable to `cihrm_db` so it matches the included schema's database name.
3. Enable the MySQL service's public TCP access temporarily and connect with a MySQL client using its public connection details. Create a dedicated application user, replacing the password placeholder with a unique strong password:

   ```sql
   CREATE USER 'cihrm_app'@'%' IDENTIFIED BY 'replace-with-a-unique-strong-password';
   GRANT ALL PRIVILEGES ON cihrm_db.* TO 'cihrm_app'@'%';
   ```

   Use that account for the app, not the MySQL `root` account. Store its password only in Railway's variable editor. Disable public database access after setup if it is not needed.
4. In the application service, add these variables using the MySQL service's variable references (replace `MySQL` with the exact service name if different):

   | Application variable | Value |
   | --- | --- |
   | `APP_ENV` | `production` |
   | `DB_HOST` | `${{MySQL.MYSQLHOST}}` |
   | `DB_PORT` | `${{MySQL.MYSQLPORT}}` |
   | `DB_NAME` | `${{MySQL.MYSQLDATABASE}}` |
   | `DB_USER` | `cihrm_app` |
   | `DB_PASSWORD` | Enter the unique password created for `cihrm_app` |

   Enter these values in Railway's variable editor, not in source files. The app reads environment variables directly and does not need a deployed `.env` file.
5. Deploy the application service from the repository. Railway should detect the Dockerfile and build the PHP/Apache image. Generate a public domain in the service's networking settings.
6. Import `database/cihrm_database.sql` into the `cihrm_db` database using a MySQL client. Then run `database/workflow_migration.php` from a private PHP environment configured with the same production database variables. Do not run `backend/seed.php` on a public database.
7. Create an administrator with a unique password through a secure private setup process before opening the site to users. The public sign-up form creates student accounts only.
8. Open the Railway domain and test sign-up, sign-in, and database-backed workflows. Keep backups enabled and do not enter real guest/student records without institutional approval.

Never paste database credentials into chat or commit them to GitHub. If a database has already been initialized with a different name, do not change its database name blindly; update the schema import and `DB_NAME` to use the database that actually exists.

## Deployment checklist

1. Create a production website and a dedicated MySQL database and user in the hosting control panel. Grant that database user access only to this application database.
2. Set the website document root to this project directory. Keep the included `.htaccess` files in place; they block access to configuration, SQL, and development files. If the host does not support Apache `.htaccess`, configure equivalent deny rules before publishing.
3. Upload the application files over SFTP or the host's secure file manager. Do not upload a developer's existing `backend/.env`; create the production file directly on the host.
4. Copy `backend/.env.example` to `backend/.env` and set `APP_ENV=production`, the host-provided database host/name/user/password, and the correct database port. Use a unique, strong database password; never use the local XAMPP `root` account.
5. Import `database/cihrm_database.sql` into the production database, then run `database/workflow_migration.php` from the hosting shell if available. Otherwise, apply the migration's additive `ALTER TABLE` through the database control panel.
6. If the hosting shell supports PHP, run `php backend/test-db.php`. It should confirm the database, nine ERD tables, eight foreign keys, and no orphaned rows.
7. Do not run `backend/seed.php` on a public installation. It resets the local demo accounts to a known shared password. Create administrator accounts with unique credentials using a secure, private setup process instead.
8. Enable HTTPS and force all traffic to HTTPS in the hosting control panel. Test registration, sign-in/sign-out, role access, reports, and database workflows over HTTPS.
9. Keep production backups private, update PHP regularly, and configure database backups before entering real data.

## Before launch

- Replace all demo or test accounts and remove any data that should not be public.
- Confirm with your institution that it approves the provider, data location, retention, access, and backup policies.
- The project is an educational simulator. Folio payment actions are simulated and do not process real payments.

Publishing requires a hosting account, domain/DNS, and credentials that only the site owner should enter into the hosting provider. Never send database or hosting passwords in chat.
