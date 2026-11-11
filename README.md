# Flex-C 1.4.2
Billing, stock, purchases and accounts for retail shops, with an offline POS and an owner dashboard.
Powered by Adnys. Plain PHP 8.1+ and MySQL/MariaDB, built for Hostinger shared hosting.

## 1. Fresh install (new database)
1. hPanel > Databases: create an empty MySQL database and a user for it. Note the three values.
2. Empty `public_html` (or the sub-domain folder), then upload and extract this package there.
3. Open `https://your-domain/install.php`. Fill in the database, company (TRN optional), first shop, owner, and optionally the Adnys Console admin. Tick samples to add demo items.
4. The installer creates the tables, writes `lib/config.php` and locks itself (`lib/installed.lock`).
5. Sign in with the username it shows, or the owner email.

Requirements: PHP 8.1+, PDO MySQL, zip. For signed uploads in the Console also enable **sodium** (hPanel > PHP Configuration > Extensions).

## 2. Pages
| Address | For |
|---|---|
| `/pos/` | Cashiers. Works offline, installable on a phone or tablet |
| `/office/` | Back office: items, stock, stock record, purchases, suppliers, expenses, returns, reports, cash-ups, alerts, customers, settings and logos, users |
| `/verify/` | Public: opens when a customer or supplier scans the QR code on a bill |
| `/dashboard/` | Owner: all companies and shops at a glance |
| `/console/` | Adnys staff only: customers, versions and uploads, activity |

### POS: quantity, stock check and camera
- **Quantity:** type `12*SKU` (or `12x`) in the scan box and Enter to add 12 at once; or use the − / + buttons or type in the Qty box of a cart line.
- **Stock check:** the cart line shows "only N in stock" when you sell more than recorded, and Pay asks for confirmation (it does not block, so a late stock entry never stops a sale). Stock on screen drops after every sale, even offline. Use **+ Add stock** when goods arrive.
- **Camera scan:** the **Scan** button opens the phone/tablet camera (needs https and camera permission). Each barcode in view is added once; press Done when finished. Uses the built-in detector where available, otherwise a bundled reader that also works offline. Also available inside + Add stock.

### Adding stock: which way?
- **Purchase (Back office > Purchases):** when goods come with a supplier bill. Records the bill, supplier balance and stock together. Scan or type barcodes (`12*code` for quantity) or use **Scan with camera**; each scan adds a line or tops up the quantity.
- **+ Add stock (POS):** quick top-up of an already registered item, no bill (manager authorises cashiers).
- **New product:** Back office > Items; the **Scan** button beside Barcodes captures the code (warns if another item already has it).
A USB/Bluetooth barcode scanner works anywhere you can type: it just "types" the code and presses Enter.

### Purchases: error-free stock and accounts
- **Bill control totals:** enter the supplier bill total (and optionally total units) exactly as printed. The purchase is refused if the lines you entered do not add up to it, and the form shows a live tick or cross while you type. A supplier bill number can only be entered once per supplier.
- **Billed vs received:** each line has a Received box (blank = same as billed). Stock is increased by what really arrived; the supplier bill stays at the billed amount, and a red "Short delivery" alert is raised so you can claim it.
- **Proof:** the Proof button on each purchase shows who entered it and when, billed vs received, and the stock entries it created. Photos are optional (add one from the Proof window if you want).
- **Rule:** stock comes in through the purchase bill only. POS Add stock is for found goods and opening stock; every manual stock-in is listed in Checks under "Stock added without a purchase bill". Do not enter the same goods in both places.
- **Rate differences:** the bill rate is always used for cost and the supplier balance; the form shows a note when it differs from the last buy rate.
- **Checks tab:** re-adds everything for a date range: purchase totals, purchases vs stock, sales totals vs payments, sales vs stock, negative stock, supplier payments. It lists any difference, and has a printable proof page. Run it at month end or before a cash-up.

### Back office menu
A grouped left sidebar (Today, Stock, Buying, Money and tax, Customers, Admin). Click a group name to collapse or open it (remembered on that device). Type in the search box, or press `/`, to find any menu: matching groups open automatically, Enter opens the first match. On a phone it folds into one "Menu" bar at the top.

**Barcode history** (Stock) lists every label print (who, when, which barcode, how many) and a register of every item's barcode with the last time its label was printed. Search by barcode, SKU or item.

