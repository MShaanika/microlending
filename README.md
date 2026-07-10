# Micro Lending System - Material Pro PHP Framework Phase 1

This ZIP contains a runnable vanilla PHP MVC foundation with Material Pro integrated.

## What is included

- Material Pro assets copied into `public/assets` and `public/dist`
- Working router
- Working PDO database connection
- Working authentication/login
- Working Material Pro dashboard layout
- Session handling
- CSRF protection
- Permission loader
- Audit logging
- Module loader placeholder

## Requirements

- PHP 8.1+
- MySQL / MariaDB
- Apache with mod_rewrite enabled
- XAMPP/WAMP/cPanel supported

## Run locally with XAMPP

1. Extract this folder to:

```text
C:\xampp\htdocs\micro_lending_system
```

2. Start Apache and MySQL.

3. Open phpMyAdmin and import:

```text
database/minimal_setup.sql
```

This creates the starter database, admin user, roles and permissions.

4. Check database config:

```text
config/database.php
```

Default local settings:

```php
host: 127.0.0.1
database: micro_lending_system
username: root
password: empty
```

5. Open:

```text
http://localhost/micro_lending_system/
```

## Login

```text
Email: admin@example.com
Password: Admin@123
```

## Important files

```text
public/index.php              Main entry point
routes/web.php                Routes
app/Core/Router.php           Router
app/Core/Database.php         PDO connection
app/Core/Auth.php             Authentication
app/Views/auth/login.php      Material Pro login
app/Views/layouts/main.php    Material Pro dashboard layout
app/Views/dashboard/index.php Dashboard
```

## Full database

The complete system SQL is in:

```text
database/schema.sql
```

For first testing, use `minimal_setup.sql`. After the full modules are developed, use `schema.sql`.
