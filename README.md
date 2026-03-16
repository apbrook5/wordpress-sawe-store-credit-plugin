# SAWE Membership Store Credits

**Version:** 1.0.6
**Requires WordPress:** 6.4+  
**Requires WooCommerce:** 8.0+  
**Requires PHP:** 8.0+

---

## Overview

SAWE Membership Store Credits gives eligible members a renewable store credit that auto-applies to qualifying products at checkout. Credits are role-based, product-scoped, and renew annually on a configurable date.

---

## Installation

1. Upload the `sawe-membership-store-credits` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **Store Credits → Settings** and configure the uninstall option.
4. Create a credit definition under **Store Credits → Add New**.

---

## Creating a Store Credit

| Field | Description |
|---|---|
| **Title** | Shown to the member in checkout and account pages |
| **Description** (body) | Shown to the member below the title |
| **Admin Notes** | Internal only; never shown to members |
| **Credit Amount ($)** | Dollar value awarded / renewed each cycle |
| **Renewal Date** | Month + Day when the credit resets to full value each year |
| **Eligible Roles** | WP user roles that receive this credit |
| **Qualifying Categories** | Product categories whose products the credit can pay for |
| **Qualifying Products** | Individual products the credit can pay for |

Set **post status** to **Published** to activate. Drafts and pending posts are ignored.

---

## How Credits Work

### Award
When a logged-in user visits the store, the plugin checks every *published* credit definition. If the user holds a matching role and has no existing row, they are awarded the full credit amount.

### Display
- **Checkout** – A styled box (light blue background, gold border) appears above the checkout form listing each active credit, its remaining balance, how much will be applied to the current order, and the renewal date.
- **My Account → Available Store Credits** – A dedicated account tab shows all credits.

### Applying the Discount
The discount is injected as a WooCommerce *fee* (negative amount) after all other coupons. It is limited to:
- The user's remaining balance, AND
- The combined subtotal of qualifying items in the cart.

### Removing / Restoring
Members can click **Remove Store Credit** to exclude it from the current order. The button changes to **Re-apply Store Credit** to allow reversal before checkout.

### Finalisation
The deduction is only committed to the database when the order is **placed** (`woocommerce_checkout_order_created`). If the order is cancelled or refunded, the deducted amount is restored automatically.

### Renewal (Cron)
A daily WP-Cron job runs at midnight. On the configured Month/Day it resets every eligible user's balance back to the full initial amount.

### Role Removal
If a user no longer holds any of the required roles, their balance is zeroed the next time they visit the store.

---

## Uninstall

Go to **Store Credits → Settings** and check **Remove database tables on uninstall** before deleting the plugin if you want the `wp_sawe_msc_user_credits` table removed.

---

## CSS Customisation

Override styles in your child theme or via **Appearance → Customize → Additional CSS**:

```css
/* Example: change border colour */
.sawe-msc-credit-box {
    border-color: #005a9c;
}

/* Example: change background */
.sawe-msc-credit-box {
    background-color: #f0f8ee;
}
```

---

## File Structure

```
sawe-membership-store-credits/
├── sawe-membership-store-credits.php   Main plugin file
├── uninstall.php                        Cleanup on delete
├── includes/
│   ├── class-sawe-msc-db.php            Database layer (tables + CRUD)
│   ├── class-sawe-msc-credit-post-type.php  CPT + meta registration
│   ├── class-sawe-msc-user-credits.php  Award / revoke / renewal logic
│   ├── class-sawe-msc-cart.php          Cart discount injection
│   ├── class-sawe-msc-checkout.php      Checkout notice + order finalisation
│   └── class-sawe-msc-account.php       My Account tab
├── admin/
│   ├── class-sawe-msc-admin.php         Admin menus, meta boxes, columns
│   ├── css/sawe-msc-admin.css
│   └── js/sawe-msc-admin.js
└── public/
    ├── css/sawe-msc-public.css
    └── js/sawe-msc-cart.js
```

---

## Changelog

### 1.0.0
- Initial release
