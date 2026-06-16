<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../models/Package.php';

$base     = rtrim(APP_URL, '/');           // e.g. http://localhost/altas
$frontend = $base . '/frontend';

// ── Load live settings ──
$siteName        = setting('site_name', 'AltasFarm');
$siteTagline     = setting('site_tagline', 'Build Your Network. Grow Your Income.');
$indirectEnabled = setting('indirect_referral_enabled', '1') === '1';
$dfiEnabled      = setting('dfi_enabled', '1') === '1';
$gcashEnabled    = setting('gcash_enabled', '1') === '1';
$mayaEnabled     = setting('maya_enabled', '1') === '1';
$minPayout       = (float) setting('min_payout', '500');
$usdtFee         = (float) setting('service_fee_usdt', '5');
$gcashFee        = (float) setting('service_fee_gcash', '0');
$mayaFee         = (float) setting('service_fee_maya', '0');

// ── Load active packages ──
$packages   = Package::all(true);
$pkgCount   = count($packages);
$featuredPkg = $packages[0] ?? null;

// Seat limit
$seatLimit  = (int) setting('seat_limit', '1000');
$membersNow = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
$seatsLeft  = max(0, $seatLimit - $membersNow);
$isFull     = $seatsLeft <= 0;

// Commission stream count for copywriting
$streamCount = 2 + ($indirectEnabled ? 1 : 0) + ($dfiEnabled ? 1 : 0);

// Build payout methods list
$payoutMethods = ['USDT TRC20'];
if ($gcashEnabled) $payoutMethods[] = 'GCash';
if ($mayaEnabled)  $payoutMethods[] = 'Maya';
$payoutMethodsText = implode(', ', $payoutMethods);

// ── Social / Contact URLs ──
// Set your Telegram channel URL here, or leave empty '' to hide all Telegram links
$telegramUrl = '';  // e.g. 'https://t.me/yourchannel' or ''

