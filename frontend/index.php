<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../models/Package.php';

$base     = rtrim(APP_URL, '/');           // e.g. http://localhost/altas
$frontend = $base . '/frontend';
// Auto-cache-bust: version string = file modification time
$styleV   = @filemtime(__DIR__ . '/style.css')  ?: 20260914;
$scriptV  = @filemtime(__DIR__ . '/script.js')  ?: 20260914;

// ── Load live settings ──
$siteName        = 'Altas Farm';
$siteTagline     = setting('site_tagline', 'Grow the farm. Share in the harvest.');
$gcashEnabled    = setting('gcash_enabled', '1') === '1';
$mayaEnabled     = setting('maya_enabled', '1') === '1';
$minPayout       = (float) setting('min_payout', '500');
$usdtFee         = (float) setting('service_fee_usdt_trc20', '5');
$gcashFee        = (float) setting('service_fee_gcash', '0');
$mayaFee         = (float) setting('service_fee_maya', '0');

// ── Load active packages ──
$packages   = Package::all(true);
$pkgCount   = count($packages);

// Per-package capability map — the package is the unit of truth.
// Everything a member earns hinges on the package an account carries.
$planFacts = [];
$minEntry  = PHP_INT_MAX;
foreach ($packages as $p) {
  $id   = (int)$p['id'];
  $fee  = (float)$p['entry_fee'];
  if ($fee < $minEntry) $minEntry = $fee;
  $planFacts[$id] = [
    'name'        => (string)$p['name'],
    'entry'       => $fee,
    'binary'      => (int)$p['pairing_enabled'] === 1 && (float)$p['pairing_bonus'] > 0,
    'pair_amount' => (float)$p['pairing_bonus'],
    'pair_cap'    => (int)$p['daily_pair_cap'],
    'indirect'    => (int)$p['indirect_referral_enabled'] === 1,
    'direct_ref'  => (float)$p['direct_ref_bonus'],
    'dfi'         => (int)$p['dfi_enabled'] === 1 && (float)$p['daily_fixed_income'] > 0,
    'dfi_amount'  => (float)$p['daily_fixed_income'],
    'dfi_days'    => (int)$p['daily_fixed_income_days'],
    'cap_mult'    => (float)$p['lifetime_cap_multiplier'],
    'react_fee'   => (float)$p['reactivation_fee'],
    'react_days'  => (int)$p['reactivation_window_days'],
    'cap_amount'  => round($fee * (float)$p['lifetime_cap_multiplier'], 2),
    'pair_cap_amount' => round((float)$p['pairing_bonus'] * (int)$p['daily_pair_cap'], 2),
    'levels'      => [],
  ];
  // Non-zero unilevel tiers for this package (primary detail-screen content)
  if ((int)$p['indirect_referral_enabled'] === 1) {
    $planFacts[$id]['levels'] = array_filter(
      Package::getIndirectLevels($id),
      fn($amt) => (float)$amt > 0
    );
    ksort($planFacts[$id]['levels']);
  }
}
if ($minEntry === PHP_INT_MAX) $minEntry = 0;

// Aggregate facts for framing copy
$binaryCount   = 0;
$indirectCount = 0;
$dfiCount = 0;
$maxDirectRef  = 0;
$maxIndirect   = 0;
$indirectBreakdown = '';
$minPairAmt = PHP_INT_MAX;
$maxPairAmt = 0;
$minDfiAmt  = PHP_INT_MAX;
$maxDfiAmt  = 0;
$minDfiDays = PHP_INT_MAX;
$maxDfiDays = 0;
$minCapMult = PHP_INT_MAX;
$maxCapMult = 0;
foreach ($planFacts as $id => $f) {
  if ($f['binary']) {
    $binaryCount++;
    $minPairAmt = min($minPairAmt, $f['pair_amount']);
    $maxPairAmt = max($maxPairAmt, $f['pair_amount']);
  }
  if ($f['indirect']) {
    $indirectCount++;
    $lvls = Package::getIndirectLevels($id);
    $thisMax = !empty($lvls) ? (float)max($lvls) : 0;
    if ($thisMax > $maxIndirect) {
      $maxIndirect = $thisMax;
      $parts = [];
      foreach ($lvls as $lv => $amt) if ((float)$amt > 0) $parts[] = "Level $lv " . fmt_money($amt);
      $indirectBreakdown = implode(' · ', $parts);
    }
  }
  if ($f['dfi']) {
    $dfiCount++;
    $minDfiAmt  = min($minDfiAmt, $f['dfi_amount']);
    $maxDfiAmt  = max($maxDfiAmt, $f['dfi_amount']);
    $minDfiDays = min($minDfiDays, $f['dfi_days']);
    $maxDfiDays = max($maxDfiDays, $f['dfi_days']);
  }
  $maxDirectRef = max($maxDirectRef, $f['direct_ref']);
  $minCapMult   = min($minCapMult, $f['cap_mult']);
  $maxCapMult   = max($maxCapMult, $f['cap_mult']);
}
$anyBinary   = $binaryCount   > 0;
$anyIndirect = $indirectCount > 0;
$anyDfi      = $dfiCount      > 0;
$minPairAmt  = $minPairAmt === PHP_INT_MAX ? 0 : $minPairAmt;
$minDfiAmt   = $minDfiAmt  === PHP_INT_MAX ? 0 : $minDfiAmt;
$minDfiDays  = $minDfiDays === PHP_INT_MAX ? 0 : $minDfiDays;
$minCapMult  = $minCapMult === PHP_INT_MAX ? 0 : $minCapMult;

// Feature bullets for any package (single source of truth for cards & matrix)
function pkg_features(array $f): array
{
  return [
    ['Binary pairing', $f['binary'], $f['binary'] ? fmt_money($f['pair_amount']) . ' per pair · cap ' . number_format($f['pair_cap']) . '/day' : ''],
    ['Unilevel referral', $f['indirect'], $f['indirect'] ? 'generational bonuses through your sponsor chain' : ''],
    ['Loyalty reward', $f['dfi'], $f['dfi'] ? fmt_money($f['dfi_amount']) . '/day for ' . number_format($f['dfi_days']) . ' days' : ''],
    ['Direct referral bonus', $f['direct_ref'] > 0, $f['direct_ref'] > 0 ? fmt_money($f['direct_ref']) . ' per recruit' : ''],
    ['Lifetime income cap', true, fmt_money($f['cap_mult']) . '× entry fee'],
  ];
}

// Registration gate (system-enforced seat limit, not a marketing claim)
$isFull = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn()
  >= (int) setting('seat_limit', '1000');

// Build payout methods list
$payoutMethods = ['USDT TRC20', 'USDT BEP20'];
if ($gcashEnabled) $payoutMethods[] = 'GCash';
if ($mayaEnabled)  $payoutMethods[] = 'Maya';
$payoutMethodsText = implode(', ', $payoutMethods);

// ── Social / Contact URLs ──
// Set your Telegram channel URL here, or leave empty '' to hide all Telegram links
$telegramUrl = '';  // e.g. 'https://t.me/yourchannel' or ''

// SEO description helper
$streamWords = [];
if ($anyBinary) $streamWords[] = 'binary pairing';
$streamWords[] = 'direct referral';
if ($anyIndirect) $streamWords[] = 'unilevel referral';
if ($anyDfi)      $streamWords[] = 'loyalty reward';
$streamText = implode(', ', $streamWords);
$streamOxford = count($streamWords) > 2
  ? implode(', ', array_slice($streamWords, 0, -1)) . ', and ' . end($streamWords)
  : implode(' and ', $streamWords);
