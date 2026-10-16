# PeopleOS HRMS

A web-based Human Resource Management System (HRMS) built with PHP,
MySQL, HTML, CSS, and JavaScript. The project brings common HR workflows
together in one application.

## Features

-   Employee records and employee management
-   Attendance tracking and attendance overview
-   Leave management
-   Task management
-   Payroll management
-   Performance tracking and reports
-   Employee portal
-   Login, logout, and password-change pages
-   Role-based access controls (review the authorization rules before
    deployment)

> **Project status:** This repository is a development project. Test
> each workflow and review security settings before using it with real
> employee or payroll data.

## Technology Stack

-   **Backend:** PHP
-   **Database:** MySQL
-   **Frontend:** HTML, CSS, JavaScript
-   **Local development:** XAMPP
-   **Editor:** Visual Studio Code (or another code editor)

## Repository Structure

  -----------------------------------------------------------------------
  File                                Purpose
  ----------------------------------- -----------------------------------
  `index.php`                         Application entry/dashboard page

  `login.php`, `logout.php`,          Authentication-related pages
  `change_password.php`               

  `employees.php`,                    Employee management
  `add_employee.php`,                 
  `edit_employee.php`,                
  `delete_employee.php`               

  `employee_portal.php`, `users.php`  Employee portal and user management

  `attendance.php`,                   Attendance workflows
  `attendance_overview.php`           

  `leave.php`                         Leave management

  `tasks.php`                         Task management

  `payroll.php`                       Payroll workflows

  `performance.php`,                  Performance tracking and reporting
  `performance_report.php`            

  `alerts.php`                        Alerts

  `nav.php`, `assets.css`             Navigation and styling

  `auth.php`                          Authentication/authorization
                                      helpers

  `db.php`                            Local database connection
                                      configuration --- intentionally not
                                      included in this public upload

  `setup.php`                         Setup utility --- intentionally not
                                      included in this public upload
  -----------------------------------------------------------------------

File names describe their likely roles; inspect and test the
implementation to confirm exact behavior.

## Run Locally with XAMPP

1.  Install and start **Apache** and **MySQL** from the XAMPP Control
    Panel.

2.  Create a database in phpMyAdmin for this project.

3.  Place the project folder inside XAMPP's `htdocs` directory, for
    example:

    `C:\xampp\htdocs\peopleos-hrms\`

4.  Create a **local-only** `db.php` file using the database connection
    settings for your machine. Do not commit passwords, credentials, or
    production connection details.

5.  Import the project's database schema or SQL file if one is
    available. This repository upload does not include a schema file, so
    the database tables must be supplied or created before the
    application can work.

6.  Open the local application in your browser, for example:

    `http://localhost/peopleos-hrms/`

7.  Test login and each module using test data before relying on the
    application.

## Security Notes

-   Never publish database passwords, API keys, default administrator
    credentials, session secrets, or real employee data.
-   `db.php` and `setup.php` were excluded from this upload. Add safe
    setup instructions and a sanitized example configuration if needed;
    do not add real credentials.
-   Use prepared statements for database queries and validate/escape
    untrusted input.
-   Apply server-side authorization checks to every sensitive action;
    hiding a link in the UI is not sufficient.
-   Protect state-changing forms against CSRF and use secure password
    hashing.
-   Do not use real payroll or personal data until access control,
    validation, backups, and security have been reviewed.

## Current Limitations

-   Database schema and seed data are not included in this repository
    upload.
-   A working local database configuration must be created separately.
-   Features and security controls require testing in the intended
    environment.

## Future Improvements

-   Add a documented database schema and safe sample data.
-   Add automated tests for authentication, employee records,
    attendance, leave, tasks, and payroll.
-   Improve audit logging, error handling, validation, and deployment
    documentation.
-   Add screenshots or a demo once the application has been tested.

## License

No license has been added yet. Unless a license is included, others do
not automatically receive permission to reuse, modify, or distribute
this code.
