# ChargeFormlabs

Webhook for Automatic Billing of Formlabs Prints via Fabman

## ✨ Overview

This PHP script enables **automated billing** for **Formlabs 3D printers** through **Fabman**, using a webhook mechanism. When a print finishes, the script:

* Queries the latest print job via the Formlabs API
* Extracts material type and volume
* Looks up price information from Fabman resource metadata
* Creates a corresponding charge in Fabman for the user

---

## 🔧 Features

* Webhook-based auto-trigger via Fabman
* Material-based or fallback per-ml pricing
* Formlabs API integration
* Works with multiple printers via `?resources=` parameter
* Structured debug logging

---

## ✅ Requirements

- **Fabman API Access**  
  You need an active [Fabman](https://fabman.io/) account with API access enabled.

- **Formlabs Web API Access**  
  Enable API access in your Formlabs dashboard and create a developer client (Client ID & Secret).

- **PHP-Compatible Web Server**  
  This webhook is written in PHP. It requires a server that supports PHP 7.4 or newer.

- **Valid SSL Certificate**  
  Your server must be accessible via **HTTPS** and have a **valid SSL certificate**.  
  This is a requirement for Fabman to successfully deliver webhook requests.

---

## ⚙️ Setup

1. **Place** the script on a publicly accessible web server (e.g. `/var/www/chargeFormlabs.php`)
2. **Configure constants** at the top of the script:

```php
const WEBHOOK_TOKEN      = 'your_webhook_token';
const FABMAN_API_URL     = 'https://fabman.io/api/v1/';
const FABMAN_TOKEN       = 'your_fabman_api_token';
const FORMLABS_CLIENT_ID = 'your_formlabs_client_id';
const FORMLABS_USER      = 'your_user_email@example.com';
const FORMLABS_PASSWORD  = 'your_formlabs_password';
```

3. **Define webhook** in Fabman:

   * Event: `resourceLog_created` or `resourceLog_updated`
   * URL: `https://yourdomain.com/chargeFormlabs.php?secret=your_webhook_token&resources=1322,1516`

> ⚠️ You must explicitly list the Fabman resource IDs of the Formlabs 3D printers (comma-separated list) to handle via the `resources` URL parameter and `secret` must match WEBHOOK_TOKEN in the script.

---

## 💼 Resource Metadata Example

In Fabman, under each resource, add metadata like:

```json
{
  "printer_serial": "LucidEel",
  "price_per_ml": 1,
  "FLCW4001": {
    "name": "CastableWax40",
    "price_per_ml": 0.23
  },
  "FLFL8001": {
    "name": "Flexible80A",
    "price_per_ml": 0.13
  }
}
```

* `price_per_ml` is the **default fallback** price.
* Each material code (e.g., `FLCW4001`) can define an **override price**.

---

## 🔢 Pricing Logic

The price is calculated as:

```php
$price = round($volume_ml * $price_per_ml, 2);
```

* If `$materialCode` exists in metadata, its `price_per_ml` is used
* Otherwise, `price_per_ml` is used as fallback

---

## 🧰 Debugging

The script outputs `[DEBUG]` messages for:

* Payload parsing
* API access
* Metadata retrieval
* Charge creation

Use Fabman's webhook log interface to review output.

---

## 🔗 Contributing

Feel free to fork, open issues, or submit pull requests. Bug fixes and support for other printer types welcome!

---

## 📄 License

This project is licensed under the [MIT License](LICENSE).

---

## 🙋‍♂️ Contact

Created by [HappyLab](https://happylab.at). For support or feature requests, open an issue on GitHub.