?>
<!DOCTYPE html>
<html lang="en" itemscope itemtype="https://schema.org/Organization">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">

  <!-- ── Primary SEO ── -->
  <title><?= e($siteName) ?> — Farm-Grown Poultry &amp; Shared Growth</title>
  <meta name="description" content="<?= e($siteName) ?> is a Philippine poultry operation built around real chicken production, farm entries, scheduled production, grow-out options, and a structured member platform.">
  <meta name="keywords" content="<?= e($siteName) ?>, Philippine poultry farm, chicken and egg production Philippines, farm entries, poultry production, farm grow-out, poultry community, referral program, GCash, Maya, USDT">
  <meta name="robots" content="index, follow">
  <meta name="author" content="<?= e($siteName) ?>">
  <link rel="canonical" href="<?= $base ?>/">

  <!-- ── Open Graph ── -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= e($siteName) ?> — Farm-Grown Poultry &amp; Shared Growth">
  <meta property="og:description" content="Real poultry production organized through farm entries, with scheduled production, optional grow-out, and a member platform built around shared farm growth.">
  <meta property="og:url" content="<?= $base ?>/">
  <meta property="og:site_name" content="<?= e($siteName) ?>">
  <meta property="og:locale" content="en_PH">
  <meta property="og:image" content="<?= $base ?>/hero-bg.jpg">

  <!-- ── Twitter Card ── -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($siteName) ?> — Farm-Grown Poultry &amp; Shared Growth">
  <meta name="twitter:description" content="A working poultry model built around real farm entries, production cycles, optional grow-out, and a member community that helps the farm grow.">
  <meta name="twitter:image" content="<?= $base ?>/hero-bg.jpg">

  <!-- ── PWA ── -->
  <meta name="theme-color" content="#1a3a1e">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= e($siteName) ?>">
  <link rel="icon" type="image/png" href="<?= $frontend ?>/favicon.png">
  <link rel="apple-touch-icon" href="<?= $frontend ?>/favicon.png">
  <link rel="manifest" href='data:application/manifest+json;charset=utf-8,{"name":"<?= e($siteName) ?>","short_name":"<?= e($siteName) ?>","start_url":".","display":"standalone","background_color":"#faf7f0","theme_color":"#1a3a1e","icons":[{"src":"<?= $frontend ?>/favicon.png","sizes":"192x192","type":"image/png"},{"src":"<?= $frontend ?>/favicon.png","sizes":"512x512","type":"image/png"}]}'>

  <!-- ── Schema.org Organization (machine-readable trust signal) ── -->
  <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "<?= e($siteName) ?>",
      "url": "<?= $base ?>",
      "logo": "<?= $base ?>/logo.png",
      "description": "A Philippine poultry operation built around real chicken production, farm entries, production cycles, and a structured member participation and referral system.",
      "foundingDate": "2024",
      "foundingLocation": {
        "@type": "Place",
        "addressCountry": "PH"
      },
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Rang-ay",
        "addressLocality": "Cabatuan",
        "addressRegion": "Isabela",
        "postalCode": "3315",
        "addressCountry": "PH"
      },
      "contactPoint": [{
        "@type": "ContactPoint",
        "email": "contact@altasfarm.com",
        "contactType": "customer support",
        "availableLanguage": ["English", "Filipino"],
        "contactOption": "TollFree"
      }],
      "sameAs": <?= json_encode(array_filter(["https://www.facebook.com/altasfarm", $telegramUrl])) ?>,
      "areaServed": {
        "@type": "Country",
        "name": "Philippines"
      },
      "numberOfEmployees": {
        "@type": "QuantitativeValue",
        "value": "10"
      }
    }
  </script>

  <!-- ── Schema.org WebSite (enables search box signals) ── -->
  <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "<?= e($siteName) ?>",
      "url": "<?= $base ?>",
      "potentialAction": {
        "@type": "SearchAction",
        "target": "<?= $base ?>/?s={search_term_string}",
        "query-input": "required name=search_term_string"
      }
    }
  </script>

  <!-- ── Schema.org FAQPage ── -->
  <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "FAQPage",
      "mainEntity": [{
          "@type": "Question",
          "name": "What is <?= e($siteName) ?>?",
          "acceptedAnswer": {
            "@type": "Answer",
            "text": "<?= e($siteName) ?> is a Philippine poultry operation and member network built around farm entries and organized production. Members choose an entry, participate in the corresponding farm cycle, and may have access to additional package features through the member platform."
          }
        },
        {
          "@type": "Question",
          "name": "How do I join <?= e($siteName) ?>?",
          "acceptedAnswer": {
            "@type": "Answer",
            "text": "You need a registration code from an existing member or from the <?= e($siteName) ?> admin. Once you have a code, register at altasfarm.com, pick your entry package and sponsor, and place your account — choosing a binary position when your package includes binary pairing."
          }
        },
        {
          "@type": "Question",
          "name": "How are payouts made?",
          "acceptedAnswer": {
            "@type": "Answer",
            "text": "All earnings are paid via <?= $payoutMethodsText ?>. You submit a withdrawal request through your dashboard and provide your wallet address.<?= $gcashEnabled || $mayaEnabled ? ' GCash and Maya are also available for local members.' : '' ?>"
          }
        }
      ]
    }
  </script>

  <!-- ── Fonts ── -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="<?= $frontend ?>/style.css?v=<?= $styleV ?>">
</head>