### VAT return, supplier returns, labels, backup
- **VAT return** (Money and tax): pick this month, last month, this quarter, last quarter or custom dates. Shows output VAT on sales (sales returns netted off), input VAT on purchases (supplier returns netted off) and expenses with VAT, and the net VAT payable or refundable. CSV and print. These are the figures for the FTA VAT 201 return; check them against your tax invoices before filing.
- **Supplier returns** (Buying): a debit note for goods sent back. Takes the goods out of stock (refused if there is not enough), reduces what you owe the supplier, reduces input VAT, and prints a debit note. The Checks tab confirms each debit note left the stock.
- **Barcode labels** (Stock): tick items, set copies, print on a label roll (38x25 or 50x30 mm) or an A4 sheet (3x8). Items without a barcode print their SKU as a Code 128 barcode, which the POS, Purchases and the camera scan like any other code.
- **Backup and export** (owner, accountant): CSV for items, stock, sales, purchases, supplier returns, suppliers, expenses and customers, plus one full JSON backup. The full database copy is also in hPanel > Backups.
- **E-invoicing:** the UAE e-invoicing system needs a government-accredited service provider and will be phased in by turnover. Flex-C already records the TRN, VAT per line, invoice number and customer TRN needed. When your provider is chosen, the connection is a separate small project.

### Themes, dark mode and phone menu
Every app has a light/dark switch (round button, bottom left). It follows the phone or computer setting until someone picks one; the choice is remembered per device. Printed bills and reports are always light.
Each app's colours follow its icon: POS teal, Back office indigo, Dashboard amber, Console Adnys blue. On phones the header links collapse behind a menu button.

### Install as an app (PWA)
Console, Dashboard, Back office and POS are each their own installable app with their own icon and name. Open the page in Chrome/Edge/Android and tap the **Install app** button (bottom right), or on iPhone/iPad use Share > Add to Home Screen. The installed app opens in its own window without browser bars.

**Automatic updates.** Every CSS/JS file is fingerprinted (`app.css?v=3aa7de8174`) and each app's service worker carries a fingerprint of its files, so after an update installed apps fetch the new version by themselves (checked on open, when you return to the app and every 30 minutes). The page reloads on its own; the POS waits until the cart is empty and no window is open, and shows a "Reload now" bar meanwhile. Fingerprints are stamped by `tools/make_release.php` (or `php tools/fingerprint.php <folder>` for a manual upload); the POS keeps working offline.

## 3. Logins
Username = shop code + first 3 letters of the name + number, for example `ALN001AHM000001`. The email also works. Owner, manager and accountant see every back-office menu. The purchase role sees only purchase-related menus. Only the owner creates owner or manager accounts.

## 4. Shop codes
Shop code = shop name letters + 001 (`ALN001`). Another branch of the same shop becomes `ALN002`, so branches are told apart in reports.

## 5. POS
- Prices include 5% VAT. Every screen and bill shows the sale amount without VAT, the VAT, and the total separately.
- Payment: choose Cash, Card or Cash + Card. In Cash + Card, type the cash and the card fills the rest (bill 350, cash 50, card 300). Cash left empty = exact cash.
- Hold up to five bills and switch between them (yellow, then red as they wait). Cancel a held bill if needed.
- Returns need an authorised person (owner, manager or accountant) with their username and password. Everything is logged. Offline approvals are flagged for review after sync.
- Daily cash-up: opening cash, counted cash, card total, difference. Export Excel/CSV or PDF, share, WhatsApp or email.
- Customer mobile number on the payment screen (optional) plus a tick that the customer agrees to offers.
- After a sale: print the 80 mm receipt, send the bill on WhatsApp, share it, or print an A4 tax invoice (B2B).
- Company or shop logo shows faintly behind the item list (Settings: watermark opacity, default 15%, 0 = off).
- "+ Add stock": receive stock for a registered product straight from the POS. Owner, manager and accountant add directly; a cashier needs a manager, accountant or owner username and password. Online only, logged.
- Bills carry the logo, thank-you message in English and Arabic, and a QR code, then "Powered by Adnys".
- **Bill QR on/off:** Settings > Preferences > Bill QR code (owner, manager, accountant).
- **Bill QR = verify link.** Scanning opens `/verify/` showing seller, TRN, invoice number, date, every item with its VAT rate, VAT by rate and the total. Purchase bills (A4) carry a QR too. Customer name, mobile, cashier and cost prices of sales are never shown. A bill that has not synced yet says so and works after sync.

