# Inactive Feeds Cleaner for FreshRSS

[![FreshRSS](https://img.shields.io/badge/FreshRSS-Extensions-blue.svg)](https://freshrss.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg?logo=php)](https://www.php.net/)
[![Databases](https://img.shields.io/badge/Database-PostgreSQL%20%7C%20MySQL%20%7C%20SQLite-brightgreen.svg)]()
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

An extension for [FreshRSS](https://github.com/FreshRSS/FreshRSS) that helps you identify, back up to OPML, and purge obsolete or abandoned RSS feeds based on customizable inactivity thresholds.

---

## ✨ Features

- **Dynamic Feed Counter:** Displays the exact number of inactive feeds directly inside the dropdown menu for each threshold before taking action.
- **Multiple Inactivity Thresholds:**
  - ⚡ **Last week** (7 days)
  - 📅 **1 month** (30 days)
  - 🍂 **3 months** (90 days)
  - ❄️ **6 months** (180 days)
  - ⏳ **1 year** (365 days)
  - 🪦 **+1 year without updates** (2 years / 730 days)
- **Safety First (OPML Export):** Generate and download a standard OPML 2.0 file containing only the targeted feeds before deleting them.
- **Clean Database Purge:** Fully compatible with **PostgreSQL**, **MySQL/MariaDB**, and **SQLite**. Deletes articles, favicons, and metadata cleanly using official FreshRSS controller routines.
- **Internationalization (i18n):** Native support for **English** and **French** (automatically follows your user profile language).
- **Full Traceability:** Detailed logs emitted in real time to both FreshRSS administration logs and the web server (`/var/log/apache2/error.log`).

---

## 🚀 Installation

### 1. Clone into your FreshRSS extensions folder

In your FreshRSS server or container terminal:

```bash
cd /opt/freshrss/extensions
git clone https://github.com/YOUR_USERNAME/xExtension-InactiveCleaner.git
```

### 2. Fix permissions

Ensure the web server user (`www-data` on Debian/Ubuntu) has read access:

```bash
chown -R www-data:www-data /opt/freshrss/extensions/xExtension-InactiveCleaner
chmod -R 755 /opt/freshrss/extensions/xExtension-InactiveCleaner
```

### 3. Enable the extension

1. Open your FreshRSS web interface.
2. Go to **Settings (`⚙️`) > Extensions**.
3. Locate **Inactive Feeds Cleaner** and click **Enable**.
4. Click on **Configure** to open the management panel.

---

## 📖 Usage

1. **Select an inactivity threshold:** Open the dropdown list. Next to each option, the extension displays the live count of feeds that have had no publications since that date (e.g. `3 months (90 days) — 12 feeds`).
2. **Download OPML (Recommended):** Click **📥 Export targeted feeds (.opml)** to save a backup file to your computer.
3. **Purge:** Click **🗑️ Delete targeted feeds** and confirm the dialog. FreshRSS purges the feeds and displays a success notification with the exact number of removed feeds.

---

## 📂 Project Structure

```text
xExtension-InactiveCleaner/
├── configure.phtml         # User interface with live feed counter
├── extension.php           # Core extension logic and database queries
├── metadata.json           # FreshRSS extension declaration
├── README.md               # Documentation
└── i18n/
    ├── en/
    │   └── ext.php         # English translations
    └── fr/
        └── ext.php         # French translations
```

---

## 🛡️ License

This project is licensed under the MIT License. Feel free to fork, adapt, and contribute!