<body>

  <!-- ════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════ -->

  <!-- ── FAQ Modal ──────────────────────────────────────────────── -->
  <div class="af-modal-backdrop" id="modal-faq" role="dialog" aria-modal="true" aria-labelledby="faq-title" onclick="closeModalOnBackdrop(event,'modal-faq')">
    <div class="af-modal">
      <div class="af-modal-header">
        <div>
          <h2 id="faq-title">Frequently Asked Questions</h2>
          <p>Last updated: January 2025</p>
        </div>
        <button class="af-modal-close" onclick="closeModal('modal-faq')" aria-label="Close">✕</button>
      </div>
      <div class="af-modal-body">

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">What is <?= e($siteName) ?>?</button>
          <div class="faq-a"><?= e($siteName) ?> is a Philippine poultry operation built around actual chicken production and farm entries. Each entry corresponds to a defined participation level, with the farm managing the underlying production cycle. The member platform also provides package-based community and earning features.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How do I join <?= e($siteName) ?>?</button>
          <div class="faq-a">You need a valid registration code from an existing member or from the <?= e($siteName) ?> admin team. Once you have a code, register at altasfarm.com, select your farm entry and sponsor, and place your account. Where a package includes binary pairing, you may also select the corresponding left or right position. Your account is confirmed after successful registration and payment.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How much does it cost to join?</button>
          <div class="faq-a">There are <?= $pkgCount ?> active farm packages, from <?= fmt_money($minEntry) ?>. Each entry is a defined participation level with its own poultry order specification and applicable platform features. There are no recurring fees attached to the entry itself.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How are commissions earned?</button>
          <div class="faq-a">
            The farm and the member platform work together, but they are not the same thing. Your entry funds an actual poultry order, while any referral or commission features are determined separately by the package you choose:
            <ul style="margin-top:.5rem;">
              <?php if ($anyBinary): ?>
                <li><strong>Binary Pairing Bonus:</strong> Packages with binary pairing pay <?= fmt_money($minPairAmt) ?>–<?= fmt_money($maxPairAmt) ?> each time a left-right pair forms in your binary downline, within each package's own daily cap.</li>
              <?php endif; ?>
              <li><strong>Direct Referral Bonus:</strong> Credited instantly every time someone you personally referred registers — up to <?= fmt_money($maxDirectRef) ?> depending on your package.</li>
              <?php if ($anyIndirect): ?>
                <li><strong>Unilevel Bonus:</strong> Packages with unilevel referral pay generational bonuses through your sponsor chain.</li>
              <?php endif; ?>
              <?php if ($anyDfi): ?>
                <li><strong>Loyalty Reward:</strong> Selected entries include a loyalty reward as the farm's way of giving back to members who choose to support its continued growth.</li>
              <?php endif; ?>
            </ul>
            Farm production follows its own physical schedule; platform commissions, when applicable, are credited according to the triggering event and package rules rather than being tied to the hatch date.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How are payouts made?</button>
          <div class="faq-a">Withdrawable platform earnings are requested through your member dashboard and paid via <?= $payoutMethodsText ?>. Farm participation and any related settlements follow the applicable farm terms and production schedule.<?php if ($gcashEnabled || $mayaEnabled): ?> Local members can use GCash or Maya for convenience.<?php endif; ?></div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">Is there a withdrawal minimum?</button>
          <div class="faq-a">The minimum withdrawal amount is <?= fmt_money($minPayout) ?>. Withdrawals are processed within 24–72 business hours after submission. You must have a verified payout method linked to your account before requesting a withdrawal.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">Can I hold more than one account?</button>
          <div class="faq-a">Yes. The network allows a member to register multiple accounts, each with its own entry package and binary position (where the package includes binary pairing). Every account carries its own entry fee and earns through the streams of its own package from day one.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">Is my personal data safe?</button>
          <div class="faq-a"><?= e($siteName) ?> collects only the information necessary for account creation and commission processing. Your data is never sold to third parties. The platform uses CSRF protection, rate-limited login, and encrypted session management. Full details are in our Privacy Policy.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How do I contact support?</button>
          <div class="faq-a">Reach us at <a href="mailto:support@altasfarm.com" style="color:var(--green-mid);font-weight:600;">support@altasfarm.com</a> or <?php if ($telegramUrl): ?>through our Telegram channel at <a href="<?= e($telegramUrl) ?>" target="_blank" rel="noopener" style="color:var(--green-mid);font-weight:600;"><?= e(preg_replace('#^https?://#', '', $telegramUrl)) ?></a>.<?php endif; ?> Support is available Monday–Saturday, 8 AM–6 PM Philippine Standard Time (PST, UTC+8). We aim to respond within 24 hours on business days.</div>
        </div>

      </div>
    </div>
  </div>

  <!-- ── Terms of Service Modal ─────────────────────────────────── -->
  <div class="af-modal-backdrop" id="modal-tos" role="dialog" aria-modal="true" aria-labelledby="tos-title" onclick="closeModalOnBackdrop(event,'modal-tos')">
    <div class="af-modal">
      <div class="af-modal-header">
        <div>
          <h2 id="tos-title">Terms of Service</h2>
          <p>Effective date: January 1, 2025 · Version 1.0</p>
        </div>
        <button class="af-modal-close" onclick="closeModal('modal-tos')" aria-label="Close">✕</button>
      </div>
      <div class="af-modal-body">

        <div class="highlight-box">
          <p>By registering an account on <?= e($siteName) ?>, you agree to be bound by these Terms of Service. Please read them carefully before completing your registration.</p>
        </div>

        <h3>1. Parties</h3>
        <p>These Terms of Service ("Terms") govern the relationship between <?= e($siteName) ?> ("the Platform," "we," "us") and any individual who registers as a member ("Member," "you"). <?= e($siteName) ?> is operated by its founding administrators, based in Santiago, Isabela, Philippines.</p>

        <h3>2. Eligibility</h3>
        <p>To register, you must: (a) be at least 18 years of age; (b) be a resident of the Philippines or a Filipino national abroad; (c) possess a valid USDT TRC20 or USDT BEP20 wallet address for receiving payouts; (d) have a valid registration code issued by an existing member or the admin team; and (e) agree to these Terms in full.</p>

        <h3>3. Membership and Multiple Accounts</h3>
        <p>Each entry into <?= e($siteName) ?> is a single account with its own package and binary position (where applicable). Members are permitted to register more than one account — every account carries its own entry fee and earns independently through the streams of its own package. Registration must always be made with accurate personal information; accounts created to manipulate the binary structure, generate fraudulent referrals, or otherwise abuse the earning system are subject to suspension and forfeiture of balances.</p>

        <h3>4. Farm Entry and Package</h3>
        <p>Members select from the farm entries offered at the time of registration. Each entry carries a defined poultry order specification and a one-time amount. The farm uses received bulk payments to schedule and produce the corresponding chicks. The member account also carries the platform features associated with the selected package.</p>

        <h3>5. Farm Cycle and Platform Earnings</h3>
        <p>The physical farm cycle begins when the farm receives the bulk payment for an entry and schedules the corresponding chicks for hatching. The normal hatch lead time is 21 days. A member may receive the chicks according to the entry specification or elect the farm grow-out option, under which the farm raises the birds toward marketable size. The current grow-out arrangement provides a 15% return on an eligible entry when the grow-out cycle is completed, according to the applicable farm terms. Selected entries may also include a Loyalty Reward cycle. This is not presented as a fixed-income product; it is the farm's way of giving back to members who choose to support the farm's growth with their entry. Referral commissions, where applicable, remain separate and are credited according to their package rules.</p>

        <h3>6. Payouts</h3>
        <p>All payouts are made via <?= $payoutMethodsText ?>. The minimum withdrawal amount is <?= fmt_money($minPayout) ?>. Withdrawals are processed within 24–72 business hours. <?= e($siteName) ?> is not liable for losses caused by incorrect wallet addresses or account details provided by the member. Ensure your payout details are correct before submitting a withdrawal request — blockchain transactions are irreversible.</p>

        <h3>7. Prohibited Conduct</h3>
        <p>Members are prohibited from: using bots or automated tools to generate referrals; misrepresenting <?= e($siteName) ?>'s earning potential to prospective members; making guarantees of income on behalf of the platform; and any conduct that manipulates the binary tree structure through fake or unauthorized registrations.</p>

        <h3>8. Account Suspension and Termination</h3>
        <p><?= e($siteName) ?> may suspend or terminate any account found in violation of these Terms, at its sole discretion, without prior notice. Suspended accounts forfeit any pending or unclaimed wallet balance. Terminated members are not entitled to a refund of their entry fee.</p>

        <h3>9. Limitation of Liability</h3>
        <p>Farm production and platform earnings are distinct. A grow-out settlement is governed by the specific farm arrangement attached to the eligible entry and depends on completion of the underlying production cycle. Platform commissions depend on their respective package rules and triggering activity and are not a guarantee of future income. Participation carries financial and operational risk. Members remain responsible for their own tax obligations under applicable law.</p>

        <div class="warn-box">
          <p><strong>Important:</strong> <?= e($siteName) ?> combines a real poultry production cycle with a separate member platform. Farm grow-out settlements are tied to the completion of the applicable production cycle and its stated terms. Referral and commission earnings depend on package rules and member or network activity and should not be presented as guaranteed income.</p>
        </div>

        <h3>10. Changes to Terms</h3>
        <p><?= e($siteName) ?> may update these Terms at any time. Continued use of the platform after an update constitutes acceptance of the revised Terms. Major changes will be communicated via your registered email address or the Telegram channel.</p>

        <h3>11. Governing Law</h3>
        <p>These Terms are governed by the laws of the Republic of the Philippines. Any disputes shall be resolved through good-faith negotiation, and if unresolved, submitted to the appropriate courts of Isabela, Philippines.</p>

        <h3>12. Contact</h3>
        <p>For questions regarding these Terms, contact: <a href="mailto:support@altasfarm.com" style="color:var(--green-mid);">support@altasfarm.com</a></p>

        <p class="meta-line"><?= e($siteName) ?> · Santiago, Isabela, Philippines · Version 1.0, effective January 1, 2025</p>
      </div>
    </div>
  </div>

  <!-- ── Privacy Policy Modal ───────────────────────────────────── -->
  <div class="af-modal-backdrop" id="modal-privacy" role="dialog" aria-modal="true" aria-labelledby="privacy-title" onclick="closeModalOnBackdrop(event,'modal-privacy')">
    <div class="af-modal">
      <div class="af-modal-header">
        <div>
          <h2 id="privacy-title">Privacy Policy</h2>
          <p>Effective date: January 1, 2025 · Version 1.0</p>
        </div>
        <button class="af-modal-close" onclick="closeModal('modal-privacy')" aria-label="Close">✕</button>
      </div>
      <div class="af-modal-body">

        <div class="highlight-box">
          <p><?= e($siteName) ?> collects only what is necessary to operate your account. We do not sell, rent, or share your personal information with third parties for marketing purposes.</p>
        </div>

        <h3>1. Data Controller</h3>
        <p><?= e($siteName) ?> (the "Platform") is the data controller for personal information collected through this website and the member dashboard. Our contact for data concerns is: <a href="mailto:support@altasfarm.com" style="color:var(--green-mid);">support@altasfarm.com</a></p>

        <h3>2. What We Collect</h3>
        <ul>
          <li><strong>Registration data:</strong> Full name, email address, mobile number, province/region, and the referral code used to register.</li>
          <li><strong>Financial data:</strong> Your USDT TRC20 or USDT BEP20 wallet address, withdrawal requests, and commission history. We do not collect bank account numbers, credit card numbers, or GCash/Maya account details.</li>
          <li><strong>Technical data:</strong> IP address (for security and fraud detection), browser type, device type, and session tokens. These are discarded after 30 days.</li>
          <li><strong>Communications:</strong> Support messages and emails you send to us.</li>
        </ul>

        <h3>3. How We Use Your Data</h3>
        <ul>
          <li>To create and manage your member account.</li>
          <li>To process and verify commissions and withdrawal requests.</li>
          <li>To detect and prevent fraud, duplicate accounts, and unauthorized access.</li>
          <li>To send transactional notifications (commission credits, withdrawal confirmations).</li>
          <li>To comply with applicable Philippine laws.</li>
        </ul>

        <h3>4. Legal Basis for Processing</h3>
        <p>We process your data on the basis of: (a) contractual necessity — to perform our obligations under the Terms of Service; and (b) legitimate interest — to maintain the integrity and security of the network.</p>

        <h3>5. Data Sharing</h3>
        <p>We do not sell, rent, or trade your personal data to any third party. Limited data may be shared with: (a) blockchain networks for USDT transaction processing (your wallet address only, which is inherently public on the TRON network); (b) service providers who assist with platform security and hosting, under strict confidentiality agreements; and (c) law enforcement or regulators, if required by Philippine law.</p>

        <h3>6. Data Retention</h3>
        <p>Account data is retained for the lifetime of the network and for a minimum of five (5) years after network closure, to comply with financial record-keeping obligations. You may request deletion of non-transactional data (e.g., support messages) at any time.</p>

        <h3>7. Security</h3>
        <p>The platform uses CSRF (Cross-Site Request Forgery) protection on all forms, rate-limited login to prevent brute-force attacks, encrypted session tokens, and HTTPS for all data in transit. Passwords are stored as salted hashes — they are never stored in plain text.</p>

        <h3>8. Your Rights</h3>
        <p>Under the Philippine Data Privacy Act of 2012 (Republic Act 10173), you have the right to: access your personal data; correct inaccurate data; object to processing; and request deletion of data not required for legal or contractual compliance. Submit requests to: <a href="mailto:support@altasfarm.com" style="color:var(--green-mid);">support@altasfarm.com</a></p>

        <h3>9. Cookies</h3>
        <p><?= e($siteName) ?> uses session cookies strictly for login and security purposes. We do not use advertising cookies or third-party tracking pixels. You may disable cookies in your browser, but this may affect login functionality.</p>

        <h3>10. Children</h3>
        <p><?= e($siteName) ?> is not intended for individuals under 18 years of age. We do not knowingly collect personal information from minors. If we discover a minor has registered, the account will be suspended immediately.</p>

        <h3>11. Changes to This Policy</h3>
        <p>We may update this Privacy Policy. The effective date at the top will reflect any changes. We will notify registered members of material changes via their registered email address.</p>

        <p class="meta-line"><?= e($siteName) ?> · Cabatuan, Isabela, Philippines · RA 10173 compliant · Version 1.0, effective January 1, 2025</p>
      </div>
    </div>
  </div>

  <!-- ── Compliance Modal ────────────────────────────────────────── -->
  <div class="af-modal-backdrop" id="modal-compliance" role="dialog" aria-modal="true" aria-labelledby="compliance-title" onclick="closeModalOnBackdrop(event,'modal-compliance')">
    <div class="af-modal">
      <div class="af-modal-header">
        <div>
          <h2 id="compliance-title">Compliance & Legal Disclosure</h2>
          <p>Transparency statement — January 2025</p>
        </div>
        <button class="af-modal-close" onclick="closeModal('modal-compliance')" aria-label="Close">✕</button>
      </div>
      <div class="af-modal-body">

        <div class="highlight-box">
          <p><?= e($siteName) ?> is committed to operating transparently. This page discloses our regulatory standing, the nature of the network, and the risks members should understand before joining.</p>
        </div>

        <h3>1. Business Registration</h3>
        <p><?= e($siteName) ?> is currently in the process of registering as a sole proprietorship with the Philippine Department of Trade and Industry (DTI). Business name registration application is pending as of January 2025. Upon approval, our DTI certificate number will be published here. <?= e($siteName) ?> operates from Santiago, Isabela, Philippines (postal code 3006).</p>

        <h3>2. Nature of the Farm and Member Platform</h3>
        <p><?= e($siteName) ?> is a Philippine poultry operation with a member platform organized around farm entries. Received bulk payments are used to schedule actual poultry production, with chicks hatched according to the selected entry specification. The normal hatch lead time is 21 days. Members may take the chicks at hatch or choose to leave them with the farm for grow-out. The current grow-out arrangement provides a 15% return on eligible entries at completion of the applicable cycle. The separate member platform may also provide direct, binary, unilevel, or Loyalty Reward features according to package rules. <?= e($siteName) ?> is not a bank, lending institution, or securities issuer.</p>

        <p>The compensation structure involves referral-based commissions that are dependent on new member registrations. Where binary pairing is part of a package, registrations placed later in a leg create fewer pairing opportunities than early ones. Members who join later in a mature leg will have fewer pairing opportunities than early members. This is a structural characteristic of binary networks that members must understand before joining.</p>

        <div class="warn-box">
          <p><strong>Important:</strong> <?= e($siteName) ?> is a poultry operation with a member platform, not a bank, lending institution, or securities dealer. The farm component involves actual poultry production and defined production cycles. Separate referral and commission features are governed by package rules and carry their own risks.</p>
        </div>

        <h3>3. Anti-Money Laundering (AML)</h3>
        <p><?= e($siteName) ?> prohibits the use of the platform for money laundering, terrorism financing, or any transaction linked to criminal activity. We collect member identity information consistent with know-your-member (KYM) practices. Suspicious activity will be reported to the appropriate Philippine authorities in compliance with Republic Act 9160 (Anti-Money Laundering Act) as amended.</p>

        <h3>4. Data Privacy Compliance</h3>
        <p><?= e($siteName) ?> processes personal data in accordance with the Philippine Data Privacy Act of 2012 (Republic Act 10173) and its implementing rules and regulations. Our Privacy Policy details what data we collect, how we use it, and how members can exercise their rights.</p>

        <h3>5. Consumer Protection</h3>
        <p><?= e($siteName) ?> operates in accordance with the Philippine Consumer Act (Republic Act 7394). Members have the right to honest and accurate information about the platform, its earning structure, and its limitations. Any member who believes they have been misled by a sponsor's claims may report the matter to <a href="mailto:support@altasfarm.com" style="color:var(--green-mid);">support@altasfarm.com</a>. We take misrepresentation by sponsors seriously and will investigate reported cases.</p>

        <h3>6. USDT / Cryptocurrency Disclosure</h3>
        <p>All payouts on <?= e($siteName) ?> are made in USDT (Tether) on the TRON network (TRC20) or the BNB Smart Chain (BEP20), at the member's choice. USDT is a stablecoin pegged to the US Dollar. While USDT is designed to maintain a 1:1 peg, cryptocurrency carries inherent risks including de-pegging events, blockchain network congestion, and wallet loss. <?= e($siteName) ?> is not liable for losses arising from cryptocurrency market conditions. Members are responsible for the security of their own USDT wallets.</p>

        <h3>7. Earnings Disclosure</h3>
        <p>The 15% grow-out return described on this site refers to the current farm arrangement for eligible grow-out entries and is tied to completion of the underlying production cycle. Separate platform commissions are not guaranteed and depend on package rules, qualifying activity, and network conditions. No member should rely on referral earnings as a guaranteed source of income.</p>

        <h3>8. Reporting and Contact</h3>
        <p>For compliance concerns, legal inquiries, or to report a policy violation: <a href="mailto:support@altasfarm.com" style="color:var(--green-mid);">support@altasfarm.com</a><br>
          Mailing address: <?= e($siteName) ?>, Rang-ay, Cabatuan, Isabela 3315, Philippines</p>

        <p class="meta-line">This disclosure is provided in good faith as part of <?= e($siteName) ?>'s commitment to operating transparently. Last reviewed: January 2025.</p>
      </div>
    </div>
  </div>

  <!-- ── Contact Modal ──────────────────────────────────────────── -->
  <div class="af-modal-backdrop" id="modal-contact" role="dialog" aria-modal="true" aria-labelledby="contact-title" onclick="closeModalOnBackdrop(event,'modal-contact')">
    <div class="af-modal">
      <div class="af-modal-header">
        <div>
          <h2 id="contact-title">Contact <?= e($siteName) ?></h2>
          <p>We respond within 24 hours on business days (Mon–Sat)</p>
        </div>
        <button class="af-modal-close" onclick="closeModal('modal-contact')" aria-label="Close">✕</button>
      </div>
      <div class="af-modal-body">

        <h3>Email Support</h3>
        <p>For account questions, withdrawal issues, compliance concerns, or general inquiries:</p>
        <p><a href="mailto:support@altasfarm.com" style="font-size:1.1rem;font-weight:700;color:var(--green-mid);">support@altasfarm.com</a></p>

        <?php if ($telegramUrl): ?>
          <h3>Telegram Community</h3>
          <p>For real-time updates, network announcements, and peer support from the <?= e($siteName) ?> community:</p>
          <p><a href="<?= e($telegramUrl) ?>" target="_blank" rel="noopener" style="font-size:1.1rem;font-weight:700;color:var(--green-mid);"><?= e(preg_replace('#^https?://#', '', $telegramUrl)) ?></a></p>
        <?php endif; ?>

        <h3>Facebook Page</h3>
        <p>Follow us for farm updates, community stories, and network announcements:</p>
        <p><a href="https://www.facebook.com/altasfarm" target="_blank" rel="noopener" style="font-size:1.1rem;font-weight:700;color:var(--green-mid);">facebook.com/altasfarm</a></p>

        <h3>Office Address</h3>
        <p><?= e($siteName) ?><br>
          Rang-ay, Cabatuan<br>
          Isabela 3315<br>
          Philippines</p>
        <p style="font-size:.82rem;color:var(--muted);">Walk-in visits are by appointment only. Contact us via email or Telegram to schedule.</p>

        <h3>Support Hours</h3>
        <p>Monday to Saturday · 8:00 AM – 6:00 PM<br>
          Philippine Standard Time (PST · UTC+8)</p>

        <div class="highlight-box">
          <p>For urgent account issues (locked account, incorrect withdrawal address), include your registered email and member ID in your message for faster resolution.</p>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Package Details Modal ─────────────────────────────────── -->
  <div class="af-modal-backdrop" id="modal-pkg-details" role="dialog" aria-modal="true" aria-labelledby="pkg-details-title" onclick="closeModalOnBackdrop(event,'modal-pkg-details')">
    <div class="af-modal">
      <div class="af-modal-header">
        <div>
          <h2 id="pkg-details-title">Package Details</h2>
          <p id="pkg-details-sub">Earning streams &amp; settings</p>
        </div>
        <button class="af-modal-close" onclick="closeModal('modal-pkg-details')" aria-label="Close">✕</button>
      </div>
      <div class="af-modal-body" id="pkgDetailsBody"><!-- filled by openPkgDetails() --></div>
    </div>
  </div>


  <!-- ════════════════════════════════════════════════════════════
     SITE HEADER (fixed wrapper: contact strip + nav)
