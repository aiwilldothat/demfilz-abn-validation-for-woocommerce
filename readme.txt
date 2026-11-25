=== Demfilz ABN Validation for WooCommerce ===
Contributors: demfilz
Tags: abn, abn lookup, australia, gst, b2b
Requires at least: 6.3
Tested up to: 7.2
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ABN field for the WooCommerce checkout (block and classic). Validates check digits and looks up active status, business name and GST on the ABR.

== Description ==

Australian stores that sell to businesses need the buyer's ABN: for tax invoices, for trade terms, and to know the buyer is a real, active business. A plain text field collects typos, made-up numbers and ABNs that were cancelled years ago. This plugin adds an ABN field that checks what is typed.

**It works the minute you activate it.** Every ABN carries two check digits, so a mistyped or invented number is caught without asking anyone. Add a free GUID from the Australian Business Register and each ABN is also looked up: you see whether it is active, who it belongs to and whether they are registered for GST.

**At the checkout**

* An ABN field in the checkout block (under the email address) and in the classic checkout (after Company).
* Optional, required, required only when a company name is given (for stores with both trade and retail customers), or switched off.
* For customers with an Australian billing address only (the default), or for everyone. For other countries the field is hidden and nothing is checked or saved.
* Live feedback under the field while the customer types: "That is not a valid ABN", or "ACME PTY LTD. Active ABN, registered for GST."
* The Company field is filled with the business name from the register when it is empty. A name the customer typed is never replaced.
* Returning customers get their saved ABN filled in, and can change it in My Account > Account details.

**What you decide**

* Look the ABN up and refuse ABNs that are cancelled or not on the register; or look it up, keep the answer and refuse nothing; or check the format only.
* Optionally treat an ABN that is not registered for GST like a cancelled one.
* If the register cannot be reached, the order always goes through and is marked "not checked". A register outage never costs you a sale.

**On the order**

* The ABN is stored as 11 digits in the order meta `_billing_abn` and the customer meta `billing_abn`, the names WooCommerce itself uses for a billing field, so stores that already collect an ABN in a field called `billing_abn` keep their data.
* An ABN check panel on the order screen: status, entity name, entity type, GST registration, state and postcode, when it was checked, a Check again button and a link to the public ABN Lookup record.
* The admin New order email says what the register answered.
* Optionally, the ABN is added as the last line of the billing address, which is what order emails and most PDF invoice and packing slip plugins print.
* Find orders by ABN in the order search.
* The ABN is included in WooCommerce's personal data export and erasure.

**Lookup tool**

WooCommerce > Settings > ABN Validation > Lookup tool looks up an ABN, an ACN or a business name, for the customer on the phone.

**Built to be safe**

* Your GUID stays on your server. Customers' browsers ask your store, and your store asks the register.
* Nothing is sent to the register unless the number passes the check digit test, and each ABN is asked about once per cache period (24 hours by default).
* The public checkout cannot be used to drain your GUID: live lookups are limited per IP address and per hour, and the check made when an order is placed has an allowance of its own, so using up the live lookups does not switch it off.
* The ABN is checked again on your server when the order is placed, whatever the browser sent.
* Works with WooCommerce High-Performance Order Storage (HPOS) and the cart and checkout blocks.

**Works with Demfilz Trade Accounts for WooCommerce**

If you use Demfilz Trade Accounts for WooCommerce, the trade account screen shows what the register says about the ABN the applicant gave.

**Not affiliated with the Australian Government**

This plugin is not affiliated with, endorsed by or supported by the Australian Business Register, the Australian Taxation Office or any other government agency. ABN Lookup data is supplied by the register "as is"; check the public record when a decision depends on it.

**For developers**

Functions: `dabn_is_valid_abn()`, `dabn_format_abn()`, `dabn_lookup_abn()`, `dabn_get_order_abn()`, `dabn_get_order_abn_check()`, `dabn_get_customer_abn()`.

Filters: `dabn_check`, `dabn_lookup_result`, `dabn_block_field`, `dabn_classic_field`, `dabn_rate_limit`, `dabn_rate_limit_site`, `dabn_rate_limit_orders`, `dabn_request_args`. Action: `dabn_order_synced`.

Order meta: `_billing_abn` (11 digits) and `_dabn_check` (array: abn, result, name, type, code, names, gst, since, state, postcode, acn, checked, note, order). Customer meta: `billing_abn`. Orders created through the REST API with `_billing_abn` in their meta are tidied and checked too.

== External services ==

This plugin can connect to **ABN Lookup**, the public web service of the Australian Business Register (Australian Government), to confirm that an ABN is registered and to read the public details of the business.

* It is used only when you have saved your own ABN Lookup GUID in WooCommerce > Settings > ABN Validation. Without a GUID the plugin contacts nothing.
* A request is made when a customer enters a well-formed ABN at the checkout or in My Account, when an order with an ABN is placed, saved in the admin or created through the REST API, when you click Check again on an order, when you use the lookup tool, and when you open the plugin's settings page (to show whether your GUID works, by looking up the Australian Taxation Office's own ABN; the answer is remembered for 12 hours, or 5 minutes after a failure). With Demfilz Trade Accounts for WooCommerce active, opening a trade account whose application gave an ABN looks that ABN up. Answers are cached, so the same ABN is not asked about again within the cache period.
* What is sent: the ABN, ACN or business name being looked up, and your GUID. Nothing else: no customer name, email address, postal address, IP address or order details.
* Requests go from your server to `https://abr.business.gov.au/json/AbnDetails.aspx`, `https://abr.business.gov.au/json/AcnDetails.aspx` and `https://abr.business.gov.au/json/MatchingNames.aspx`.

