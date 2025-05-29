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
* Splits a single Fabman activity into per-printjob activities when multiple prints occur
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
const WEBHOOK_TOKEN           = 'your_webhook_token';
const FABMAN_API_URL          = 'https://fabman.io/api/v1/';
const FABMAN_TOKEN            = 'your_fabman_api_token';
const FORMLABS_CLIENT_ID      = 'your_formlabs_client_id';
const FORMLABS_USER           = 'your_user_email@example.com';
const FORMLABS_PASSWORD       = 'your_formlabs_password';
const DESC_TEMPLATE_BASE      = '3D print %s on %s';
const DESC_TEMPLATE_SURCHARGE = '3D print %s on %s - surcharge for %.2f ml %s';
```

You can override the default charge description formats by editing these constants in the script:
  * %s → print job name
  * %s → Fabman resource name
  * %.2f → (surcharge only) volume in ml
  * %s → (surcharge only) material name

3. **Define webhook** in Fabman:

   * Event: `Activity log`
   * URL: `https://yourdomain.com/chargeFormlabs.php?secret=your_webhook_token&resources=1322,1516`

> ⚠️ You must explicitly list the Fabman resource IDs of the Formlabs 3D printers (comma-separated list) to handle via the `resources` URL parameter and `secret` must match WEBHOOK_TOKEN in the script.

---

## 🔌 Fabman Configuration

To ensure charges are triggered **after a print finishes**, we recommend the following settings in your printer's resource configuration in Fabman:

1. ✅ **Enable machine status detection via power consumption**  
   → Activate “Detect machine status based on power consumption”  
   → Set threshold to **40 VA**

2. ✅ **Enable automatic shutdown of idle equipment**  
   → Activate “Turn off idle equipment if members don’t interact”  
   → Set timeout to a suitable value (e.g., **10 minutes**)

These settings ensure the printer powers down a few minutes after the print finishes.  
The power-off event will trigger a `resourceLog_updated` webhook — causing the charge to be created.

---

## 💼 Resource Metadata Example

In Fabman, under each resource, add metadata like:

```json
{
  "printer_serial": "LucidEel",
  "price_per_ml": 0.10,
  "billing_mode": "surcharge",
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

* `printer_serial` Serial number of the Formlabs printer.
* `price_per_ml` is the **default fallback** price.
* Each material code (e.g., `FLCW4001`) can define an **override price** or a **base price** (see `billing_mode`).
* `billing_mode` (optional):
  * `"default"` (default) – Uses material-specific price if available
  * `"surcharge"` – Always charges the base price (`price_per_ml`) plus a separate surcharge if a material-specific price is defined

---

## 🔢 Pricing Logic

### Mode: "default" (default)

The price is calculated as:

```php
$price = round($volume_ml * $price_per_ml, 2);
```

* If `$materialCode` exists in metadata, its `price_per_ml` is used
* Otherwise, `price_per_ml` is used as fallback

### Mode: "surcharge"

Two separate charges are created:

* **Base charge:** Always calculated with the general price_per_ml
* **Surcharge** (if applicable): Only added if a material-specific `price_per_ml` is defined.

This makes charges more transparent for end users, distinguishing basic and material-related costs.

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