════════════════════════════════════════════════════════════ -->
  <header class="site-header">

    <!-- ════════════════════════════════════════════════════════
       NAV
    ════════════════════════════════════════════════════════ -->
    <nav>
      <div class="nav-inner">
        <a href="#" class="nav-logo">
          <img src="<?= $frontend ?>/logo.png" alt="<?= e($siteName) ?> logo" width="auto" height="36" onerror="this.style.display='none'">
          <span class="nav-logo-text"><?= e($siteName) ?></span>
        </a>
        <ul class="nav-links">
          <li><a href="#about">About</a></li>
          <li><a href="#how">How It Works</a></li>
          <li><a href="#plan">Earn Plan</a></li>
          <li><a href="#packages">Packages</a></li>
          <li><a href="#why">Why Us</a></li>
        </ul>
        <div class="nav-cta">
          <a href="<?= $base ?>/?page=login" class="nav-btn-login">Login</a>
          <a href="<?= $base ?>/?page=register" class="nav-btn-register">Join Now</a>
        </div>
        <button class="nav-mobile-toggle" aria-label="Toggle Menu" onclick="toggleMobileMenu()">☰</button>
      </div>
    </nav>

  </header><!-- /site-header -->

  <!-- Mobile Menu -->
  <div class="mobile-menu" id="mobileMenu" aria-hidden="true">
    <div class="mobile-menu-header">
      <span class="nav-logo-text" style="color:#fff"><?= e($siteName) ?></span>
      <button class="mobile-close-btn" onclick="toggleMobileMenu()" aria-label="Close Menu">✕</button>
    </div>
    <a href="#about" onclick="toggleMobileMenu()">About</a>
    <a href="#how" onclick="toggleMobileMenu()">How It Works</a>
    <a href="#plan" onclick="toggleMobileMenu()">Earn Plan</a>
    <a href="#packages" onclick="toggleMobileMenu()">Packages</a>
    <a href="#why" onclick="toggleMobileMenu()">Why Us</a>
    <div style="margin-top:2rem;display:flex;flex-direction:column;gap:1rem;">
      <a href="<?= $base ?>/?page=login" style="color:var(--gold);text-align:center;">Sign In</a>
      <a href="<?= $base ?>/?page=register" class="btn-gold" style="text-align:center;">Join Now</a>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════════════ -->
  <section class="hero" id="hero">
    <div class="hero-content fade-up">
      <div class="hero-eyebrow">🐓 Philippine Poultry Farm · Est. 2024</div>
      <h1 class="hero-title">
        Real Poultry.<br>
        <span>Shared Growth.</span>
      </h1>
      <p class="hero-desc">
        <?= e($siteName) ?> is built around a real poultry operation. Members participate through farm entries that correspond to actual production, while the platform keeps farm participation, community activity, and package features organized in one place. The idea is simple: support something tangible, let the farm do what it does best, and grow alongside it.
      </p>
      <div class="hero-actions">
        <a href="<?= $base ?>/?page=register" class="btn-gold">🌱 Get Started</a>
        <a href="#how" class="btn-outline" style="color:#fff;border-color:rgba(255,255,255,.6);">Learn How It Works</a>
      </div>
      <div class="hero-stats">
        <div>
          <div class="hero-stat-val"><?= $pkgCount ?></div>
          <div class="hero-stat-label">Entry Packages</div>
        </div>
        <div>
          <div class="hero-stat-val">Real</div>
          <div class="hero-stat-label">Farm Production</div>
        </div>
        <div>
          <div class="hero-stat-val">Real</div>
          <div class="hero-stat-label">Poultry Production</div>
        </div>
      </div>
    </div>

    <div class="hero-badge fade-up">
      <div style="font-family:var(--serif);font-size:1.4rem;font-weight:700;color:#fff;margin-bottom:.5rem;">Farm Entries From</div>
      <div style="font-family:var(--mono);font-size:2rem;font-weight:500;color:var(--gold);"><?= fmt_money($minEntry) ?></div>
      <div style="height:1px;background:rgba(255,255,255,.1);margin:1rem 0;"></div>
      <div style="font-family:var(--serif);font-size:1.4rem;font-weight:700;color:#fff;margin-bottom:.5rem;"><?= $pkgCount ?> Farm Packages</div>
      <div style="font-family:var(--mono);font-size:.85rem;font-weight:400;color:var(--gold);display:flex;flex-direction:column;gap:.25rem;margin-bottom:1rem;">
        <div>Production · Grow-Out · Community</div>
      </div>
      <div style="height:1px;background:rgba(255,255,255,.1);margin:0 0 1rem;"></div>
      <a href="#packages" class="btn-outline" style="font-size:.8rem;padding:.5rem 1rem;border-color:rgba(255,255,255,.4);color:#fff;">Compare Packages →</a>
    </div>

    <div class="hero-illustration fade-up">
      <svg viewBox="0 0 400 400" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="320" cy="80" r="40" fill="#FFD54F" class="svg-sun" />
        <g class="svg-cloud" transform="translate(20,40)">
          <path d="M30,20 Q50,0 70,20 T110,20 Q130,40 110,60 H30 Q10,40 30,20" fill="rgba(255,255,255,0.2)" />
        </g>
        <path d="M0,320 Q100,280 200,320 T400,320 V400 H0 Z" fill="rgba(26,58,30,0.8)" />
        <path d="M0,350 Q150,320 400,360 V400 H0 Z" fill="rgba(26,58,30,0.9)" />
        <g transform="translate(180,340)">
          <path d="M0,0 Q10,-50 0,-100" stroke="#4caf50" stroke-width="8" stroke-linecap="round" class="svg-plant" />
          <path d="M0,-50 Q-20,-60 -30,-40 Q-10,-40 0,-50" fill="#66bb6a" class="svg-leaf-1" />
          <path d="M0,-80 Q20,-90 30,-70 Q10,-70 0,-80" fill="#66bb6a" class="svg-leaf-2" />
        </g>
        <g transform="translate(240,310)">
          <circle cx="0" cy="0" r="25" fill="#d4a017" />
          <circle cx="0" cy="-5" r="18" fill="#fbc02d" />
          <path d="M10,0 Q20,-5 20,5 Q10,5 10,0" fill="#ef5350" transform="rotate(30)" />
        </g>
      </svg>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     TRUST BAR (immediately after hero — high visibility for crawlers)