Service information and registration: [ABN Lookup web services](https://abr.business.gov.au/Tools/WebServices). Terms of use: [Web services agreement](https://abr.business.gov.au/Tools/WebServicesAgreement). Privacy: [ABN Lookup privacy policies](https://abr.business.gov.au/Home/PrivacyPolicies).

== Installation ==

1. Install and activate the plugin. WooCommerce 9.9 or newer must be active.
2. The ABN field is live at once: optional, for customers with an Australian billing address, with the check digit test.
3. To look ABNs up, register (free) for [ABN Lookup web services](https://abr.business.gov.au/Tools/WebServices). The register emails you a GUID.
4. Paste the GUID in WooCommerce > Settings > ABN Validation and save. The page tells you whether the register accepted it.
5. Choose what happens when an ABN is entered, and whether the ABN is required.

== Frequently Asked Questions ==

= Do I need a GUID? =

No. Without one the plugin still refuses mistyped and made-up numbers, because an ABN contains two check digits. With a GUID it also knows whether the ABN is active, who holds it and whether they are registered for GST. Registration with the Australian Business Register is free.

= What does "valid format" mean? =

The number has 11 digits and its check digits add up, so it could be an ABN. It does not mean the ABN has been issued or is still active. Only a lookup tells you that.

= What happens if the register is down? =

The order goes through. The ABN still gets the check digit test, the order screen shows "Not checked" with the reason, and Check again asks the register when you are ready. After a failed request the plugin waits a minute before trying again, so a slow register does not slow your checkout.

= Can I refuse customers whose ABN is cancelled? =

Yes. Choose "Look it up, and refuse ABNs that are cancelled or not on the register". The customer sees the reason under the field and when they try to place the order.

= Can I ask only business customers for an ABN? =

Yes. Set the ABN field to "Required when a company name is given". Customers who fill in Company must give an ABN; everyone else can leave it empty. (The Company field has to be shown in your checkout for this.) Or make the field optional.

= If a customer gives an ABN, should I stop charging GST? =

No. In Australia an ABN does not make a sale GST-free, so this plugin never changes tax. The ABN is for your records, your tax invoices and your trade terms.

= Does the buyer's ABN need to be on my tax invoices? =

A tax invoice for a sale of $1,000 or more has to show the buyer's identity or ABN. Tick "Add the ABN as the last line of the order's billing address" and the ABN is printed wherever the billing address is, which covers order emails and most PDF invoice plugins. Check a sample invoice from your own invoice plugin after switching it on.

= How long does it take to get a GUID? =

The Australian Business Register sends it by email after you register, which is not always instant. Until you paste it in, the field works with the check digit test.

= Where does the ABN appear? =

On the order screen, in order emails, on the order received and View order pages, and in My Account > Account details. Tick "Add the ABN as the last line of the order's billing address" to print it wherever the billing address is printed, which includes most PDF invoice plugins.

= We already collect ABNs in a custom checkout field. Will we lose them? =

If the field is called `billing_abn`, no: this plugin uses the same order meta (`_billing_abn`) and customer meta (`billing_abn`). Remove the old field so it is not shown twice.

= Is my GUID visible to customers? =

No. It is stored in your settings and used only by your server. The checkout asks your store about an ABN, and your store asks the register.

= Could someone use my checkout to run thousands of lookups? =

Live lookups are limited to 20 an hour per IP address and 300 an hour across all visitors, numbers that fail the check digit test are never sent, and answers are cached. The check made when an order is placed has its own allowance (300 new ABNs an hour), so a visitor who uses up the live lookups cannot switch off the check on orders. When an allowance is used up the ABN is marked "not checked" on the order, where you can check it again. The limits can be changed with the `dabn_rate_limit`, `dabn_rate_limit_site` and `dabn_rate_limit_orders` filters.

= Does it work with the checkout block and HPOS? =

Yes, with the checkout block and the classic checkout shortcode, and with both order storage modes.

= Does it support ACNs or New Zealand business numbers? =

The lookup tool accepts an ACN and shows the company's ABN record. The checkout field is for ABNs only. NZBNs are not supported.

= What happens to ABNs if I remove the plugin? =

They stay on your orders and customer accounts. Cached register answers are deleted; the settings are deleted only if you tick the Uninstall box.

== Screenshots ==

1. The ABN field in the checkout block, verified against the register.
2. A mistyped ABN is caught straight away.
3. The ABN check on the order screen.
4. WooCommerce > Settings > ABN Validation.
5. The lookup tool: an ABN, an ACN or a business name.
6. The ABN field in the classic checkout.

== Changelog ==

= 1.0.1 =
* Renamed to Demfilz ABN Validation for WooCommerce. ABN Lookup is the name of the Australian Business Register's own service, which this plugin is not part of. The plugin folder and the text domain changed with the name. Settings, orders and customer data are untouched.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.1 =
New name: Demfilz ABN Validation for WooCommerce. If you installed 1.0.0 from demfilz.com, deactivate and delete that copy before you activate this one. The ABNs on your orders and customers are kept.

= 1.0.0 =
First release.
