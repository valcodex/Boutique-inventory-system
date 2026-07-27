# Micah Boutique — Inventory & Sales Management System

A single-file PHP + MySQL web application for managing boutique inventory, stock levels, and sales across two shop locations. The entire app (backend API, HTML, CSS, and JavaScript) lives in one PHP file for simple deployment on shared hosting.

## Features

- **Authentication** — Session-based login with two roles: `admin` and `staff`.
- **Product catalog** — Add, edit, delete, and browse products with name, category, sizes, colors, price, shop location, and image.
- **Automatic stock status** — Status (`In Stock` / `Low Stock` / `Out of Stock`) is always derived from quantity vs. a configurable low-stock threshold — never stored as a manual, editable field.
- **Sell tracking** — "Mark as Sold" deducts one unit from inventory and logs the sale (product, price, shop, staff member, timestamp).
- **Sales log** — Admin-only view of all sales, with the ability to clear the log.
- **Search & filters** — Filter products by name/category/color, category, status, and shop.
- **Image uploads** — Product photos stored on disk under `/uploads`.
- **Two-shop support** — Every product and sale is tagged with `Shop 1` or `Shop 2`.
- **Self-installing schema** — Tables are created and demo data seeded automatically on first run.

## Tech Stack

| Layer    | Technology                                  |
|----------|----------------------------------------------|
| Backend  | PHP (mysqli), single-file API + view         |
| Database | MySQL / MariaDB                              |
| Frontend | Vanilla JavaScript, HTML, CSS (no framework) |
| Fonts    | Google Fonts (Cormorant Garamond, Josefin Sans) |

No build step, no package manager, no framework — just PHP and a browser.

## Requirements

- PHP 7.4+ with the `mysqli` extension enabled
- MySQL or MariaDB database
- Web server (Apache/Nginx) or PHP's built-in server
- Write access to the app directory (for the `uploads/` folder)

## Setup

1. **Configure the database connection** at the top of the file:
   ```php
   define('DB_HOST', 'your-db-host');
   define('DB_USER', 'your-db-user');
   define('DB_PASS', 'your-db-password');
   define('DB_NAME', 'your-db-name');
   ```
2. **Upload the file** to your web server (e.g. as `index.php`).
3. **Visit the page in a browser.** On first load, the app automatically:
   - Creates the `users`, `products`, and `sold_log` tables
   - Seeds two default accounts (see below)
   - Seeds six demo products
   - Creates the `uploads/` directory
4. **Log in** and start managing inventory.

## Default Accounts

| Role  | Username | Password   |
|-------|----------|------------|
| Admin | `admin`  | `admin123` |
| Staff | `staff`  | `staff123` |

**Change these credentials before deploying to production.**

## Roles & Permissions

- **Staff**: view products, search/filter, mark items as sold.
- **Admin**: everything staff can do, plus add/edit/delete products, view the sales log, and clear the log.

## Project Structure

```
/
├── index.php        # Entire application: schema, API, HTML, CSS, JS
└── uploads/          # Product images (auto-created)
```

## API Endpoints (internal, same-file)

All requests go to the same file with an `?api=` query parameter:

| Endpoint                | Method | Auth        | Description                     |
|--------------------------|--------|-------------|----------------------------------|
| `?api=login`             | POST   | —           | Authenticate and start session   |
| `?api=logout`            | GET    | —           | Destroy session                  |
| `?api=session`           | GET    | —           | Check current session            |
| `?api=products`          | GET    | Logged in   | List/search/filter products      |
| `?api=add_product`       | POST   | Admin       | Create a product                 |
| `?api=update_product`    | POST   | Admin       | Update a product                 |
| `?api=delete_product`    | POST   | Admin       | Delete a product                 |
| `?api=mark_sold`         | POST   | Logged in   | Deduct 1 unit, log sale          |
| `?api=sold_log`          | GET    | Admin       | List all sales                   |
| `?api=clear_log`         | POST   | Admin       | Delete all sale records          |

## Security Notes

⚠️ Before using this in production, consider addressing:
- Database credentials are hardcoded in the file — move them to environment variables or a `.env`/config file excluded from version control.
- Several queries interpolate integer IDs directly into SQL strings (e.g. `WHERE id=$id`) rather than using prepared statements throughout.
- `display_errors` is enabled at the top of the file — disable this in production to avoid leaking stack traces.
- No CSRF protection on state-changing requests.
- No rate limiting on the login endpoint.

## License

Add your preferred license here (e.g. MIT).