════════════════════════════════════════════════════════════ -->
  <div class="trust-bar" role="complementary" aria-label="Trust signals">
    <div class="trust-bar-inner">
      <div class="trust-item"><span class="trust-icon">🔒</span> <strong>HTTPS</strong> Secured</div>
      <div class="trust-item"><span class="trust-icon">📍</span> Based in <strong>Isabela, PH</strong></div>
      <div class="trust-item"><span class="trust-icon">✉</span> <strong>support@altasfarm.com</strong></div>
      <div class="trust-item"><span class="trust-icon">🛡</span> RA 10173 <strong>Data Privacy</strong></div>
      <div class="trust-item"><span class="trust-icon">📅</span> Est. <strong>2024</strong></div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════
     MARQUEE
════════════════════════════════════════════════════════════ -->
  <?php
  $marqueeItems = [
    'Real Poultry Products',
    'Instant Commissions',
    'USDT TRC20 & BEP20 Payouts',
    'Philippine Farms',
    'Binary Pairing',
    'Unilevel Referral',
    'Loyalty Reward',
    'Direct Referral Bonus',
    'Bayanihan Network',
    'Open Community',
    $pkgCount . ' Entry Packages',
  ];
  ?>
  <div class="marquee-wrap" aria-hidden="true">
    <div class="marquee-track">
      <?php foreach ($marqueeItems as $item): ?>
        <div class="marquee-item"><span class="marquee-dot"></span><?= e($item) ?></div>
      <?php endforeach; ?>
      <?php foreach ($marqueeItems as $item): ?>
        <div class="marquee-item"><span class="marquee-dot"></span><?= e($item) ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════
     ABOUT