// SEO description helper
$streamWords = ['binary pairing', 'direct referral'];
if ($indirectEnabled) $streamWords[] = 'unilevel';
if ($dfiEnabled)      $streamWords[] = 'daily fixed income';
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
  <title><?= e($siteName) ?> — Closed <?= number_format($seatLimit) ?>-Member Philippine Poultry Network</title>
  <meta name="description" content="<?= e($siteName) ?> is a closed <?= number_format($seatLimit) ?>-member Philippine poultry binary network. <?= $streamCount ?> income streams (<?= $streamText ?>). <?= $payoutMethodsText ?> payouts. Seats are finite — join before the network is full.">
  <meta name="keywords" content="<?= e($siteName) ?>, Philippine poultry network, binary MLM Philippines, USDT payout, farm investment Philippines, poultry farming community, bayanihan network<?= $indirectEnabled ? ', unilevel' : '' ?>">
  <meta name="robots" content="index, follow">
  <meta name="author" content="<?= e($siteName) ?>">
  <link rel="canonical" href="<?= $base ?>/">

  <!-- ── Open Graph (ScamAdviser reads this) ── -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= e($siteName) ?> — Closed <?= number_format($seatLimit) ?>-Member Philippine Poultry Network">
  <meta property="og:description" content="A closed community of <?= number_format($seatLimit) ?> farmers and networkers backed by real Philippine poultry operations. <?= $streamCount ?> income streams. <?= $payoutMethodsText ?> payouts.">
  <meta property="og:url" content="<?= $base ?>/">
  <meta property="og:site_name" content="<?= e($siteName) ?>">
  <meta property="og:locale" content="en_PH">
  <meta property="og:image" content="<?= $base ?>/hero-bg.jpg">

  <!-- ── Twitter Card ── -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($siteName) ?> — Closed <?= number_format($seatLimit) ?>-Member Poultry Network">
  <meta name="twitter:description" content="<?= number_format($seatLimit) ?> seats. Real farms. <?= $payoutMethodsText ?> payouts. Binary referral structure. Philippines.">
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
      "description": "A closed <?= number_format($seatLimit) ?>-member Philippine poultry network connecting real farm investment with community-powered binary income, paying out via <?= $payoutMethodsText ?>.",
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
            "text": "<?= e($siteName) ?> is a closed binary referral network backed by real Philippine poultry operations. It is limited to exactly <?= number_format($seatLimit) ?> members. Each member holds one seat, earns through <?= $streamCount ?> commission streams (<?= $streamOxford ?>), and receives payouts via <?= $payoutMethodsText ?>."
          }
        },
        {
          "@type": "Question",
          "name": "How do I join <?= e($siteName) ?>?",
          "acceptedAnswer": {
            "@type": "Answer",
            "text": "You need a registration code from an existing member or from the <?= e($siteName) ?> admin. Once you have a code, register at altasfarm.com, choose your sponsor and binary position (left or right leg), and your seat is confirmed."
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

  <link rel="stylesheet" href="<?= $frontend ?>/style.css">
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
          <div class="faq-a"><?= e($siteName) ?> is a closed binary referral network backed by real Philippine poultry operations. Membership is strictly limited to <?= number_format($seatLimit) ?> seats. Each member holds one seat, participates in <?= $streamCount ?> commission streams (<?= $streamOxford ?>), and receives payouts via <?= $payoutMethodsText ?>. When the <?= number_format($seatLimit) ?>th seat is filled, registration closes permanently.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How do I join <?= e($siteName) ?>?</button>
          <div class="faq-a">You need a valid registration code from an existing member (your sponsor) or from the <?= e($siteName) ?> admin team. Once you have a code, register at altasfarm.com, choose your sponsor, and select your binary position (left or right leg). Your seat is confirmed immediately upon successful registration and payment.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How much does it cost to join?</button>
          <div class="faq-a">There are <?= $pkgCount ?> active package<?= $pkgCount > 1 ? 's' : '' ?>.<?php if ($featuredPkg): ?> Entry starts at <?= fmt_money($featuredPkg['entry_fee']) ?> for the <?= e($featuredPkg['name']) ?> package.<?php endif; ?> There are no recurring fees, no upgrade tiers, and no hidden charges. Every member enters with access to the full earning structure from day one.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How are commissions earned?</button>
          <div class="faq-a">
            There are <?= $streamCount ?> commission streams:
            <ul style="margin-top:.5rem;">
              <li><strong>Binary Pairing Bonus (<?= $featuredPkg ? fmt_money($featuredPkg['pairing_bonus']) : '₱—' ?>):</strong> Earned each time a left-right pair forms anywhere in your binary downline. Capped at <?= $featuredPkg ? $featuredPkg['daily_pair_cap'] : '—' ?> pairs per day.</li>
              <li><strong>Direct Referral Bonus (<?= $featuredPkg ? fmt_money($featuredPkg['direct_ref_bonus']) : '₱—' ?>):</strong> Credited instantly every time someone you personally referred registers with your code.</li>
              <?php if ($indirectEnabled && $featuredPkg):
                $lvls = Package::getIndirectLevels($featuredPkg['id']);
                $lvlParts = [];
                foreach ($lvls as $lvl => $amt) {
                  if ($amt > 0) $lvlParts[] = "Level $lvl: " . fmt_money($amt);
                }
              ?>
                <li><strong>Unilevel Bonus:</strong> Generational bonuses paid 10 levels deep — <?= implode(', ', $lvlParts) ?> per registration in that level.</li>
              <?php endif; ?>
              <?php if ($dfiEnabled && $featuredPkg): ?>
                <li><strong>Daily Fixed Income (<?= fmt_money($featuredPkg['daily_fixed_income']) ?>/day):</strong> A fixed daily payout credited for <?= $featuredPkg['daily_fixed_income_days'] ?> days after registration, on top of network earnings.</li>
              <?php endif; ?>
            </ul>
            All commissions are credited to your e-wallet in real time on the triggering event (registration), not on a batch schedule.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">How are payouts made?</button>
          <div class="faq-a">All earnings are paid via <?= $payoutMethodsText ?>. You submit a withdrawal request through your member dashboard and provide your wallet address.<?php if ($gcashEnabled || $mayaEnabled): ?> Local members can also receive payouts through GCash or Maya for convenience.<?php endif; ?> This flexibility makes the network borderless and accessible to OFW members and international participants.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">Is there a withdrawal minimum?</button>
          <div class="faq-a">The minimum withdrawal amount is <?= fmt_money($minPayout) ?>. Withdrawals are processed within 24–72 business hours after submission. You must have a verified payout method linked to your account before requesting a withdrawal.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">What happens when the <?= number_format($seatLimit) ?> seats are filled?</button>
          <div class="faq-a">Registration closes permanently. There is no waitlist, no second batch, no re-opening, and no exceptions. The <?= number_format($seatLimit) ?>-member ceiling is a structural decision — not a marketing device — and it is enforced at the system level. Once the counter reaches <?= number_format($seatLimit) ?>, the registration page will display a closed status and no new codes will be issued.</div>
        </div>

        <div class="faq-item">
          <button class="faq-q" onclick="toggleFaq(this)">Can I hold more than one seat?</button>
          <div class="faq-a">No. Each member is limited to one seat, one account, and one registration code. Creating duplicate accounts is a violation of the Terms of Service and will result in immediate suspension and forfeiture of any accumulated balance.</div>
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
        <p>To register, you must: (a) be at least 18 years of age; (b) be a resident of the Philippines or a Filipino national abroad; (c) possess a valid USDT TRC20 wallet address for receiving payouts; (d) have a valid registration code issued by an existing member or the admin team; and (e) agree to these Terms in full.</p>

        <h3>3. Membership Limit</h3>
        <p><?= e($siteName) ?> operates a hard membership cap of exactly <?= number_format($seatLimit) ?> (<?= e((new NumberFormatter('en', NumberFormatter::SPELLOUT))->format($seatLimit)) ?>) seats. When the <?= number_format($seatLimit) ?>th registration is confirmed, the platform will permanently close registration. No exceptions, waitlists, or re-openings will be considered. Each member is limited to one (1) account. Registering multiple accounts constitutes fraud and will result in immediate suspension and forfeiture of balances.</p>

        <h3>4. Entry Fee and Package</h3>
        <p>There is one entry package (the Broiler Starter) at a one-time fee of ₱10,000. This fee is non-refundable upon confirmed registration and binary placement. The fee covers your platform seat, access to all earning streams, and participation in the network's poultry-backed operations.</p>

        <h3>5. Commissions and Earning Structure</h3>
        <p>Members earn through <?= $streamCount ?> streams:
          (a) Binary Pairing Bonus of <?= $featuredPkg ? fmt_money($featuredPkg['pairing_bonus']) : '₱—' ?> per confirmed pair, capped at <?= $featuredPkg ? $featuredPkg['daily_pair_cap'] : '—' ?> pairs per calendar day;
          (b) Direct Referral Bonus of <?= $featuredPkg ? fmt_money($featuredPkg['direct_ref_bonus']) : '₱—' ?> per personally sponsored member;
          <?php if ($indirectEnabled): ?>
            (c) Unilevel Bonus as detailed in the Compensation Plan section of the website;
          <?php endif; ?>
          <?php if ($dfiEnabled && $featuredPkg): ?>
            <?= $indirectEnabled ? '(d)' : '(c)' ?> Daily Fixed Income of <?= fmt_money($featuredPkg['daily_fixed_income']) ?> per day for <?= $featuredPkg['daily_fixed_income_days'] ?> days after registration;
          <?php endif; ?>
          Commissions are credited to your platform e-wallet in real time on the triggering event. <?= e($siteName) ?> reserves the right to verify and withhold commissions suspected of being generated through fraud, duplicate accounts, or system manipulation.</p>

        <h3>6. Payouts</h3>
        <p>All payouts are made via <?= $payoutMethodsText ?>. The minimum withdrawal amount is <?= fmt_money($minPayout) ?>. Withdrawals are processed within 24–72 business hours. <?= e($siteName) ?> is not liable for losses caused by incorrect wallet addresses or account details provided by the member. Ensure your payout details are correct before submitting a withdrawal request — blockchain transactions are irreversible.</p>

        <h3>7. Prohibited Conduct</h3>
        <p>Members are prohibited from: creating duplicate accounts; using bots or automated tools to generate referrals; misrepresenting <?= e($siteName) ?>'s earning potential to prospective members; making guarantees of income on behalf of the platform; and any conduct that manipulates the binary tree structure through fake or unauthorized registrations.</p>

        <h3>8. Account Suspension and Termination</h3>
        <p><?= e($siteName) ?> may suspend or terminate any account found in violation of these Terms, at its sole discretion, without prior notice. Suspended accounts forfeit any pending or unclaimed wallet balance. Terminated members are not entitled to a refund of their entry fee.</p>

        <h3>9. Limitation of Liability</h3>
        <p><?= e($siteName) ?> does not guarantee any specific income or return on your entry fee. Earnings depend entirely on network activity, which is subject to the <?= number_format($seatLimit) ?>-seat ceiling and the binary structure. Participation in <?= e($siteName) ?> involves inherent financial risk. <?= e($siteName) ?> is not liable for income tax obligations arising from your earnings — members are responsible for their own tax compliance under Philippine law (NIRC) or the laws of their country of residence.</p>

        <div class="warn-box">
          <p><strong>Income Disclaimer:</strong> Earnings from <?= e($siteName) ?> depend on your own activity, your network's growth, and the overall pace of registration within the <?= number_format($seatLimit) ?>-seat limit. Past performance of other members is not indicative of your potential results. Do not invest funds you cannot afford to lose.</p>
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
          <li><strong>Financial data:</strong> Your USDT TRC20 wallet address, withdrawal requests, and commission history. We do not collect bank account numbers, credit card numbers, or GCash/Maya account details.</li>
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

        <h3>2. Nature of the Network</h3>
        <p><?= e($siteName) ?> is a direct referral network structured as a binary compensation plan. It is backed by a real poultry operation — meaning the entry fee is partially invested in Philippine broiler farming activities. The network is not a bank, not a lending institution, and not a securities issuer. It does not offer guaranteed returns.</p>

        <p>The compensation structure involves referral-based commissions that are dependent on new member registrations. Because the network is hard-capped at <?= number_format($seatLimit) ?> members, the binary tree will stop generating new pairing bonuses once all seats are filled. Members who join later in the network will have fewer pairing opportunities than early members. This is a structural characteristic members must understand before joining.</p>

        <div class="warn-box">
          <p><strong>Important:</strong> <?= e($siteName) ?> is not registered with the Philippine Securities and Exchange Commission (SEC) as an investment company or securities dealer. It operates as a referral-based community network, not as a registered investment vehicle. Participation is voluntary and carries financial risk.</p>
        </div>

        <h3>3. Anti-Money Laundering (AML)</h3>
        <p><?= e($siteName) ?> prohibits the use of the platform for money laundering, terrorism financing, or any transaction linked to criminal activity. We collect member identity information consistent with know-your-member (KYM) practices. Suspicious activity will be reported to the appropriate Philippine authorities in compliance with Republic Act 9160 (Anti-Money Laundering Act) as amended.</p>

        <h3>4. Data Privacy Compliance</h3>
        <p><?= e($siteName) ?> processes personal data in accordance with the Philippine Data Privacy Act of 2012 (Republic Act 10173) and its implementing rules and regulations. Our Privacy Policy details what data we collect, how we use it, and how members can exercise their rights.</p>

        <h3>5. Consumer Protection</h3>
        <p><?= e($siteName) ?> operates in accordance with the Philippine Consumer Act (Republic Act 7394). Members have the right to honest and accurate information about the platform, its earning structure, and its limitations. Any member who believes they have been misled by a sponsor's claims may report the matter to <a href="mailto:support@altasfarm.com" style="color:var(--green-mid);">support@altasfarm.com</a>. We take misrepresentation by sponsors seriously and will investigate reported cases.</p>

        <h3>6. USDT / Cryptocurrency Disclosure</h3>
        <p>All payouts on <?= e($siteName) ?> are made in USDT (Tether) on the TRON network (TRC20). USDT is a stablecoin pegged to the US Dollar. While USDT is designed to maintain a 1:1 peg, cryptocurrency carries inherent risks including de-pegging events, blockchain network congestion, and wallet loss. <?= e($siteName) ?> is not liable for losses arising from cryptocurrency market conditions. Members are responsible for the security of their own USDT wallets.</p>

        <h3>7. Income Disclaimer</h3>
        <p>Earnings from <?= e($siteName) ?> are not guaranteed. The amount a member earns depends on their own referral activity, the activity of their network, and the pace of overall registrations within the <?= number_format($seatLimit) ?>-seat limit. <?= e($siteName) ?> does not represent, warrant, or imply that any specific income level is achievable. Do not invest funds you cannot afford to lose.</p>

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


  <!-- ════════════════════════════════════════════════════════════
     SITE HEADER (fixed wrapper: contact strip + nav)
════════════════════════════════════════════════════════════ -->
  <header class="site-header">

    <!-- CONTACT STRIP (gives crawlers a top-level address) -->
    <div class="contact-strip" role="banner">
      <div class="contact-strip-inner">
        <a href="mailto:support@altasfarm.com" class="contact-item">
          <span>✉</span> support@altasfarm.com
        </a>
        <?php if ($telegramUrl): ?>
          <a href="<?= e($telegramUrl) ?>" target="_blank" rel="noopener" class="contact-item">
            <span>✈</span> <?= e(preg_replace('#^https?://#', '', $telegramUrl)) ?>
          </a>
        <?php endif; ?>
        <span class="contact-item">
          <span>📍</span> Rang-ay, Cabatuan, Isabela, Philippines
        </span>
        <span class="contact-item">
          <span>🕐</span> Mon–Sat · 8 AM–6 PM PST
        </span>
      </div>
    </div>

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
      <div class="hero-eyebrow">🐓 Philippine Poultry Network · Est. 2024</div>
      <h1 class="hero-title">
        Real Farming.<br>
        <span>Shared Income.</span>
      </h1>
      <p class="hero-desc">
        <?= e($siteName) ?> ties a real poultry operation to a binary referral network. You invest in a farm package, bring in your team, and earn commissions as your network grows — all tracked in real time on your dashboard.
      </p>
      <div class="hero-actions">
        <a href="<?= $base ?>/?page=register" class="btn-gold">🌱 Get Started</a>
        <a href="#how" class="btn-outline" style="color:#fff;border-color:rgba(255,255,255,.6);">Learn How It Works</a>
      </div>
      <div class="hero-stats">
        <div>
          <div class="hero-stat-val"><?= $featuredPkg ? fmt_money($featuredPkg['pairing_bonus']) : '₱—' ?></div>
          <div class="hero-stat-label">Per Pair Bonus</div>
        </div>
        <?php if ($indirectEnabled): ?>
          <div>
            <div class="hero-stat-val">10</div>
            <div class="hero-stat-label">Unilevel Levels</div>
          </div>
        <?php endif; ?>
        <div>
          <div class="hero-stat-val">Real</div>
          <div class="hero-stat-label">Farm Products</div>
        </div>
      </div>
    </div>

    <div class="hero-badge fade-up">
      <div style="font-family:var(--serif);font-size:1.5rem;font-weight:700;color:#fff;margin-bottom:.5rem;"><?= $featuredPkg ? e($featuredPkg['name']) : 'Entry' ?></div>
      <div style="font-family:var(--mono);font-size:2rem;font-weight:500;color:var(--gold);"><?= $featuredPkg ? fmt_money($featuredPkg['entry_fee']) : '₱—' ?></div>
      <div style="height:1px;background:rgba(255,255,255,.1);margin:1rem 0;"></div>
      <div style="font-family:var(--serif);font-size:1.5rem;font-weight:700;color:#fff;margin-bottom:.5rem;">Pair Earned</div>
      <div style="font-family:var(--mono);font-size:2rem;font-weight:500;color:var(--gold);"><?= $featuredPkg ? fmt_money($featuredPkg['pairing_bonus']) : '₱—' ?></div>
      <div style="height:1px;background:rgba(255,255,255,.1);margin:1rem 0;"></div>
      <div style="font-family:var(--serif);font-size:1.5rem;font-weight:700;color:#fff;margin-bottom:.5rem;">Daily Cap</div>
      <div style="font-family:var(--mono);font-size:2rem;font-weight:500;color:var(--gold);"><?= $featuredPkg ? $featuredPkg['daily_pair_cap'] . '×' : '—' ?></div>
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
      <div class="trust-item"><span class="trust-icon">₮</span> USDT <strong>TRC20</strong> Payouts</div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════
     MARQUEE
════════════════════════════════════════════════════════════ -->
  <?php
  $marqueeItems = [
    number_format($seatLimit) . ' Members Only',
    'Real Poultry Products',
    'Instant Commissions',
    'USDT TRC20 Payouts',
    'Philippine Farms',
    'Daily Pair Bonuses',
    'Bayanihan Network',
    'Closed Community',
    'Binary Structure',
  ];
  if ($indirectEnabled) {
    $marqueeItems[] = '10-Level Unilevel';
  }
  if ($dfiEnabled) {
    $marqueeItems[] = 'Daily Fixed Income';
  }
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
          <div class="about-chip"><?= number_format($seatLimit) ?><small>Seats Total</small></div>
        </div>
        <div class="fade-up">
          <div class="tag">Our Story</div>
          <h2 class="section-title">Small on Purpose. Solid by Design.</h2>
          <p class="section-lead">
            Most networks grow without a ceiling — and dilute without a floor. <?= e($siteName) ?> chose a different path: cap the membership at <?= number_format($seatLimit) ?>, keep the structure flat with <?= $pkgCount === 1 ? 'one package' : 'clear package tiers' ?>, and let bayanihan do the rest. A community this size knows its people. It moves deliberately. It holds.
          </p>
          <ul class="about-features">
            <li>Hard cap of <?= number_format($seatLimit) ?> members — registration closes permanently when full</li>
            <li>Backed by real, operating Philippine poultry farms in Isabela</li>
            <li>Commissions fire the instant a new member registers</li>
            <li>One package tier — every member enters as an equal</li>
            <li>Payouts in Gcash, Maya, or USDT TRC20 — borderless, no bank required</li>
            <li>Full audit trail: every commission logged and traceable</li>
          </ul>
          <div style="margin-top:2rem;">
            <a href="<?= $base ?>/?page=register" class="btn-primary">Secure Your Place →</a>
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
      <h2 class="section-title">How <?= e($siteName) ?> Works</h2>
      <p class="section-lead">Easy steps from your first registration to your first withdrawal. The structure is binary — your income grows as both sides of your tree fill, within a defined daily cap and a community that stops at <?= number_format($seatLimit) ?>.</p>
      <div class="steps-grid">
        <div class="step-card fade-up">
          <div class="step-num">01</div>
          <div class="step-icon">🎟️</div>
          <div class="step-title">Get Your Code</div>
          <div class="step-desc">Obtain a registration code from your sponsor or through our admin-approved channels. Choose the package that fits your goals.</div>
        </div>
        <div class="step-card fade-up">
          <div class="step-num">02</div>
          <div class="step-icon">📝</div>
          <div class="step-title">Register &amp; Place</div>
          <div class="step-desc">Create your account, choose your sponsor, and select your binary position — left or right leg. Your seat among the <?= number_format($seatLimit) ?> is confirmed on registration.</div>
        </div>
        <div class="step-card fade-up">
          <div class="step-num">03</div>
          <div class="step-icon">👥</div>
          <div class="step-title">Build Your Team</div>
          <div class="step-desc">Share your referral link and bring in your network. Every direct referral earns you <?= $featuredPkg ? fmt_money($featuredPkg['direct_ref_bonus']) : '₱—' ?> — credited the moment they register.</div>
        </div>
        <div class="step-card fade-up">
          <div class="step-num">04</div>
          <div class="step-icon">💸</div>
          <div class="step-title">Earn Pair Bonuses</div>
          <div class="step-desc">When a left-right pair forms anywhere beneath you, <?= $featuredPkg ? fmt_money($featuredPkg['pairing_bonus']) : '₱—' ?> fires to your wallet in real time. Daily pairs are capped at <?= $featuredPkg ? $featuredPkg['daily_pair_cap'] : '—' ?> to keep the system sustainable.</div>
        </div>
        <?php if ($indirectEnabled): ?>
          <div class="step-card fade-up">
            <div class="step-num">05</div>
            <div class="step-icon">🔗</div>
            <div class="step-title">Unilevel Royalties</div>
            <div class="step-desc">Generational bonuses paid 10 levels deep through your sponsor chain. Because the network is capped at <?= number_format($seatLimit) ?>, every level is reachable — no hollow depth.</div>
          </div>
        <?php endif; ?>
        <div class="step-card fade-up">
          <div class="step-num"><?= $indirectEnabled ? '06' : '05' ?></div>
          <div class="step-icon">₮</div>
          <div class="step-title">Withdraw Earnings</div>
          <div class="step-desc">All earnings settle via <?= $payoutMethodsText ?>. Whether you are in the Philippines or abroad, your wallet receives the same way — no remittance fees, no cut, no geography.</div>
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
        <div class="tag">Compensation Plan</div>
        <h2 class="section-title">Three Streams. One Entry.</h2>
        <p class="section-lead">Every member enters at the same level and accesses all <?= $streamCount ?> income streams from day one. The binary, the referral<?= $indirectEnabled ? ', and the unilevel' : '' ?><?= $dfiEnabled ? ($indirectEnabled ? ', plus daily fixed income' : ', plus daily fixed income') : '' ?> — none of them locked behind a higher tier.</p>
      </div>
      <div class="plan-grid">
        <div class="plan-card fade-up">
          <div class="plan-card-icon">🤝</div>
          <div class="plan-card-title">Binary Pairing Bonus</div>
          <div class="plan-card-amount"><?= $featuredPkg ? fmt_money($featuredPkg['pairing_bonus']) : '₱—' ?></div>
          <div class="plan-card-desc">Earned every time a left-right pair forms anywhere in your binary downline. Capped at <?= $featuredPkg ? $featuredPkg['daily_pair_cap'] : '—' ?> pairs per day — a ceiling that keeps payouts consistent and the network stable.</div>
        </div>
        <div class="plan-card featured fade-up">
          <div class="plan-card-icon">👥</div>
          <div class="plan-card-title">Direct Referral Bonus</div>
          <div class="plan-card-amount"><?= $featuredPkg ? fmt_money($featuredPkg['direct_ref_bonus']) : '₱—' ?></div>
          <div class="plan-card-desc">Credited instantly every time someone you referred registers. Because the community is capped at <?= number_format($seatLimit) ?>, referral slots are finite — your network fills in before the door closes.</div>
        </div>
        <?php if ($indirectEnabled && $featuredPkg):
          $lvls = Package::getIndirectLevels($featuredPkg['id']);
          $maxIndirect = !empty($lvls) ? max($lvls) : 0;
        ?>
          <div class="plan-card fade-up">
            <div class="plan-card-icon">🔗</div>
            <div class="plan-card-title">Unilevel Bonus</div>
            <div class="plan-card-amount">Up to <?= fmt_money($maxIndirect) ?></div>
            <div class="plan-card-desc">Generational bonuses 10 levels deep through your sponsor chain. Passive income that compounds as your wider network grows — within the <?= number_format($seatLimit) ?>-member ceiling.</div>
          </div>
        <?php endif; ?>
        <?php if ($dfiEnabled && $featuredPkg): ?>
          <div class="plan-card fade-up">
            <div class="plan-card-icon">📅</div>
            <div class="plan-card-title">Daily Fixed Income</div>
            <div class="plan-card-amount"><?= fmt_money($featuredPkg['daily_fixed_income']) ?><small style="font-size:.6em;display:block;color:var(--muted);">/ day</small></div>
            <div class="plan-card-desc">A fixed daily payout for <?= $featuredPkg['daily_fixed_income_days'] ?> days after registration. A predictable baseline on top of your network earnings.</div>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($indirectEnabled && $featuredPkg):
        $lvls = Package::getIndirectLevels($featuredPkg['id']);
        $parts = [];
        foreach ($lvls as $lvl => $amt) {
          if ($amt > 0) $parts[] = "Level $lvl " . fmt_money($amt);
        }
      ?>
        <div class="plan-note">
          <strong>Unilevel Breakdown:</strong> <?= implode(' · ', $parts) ?> per member registration.
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════════
     PACKAGES
════════════════════════════════════════════════════════════ -->
  <section class="packages" id="packages">
    <div class="container">
      <div class="packages-header">
        <div class="tag"><?= $pkgCount === 1 ? 'The Package' : 'Packages' ?></div>
        <h2 class="section-title"><?= $pkgCount === 1 ? 'One Entry. No Tiers.' : 'Choose Your Entry' ?></h2>
        <p class="section-lead" style="margin:0 auto;">
          <?= $pkgCount === 1
            ? e($siteName) . ' runs a single package. No premium tiers, no VIP upgrades. Everyone enters the same way — and everyone accesses the same earning structure from the same starting point.'
            : e($siteName) . ' offers ' . $pkgCount . ' packages. Pick the entry that matches your goals — every package includes the full earning structure, with higher tiers unlocking larger bonuses and longer income periods.';
          ?>
        </p>
      </div>

      <div class="closed-banner fade-up">
        <div class="closed-banner-icon">🔒</div>
        <div class="closed-banner-text">
          <strong>This is a closed network of <?= number_format($seatLimit) ?>.</strong>
          <span>Once all seats are filled, registration closes permanently. There is no waitlist, no second batch, and no re-opening. The hard cap is what keeps this community undiluted.</span>
        </div>
      </div>

      <?php if ($pkgCount === 1): ?>
        <div class="pkg-single-wrap">
          <?php $pkg = $packages[0]; ?>
          <div class="pkg-single fade-up">
            <div class="pkg-img">
              <img src="<?= $frontend ?>/pkg-starter.jpg" alt="<?= e($pkg['name']) ?>" loading="lazy">
            </div>
            <div class="pkg-body">
              <div class="pkg-badge">🐣 <?= e($pkg['name']) ?></div>
              <div class="pkg-title">The <?= e($siteName) ?> Seat</div>
              <p class="pkg-desc">Your entry into the network. One seat, one package, backed by a real Philippine poultry operation. All earning streams are active from the moment you register.</p>
              <ul class="pkg-features">
                <li>Full binary tree placement — left or right leg of your choice</li>
                <li><?= fmt_money($pkg['pairing_bonus']) ?> per binary pair · capped at <?= $pkg['daily_pair_cap'] ?> pairs per day</li>
                <li><?= fmt_money($pkg['direct_ref_bonus']) ?> direct referral bonus per recruit</li>
                <?php if ($indirectEnabled): ?><li>10-level unilevel generational bonuses</li><?php endif; ?>
                <?php if ($dfiEnabled): ?><li><?= fmt_money($pkg['daily_fixed_income']) ?> daily fixed income for <?= $pkg['daily_fixed_income_days'] ?> days</li><?php endif; ?>
                <li>Lifetime income cap: <?= $pkg['lifetime_cap_multiplier'] ?>× entry fee</li>
                <li>Real-time dashboard — binary tree, wallet, full history</li>
              </ul>
              <div class="payout-methods">
                <span style="font-size:.75rem;color:var(--muted);margin-right:.5rem;">Payouts:</span>
                <?php if ($gcashEnabled): ?><span class="badge-payout" style="background:#0070d820;color:#0070d8;">GCash</span><?php endif; ?>
                <?php if ($mayaEnabled): ?><span class="badge-payout" style="background:#48b0db20;color:#48b0db;">Maya</span><?php endif; ?>
                <span class="badge-payout" style="background:#26a17b20;color:#26a17b;">₮ USDT TRC20</span>
              </div>
              <div class="pkg-price"><?= fmt_money($pkg['entry_fee']) ?> <small>one-time entry fee</small></div>
              <?php if (!$isFull): ?>
                <a href="<?= $base ?>/?page=register" class="btn-primary" style="width:100%;font-size:.95rem;">Claim Your Seat →</a>
              <?php else: ?>
                <span class="btn btn-secondary" style="width:100%;font-size:.95rem;cursor:not-allowed;opacity:.6;">🔒 Registration Closed</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="pkg-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:2rem;margin-top:2rem;">
          <?php foreach ($packages as $idx => $pkg): ?>
            <div class="pkg-single fade-up" style="border:2px solid <?= $idx === 0 ? 'var(--gold)' : 'var(--border-color)' ?>;border-radius:var(--radius);overflow:hidden;">
              <div class="pkg-img">
                <img src="<?= $frontend ?>/pkg-starter.jpg" alt="<?= e($pkg['name']) ?>" loading="lazy">
              </div>
              <div class="pkg-body" style="padding:1.5rem;">
                <div class="pkg-badge">🐣 <?= e($pkg['name']) ?></div>
                <div class="pkg-price" style="font-size:1.75rem;margin:.5rem 0;"><?= fmt_money($pkg['entry_fee']) ?> <small style="font-size:.5em;">one-time</small></div>
                <ul class="pkg-features" style="margin:1rem 0;padding-left:1.2rem;font-size:.85rem;">
                  <li><?= fmt_money($pkg['pairing_bonus']) ?> per pair · cap <?= $pkg['daily_pair_cap'] ?>/day</li>
                  <li><?= fmt_money($pkg['direct_ref_bonus']) ?> direct referral</li>
                  <?php if ($indirectEnabled): ?><li>10-level unilevel bonuses</li><?php endif; ?>
                  <?php if ($dfiEnabled): ?><li><?= fmt_money($pkg['daily_fixed_income']) ?>/day DFI · <?= $pkg['daily_fixed_income_days'] ?> days</li><?php endif; ?>
                  <li>Lifetime cap <?= $pkg['lifetime_cap_multiplier'] ?>× fee</li>
                </ul>
                <div class="payout-methods" style="margin-bottom:1rem;">
                  <?php if ($gcashEnabled): ?><span class="badge-payout" style="background:#0070d820;color:#0070d8;font-size:.7rem;padding:.2rem .5rem;border-radius:4px;">GCash</span><?php endif; ?>
                  <?php if ($mayaEnabled): ?><span class="badge-payout" style="background:#48b0db20;color:#48b0db;font-size:.7rem;padding:.2rem .5rem;border-radius:4px;">Maya</span><?php endif; ?>
                  <span class="badge-payout" style="background:#26a17b20;color:#26a17b;font-size:.7rem;padding:.2rem .5rem;border-radius:4px;">USDT</span>
                </div>
                <?php if (!$isFull): ?>
                  <a href="<?= $base ?>/?page=register" class="btn-primary" style="width:100%;font-size:.9rem;">Claim Your Seat →</a>
                <?php else: ?>
                  <span class="btn btn-secondary" style="width:100%;font-size:.9rem;cursor:not-allowed;opacity:.6;">🔒 Closed</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p style="text-align:center;margin-top:2rem;font-size:.82rem;color:var(--muted);">A registration code from your sponsor is required to join. Contact your sponsor or the admin team before seats close.</p>
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
          <h2 class="section-title">Constraints Are the Point</h2>
          <p class="section-lead">The <?= number_format($seatLimit) ?>-member cap is not a marketing device — it is how the network stays intact. A smaller, deliberate community earns more per seat, knows its members, and moves with the kind of collective care that Filipinos call bayanihan.</p>
          <div class="why-items">
            <div class="why-item">
              <div class="why-icon">⚡</div>
              <div>
                <div class="why-item-title">Real-Time Commission Firing</div>
                <div class="why-item-desc">Every bonus fires the instant a new member registers. No batch processing, no overnight queues — commissions are computed and credited on registration itself.</div>
              </div>
            </div>
            <div class="why-item">
              <div class="why-icon">🌳</div>
              <div>
                <div class="why-item-title">Live Binary Tree Visualization</div>
                <div class="why-item-desc">Your dashboard shows your binary network in real time. You see exactly where each member sits and how your legs are growing toward the cap.</div>
              </div>
            </div>
            <div class="why-item">
              <div class="why-icon">₮</div>
              <div>
                <div class="why-item-title">USDT — No Geography, No Bank</div>
                <div class="why-item-desc">Payouts settle via <?= $payoutMethodsText ?>. Whether you are in the Philippines or working abroad, your wallet receives the same way — no remittance cut, no delay.</div>
              </div>
            </div>
            <div class="why-item">
              <div class="why-icon">🔒</div>
              <div>
                <div class="why-item-title">Transparent &amp; Secure Platform</div>
                <div class="why-item-desc">Every commission has a full audit trail. The platform includes CSRF protection, rate-limited login, and secure session management built in from the start.</div>
              </div>
            </div>
            <?php if ($dfiEnabled && $featuredPkg): ?>
              <div class="why-item">
                <div class="why-icon">📅</div>
                <div>
                  <div class="why-item-title">Daily Fixed Income</div>
                  <div class="why-item-desc">Earn a fixed daily amount for up to <?= $featuredPkg['daily_fixed_income_days'] ?> days after joining — a predictable baseline of <?= fmt_money($featuredPkg['daily_fixed_income']) ?>/day on top of your network earnings.</div>
                </div>
              </div>
            <?php endif; ?>
            <?php if ($featuredPkg): ?>
              <div class="why-item">
                <div class="why-icon">🛡️</div>
                <div>
                  <div class="why-item-title">Lifetime Cap Protection</div>
                  <div class="why-item-desc">Your total lifetime earnings are capped at <?= $featuredPkg['lifetime_cap_multiplier'] ?>× your entry fee. This protects the network from overextension and guarantees sustainability for all members.</div>
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
        <div class="tag">From the Network</div>
        <h2 class="section-title">What Early Members Say</h2>
      </div>
      <div class="testi-grid">
        <div class="testi-card fade-up">
          <div class="testi-stars">★★★★★</div>
          <div class="testi-quote">"Gusto ko na may hangganan ang community. Hindi ako malalagyan ng libo-libong strangers. Kilala ko ang mga tao sa network ko — at mas komportable akong mag-refer ng kilala."</div>
          <div class="testi-author">
            <div class="testi-avatar" style="background:#2d6a35;">R</div>
            <div>
              <div class="testi-name">Roger A.</div>
              <div class="testi-role">Member since Jan 2024 · Isabela</div>
            </div>
          </div>
        </div>
        <div class="testi-card fade-up">
          <div class="testi-stars">★★★★★</div>
          <div class="testi-quote">"Ang USDT payout ang dahilan kung bakit nag-join ako. OFW ang aking asawa — mas madali para sa amin na mag-transact ng hindi dumaan sa remittance. Direkta na."</div>
          <div class="testi-author">
            <div class="testi-avatar" style="background:#d4a017;color:#1a3a1e;">M</div>
            <div>
              <div class="testi-name">Maria Santos</div>
              <div class="testi-role">Member since Mar 2024 · Batangas</div>
            </div>
          </div>
        </div>
        <div class="testi-card fade-up">
          <div class="testi-stars">★★★★★</div>
          <div class="testi-quote">"Farmer ako at isang package lang ang nagpasimple ng lahat — hindi na ako nag-alinlangan kung kukuha ng mas mataas na tier. Pantay-pantay tayo dito. Bayanihan talaga."</div>
          <div class="testi-author">
            <div class="testi-avatar" style="background:#6b4c2a;">J</div>
            <div>
              <div class="testi-name">Jose Dela Cruz</div>
              <div class="testi-role">Member since Feb 2024 · Nueva Ecija</div>
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
        <h2>All <?= number_format($seatLimit) ?> Seats Have Been Filled.</h2>
        <p>The network is now complete. Registration has closed permanently and no new accounts can be created. If you are already a member, sign in to access your dashboard.</p>
        <div class="cta-buttons">
          <a href="<?= $base ?>/?page=login" class="btn-gold" style="font-size:1rem;padding:1rem 2.5rem;">Sign In →</a>
        </div>
      <?php else: ?>
        <div class="tag" style="background:rgba(212,160,23,.2);color:var(--gold-light);">Limited Seats</div>
        <h2><?= e($siteName) ?> — <?= number_format($seatLimit) ?> Seats. Not One More.</h2>
        <p>The network closes the moment the last seat is taken. There is no second wave, no waitlist, and no appeal. If you are reading this, seats are still open — but that changes with every registration that comes in before yours.</p>
        <div class="cta-buttons">
          <a href="<?= $base ?>/?page=register" class="btn-gold" style="font-size:1rem;padding:1rem 2.5rem;">🌱 Claim Your Seat Now</a>
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
          <div class="footer-brand-desc" itemprop="description">A closed <?= number_format($seatLimit) ?>-member Philippine poultry network. <?= $pkgCount === 1 ? 'One package' : $pkgCount . ' packages' ?>, <?= count($payoutMethods) === 1 ? 'one payout currency' : count($payoutMethods) . ' payout methods' ?>, one community built on bayanihan.</div>

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
            <li><a href="<?= $base ?>/?page=register">Claim a Seat</a></li>
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
  <script src="<?= $frontend ?>/script.js"></script>

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