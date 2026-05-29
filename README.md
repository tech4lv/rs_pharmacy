# RS Pharmacy Management System

A PHP-based pharmacy management application for managing inventory, sales, orders, prescriptions, patients, appointments, and users.

## Features

- POS system for in-store sales
- Inventory management with stock tracking
- Product management and suppliers
- Patient management and appointment tracking
- Transaction and audit logs
- Role-based access for admin, pharmacist, staff, and patients

## Installation

1. Copy the project into your web server root.
2. Create a MySQL database and import `schema.sql`.
3. Update database settings in `config/database.php`.
4. Start the web server and open the project in your browser.

## Important Files

- `index.php` - main dashboard entry
- `login.php` - sign-in page
- `logout.php` - logout handler
- `pos.php` - point of sale interface
- `products.php` - product management
- `inventory.php` - inventory and stock controls
- `orders.php` - order management
- `patients.php` - patient records
- `appointments.php` - appointment scheduling
- `transactions.php` - sales transaction history
- `audit_logs.php` - activity audit trail
- `includes/auth.php` - authentication and access helper
- `config/database.php` - database connection settings

## Notes

- Ensure `session_start()` is available and PHP sessions are configured.
- Logout redirects to `login.php` after session destruction.
- Use `assets/css/style.css` and `assets/js/main.js` for client-side UI behavior.

## Default Credentials

- Admin
  - Username / Email: `admin@rspharmacy.com`
  - Password: `password`
- Pharmacist
  - Username / Email: `robyn@rspharmacy.com`
  - Password: `password`
- Staff
  - Username / Email: `alice@rspharmacy.com`
  - Password: `password`

## Usage

Open `http://localhost/rs_pharmacy/login.php` and log in with valid credentials.

## License

This project is provided as-is for internal pharmacy management use.