════════════════════════════════════════════════════════════ -->
  <section class="about" id="about">
    <div class="container">
      <div class="about-grid">
        <div class="about-img-wrap fade-up">
          <div class="about-img">
            <img src="<?= $frontend ?>/about.jpg" alt="Rhode Island Red and Australorp chickens on the <?= e($siteName) ?> partner farm" loading="lazy">
          </div>
          <div class="about-chip">Farm Entries<small>Chicks per Order</small></div>
        </div>
        <div class="fade-up">
          <div class="tag">Our Story</div>
          <h2 class="section-title">A Working Farm at the Center of It All.</h2>
          <p class="section-lead">
            <?= e($siteName) ?> begins with something tangible: poultry. Members place farm entries that are used to support actual chicken production, while the platform organizes participation, referrals, and package-based earnings around that underlying farm activity. The aim is straightforward: grow the farm through participation, keep the process visible, and let each batch move through a defined production cycle.
          </p>
          <ul class="about-features">
            <li>Entries are connected to actual chicken production and planned farm activity</li>
            <li>Each entry follows the farm's scheduled production cycle</li>
            <li>Members may participate directly or make use of the farm's grow-out option where available</li>
            <li>Selected entries may include a Loyalty Reward as the farm gives back to participating members</li>
            <li>Production is organized in recurring batches to support steady farm growth</li>
          </ul>
          <div style="margin-top:2rem;">
            <a href="<?= $base ?>/?page=register" class="btn-primary">Start a Farm Entry →</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     HOW IT WORKS
════════════════════════════════════════════════════════════ -->
  <section class="how" id="how">
    <div class="container">
      <div class="tag tag-green" style="background:rgba(76,175,80,.15);color:rgba(255,255,255,.7);">Simple Process</div>
      <h2 class="section-title">How the Farm Model Works</h2>
      <p class="section-lead">The process starts with a farm entry and continues through a real poultry production cycle, with the member platform keeping your participation, package, and eligible earnings organized along the way.</p>
      <div class="steps-grid">
        <div class="step-card fade-up">
          <div class="step-num">01</div>
          <div class="step-icon">🐣</div>
          <div class="step-title">Choose Your Farm Entry</div>
          <div class="step-desc">Select the entry package that fits your intended level of participation. Your entry defines the farm order and the package features attached to your account.</div>
        </div>
        <div class="step-card fade-up">
          <div class="step-num">02</div>
          <div class="step-icon">📋</div>
          <div class="step-title">Farm Production</div>
          <div class="step-desc">Your entry is incorporated into the farm's production schedule. The farm handles the underlying poultry cycle while your participation is recorded in your account.</div>
        </div>
        <div class="step-card fade-up">
          <div class="step-num">03</div>
          <div class="step-icon">🌾</div>
          <div class="step-title">Choose Your Participation</div>
          <div class="step-desc">Depending on the entry, you may take the poultry associated with your participation or continue with the farm's grow-out option.</div>
        </div>
        <?php if ($anyBinary): ?>
          <div class="step-card fade-up">
            <div class="step-num">04</div>
            <div class="step-icon">👥</div>
            <div class="step-title">Let the Farm Grow It</div>
            <div class="step-desc">Where grow-out is available, the farm continues the production work on your behalf. It is a simple way to remain part of the farm cycle without managing the birds yourself.</div>
          </div>
        <?php endif; ?>
        <?php if ($anyIndirect): ?>
          <div class="step-card fade-up">
            <div class="step-num"><?= $anyBinary ? '05' : '04' ?></div>
            <div class="step-icon">🔗</div>
            <div class="step-title">Grow the Community</div>
            <div class="step-desc">The referral side helps expand the farm. Depending on the package, member activity may also generate direct, binary, unilevel, or Loyalty Reward credits according to the selected package.</div>
          </div>
        <?php endif; ?>
        <div class="step-card fade-up">
          <div class="step-num"><?= ($anyBinary ? 1 : 0) + ($anyIndirect ? 1 : 0) + 4 ?></div>
          <div class="step-icon">₮</div>
          <div class="step-title">Track &amp; Receive</div>
          <div class="step-desc">Farm participation and eligible platform earnings are recorded in your account. Withdrawable balances can be requested through <?= $payoutMethodsText ?>, subject to the applicable package and payout rules.</div>
        </div>
      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     COMPENSATION PLAN
════════════════════════════════════════════════════════════ -->
  <section class="plan" id="plan">
    <div class="container">
      <div class="plan-header">
        <div class="tag">Participation Plan</div>
        <h2 class="section-title">Entries Designed Around the Farm.</h2>
        <p class="section-lead">Each package represents a different level of participation in the farm and carries its own platform features. The farm side is built around actual poultry production, while the member side records referrals, commissions, and account balances.</p>
      </div>
      <div class="plan-grid">
        <div class="plan-card fade-up">
          <div class="plan-card-icon">🤝</div>
          <div class="plan-card-title">Farm Production Cycle</div>
          <div class="plan-card-amount">Defined by entry</div>
          <div class="plan-card-desc">Each farm entry corresponds to a defined production arrangement. The farm manages the underlying poultry cycle and keeps the member participation tied to the selected package.</div>
        </div>
        <div class="plan-card featured fade-up">
          <div class="plan-card-icon">👥</div>
          <div class="plan-card-title">Farm Grow-Out</div>
          <div class="plan-card-amount">Where available</div>
          <div class="plan-card-desc">Where the grow-out option applies, the farm continues raising the poultry through its production cycle. The applicable settlement is governed by the terms of the selected entry.</div>
        </div>
        <?php if ($anyIndirect): ?>
          <div class="plan-card fade-up">
            <div class="plan-card-icon">🔗</div>
            <div class="plan-card-title">Community Referral</div>
            <div class="plan-card-amount">Package-based</div>
            <div class="plan-card-desc">The referral network exists to help grow participation in the farm. Depending on the package, direct, binary, and unilevel bonuses may be available and are calculated by the platform.</div>
          </div>
        <?php endif; ?>
        <?php if ($anyDfi): ?>
          <div class="plan-card fade-up">
            <div class="plan-card-icon">📅</div>
            <div class="plan-card-title">Loyalty Reward</div>
            <div class="plan-card-amount">Package-based</div>
            <div class="plan-card-desc">Selected entries may include a Loyalty Reward. It is simply the farm's way of giving back to members who have chosen to support its growth. The reward follows the terms of the applicable entry and is tracked by the platform.</div>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($anyIndirect && $indirectBreakdown !== ''): ?>
        <div class="plan-note">
          <strong>Referral detail:</strong> <?= $indirectBreakdown ?> per qualifying member registration. These platform commissions are separate from the physical poultry production cycle.
        </div>
      <?php endif; ?>

      <!-- Package × stream matrix -->
      <div class="plan-matrix" style="margin-top:2.5rem;overflow-x:auto;border:1px solid rgba(255,255,255,.12);border-radius:var(--radius);">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem;min-width:760px;background:rgba(255,255,255,.02);">
          <thead>
            <tr style="text-align:left;background:rgba(255,255,255,.04);">
              <th style="padding:.85rem 1rem;border-bottom:2px solid var(--gold);color:#6b4c2a;font-weight:600;">Package</th>
              <th style="padding:.85rem 1rem;border-bottom:2px solid var(--gold);text-align:center;color:#6b4c2a;font-weight:600;">Farm Entry</th>
              <th style="padding:.85rem 1rem;border-bottom:2px solid var(--gold);text-align:center;color:#6b4c2a;font-weight:600;">Binary Pairing</th>
              <th style="padding:.85rem 1rem;border-bottom:2px solid var(--gold);text-align:center;color:#6b4c2a;font-weight:600;">Unilevel</th>
              <th style="padding:.85rem 1rem;border-bottom:2px solid var(--gold);text-align:center;color:#6b4c2a;font-weight:600;">Loyalty Reward</th>
              <th style="padding:.85rem 1rem;border-bottom:2px solid var(--gold);text-align:center;color:#6b4c2a;font-weight:600;">Direct Ref</th>
              <th style="padding:.85rem 1rem;border-bottom:2px solid var(--gold);text-align:right;color:#6b4c2a;font-weight:600;">Lifetime Cap</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($planFacts as $f): ?>
              <tr>
                <td style="padding:.7rem 1rem;border-bottom:1px solid var(--border-color);font-weight:600;"><?= e($f['name']) ?></td>
                <td style="padding:.7rem 1rem;border-bottom:1px solid var(--border-color);text-align:center;font-family:var(--mono);"><?= fmt_money($f['entry']) ?></td>
                <td style="padding:.7rem 1rem;border-bottom:1px solid var(--border-color);text-align:center;font-family:var(--mono);">
                  <?php if ($f['binary']): ?>
                    <?= fmt_money($f['pair_amount']) ?><br><small style="color:var(--muted);">cap <?= number_format($f['pair_cap']) ?>/day</small>
                  <?php else: ?>
                    <span style="color:var(--muted);">—</span>
                  <?php endif; ?>
                </td>
                <td style="padding:.7rem 1rem;border-bottom:1px solid var(--border-color);text-align:center;"><?= $f['indirect'] ? '✓' : '<span style="color:var(--muted);">—</span>' ?></td>
                <td style="padding:.7rem 1rem;border-bottom:1px solid var(--border-color);text-align:center;font-family:var(--mono);">
                  <?php if ($f['dfi']): ?>
                    <?= fmt_money($f['dfi_amount']) ?>/day<br><small style="color:var(--muted);"><?= number_format($f['dfi_days']) ?> days</small>
                  <?php else: ?>
                    <span style="color:var(--muted);">—</span>
                  <?php endif; ?>
                </td>
                <td style="padding:.7rem 1rem;border-bottom:1px solid var(--border-color);text-align:center;font-family:var(--mono);"><?= $f['direct_ref'] > 0 ? fmt_money($f['direct_ref']) : '<span style="color:var(--muted);">—</span>' ?></td>
                <td style="padding:.7rem 1rem;border-bottom:1px solid var(--border-color);text-align:right;font-family:var(--mono);"><?= fmt_money($f['cap_mult']) ?>×</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     PACKAGES
