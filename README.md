# Seed Fund ITM

## About

This repository contains the **Seed Fund ITM** web application. It is a PHP based portal that manages grant applications, user registrations, and reporting. The project is structured to run on a standard LAMP stack and includes:

- PHP source files under `public/` and reusable templates under `templates/`
- MySQL database schema in `database/schema.sql`
- Configuration files in `config/`
- Front‑end assets (CSS, JS, images) in `public/`
- Basic unit tests in `tests/`

The application is intended to be deployed locally for development and testing using **XAMPP**, **phpMyAdmin**, and **Visual Studio Code**.

---

## Prerequisites

- **XAMPP** (includes Apache, MySQL, PHP) – install from https://www.apachefriends.org/
- **phpMyAdmin** – comes bundled with XAMPP; used to import the database schema.
- **Visual Studio Code** – optional but recommended for editing the code.

---

## How to Run Locally

1. **Clone the repository** (or copy the project folder) into XAMPP's `htdocs` directory.
   ```bash
   # Example on Linux
   cd /opt/lampp/htdocs
   git clone https://github.com/Kartik-T29/NEW_SEED_FUND.git seed_fund_itm
   cd seed_fund_itm
   ```

2. **Import the database**
   - Start XAMPP and launch **phpMyAdmin** (usually at `http://localhost/phpmyadmin`).
   - Create a new database (e.g., `seed_fund`).
   - Use the **Import** tab to upload `database/schema.sql`.
   - Optionally run `database/seed.php` through the browser to populate sample data.

3. **Configure the application**
   - Open `config/constants.php` (or `config/database.php`) and update the DB credentials if the default XAMPP settings differ:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_NAME', 'seed_fund');
     define('DB_USER', 'root');
     define('DB_PASS', ''); // XAMPP default password is empty
     ```
   - Save the changes.

4. **Start the server**
   - In the XAMPP Control Panel, start **Apache** and **MySQL**.
   - Open a browser and navigate to `http://localhost/seed_fund_itm/public/` (or the directory you placed the project in).
   - You should see the landing page. Use the provided routes such as `register.php`, `login.php`, etc.

5. **Develop with VSCode**
   - Open the project folder in VSCode:
     ```bash
     code /opt/lampp/htdocs/seed_fund_itm
     ```
   - Install the recommended extensions (PHP Intelephense, PHP Debug, etc.) when prompted.
   - Edit files under `public/`, `templates/`, or `config/` and refresh the browser to see changes.

---

## Common Commands

| Action | Command |
|--------|---------|
| Run tests | `php tests/test_pure_php_portal.php` |
| Import seed data | Open `http://localhost/seed_fund_itm/database/seed.php` |
| Restart Apache | Use XAMPP Control Panel or `sudo /opt/lampp/lampp restart` |

---

## Contributing

Feel free to fork the repository, make changes, and submit a pull request. Ensure any new PHP files follow the existing coding style and update `README.md` if you add new setup steps.

---

## License

This project is licensed under the MIT License – see the `LICENSE` file for details.