## 6. Back office settings
- **Settings > Preferences:** low-stock level (default 10), currency, share email/WhatsApp, receipt note, thank-you message, bill QR link.
  Empty QR link = the QR holds the invoice details (seller, TRN, time, total, VAT). A link = customers scan it to reach your review or Instagram page.
- **Logos:** company and shop logos (PNG or JPEG). Registers pick up a new logo after Refresh or the next sign-in.
- **Customers:** numbers captured at the POS, visits, spend, WhatsApp button with an editable offer message, CSV export. Send offers only to customers marked as agreeing.
- **Low stock:** below 10 units (or the item's own level) raises an alert for admin and the purchase team.

## 7. Updating
**Signed upload (normal way).** On your computer, in the project folder:
```
php tools/make_release.php build 1.3.1 --key=adnys-release.key --min-from=1.3.0 --notes="What changed"
```
Upload the resulting `flex-c-1.3.1.zip` in Console > Versions. The server checks the signature and every file, makes a backup, installs, runs database updates, and restores the old files if anything fails.
Your Console public key is stored in `lib/update_pubkey.txt` (fingerprint shown in the Console).

**Manual.** Upload the files over `public_html`. `lib/config.php`, `lib/installed.lock` and `lib/update_pubkey.txt` are never overwritten. Database changes apply by themselves on the first page load.

## 8. Keys and safety
- `adnys-release.key` (private) stays on your computer only. Never upload it, email it or paste it into a chat. Anyone holding it can push code to your servers. If it leaks, make a new pair with `php tools/make_release.php keygen new.key` and paste the new public key into Console > Versions.
- Share only the public key.
- Delete `install.php` after installing if you want (it already refuses to run again).

## 9. Known limits
- The bill goes to WhatsApp as text, not as a PDF attachment. A4 PDFs go through print / Save as PDF.
- WhatsApp opens on the cashier's device; it needs that device to have WhatsApp and internet.
- Returns approved while offline are stored as "offline approval" and shown in the Returns review.
- Email sending uses PHP `mail()`; delivery depends on your host.

## 1.8.0 – Home page, About page, colour-coded menu
- `index.html` is now the public home page and login chooser: POS, Owner dashboard, Back office, Console, each with an Install app button; SEO title/description/Open Graph/JSON-LD; features; plans. It is also installable as a "Flex-C" PWA (`manifest.webmanifest`, `sw.js`).
- `about/index.html` explains UAE VAT/Arabic bills, the onboarding process, hosting (shared vs dedicated UAE), market price comparison (pricing model only), customisation and FAQ, and links to https://adnys.com.
- **Prices are placeholders.** Edit the plan block in `index.html` (marked `EDIT PRICES HERE`) and the "Indicative price" row in `about/index.html`. Style is in `assets/landing.css`.
- Back office menu: each group has its own colour, collapses/expands, with Expand all / Collapse all.

## 1.8.1 – Your home page + enquiry form
- Root `index.html` is your supplied design (PWA manifest/worker added); "Know More About Flex-C" opens `about/`.
- `about/` has an enquiry form posting to `commercial.php` (your handler, redirects now point to `about/#contact`). It uses PHP `mail()`, so it needs mail enabled on the host.

## 1.8.2 – commercial.php is the plans & pricing page
- `commercial.php` shows plans (installation one-time + monthly for shared and dedicated hosting), typical hosting costs, and the enquiry form; it also receives the form (POST). Edit prices in the `$plans` array at its top (dedicated figures are placeholders). Home page "Know More" opens it.

## 1.9.0 – Fresh start from Excel, ABC demo, staged roll-out
- **Back office > Stock > Import stock (Excel)**: upload .xlsx or .csv for one shop. "Check file" previews and lists every problem; "Import" sets each item's stock to the Qty in the file (writes "opening" or "adjust" in the stock record) and creates new items. Template download included. Any error row stops the whole import.
- **Console > Customers > Fresh start (admin)**: "Clear items and stock" (per customer, or all customers except the demo). Companies, profiles, shops, users and settings are kept. Customers that already have bills need the "also remove sales, purchases and returns" tick. Needs your Console password and the words CLEAR ITEMS AND STOCK. Take a backup first (Back office > Backup and export, and hPanel Backups).
- **ABC demo company**: Console > Customers > "Create ABC demo company" (2 shops, 3 logins, 12 sample items with stock). Shown once with passwords.
- **Roll-out**: Console > Versions > Roll-out to customers. The demo is Auto (told about every release at once). Other customers are Push: they hear about a new version only after an admin presses Push. Note: files are installed once for everyone; Push controls the in-app "new version" notice and forced updates, not which files a customer's browser can load.

## 1.9.1
- Fix: "Clear items and stock" with the "also remove sales, purchases and returns" tick gave server_error (supplier-return lines were deleted with a wrong column).

## 1.9.2 – Owner rules, forgot password, version label
- **Primary owner**: each customer's first owner. Nobody in the shop can disable, demote or reset it; only an Adnys admin (Console) can. The primary owner can edit their own name, email and password.
- **Sub-owner** (role "Sub-owner (all controls)"): nominated only by the primary owner. Has all controls but cannot touch the primary owner. Only the primary owner can edit, disable or reset a sub-owner.
- **Passwords of other users**: only the owner or a sub-owner can reset or set them. Managers can manage staff but cannot reset passwords.
- **Console**: only an Adnys admin can create another owner login, reset an owner's password, or disable an owner.
- **Forgot password** link on every sign-in screen: an owner who forgot their password triggers an e-mail to adnys.dsf@gmail.com and info@adnys.com (uses PHP mail()), also logged under Console > Activity. Adnys verifies the person and resets it in Console. Other users are told to ask their owner.
- A small light "Flex-C vX.Y.Z" label at the bottom of every app screen.

## 1.9.3
- Expenses can now be edited and deleted by the owner / sub-owner (Back office > Expenses). Accountants and managers can still only add. Every edit (old and new values) and delete is kept in the audit log.

## 1.9.4 – Scan stock (phone)
- Back office > Stock > Scan stock (phone): choose a shop, scan a barcode with the phone camera (or type it), enter the quantity and save. Known product: shows current stock and sets the total counted (or adds). Unknown barcode: type name, price, cost and quantity once and the product is created with its barcode. "Scan next automatically" keeps the camera cycling. Writes "opening" (first time) or "adjust" in the stock record. Owner and manager only.

## 1.10.0 – Team stock take (several stockers at once)
- **Back office > Stock > Stock take (team)** (owner, manager). Start a count for a shop (one running count per shop), choose whether the rate is the selling price (VAT incl.) or the cost price.
- **Stocker logins:** on the same page type the names (one per line) and press Create. Each stocker gets their own username and password, locked to that shop, shown once with printable slips. Usernames are never repeated (checked across all customers) and two active stockers of one shop cannot share a name. New role "Stocker (stock take only)" also in Admin > Users and the Console. Stockers see only the scanning screen; the POS refuses them.
- **Scanning (phone):** the stocker signs in to the Back office and scans with the camera, a barcode gun or by typing (`12*code` adds 12; "Qty per scan" for boxes). The **first scan** of a product in the count asks its rate (pre-filled with the current price) and, for an unknown barcode, its name. **Every next scan** of the same product, by any stocker, adds to the same line. The SKU and every barcode of a product count on one line. Undo takes a scan back.
- **No double counting:** every scan carries its own id. A scan re-sent after a weak connection is ignored by the server. Scans made with no connection wait on the phone and are sent by themselves when the line returns. Two stockers scanning the same new product at the same moment still make one line.
- **Live view:** products, units, value at rate, difference from system stock, new barcodes, rate changes, per-stocker scans, and "in stock but not scanned yet". Edit or delete a line (logged). CSV and print with signature lines.
- **Close, then Apply:** sets the shop's stock to the counted quantities ("Stock take #N" in the Stock record), creates new products for unknown barcodes, updates price (or cost) to the counted rate, and optionally sets products that were not scanned to 0. Count while the shop is closed: sales made during the count are overwritten by the count.

## 1.10.1 – Black and white stocker screen
- Stocker logins see the Back office in plain black and white: white page, black text, black buttons with white icons and a black header. Dark mode is off for them. The phone remembers it, so the sign-in screen is black and white too next time. Owner, manager and other logins keep the normal colours (signing in as them on that phone switches it back).

## 1.10.2 – Double-count guard in stock take
- Scanning the same product again and again (one pile) keeps adding, no question.
- When a product is **already counted** and a stocker comes to it **later** (no scan of it by them in the last 3 minutes) or **another stocker** scans it, the phone asks: **More stock - add** (another shelf or box) or **Same goods - skip**. The server decides, so it cannot be skipped by a weak connection or two phones at once.
- Owner / manager view: "Scanned by" shows each person's quantity per product; lines counted by 2+ people or confirmed as "more stock" are marked and can be filtered ("Counted by 2+ people / again") to check before applying.

## 1.10.3
- The "Already counted" question on the stocker phone is red (red frame, title and buttons), so it stands out on the black and white screen.

## 1.11.0 – Stock take app for stockers (installable)
- New app **/stock/** ("Flex-C Stock", black and white icon), also a **Stock take** card on the home page. Stockers open it on their phone, tap **Install app** once, and sign in with the login made in Back office > Stock take (team). It shows only the scanning screen.
- Stocker logins that open the Back office or the POS are sent to the Stock take app. Credential slips now point to /stock/.
- Owner / manager can also sign in to the Stock app to scan; managing the count stays in the Back office.
- The stock take screens now live in `assets/stocktake.js`, shared by the Back office and the Stock app.

## 1.11.1 – Shops added by Adnys admin only; Install buttons on the home page
- **New shop / branch:** only an Adnys **admin** in the Console can add one (Console > Customers > customer > + Shop). Support staff see "Only an Adnys admin adds shops" and the server refuses them. Customers cannot add shops; Back office > Settings tells them to contact Adnys.
- **Home page:** every card (POS, Owner dashboard, Back office, Stock take) has an **Install app** button. It opens that app and shows its own install button; on iPhone/iPad it shows the "Share > Add to Home Screen" steps; if the browser offers no button, it explains where to find Install in the browser menu.

## 1.12.0 – Easier menu, scan-first item master, stock report, old .xls import
- **Back office menu** in working order: **Suppliers** (searchable list, total owed) → **Purchase** (Purchases, Purchase returns; supplier boxes have a "find supplier" search) → **Items** (Item master, Barcode labels, Barcode history) → **Stock** (levels & transfers, Stock take, Scan stock, Import) → **Sales** (Sales report, Sales returns, Customers) → **Accounts** (Cash-ups, VAT return, Checks) → **Expenses** → **Reports** (Stock report, Stock record, Alerts) → **Settings** (Users, Company, Preferences & logos, Backup).
- **Item master (scan):** scan with a barcode gun, the camera or by typing. A known barcode counts +1 (beep); `12*code` counts 12. A **new barcode** gives a red alert with two low beeps: pick the item it belongs to (for example an item imported from the old stock list) and the barcode is remembered on it, or add it as a new item. Then **Add to stock** or **Set stock to count** for the whole list in one go. The list stays on the device until then.
- **Stock take app:** the same red "New barcode" alert lets a stocker find the existing product, so the barcode is remembered for everyone.
- **Stock report** (Reports): item code, barcode, description, qty, unit, cost, sale price, value at cost and at sale price, totals; items in stock / all / negative / zero; search; Excel (CSV) and Print/PDF.
- **Company** (Settings): the owner edits company name, Arabic name, TRN, address, phone and bill footer. New shops are added by Adnys.
- **Import stock** now reads old **.xls** files from other shop programs (e.g. "Item Code, Description, Qty, Unit, Cost, S.Price 1"). Codes with spaces are adjusted (KU RTA → KU-RTA, search finds both), negative stock imports as 0, impossible stock rows are skipped, the totals line is ignored; all listed as notes, not errors.

## 1.12.1 – Always the latest screens, POS refresh, profit summary
- **Automatic fingerprints on every visit:** files uploaded or edited by hand (File Manager) are detected on the next visit; the pages and app versions are re-stamped by the server (`lib/autofp.php`), so browsers and installed apps load the new CSS/JS and reload by themselves. Pages are re-checked on every visit (.htaccess), and the POS now loads the network copy first (saved copy after 3 seconds or when offline).
- **POS items:** also refresh when the POS comes back on screen (every 2 minutes at most), besides the Refresh button and the 15-minute refresh.
- **Sample items:** Item master shows "Remove sample items" while the 3 installer samples (Sample item 1-3) exist. Removed completely if never sold or bought, otherwise hidden from the POS.
- **Reports > Profit summary (monthly):** for a year and shop: sales, returns, net sales, cost of goods sold, gross profit and margin, every expense category, total expenses and **net profit** for each month and the year; plus bills, purchases, returns to suppliers and VAT for information. Without VAT. Excel (CSV) and Print/PDF.

## 1.12.2 – Import items only, then count with stockers
- **Stock > Import stock (Excel)** now asks **What to import**: **Items only** (codes, names, unit, cost, selling price; stock is not changed) or **Items and stock**. A file without a Qty column is always items only. New items without a selling price are summed up in one note; stockers enter the price at the first scan.
- Suggested start: 1) Import your item list, Items only. 2) Start a Stock take (team), rate = selling price. 3) Stockers scan: a new barcode / QR shows the red alert, they pick the item from the list (the barcode is remembered), enter the **quantity on shelf** and the **selling price**; next scans add 1. 4) Close and Apply (tick "set not-scanned to 0" for a full count).

## 1.12.3 – New item straight from the purchase bill; full supplier details with TRN check
- **Purchases:** a barcode or code that is not an item opens the **New item** window at once (scan box, camera, or a name typed on a line; also a **+ New item** button). Save it and it goes straight onto the bill with its cost. If it is an item you already have, find it in the same window and the new barcode is remembered on it. The purchase team may add new items; editing existing items stays with owner / manager.
- **Suppliers:** full record: name, contact person, phone, email, emirate, P.O. Box, address, opening balance, notes, edit any time. One supplier per name and per TRN (no duplicates).
- **TRN:** must be a UAE TRN (15 digits starting with 100), checked as you type. **Check on FTA site** copies the TRN and opens EmaraTax (TRN Verification needs a security code, so a person does it); tick "I checked this TRN" and type the legal name the FTA shows. Flex-C stores who checked it and when; the list shows ✓ verified / not verified. Changing the TRN or legal name needs a new check.

## 1.12.4 – Undo that always takes back the right scan
- **Stock take app:** Undo now takes back that exact scan (its own quantity, on its own product line, even when the product was scanned by SKU or another barcode), and only once: the row is struck through and marked "undone", so it cannot be taken back twice. Tap **Undo**, then **Tap again to undo** within 4 seconds (no pop-up, works in the installed app). Undo works offline too: it waits on the phone and is sent once.
- **Item master scan:** new **↶ Undo last scan** button takes back the last scan; press again for the one before.

## 1.12.5 – Fresh start clears everything but company and users
- Console > Customers > customer > **Clear items and stock** now also removes stock takes (they point at items). New tick **Also remove suppliers, expenses and customers**: with both ticks only the company, shops, registers, users (logins) and settings stay.

## 1.12.6 – Item delete for the owner
- **Items > Item master:** every row has **Edit** (owner, manager) and **Delete** (owner only); Delete is also in the edit form. An item never used on a bill is removed with its barcodes and stock records. An item on old sales, purchases or supplier returns cannot be deleted (bills and VAT need it): Flex-C offers to **hide** it instead (inactive, off the POS). Items in a running stock take must wait until that count is applied or cancelled. Every delete / hide is in the audit log.
- Edit now scrolls to the item form (it is below the scan box).

## 1.12.7 – Company name on barcode labels
- **Items > Barcode labels:** new **Name on top**: company name (default), a shop name, or none. Labels print like a shop label: company name, barcode, code, item name, price (AED: 45.00).

## 1.12.8 – Clear "My scans" and barcodes with leading zeros
- **My scans (Stock take app):** each row says what Undo can do: **Undo** (still counted), **undone**, **undo** (the take-back row), **taken back** (older take-backs), or **already 0** when the product's count is already lower than that scan, so there is nothing to undo. Totals refresh from the server after a burst of scans; "Count so far" counts only products above 0.
- **Barcodes with leading zeros:** a label that prints 16587 often scans as 000000016587. POS, Purchases, Item master, Scan stock and Stock take now treat them as the same barcode.