════════════════════════════════════════════════════════════ -->
  <section class="packages" id="packages">
    <div class="container">
      <div class="packages-header">
        <div class="tag">Packages</div>
        <h2 class="section-title">Choose Your Farm Entry</h2>
        <p class="section-lead" style="margin:0 auto;">
          <?= e($siteName) ?> offers <?= $pkgCount ?> farm packages, from <?= fmt_money($minEntry) ?>. Each entry corresponds to a defined participation level and carries its own platform features.
        </p>
      </div>

      <div class="closed-banner fade-up">
        <div class="closed-banner-icon">➕</div>
        <div class="closed-banner-text">
          <strong>One farm. Multiple levels of participation.</strong>
          <span>Each entry represents a defined level of participation in the farm and carries its own package features. Choose the level that suits you and take part in the farm's ongoing production cycle.</span>
        </div>
      </div>

      <div class="pkg-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:2rem;margin-top:2rem;">
        <?php foreach ($planFacts as $id => $f): ?>
          <div class="pkg-single fade-up" style="display:flex;flex-direction:column;border:2px solid var(--border-color);border-radius:var(--radius);overflow:hidden;">
            <div class="pkg-img">
              <img src="<?= $frontend ?>/pkg-starter.jpg" alt="<?= e($f['name']) ?>" loading="lazy">
            </div>
            <div class="pkg-body" style="display:flex;flex-direction:column;flex:1;padding:1.5rem;">
              <div class="pkg-title-row">
                <div class="pkg-badge">🐣 <?= e($f['name']) ?></div>
                <button type="button" class="pkg-details-btn" onclick="openPkgDetails(<?= $id ?>)">Details</button>
              </div>
              <div class="pkg-price" style="font-size:1.75rem;margin:.5rem 0;"><?= fmt_money($f['entry']) ?> <small style="font-size:.5em;">one-time</small></div>
              <ul class="pkg-features" style="margin:1rem 0;padding-left:1.2rem;font-size:.85rem;">
                <li>Farm entry — <?= fmt_money($f['entry']) ?> one-time</li>
                <li>Participation in a defined farm production cycle</li>
                <li>Grow-out option available on eligible entries</li>
                <?php foreach (pkg_features($f) as [$lbl, $isOn, $detail]): ?>
                  <li style="<?= $isOn ? '' : 'opacity:.55;' ?>">
                    <span style="<?= $isOn ? '' : 'text-decoration:line-through;' ?>"><?= $lbl ?></span>
                    <?php if ($isOn): ?> — <?= $detail ?><?php else: ?> — not included<?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
              <div class="payout-methods" style="margin-bottom:1rem;">
                <?php if ($gcashEnabled): ?><span class="badge-payout" style="background:#0070d820;color:#0070d8;font-size:.7rem;padding:.2rem .5rem;border-radius:4px;">GCash</span><?php endif; ?>
                <?php if ($mayaEnabled): ?><span class="badge-payout" style="background:#48b0db20;color:#48b0db;font-size:.7rem;padding:.2rem .5rem;border-radius:4px;">Maya</span><?php endif; ?>
                <span class="badge-payout" style="background:#26a17b20;color:#26a17b;font-size:.7rem;padding:.2rem .5rem;border-radius:4px;">USDT TRC20</span>
                <span class="badge-payout" style="background:#f0b90b20;color:#f0b90b;font-size:.7rem;padding:.2rem .5rem;border-radius:4px;">USDT BEP20</span>
              </div>
              <?php if (!$isFull): ?>
                <a href="<?= $base ?>/?page=register" class="btn-primary" style="width:100%;font-size:.9rem;margin-top:auto;flex-shrink:0;">Register Now →</a>
              <?php else: ?>
                <span class="btn btn-secondary" style="width:100%;font-size:.9rem;cursor:not-allowed;opacity:.6;margin-top:auto;flex-shrink:0;">🔒 Closed</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     WHY ALTASFARM
════════════════════════════════════════════════════════════ -->
  <section class="why" id="why">
    <div class="container">
      <div class="why-grid">
        <div class="why-img fade-up">
          <img src="<?= $frontend ?>/why.jpg" alt="Farmer collecting eggs from free-range chickens at <?= e($siteName) ?> partner operation" loading="lazy">
        </div>
        <div class="fade-up">
          <div class="tag">Why <?= e($siteName) ?></div>
          <h2 class="section-title">Built Around Real Production.</h2>
          <p class="section-lead">The model is intentionally straightforward: actual poultry production first, supported by an organized member platform. Farm entries help support continued production, while the digital side keeps participation and eligible earnings easy to follow.</p>
          <div class="why-items">
            <div class="why-item">
              <div class="why-icon">⚡</div>
              <div>
                <div class="why-item-title">Real Farm Activity</div>
                <div class="why-item-desc">The model starts with actual poultry production. Farm entries support the ongoing work of keeping birds, batches, and farm activity moving forward.</div>
              </div>
            </div>
            <div class="why-item">
              <div class="why-icon">🌳</div>
              <div>
                <div class="why-item-title">A Real Production Cycle</div>
                <div class="why-item-desc">Each entry follows an actual farm schedule. The physical work happens on the farm, while your account keeps the corresponding participation organized.</div>
              </div>
            </div>
            <div class="why-item">
              <div class="why-icon">₮</div>
              <div>
                <div class="why-item-title">Grow-Out by Choice</div>
                <div class="why-item-desc">Where available, the farm can continue the grow-out work for you. That keeps the emphasis where it belongs: on production, not on managing every step yourself.</div>
              </div>
            </div>
            <?php if ($anyDfi): ?>
              <div class="why-item">
                <div class="why-icon">📅</div>
                <div>
                  <div class="why-item-title">Member Platform Features</div>
                  <div class="why-item-desc">Selected entries may include a Loyalty Reward. This is the farm's way of giving back to members who support its growth, and it is tracked separately from referral activity.</div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     TESTIMONIALS
