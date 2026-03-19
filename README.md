# Kabbo-WC-Category-Hide-Noindex
Control WooCommerce category visibility and SEO with per-category toggles to hide from frontend, remove from listings and menus, force 404 on access, and apply noindex rules to categories and products.

# Kabbo WC Category Hide + Noindex

Control WooCommerce category visibility and SEO behavior with precision.

This plugin allows you to **hide product categories from the frontend**, remove them from listings and menus, block access via direct URLs, and apply **noindex rules** to both categories and their products.

---

## 🚀 Features

- Hide product categories from frontend
- Remove hidden categories from:
  - Shop pages
  - Product loops
  - Menus
- Force **404 on hidden category archives**
- Hide products inside hidden categories
- Block direct access to hidden products (404)
- Add **noindex, nofollow** to:
  - Category pages
  - Product pages inside those categories
- Lightweight and performance optimized
- Smart caching for category IDs

---

## 🧠 How It Works

Each product category gets two options:

### 1. Hide from Storefront
- Removes category from all frontend displays
- Excludes products from queries
- Blocks category archive with 404
- Blocks product pages inside that category

### 2. Noindex for Search Engines
- Adds:
  ```html
  <meta name="robots" content="noindex, nofollow">
