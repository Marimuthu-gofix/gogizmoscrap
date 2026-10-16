<?php
// TEMPLATE ONLY. Copy this file to config.php (same folder) and put your
// real values in config.php, never here. config.php is ignored by git on
// purpose: it holds the database password and the API key, and those must
// never be uploaded to GitHub.
return [
    // From hPanel > Databases > Management. Hostinger's host is usually
    // "localhost"; the database and user names start with "u" and digits.
    // The buyback tables (buyback_prices, buyback_users) can share a
    // database with other sites; their names do not clash.
    'dsn' => 'mysql:host=localhost;dbname=u123456789_buyback;charset=utf8mb4',
    'user' => 'u123456789_buyback',
    'password' => 'change-me',

    // Long random secret. The same value goes into the PRICE_API_KEY GitHub
    // Actions secret, into PRICE_API_KEY on Render (if used), and into the
    // .env file on your PC. It is also the setup code for the first admin.
    // Generate one with:  python -c "import secrets; print(secrets.token_urlsafe(32))"
    'api_key' => 'change-me',

    // Run the dashboard's scrape buttons on GitHub Actions (recommended).
    // A fine-grained GitHub token for the repository with the permission
    // "Actions: Read and write", and the repository as "owner/name".
    // 'github_token' => 'github_pat_...',
    // 'github_repo' => 'Marimuthu-gofix/gogizmoscrap',

    // Or forward the buttons to a Render app running dashboard.py instead.
    // 'scraper_url' => 'https://your-app.onrender.com',
];
