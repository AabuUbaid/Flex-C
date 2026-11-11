<?php
declare(strict_types=1);

$recipient = 'adnys.dsf@gmail.com';
$whatsapp = '971561166349';

/* ===== EDIT PRICES HERE (AED, excluding 5% VAT). Installation = one-time, monthly = Flex-C service. ===== */
$plans = [
    'shared'    => ['install' => 2500, 'monthly' => 99],
    'dedicated' => ['install' => 4500, 'monthly' => 299],   // placeholder: confirm
];
function aed(int $n): string { return 'AED ' . number_format($n); }

function clean(string $value): string {
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    show_page($plans, $whatsapp, $recipient, isset($_GET['sent']));
    exit;
}

// Honeypot field: silently reject automated spam.
if (!empty($_POST['website'] ?? '')) {
    header('Location: commercial.php?sent=1#contact');
    exit;
}

function show_page(array $plans, string $wa, string $mail, bool $sent): void {
    $sh = $plans['shared']; $de = $plans['dedicated'];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flex-C Plans &amp; Pricing UAE – Installation, Hosting &amp; Monthly Charges | ADNYS</title>
<meta name="description" content="Flex-C pricing for UAE shops: one-time installation, shared or dedicated hosting and simple monthly charges. Unlimited users. Customisable by ADNYS.">
<link rel="canonical" href="https://flex-c.adnys.com/commercial.php">
<link rel="icon" href="assets/icons/console-32.png">
<script>try{var t=localStorage.getItem('fc_theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
<link rel="stylesheet" href="assets/landing.css">
</head>
<body>
<header class="nav"><div class="wrap"><a class="logo" href="./"><i></i>Flex-C</a><span class="sp"></span><a class="l h" href="#plans">Plans</a><a class="l h" href="#hosting">Hosting cost</a><a class="l h" href="about/">Details</a><a class="l h" href="#contact">Enquire</a><button class="tg" id="tg" aria-label="Dark or light mode">◐</button></div></header>

<div class="hero" style="padding-bottom:44px"><div class="wrap">
  <div class="crumb"><a href="./">Home</a> / Plans &amp; pricing</div>
  <h1>Simple, honest pricing for UAE shops</h1>
  <p>Pay once for installation, then one small monthly charge. Unlimited users, no per-seat fees, no hidden extras. Choose shared hosting to start, or a dedicated server as you grow.</p>
  <div class="chips"><span>One-time installation</span><span>Monthly service</span><span>Unlimited users</span><span>5% VAT extra</span></div>
  <div class="cta"><a class="btn p" href="#contact">Get started</a><a class="btn g" href="about/">Features &amp; process →</a></div>
</div></div>

<section id="plans"><div class="wrap">
  <h2>Choose your plan</h2><p class="sub">Installation includes setting up Flex-C on the server, your company, VAT/TRN, shops, staff logins, bill design and a short training.</p>
  <div class="plans">
    <div class="plan"><span class="tag">Start small</span><h3>Shared hosting</h3>
      <div class="pr"><?= aed($sh['install']) ?> <small>one-time installation</small></div>
      <div class="pr" style="margin-top:2px"><?= aed($sh['monthly']) ?> <small>/ month per shop</small></div>
      <ul><li>Install on your shared hosting account</li><li>POS, Back office, Owner dashboard</li><li>Company, VAT/TRN and bill setup</li><li>Item import and staff training</li><li>Signed one-click updates</li><li>Email support</li></ul><a class="btn o" href="#contact">Choose shared</a></div>
    <div class="plan hot"><span class="tag">Growing business</span><h3>Dedicated hosting</h3>
      <div class="pr"><?= aed($de['install']) ?> <small>one-time installation</small></div>
      <div class="pr" style="margin-top:2px"><?= aed($de['monthly']) ?> <small>/ month, up to 5 shops</small></div>
      <ul><li>Private server, set up and secured by ADNYS</li><li>Faster, with daily backups</li><li>Data kept in the UAE</li><li>Custom domain and branding</li><li>Priority WhatsApp support</li><li>Everything in Shared</li></ul><a class="btn s" href="#contact">Choose dedicated</a></div>
    <div class="plan"><span class="tag">Tailor-made</span><h3>Customised by ADNYS</h3>
      <div class="pr">Quote <small>based on scope</small></div>
      <ul><li>Advanced features for your business</li><li>Integrations and e-invoicing provider</li><li>Custom reports and workflows</li><li>Data migration from old software</li></ul><a class="btn o" href="#contact">Request a quote</a></div>
  </div>
  <p class="note">All prices in AED and exclude 5% VAT. Monthly charges cover the Flex-C service, updates and support; the hosting bill (below) is separate unless you ask us to include it.</p>
</div></section>

<section id="hosting" style="padding-top:10px"><div class="wrap">
  <h2>What hosting costs (paid to the host)</h2>
  <p class="sub">Typical market figures so you can budget. These go to the hosting company, not to ADNYS, and change often, so check the provider's page before you buy.</p>
  <div class="scroll"><table class="cmp">
    <tr><th>Item</th><th>Typical market cost</th><th>Notes</th></tr>
    <tr><td><b>Shared hosting (e.g. Hostinger Premium / Business)</b></td><td>about AED 7 – 11 / month on the first-term offer, about AED 40 – 62 / month on renewal</td><td>No setup fee charged by the host. Domain and SSL are normally included for the first year. Longer terms are cheaper.</td></tr>
    <tr><td><b>VPS (e.g. Hostinger KVM 2)</b></td><td>about AED 33 – 53 / month</td><td>No setup fee. Needs server setup, which ADNYS does in the dedicated plan.</td></tr>
    <tr><td><b>Dedicated or UAE-located server</b></td><td>Quoted by the provider, usually higher than a global VPS</td><td>Pick this when you want data stored in the UAE. We help you choose and set it up.</td></tr>
    <tr><td><b>Domain name</b></td><td>about AED 40 – 100 / year</td><td>Often free for the first year with hosting.</td></tr>
  </table></div>
  <p class="note">Hosting figures are converted from USD at about 3.67 AED per USD from public price lists (Hostinger shared: roughly USD 1.99 – 2.99 introductory, USD 10.99 – 16.99 on renewal; VPS KVM 2: roughly USD 9 – 14.5 per month). Indicative only.</p>
</div></section>

<section style="padding-top:10px"><div class="wrap">
  <h2>What you pay, in plain words</h2>
  <div class="grid3">
    <div class="card"><h3>1. Installation (once)</h3><p>We install, configure and train. You pay once, then it is yours to run.</p></div>
    <div class="card"><h3>2. Monthly charge</h3><p>Flex-C service, updates and support at a flat price per shop, with unlimited users.</p></div>
    <div class="card"><h3>3. Hosting bill</h3><p>Paid directly to the host (table above), so you stay in control of your server and data.</p></div>
  </div>
  <p class="note">Compared with per-user suites such as Odoo or Zoho, your cost does not grow when you add cashiers or managers.</p>
</div></section>

<section id="contact" style="padding-top:10px"><div class="wrap">
  <h2>Enquire about Flex-C</h2><p class="sub">Tell us about your business and we will reply with a plan and quote. Or WhatsApp us: <a href="https://wa.me/<?= htmlspecialchars($wa, ENT_QUOTES, 'UTF-8') ?>">+<?= htmlspecialchars($wa, ENT_QUOTES, 'UTF-8') ?></a>.</p>
  <?php if ($sent): ?><p class="note" style="color:var(--ok);font-weight:700">Thank you. Your enquiry has been submitted.</p><?php endif; ?>
  <form method="post" action="commercial.php" class="card" style="display:grid;gap:12px;max-width:620px">
    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
    <input name="name" required maxlength="100" placeholder="Your name" style="padding:11px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit">
    <input name="phone" required maxlength="30" placeholder="Phone / WhatsApp" style="padding:11px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit">
    <input name="email" type="email" maxlength="150" placeholder="Email (optional)" style="padding:11px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit">
    <textarea name="message" required maxlength="2000" rows="4" placeholder="Shop type, number of shops, plan (shared / dedicated), features you need" style="padding:11px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit"></textarea>
    <button class="btn s" type="submit">Send enquiry</button>
  </form>
</div></section>

<footer><div class="wrap"><span>© <?= date('Y') ?> Flex-C · A product of <a href="https://adnys.com" target="_blank" rel="noopener">ADNYS</a></span><span><a href="./">Home</a> · <a href="about/">Details</a> · <a href="https://adnys.com" target="_blank" rel="noopener">Business portal</a></span></footer>
<script>(function(){var h=document.documentElement,b=document.getElementById('tg');b.onclick=function(){var d=(h.dataset.theme?h.dataset.theme==='dark':matchMedia('(prefers-color-scheme:dark)').matches);var n=d?'light':'dark';h.dataset.theme=n;try{localStorage.setItem('fc_theme',n)}catch(e){}}})();</script>
</body>
</html>
<?php
}

$name = clean((string)($_POST['name'] ?? ''));
$phone = clean((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

$errors = [];
if ($name === '' || mb_strlen($name) > 100) $errors[] = 'Please enter your name.';
if ($phone === '' || mb_strlen($phone) > 30) $errors[] = 'Please enter a valid phone number.';
if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) $errors[] = 'Please enter a valid email address.';
if ($message === '' || mb_strlen($message) > 2000) $errors[] = 'Please enter your enquiry.';

if ($errors) {
    http_response_code(400);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Enquiry not sent</title><body style="font-family:Arial;max-width:600px;margin:60px auto;padding:20px"><h2>Please check your details</h2><p>' . htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8') . '</p><p><a href="commercial.php#contact">Go back to the enquiry form</a></p></body></html>';
    exit;
}

$subject = 'New Flex-C Website Enquiry';
$body = "A new enquiry was submitted from the Flex-C website.\n\n"
      . "Name: {$name}\nPhone: {$phone}\nEmail: {$email}\n\nEnquiry:\n{$message}\n";

$headers = [
    'From: Flex-C Website <no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
    'Reply-To: ' . ($email !== '' ? $email : $recipient),
    'Content-Type: text/plain; charset=UTF-8'
];

$sent = mail($recipient, $subject, $body, implode("\r\n", $headers));

if ($sent) {
    $status = 'Thank you. Your enquiry has been submitted.';
} else {
    $status = 'We could not send your enquiry right now. Please contact us by email or WhatsApp.';
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Flex-C Enquiry</title></head>
<body style="font-family:Arial,sans-serif;background:#f5f8fb;color:#172536;margin:0;padding:40px 16px">
  <main style="max-width:560px;margin:40px auto;background:#fff;padding:30px;border-radius:12px">
    <h2 style="color:#123b66">Flex-C Enquiry</h2>
    <p><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if (!$sent): ?>
      <p><a href="mailto:adnys.dsf@gmail.com">Email Adnys</a> · <a href="https://wa.me/<?= htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') ?>">WhatsApp us</a></p>
    <?php endif; ?>
    <p><a href="./">Back to home</a></p>
  </main>
</body>
</html>