════════════════════════════════════════════════════════════ -->
  <section class="testi" id="testimonials">
    <div class="container">
      <div class="testi-header">
        <div class="tag">The Farm Model</div>
        <h2 class="section-title">What Makes Altas Farm Different</h2>
      </div>
      <div class="testi-grid">
        <div class="testi-card fade-up">
          <div class="testi-stars">★★★★★</div>
          <div class="testi-quote">The starting point is tangible: a real poultry operation producing actual chickens and eggs. The platform is built around helping that production grow through organized farm entries.</div>
          <div class="testi-author">
            <div class="testi-avatar" style="background:#2d6a35;">R</div>
            <div style="margin-top:auto;flex-shrink:0;">
              <div class="testi-name">Farm First</div>
              <div class="testi-role">Actual poultry production</div>
            </div>
          </div>
        </div>
        <div class="testi-card fade-up">
          <div class="testi-stars">★★★★★</div>
          <div class="testi-quote">Every entry begins with something tangible: a real farm production cycle, managed on the ground and recorded through the member platform.</div>
          <div class="testi-author">
            <div class="testi-avatar" style="background:#d4a017;color:#1a3a1e;">M</div>
            <div style="margin-top:auto;flex-shrink:0;">
              <div class="testi-name">Farm Cycle</div>
              <div class="testi-role">Production and scheduling</div>
            </div>
          </div>
        </div>
        <div class="testi-card fade-up">
          <div class="testi-stars">★★★★★</div>
          <div class="testi-quote">Where grow-out is available, members can leave the production work with the farm and remain part of the cycle without taking on the day-to-day work themselves.</div>
          <div class="testi-author">
            <div class="testi-avatar" style="background:#6b4c2a;">J</div>
            <div style="margin-top:auto;flex-shrink:0;">
              <div class="testi-name">Grow-Out Option</div>
              <div class="testi-role">Farm-managed grow-out</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     CTA
════════════════════════════════════════════════════════════ -->
  <section class="cta-section">
    <div class="cta-inner">
      <?php if ($isFull): ?>
        <div class="tag" style="background:rgba(224,52,52,.2);color:#fca5a5;">Registration Closed</div>
        <h2>Registration Is Currently Closed.</h2>
        <p>New accounts cannot be created at this time. If you are already a member, sign in to access your dashboard.</p>
        <div class="cta-buttons">
          <a href="<?= $base ?>/?page=login" class="btn-gold" style="font-size:1rem;padding:1rem 2.5rem;">Sign In →</a>
        </div>
      <?php else: ?>
        <div class="tag" style="background:rgba(212,160,23,.2);color:var(--gold-light);">Open Community</div>
        <h2><?= e($siteName) ?> — Grow the Farm With Us.</h2>
        <p>Every entry contributes to the farm's continued growth. Start with the participation level that suits you, take part in the cycle, and use the options available through your selected entry.</p>
        <div class="cta-buttons">
          <a href="<?= $base ?>/?page=register" class="btn-gold" style="font-size:1rem;padding:1rem 2.5rem;">🌱 Register Now</a>
        </div>
        <a href="<?= $base ?>/?page=login" class="cta-login">Already a member? Sign in →</a>
      <?php endif; ?>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     FOOTER
════════════════════════════════════════════════════════════ -->
  <footer itemscope itemtype="https://schema.org/Organization">
    <div class="footer-inner">
      <div class="footer-top">

        <!-- Brand column -->
        <div>
          <div class="footer-brand-name" itemprop="name"><?= e($siteName) ?></div>
          <div class="footer-brand-desc" itemprop="description">Real poultry production, farm entries, recurring production cycles, and grow-out participation.<br><a href="mailto:support@altasfarm.com" style="color:rgba(255,255,255,.75);">support@altasfarm.com</a><br>Mon–Sat, 8 AM–6 PM PST</div>

          <!-- Address (machine-readable for ScamAdviser / Schema) -->
          <address itemprop="address" itemscope itemtype="https://schema.org/PostalAddress"
            style="font-style:normal;font-size:.82rem;color:rgba(255,255,255,.45);margin-top:1rem;line-height:1.7;">
            <span itemprop="streetAddress">Rang-ay</span>,
            <span itemprop="addressLocality">Cabatuan</span>,
            <span itemprop="addressRegion">Isabela</span>
            <span itemprop="postalCode">3315</span><br>
            <span itemprop="addressCountry">Philippines</span>
          </address>

          <!-- Social -->
          <div class="footer-social" aria-label="Social media links">
            <a href="https://www.facebook.com/altasfarm" target="_blank" rel="noopener" aria-label="Facebook" title="<?= e($siteName) ?> on Facebook">f</a>
            <?php if ($telegramUrl): ?><a href="<?= e($telegramUrl) ?>" target="_blank" rel="noopener" aria-label="Telegram" title="<?= e($siteName) ?> on Telegram">✈</a><?php endif; ?>
            <a href="mailto:support@altasfarm.com" aria-label="Email Support" title="Email support@altasfarm.com">✉</a>
          </div>
        </div>

        <!-- Platform -->
        <div>
          <div class="footer-col-title">Platform</div>
          <ul class="footer-links">
            <li><a href="<?= $base ?>/?page=login">Member Login</a></li>
            <li><a href="<?= $base ?>/?page=register">Register Now</a></li>
            <li><a href="#how">How It Works</a></li>
            <li><a href="#plan">Earn Plan</a></li>
          </ul>
        </div>

        <!-- Company -->
        <div>
          <div class="footer-col-title">Company</div>
          <ul class="footer-links">
            <li><a href="#about">About Us</a></li>
            <li><a href="#why">Why <?= e($siteName) ?></a></li>
            <li><a href="#testimonials">Testimonials</a></li>
            <li><a href="#" onclick="openModal('modal-contact');return false;">Contact Us</a></li>
          </ul>
        </div>

        <!-- Support / Legal -->
        <div>
          <div class="footer-col-title">Support &amp; Legal</div>
          <ul class="footer-links">
            <li><a href="#" onclick="openModal('modal-faq');return false;">FAQ</a></li>
            <li><a href="#" onclick="openModal('modal-tos');return false;">Terms of Service</a></li>
            <li><a href="#" onclick="openModal('modal-privacy');return false;">Privacy Policy</a></li>
            <li><a href="#" onclick="openModal('modal-compliance');return false;">Compliance</a></li>
          </ul>
        </div>

      </div><!-- /footer-top -->

      <!-- Footer bottom bar -->
      <div class="footer-bottom">
        <div class="footer-copy">
          © 2024–<script>
            document.write(new Date().getFullYear())
          </script> <?= e($siteName) ?> · All rights reserved · Philippines 🇵🇭<br>
          <span style="font-size:.75rem;opacity:.6;">Registered business name application pending · DTI — Isabela</span>
        </div>
        <div class="footer-legal">
          <a href="#" onclick="openModal('modal-privacy');return false;">Privacy Policy</a>
          <a href="#" onclick="openModal('modal-tos');return false;">Terms of Service</a>
          <a href="#" onclick="openModal('modal-compliance');return false;">Compliance</a>
        </div>
      </div>

    </div>
  </footer>

  <!-- Back to Top -->
  <button id="backToTop" aria-label="Back to Top">↑</button>

  <!-- ════════════════════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════════════════════ -->
  <script src="<?= $frontend ?>/script.js?v=<?= $scriptV ?>"></script>

  <script>
    /* ── Package-details modal data (built server-side) ── */
    window.PKG_DETAILS = <?= json_encode($planFacts, JSON_PRESERVE_ZERO_FRACTION) ?>;
    window.PKG_GLOBALS = <?= json_encode([
                            'site_name'    => $siteName,
                            'min_payout'   => $minPayout,
                          ]) ?>;
  </script>

  <script>
    /* ── Sync fixed header height → CSS var so hero always clears it ── */
    (function() {
      function syncHeaderH() {
        var hdr = document.querySelector('.site-header');
        if (hdr) {
          document.documentElement.style.setProperty('--header-h', hdr.offsetHeight + 'px');
        }
      }
      syncHeaderH();
      window.addEventListener('resize', syncHeaderH);
      /* Re-check after fonts load (can shift layout) */
      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(syncHeaderH);
      }
    })();
  </script>
</body>

</html>