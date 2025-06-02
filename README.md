# 🔐 WordPress DigiCommerce License System

A complete, drop-in licensing system for WordPress plugins/themes or any digital product that works with DigiCommerce licensing servers. Just change 3 lines and you're done!

## 🌟 Features

- ✅ **Complete License Management** - Activate, deactivate, verify licenses
- ✅ **Automatic Plugin Updates** - Only for licensed users
- ✅ **Professional Admin Interface** - Clean license management page
- ✅ **Smart Caching** - Reduces API calls, improves performance
- ✅ **Development Site Detection** - Auto-detects staging/dev environments
- ✅ **Security First** - WordPress nonces, capability checks, sanitization
- ✅ **Drop-in Ready** - Just change 3 constants and you're done!

## ⚙️ Configuration (Only 3 Lines!)

Open the main plugin file and update these 3 constants:

```php
// API Configuration - Update these for your licensing server
define('AWESOME_API_URL', 'https://your-licensing-server.com');     // Your DigiCommerce site URL
define('AWESOME_PLUGIN_SLUG', 'your-product-slug');                 // Your product slug in DigiCommerce
define('AWESOME_PLUGIN_NAME', 'Your Product Name');                 // Your product display name