## Elective4 E‑Commerce Demo (PHP + MySQL)

Lightweight demo shop built with plain PHP, MySQL (MariaDB), and Bootstrap. It includes:

- Customer product catalog with categories, cart, checkout, and voucher codes
- Admin dashboard to manage products, categories, vouchers, and orders
- Customer order history page
- CSV importer for bulk products (with remote image download)

### Contents
- `view_product.php` — Customer storefront, cart, and checkout (with PayPal button placeholder)
- `index.php` — Admin dashboard (products, categories, vouchers, orders, CSV import)
- `order_history.php` — Customer order history
- `admin_orders_api.php` — Admin endpoint to update order status and send notifications
- `conn.php` — Database connection
- `image/` — Sample images used by seeded data and as fallbacks

---

## Quick Start (XAMPP)

1) Requirements
- Windows + XAMPP (Apache + MySQL/MariaDB)
- PHP 8.x compatible

2) Clone/Copy into XAMPP htdocs
- Path example: `C:\xampp\htdocs\elective4`

3) Create database and import schema/data
- Create database `elective4`.
- Import your SQL dump (for example `elective4 (4).sql`) via phpMyAdmin.

4) Configure DB connection
- Update `conn.php` if your credentials differ (default XAMPP: user `root`, empty password):

```php
$servername = "localhost";
$username = "root";
$password = "";
$database = "elective4";
```

5) Start Apache and MySQL in XAMPP

6) Login
- Admin: email or username: `admin1` (or `admin1@example.com`), password: `123`
- Customer (with plaintext in dump): `customer_test@example.com`, password: `customer123`

Notes: the login code uses plain equality (no hashing). If you want to use bcrypt hashes, update `login.php` to verify with `password_verify` and ensure your DB has hashed values.

---

## Features

### Storefront (Customer)
- Browse by Category → Products → Product detail with gallery
- Session-based Cart with quantity management
- Checkout modal with contact, shipping, and billing details
- Voucher support during checkout (validated and applied server-side and re-checked on order placement)
- Order placement persists items, captures optional details, and decrements stock atomically
- Order History page (`order_history.php`) shows past orders and items

Voucher example from dump: `HIGALAAY2025`
- Ensure the voucher is Active and date-valid in Admin → Vouchers. You may need to toggle Active or set start/end dates.

### Admin Dashboard
- Manage Categories and Products (create, update, delete, upload images)
- Manage Vouchers (FIXED/PERCENT, min amount, start/end, limits, toggle active)
- Orders list with actions: Ship, Deliver, Cancel
  - Animated toast confirmations
  - Writes a notification entry to `notifications` table for the customer

### CSV Importer (with remote images)
- Admin → “Import CSV” button opens a modal to upload a CSV.
- The importer creates missing categories, upserts products by `product_name`, and downloads images.

CSV Header (case-insensitive):
```
product_name,description,category,price,quantity,image_url,gallery_url_1,gallery_url_2,gallery_url_3,gallery_url_4,gallery_url_5
```

Example row:
```
Ultra Tee,Soft cotton tee,Tops,299,50,https://example.com/img/tee.jpg,https://example.com/img/tee_1.jpg,https://example.com/img/tee_2.jpg
```

Importer details:
- Downloads main `image_url` into `image/` and sets as featured in `product_images`.
- Downloads up to 5 gallery URLs into `image/` and adds them to `product_images`.
- Upserts products by name: if `product_name` exists, updates description/category/price/quantity (keeps old photo if download fails).
- Requires outbound HTTP/S (cURL or `file_get_contents`).

### Sample Seeder
- Admin → “Seed Sample Data” will create several categories and sample products using the existing images in `image/`.

---

## Order Status and Notifications
- Admin can update order status to `SHIPPED`, `DELIVERED`, or `CANCELLED`.
- A notification record is created in `notifications` for the customer (UI for reading notifications can be added easily later).

---

## Troubleshooting

### MySQL won’t start in XAMPP
- Kill leftover `mysqld.exe` processes, check port 3306 conflicts, run XAMPP as Administrator.
- If `InnoDB` corruption errors appear, try deleting `ib_logfile0`, `ib_logfile1`, and `ibtmp1` inside `C:\xampp\mysql\data` (do not remove database folders), or do a data reset from `C:\xampp\mysql\backup` after backing up your `data`.

### Voucher says “not found or inactive”
- Ensure voucher is Active and current date is between `starts_at` and `expires_at`.
- Meets minimum order and usage limits.

### Checkout form won’t submit
- All fields are required. If “Billing same as shipping” is unchecked, billing fields become required.

---

## Development & Branching

Main branches:
- `main` — stable
- `versioning` — for your ongoing changes and PRs

Typical flow:
```bash
git checkout versioning
# make changes
git add -A && git commit -m "Your message" && git push
# open a Pull Request from versioning → main on GitHub
```

---

## Security Notes
- This is a demo. If going to production:
  - Implement proper password hashing and authentication
  - Use prepared statements consistently (already used in most places)
  - Add CSRF protection for admin actions
  - Validate and sanitize CSV inputs and downloaded images further


