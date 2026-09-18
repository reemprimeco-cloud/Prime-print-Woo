# WooCommerce template overrides

Files placed here override WooCommerce's own templates of the same path. They
are added by the phase that owns them, **not before** — an empty or partial
override replaces working WooCommerce output with broken output, so the default
template stays in place until its replacement is actually finished.

| Path | Replaces | Phase |
|---|---|---|
| `archive-product.php` | Shop and category archives | 3 |
| `content-product.php` | The product card in the loop | 3 |
| `single-product.php` | Product page shell | 4 |
| `single-product/*.php` | Gallery, tabs, add-to-cart | 4 |
| `checkout/form-checkout.php` | Checkout layout | 5 |
| `checkout/form-billing.php` | House / Apartment / Gift address forms | 5 |
| `myaccount/*.php` | Account tabs | 8 |
| `emails/*.php` | Transactional email styling | 7 |

Two rules for everything in this directory:

1. **Copy the template from the installed WooCommerce version first**, then edit
   it. Writing one from scratch drops hooks that plugins (MyFatoorah, Armada,
   the QuickBooks connector) depend on.
2. **Keep the `@version` docblock accurate.** WooCommerce compares it against its
   own and warns in Status → Templates when an override falls behind, which is
   the only warning that an upstream change has been missed.

Presentation-only changes belong in the theme's CSS or on a WooCommerce hook in
`inc/woocommerce.php`. Override a template only when the markup itself has to
change.
