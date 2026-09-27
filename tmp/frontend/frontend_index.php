<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../models/Package.php';

$base     = rtrim(APP_URL, '/');
$frontend = $base . '/frontend';

$styleV  = @filemtime(__DIR__ . '/style.css') ?: 20260927;
$scriptV = @filemtime(__DIR__ . '/script.js') ?: 20260927;

/*
 * Public-homepage configuration.
 *
 * The homepage intentionally presents Altas Farm first as a poultry
 * production and distribution business. The partner/network program
 * remains fully disclosed, but its detailed compensation mechanics live
 * in the partner/package area instead of defining the first impression.
 */
$siteName    = 'Altas Farm';
$siteTagline = setting('site_tagline', 'Grow the farm. Share in the harvest.');

$gcashEnabled = setting('gcash_enabled', '1') === '1';
$mayaEnabled  = setting('maya_enabled', '1') === '1';
$minPayout   = (float) setting('min_payout', '500');

$packages = Package::all(true);
$pkgCount = count($packages);

$planFacts = [];
$minEntry  = PHP_INT_MAX;

foreach ($packages as $p) {
  $id  = (int) $p['id'];
  $fee = (float) $p['entry_fee'];

  if ($fee < $minEntry) {
    $minEntry = $fee;
  }

  $planFacts[$id] = [
    'name'        => (string) $p['name'],
    'image_url'   => !empty($p['image'])
      ? $base . '/uploads/' . $p['image']
      : $frontend . '/pkg-starter.jpg',
    'entry'       => $fee,
    'binary'      => (int) $p['pairing_enabled'] === 1 && (float) $p['pairing_bonus'] > 0,
    'pair_amount' => (float) $p['pairing_bonus'],
    'pair_cap'    => (int) $p['daily_pair_cap'],
    'indirect'    => (int) $p['indirect_referral_enabled'] === 1,
    'direct_ref'  => (float) $p['direct_ref_bonus'],
    'dfi'         => (int) $p['dfi_enabled'] === 1 && (float) $p['daily_fixed_income'] > 0,
    'dfi_amount'  => (float) $p['daily_fixed_income'],
    'dfi_days'    => (int) $p['daily_fixed_income_days'],
    'cap_mult'    => (float) $p['lifetime_cap_multiplier'],
    'react_fee'   => (float) $p['reactivation_fee'],
    'react_days'  => (int) $p['reactivation_window_days'],
    'cap_amount'  => round($fee * (float) $p['lifetime_cap_multiplier'], 2),
    'pair_cap_amount' => round(
      (float) $p['pairing_bonus'] * (int) $p['daily_pair_cap'],
      2
    ),
    'levels' => [],
  ];

  if ((int) $p['indirect_referral_enabled'] === 1) {
    $planFacts[$id]['levels'] = array_filter(
      Package::getIndirectLevels($id),
      static fn($amt) => (float) $amt > 0
    );
    ksort($planFacts[$id]['levels']);
  }
}

if ($minEntry === PHP_INT_MAX) {
  $minEntry = 0;
}

$binaryCount = 0;
$indirectCount = 0;
$dfiCount = 0;
$maxDirectRef = 0;
$minPairAmt = PHP_INT_MAX;
$maxPairAmt = 0;
$minDfiAmt = PHP_INT_MAX;
$maxDfiAmt = 0;
$minDfiDays = PHP_INT_MAX;
$maxDfiDays = 0;
$minCapMult = PHP_INT_MAX;
$maxCapMult = 0;

foreach ($planFacts as $f) {
  if ($f['binary']) {
    $binaryCount++;
    $minPairAmt = min($minPairAmt, $f['pair_amount']);
    $maxPairAmt = max($maxPairAmt, $f['pair_amount']);
  }

  if ($f['indirect']) {
    $indirectCount++;
  }

  if ($f['dfi']) {
    $dfiCount++;
    $minDfiAmt = min($minDfiAmt, $f['dfi_amount']);
    $maxDfiAmt = max($maxDfiAmt, $f['dfi_amount']);
    $minDfiDays = min($minDfiDays, $f['dfi_days']);
    $maxDfiDays = max($maxDfiDays, $f['dfi_days']);
  }

  $maxDirectRef = max($maxDirectRef, $f['direct_ref']);
  $minCapMult = min($minCapMult, $f['cap_mult']);
  $maxCapMult = max($maxCapMult, $f['cap_mult']);
}

$anyBinary   = $binaryCount > 0;
$anyIndirect = $indirectCount > 0;
$anyDfi      = $dfiCount > 0;

$minPairAmt = $minPairAmt === PHP_INT_MAX ? 0 : $minPairAmt;
$minDfiAmt  = $minDfiAmt === PHP_INT_MAX ? 0 : $minDfiAmt;
$minDfiDays = $minDfiDays === PHP_INT_MAX ? 0 : $minDfiDays;
$minCapMult = $minCapMult === PHP_INT_MAX ? 0 : $minCapMult;

function pkg_features(array $f): array
{
  return [
    [
      'Binary pairing',
      $f['binary'],
      $f['binary']
        ? fmt_money($f['pair_amount']) . ' per pair · cap ' . number_format($f['pair_cap']) . '/day'
        : '',
    ],
    [
      'Unilevel referral',
      $f['indirect'],
      $f['indirect'] ? 'generational bonuses through the sponsor chain' : '',
    ],
    [
      'Loyalty reward',
      $f['dfi'],
      $f['dfi']
        ? fmt_money($f['dfi_amount']) . '/day for ' . number_format($f['dfi_days']) . ' days'
        : '',
    ],
    [
      'Direct referral bonus',
      $f['direct_ref'] > 0,
      $f['direct_ref'] > 0 ? fmt_money($f['direct_ref']) . ' per recruit' : '',
    ],
    [
      'Lifetime income cap',
      true,
      fmt_money($f['cap_mult']) . '× entry fee',
    ],
  ];
}

$isFull = (int) db()
  ->query("SELECT COUNT(*) FROM users WHERE role = 'member'")
  ->fetchColumn() >= (int) setting('seat_limit', '1000');

$payoutMethods = ['USDT TRC20', 'USDT BEP20'];
if ($gcashEnabled) {
  $payoutMethods[] = 'GCash';
}
if ($mayaEnabled) {
  $payoutMethods[] = 'Maya';
}
$payoutMethodsText = implode(', ', $payoutMethods);

$telegramUrl = '';

$legalDocs = [
  ['dti.png', 'DTI Business Name Registration', 'Business name registration document'],
  ['business_registration.png', 'Business Registration', 'Business registration document'],
  ['business_permit.png', "Mayor's Business Permit", 'Local business permit'],
  ['sanitary_permit.png', 'Sanitary Permit', 'Sanitary permit document'],
  ['fire_safety.png', 'Fire Safety Certificate', 'Fire safety certification'],
  ['denr_certificate.png', 'DENR Environmental Compliance', 'Environmental compliance document'],
];
?>
<!DOCTYPE html>
<html lang="en" itemscope itemtype="https://schema.org/Organization">

<head>
  <meta charset="UTF-8">
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover"
  >

  <title><?= e($siteName) ?> | Philippine Poultry Production &amp; Distribution</title>
  <meta
    name="description"
    content="<?= e($siteName) ?> is a Philippine poultry business based in Isabela, focused on poultry production, farm-managed grow-out, bulk orders, distribution, and a transparently disclosed partner program."
  >
  <meta
    name="keywords"
    content="<?= e($siteName) ?>, Philippine poultry farm, Isabela poultry, chicks, eggs, live poultry, poultry production, bulk poultry orders, farm partnerships"
  >
  <meta name="robots" content="index, follow">
  <meta name="author" content="<?= e($siteName) ?>">
  <link rel="canonical" href="<?= $base ?>/">

  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= e($siteName) ?> | Philippine Poultry Production &amp; Distribution">
  <meta
    property="og:description"
    content="Poultry production, farm-managed grow-out, bulk orders, distribution, and a transparently disclosed partner program."
  >
  <meta property="og:url" content="<?= $base ?>/">
  <meta property="og:site_name" content="<?= e($siteName) ?>">
  <meta property="og:locale" content="en_PH">
  <meta property="og:image" content="<?= $frontend ?>/hero-bg.jpg">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($siteName) ?> | Philippine Poultry Production &amp; Distribution">
  <meta
    name="twitter:description"
    content="A working Philippine poultry business with production, grow-out, distribution, and a structured partner network."
  >
  <meta name="twitter:image" content="<?= $frontend ?>/hero-bg.jpg">

  <meta name="theme-color" content="#17371d">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= e($siteName) ?>">
  <link rel="icon" type="image/png" href="<?= $frontend ?>/favicon.png">
  <link rel="apple-touch-icon" href="<?= $frontend ?>/favicon.png">

  <link
    rel="manifest"
    href='data:application/manifest+json;charset=utf-8,{"name":"<?= e($siteName) ?>","short_name":"<?= e($siteName) ?>","start_url":".","display":"standalone","background_color":"#faf7f0","theme_color":"#17371d","icons":[{"src":"<?= $frontend ?>/favicon.png","sizes":"192x192","type":"image/png"},{"src":"<?= $frontend ?>/favicon.png","sizes":"512x512","type":"image/png"}]}'
  >

  <script type="application/ld+json">
    <?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'Organization',
      'name' => $siteName,
      'url' => $base,
      'logo' => $base . '/logo.png',
      'description' => 'A Philippine poultry production and distribution business with a structured partner program.',
      'foundingDate' => '2024',
      'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => 'Rang-ay',
        'addressLocality' => 'Cabatuan',
        'addressRegion' => 'Isabela',
        'postalCode' => '3315',
        'addressCountry' => 'PH',
      ],
      'contactPoint' => [[
        '@type' => 'ContactPoint',
        'email' => 'contact@altasfarm.com',
        'contactType' => 'customer support',
        'availableLanguage' => ['English', 'Filipino'],
      ]],
      'sameAs' => array_values(array_filter([
        'https://www.facebook.com/altasfarm',
        $telegramUrl,
      ])),
      'areaServed' => [
        '@type' => 'Country',
        'name' => 'Philippines',
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap"
    rel="stylesheet"
  >
  <link rel="stylesheet" href="<?= $frontend ?>/style.css?v=<?= $styleV ?>">

  <!-- Homepage restructure: scoped additions, existing visual system retained. -->
  <style>
    :root {
      --af-ink: #1c2a20;
      --af-deep: #17371d;
      --af-green: #2f6840;
      --af-soft: #edf4ea;
      --af-cream: #faf7f0;
      --af-gold: #d4a017;
      --af-brown: #6b4c2a;
      --af-border: #dde5dc;
      --af-muted: #69736c;
      --af-white: #ffffff;
      --af-shadow: 0 18px 55px rgba(19, 48, 28, .09);
      --af-radius: 18px;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      background: var(--af-cream);
      color: var(--af-ink);
    }

    .site-header {
      position: fixed;
      inset: 0 0 auto;
      z-index: 7000;
    }

    .site-header nav {
      background: rgba(17, 42, 22, .97);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .af-utility {
      background: var(--af-deep);
      color: rgba(255,255,255,.7);
      font-size: .74rem;
      letter-spacing: .03em;
      border-bottom: 1px solid rgba(255,255,255,.06);
    }

    .af-utility-inner {
      width: min(1180px, calc(100% - 32px));
      margin: 0 auto;
      min-height: 34px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }

    .af-utility-left,
    .af-utility-right {
      display: flex;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .af-utility strong {
      color: rgba(255,255,255,.92);
      font-weight: 600;
    }

    .af-home {
      padding-top: var(--header-h, 100px);
    }

    .af-hero {
      position: relative;
      overflow: hidden;
      background:
        linear-gradient(115deg, rgba(14, 38, 20, .97) 0%, rgba(24, 63, 31, .91) 54%, rgba(24, 63, 31, .48) 100%),
        url('<?= $frontend ?>/hero-bg.jpg') center/cover no-repeat;
      color: #fff;
      min-height: 700px;
      display: flex;
      align-items: center;
    }

    .af-hero-inner {
      width: min(1180px, calc(100% - 32px));
      margin: 0 auto;
      padding: 78px 0 86px;
      display: grid;
      grid-template-columns: minmax(0, 1.08fr) minmax(300px, .92fr);
      gap: 5rem;
      align-items: center;
    }

    .af-hero-copy {
      max-width: 720px;
    }

    .af-kicker {
      display: inline-flex;
      align-items: center;
      gap: .55rem;
      padding: .42rem .72rem;
      border: 1px solid rgba(255,255,255,.18);
      border-radius: 999px;
      background: rgba(255,255,255,.05);
      color: rgba(255,255,255,.82);
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .12em;
      text-transform: uppercase;
    }

    .af-hero h1 {
      margin: 1.2rem 0 1.2rem;
      font-family: var(--serif);
      font-size: clamp(3rem, 6vw, 5.9rem);
      line-height: .98;
      letter-spacing: -.035em;
      color: #fff;
    }

    .af-hero h1 span {
      color: #f2d57a;
    }

    .af-hero-lead {
      max-width: 670px;
      margin: 0;
      color: rgba(255,255,255,.77);
      font-size: 1.08rem;
      line-height: 1.8;
    }

    .af-hero-actions {
      display: flex;
      gap: .9rem;
      flex-wrap: wrap;
      margin-top: 2rem;
    }

    .af-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: .8rem 1.15rem;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 700;
      font-size: .86rem;
      border: 1px solid transparent;
      transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }

    .af-btn:hover {
      transform: translateY(-2px);
    }

    .af-btn-primary {
      background: var(--af-gold);
      color: var(--af-deep);
    }

    .af-btn-secondary {
      color: #fff;
      border-color: rgba(255,255,255,.28);
      background: rgba(255,255,255,.03);
    }

    .af-hero-facts {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1px;
      margin-top: 2.7rem;
      max-width: 650px;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.1);
      border-radius: 14px;
      overflow: hidden;
    }

    .af-hero-fact {
      padding: 1rem;
      background: rgba(11, 36, 18, .28);
    }

    .af-hero-fact-value {
      font-family: var(--serif);
      font-size: 1.35rem;
      color: #fff;
    }

    .af-hero-fact-label {
      margin-top: .2rem;
      font-size: .72rem;
      line-height: 1.4;
      color: rgba(255,255,255,.55);
    }

    .af-hero-card {
      padding: 1rem;
      border-radius: 24px;
      background: rgba(250,247,240,.08);
      border: 1px solid rgba(255,255,255,.13);
      box-shadow: 0 25px 80px rgba(0,0,0,.19);
      backdrop-filter: blur(12px);
    }

    .af-hero-card img {
      width: 100%;
      aspect-ratio: 4 / 3;
      object-fit: cover;
      border-radius: 18px;
      display: block;
    }

    .af-hero-card-bottom {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 1rem;
      align-items: end;
      padding: 1rem .3rem .2rem;
    }

    .af-hero-card-label {
      font-size: .68rem;
      text-transform: uppercase;
      letter-spacing: .12em;
      color: rgba(255,255,255,.5);
    }

    .af-hero-card-title {
      margin-top: .24rem;
      font-family: var(--serif);
      font-size: 1.18rem;
      color: #fff;
    }

    .af-hero-card-meta {
      text-align: right;
      font-size: .76rem;
      color: rgba(255,255,255,.62);
    }

    .af-section {
      padding: 92px 0;
    }

    .af-section-alt {
      background: #fff;
    }

    .af-container {
      width: min(1180px, calc(100% - 32px));
      margin: 0 auto;
    }

    .af-section-head {
      max-width: 760px;
      margin-bottom: 3rem;
    }

    .af-section-head.center {
      margin-left: auto;
      margin-right: auto;
      text-align: center;
    }

    .af-eyebrow {
      margin-bottom: .7rem;
      color: var(--af-green);
      font-size: .7rem;
      font-weight: 800;
      letter-spacing: .16em;
      text-transform: uppercase;
    }

    .af-section-title {
      margin: 0;
      font-family: var(--serif);
      font-size: clamp(2.2rem, 4vw, 3.55rem);
      line-height: 1.05;
      letter-spacing: -.02em;
      color: var(--af-deep);
    }

    .af-section-lead {
      margin: 1rem 0 0;
      color: var(--af-muted);
      font-size: 1rem;
      line-height: 1.85;
    }

    .af-intro-grid,
    .af-business-grid,
    .af-contact-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 4rem;
      align-items: center;
    }

    .af-image-frame {
      position: relative;
    }

    .af-image-frame img {
      display: block;
      width: 100%;
      aspect-ratio: 4 / 3;
      object-fit: cover;
      border-radius: 22px;
      box-shadow: var(--af-shadow);
    }

    .af-image-note {
      position: absolute;
      left: 18px;
      bottom: 18px;
      width: min(290px, calc(100% - 36px));
      padding: 1rem 1.1rem;
      border-radius: 14px;
      background: rgba(23,55,29,.94);
      color: #fff;
      box-shadow: 0 16px 35px rgba(0,0,0,.15);
    }

    .af-image-note strong {
      display: block;
      font-family: var(--serif);
      font-size: 1.2rem;
    }

    .af-image-note span {
      display: block;
      margin-top: .25rem;
      color: rgba(255,255,255,.58);
      font-size: .74rem;
      line-height: 1.5;
    }

    .af-copy p {
      color: var(--af-muted);
      line-height: 1.85;
    }

    .af-copy strong {
      color: var(--af-ink);
    }

    .af-checks {
      display: grid;
      gap: .85rem;
      margin: 1.7rem 0 0;
    }

    .af-check {
      display: grid;
      grid-template-columns: 28px 1fr;
      gap: .75rem;
      align-items: start;
      color: var(--af-ink);
      font-size: .9rem;
      line-height: 1.65;
    }

    .af-check-icon {
      width: 28px;
      height: 28px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: var(--af-soft);
      color: var(--af-green);
      font-size: .82rem;
      font-weight: 800;
    }

    .af-card-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1.2rem;
    }

    .af-info-card {
      height: 100%;
      padding: 1.45rem;
      border-radius: var(--af-radius);
      border: 1px solid var(--af-border);
      background: #fff;
      box-shadow: 0 10px 30px rgba(26, 56, 31, .04);
    }

    .af-info-card.dark {
      background: var(--af-deep);
      border-color: transparent;
      color: #fff;
    }

    .af-icon {
      width: 44px;
      height: 44px;
      display: grid;
      place-items: center;
      border-radius: 12px;
      background: var(--af-soft);
      color: var(--af-green);
      font-size: 1.15rem;
    }

    .af-info-card.dark .af-icon {
      background: rgba(255,255,255,.08);
      color: #f3d67c;
    }

    .af-info-title {
      margin-top: 1rem;
      font-family: var(--serif);
      font-size: 1.3rem;
      color: var(--af-deep);
    }

    .af-info-card.dark .af-info-title {
      color: #fff;
    }

    .af-info-text {
      margin-top: .55rem;
      color: var(--af-muted);
      font-size: .86rem;
      line-height: 1.75;
    }

    .af-info-card.dark .af-info-text {
      color: rgba(255,255,255,.62);
    }

    .af-process {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 0;
      border-top: 1px solid var(--af-border);
      border-bottom: 1px solid var(--af-border);
    }

    .af-process-step {
      position: relative;
      padding: 1.45rem 1.15rem 1.7rem;
      border-right: 1px solid var(--af-border);
      background: #fff;
    }

    .af-process-step:last-child {
      border-right: 0;
    }

    .af-step-num {
      font-family: var(--mono);
      color: var(--af-gold);
      font-size: .72rem;
      letter-spacing: .12em;
    }

    .af-step-title {
      margin-top: .65rem;
      color: var(--af-deep);
      font-family: var(--serif);
      font-size: 1.15rem;
    }

    .af-step-text {
      margin-top: .4rem;
      color: var(--af-muted);
      font-size: .8rem;
      line-height: 1.65;
    }

    .af-process-note {
      margin-top: 1.2rem;
      padding: 1rem 1.1rem;
      border-left: 3px solid var(--af-gold);
      border-radius: 0 12px 12px 0;
      background: #fff7df;
      color: #6a560f;
      font-size: .82rem;
      line-height: 1.7;
    }

    .af-product-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1.3rem;
    }

    .af-product-card {
      overflow: hidden;
      height: 100%;
      border: 1px solid var(--af-border);
      border-radius: 18px;
      background: #fff;
    }

    .af-product-image {
      aspect-ratio: 16 / 10;
      overflow: hidden;
      background: #e9efe6;
    }

    .af-product-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform .35s ease;
    }

    .af-product-card:hover .af-product-image img {
      transform: scale(1.03);
    }

    .af-product-body {
      padding: 1.25rem;
    }

    .af-product-kicker {
      color: var(--af-green);
      font-size: .68rem;
      font-weight: 800;
      letter-spacing: .12em;
      text-transform: uppercase;
    }

    .af-product-title {
      margin-top: .45rem;
      font-family: var(--serif);
      font-size: 1.35rem;
      color: var(--af-deep);
    }

    .af-product-text {
      margin-top: .5rem;
      color: var(--af-muted);
      font-size: .84rem;
      line-height: 1.7;
    }

    .af-product-foot {
      margin-top: 1rem;
      padding-top: .9rem;
      border-top: 1px solid var(--af-border);
      color: #7c847e;
      font-size: .74rem;
    }

    .af-business-flow {
      display: grid;
      gap: .85rem;
    }

    .af-flow-row {
      display: grid;
      grid-template-columns: 56px 1fr;
      gap: 1rem;
      align-items: center;
      padding: 1rem;
      border: 1px solid var(--af-border);
      border-radius: 14px;
      background: #fff;
    }

    .af-flow-num {
      width: 56px;
      height: 56px;
      display: grid;
      place-items: center;
      border-radius: 14px;
      background: var(--af-soft);
      color: var(--af-green);
      font-family: var(--mono);
      font-size: .8rem;
    }

    .af-flow-title {
      font-weight: 700;
      color: var(--af-deep);
    }

    .af-flow-text {
      margin-top: .22rem;
      color: var(--af-muted);
      font-size: .82rem;
      line-height: 1.6;
    }

    .af-partner-band {
      position: relative;
      overflow: hidden;
      background:
        linear-gradient(135deg, rgba(23,55,29,.98), rgba(35,82,45,.95)),
        url('<?= $frontend ?>/why.jpg') center/cover no-repeat;
      color: #fff;
    }

    .af-partner-band::after {
      content: "";
      position: absolute;
      inset: auto -80px -150px auto;
      width: 360px;
      height: 360px;
      border-radius: 50%;
      background: rgba(212,160,23,.12);
    }

    .af-partner-inner {
      position: relative;
      z-index: 1;
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr);
      gap: 3rem;
      align-items: center;
    }

    .af-partner-band .af-eyebrow {
      color: #f0d67f;
    }

    .af-partner-band .af-section-title {
      color: #fff;
    }

    .af-partner-band .af-section-lead {
      color: rgba(255,255,255,.65);
    }

    .af-partner-points {
      display: grid;
      gap: .8rem;
    }

    .af-partner-point {
      padding: 1rem 1.1rem;
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 14px;
      background: rgba(255,255,255,.04);
    }

    .af-partner-point strong {
      display: block;
      color: #fff;
      font-size: .9rem;
    }

    .af-partner-point span {
      display: block;
      margin-top: .25rem;
      color: rgba(255,255,255,.56);
      font-size: .76rem;
      line-height: 1.6;
    }

    .af-package-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1.3rem;
    }

    .af-package-card {
      display: flex;
      flex-direction: column;
      overflow: hidden;
      border: 1px solid var(--af-border);
      border-radius: 18px;
      background: #fff;
      box-shadow: 0 10px 30px rgba(26,56,31,.035);
    }

    .af-package-image {
      aspect-ratio: 16 / 10;
      background: #eef2eb;
      overflow: hidden;
    }

    .af-package-image img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .af-package-body {
      display: flex;
      flex: 1;
      flex-direction: column;
      padding: 1.2rem;
    }

    .af-package-name {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: .7rem;
    }

    .af-package-name h3 {
      margin: 0;
      color: var(--af-deep);
      font-family: var(--serif);
      font-size: 1.35rem;
    }

    .af-package-price {
      font-family: var(--mono);
      color: var(--af-green);
      font-size: 1rem;
      white-space: nowrap;
    }

    .af-package-price small {
      color: #8c968e;
      font-family: var(--sans);
      font-size: .65rem;
    }

    .af-package-description {
      margin-top: .65rem;
      color: var(--af-muted);
      font-size: .8rem;
      line-height: 1.7;
    }

    .af-package-list {
      display: grid;
      gap: .52rem;
      margin: 1rem 0 0;
      padding: 0;
      list-style: none;
    }

    .af-package-list li {
      position: relative;
      padding-left: 1rem;
      color: #4c5a50;
      font-size: .76rem;
      line-height: 1.55;
    }

    .af-package-list li::before {
      content: "";
      position: absolute;
      left: 0;
      top: .55em;
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: var(--af-gold);
    }

    .af-package-actions {
      display: flex;
      gap: .55rem;
      flex-wrap: wrap;
      margin-top: auto;
      padding-top: 1.1rem;
    }

    .af-small-btn {
      min-height: 40px;
      padding: .6rem .8rem;
      border-radius: 9px;
      font-size: .72rem;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
      border: 1px solid var(--af-border);
      background: #fff;
      color: var(--af-green);
    }

    .af-small-btn.primary {
      flex: 1;
      border-color: var(--af-green);
      background: var(--af-green);
      color: #fff;
      text-align: center;
    }

    .af-package-disclaimer {
      margin-top: 1.35rem;
      padding: 1rem 1.1rem;
      border: 1px solid var(--af-border);
      border-radius: 14px;
      background: #fff;
      color: var(--af-muted);
      font-size: .78rem;
      line-height: 1.7;
    }

    .af-evidence-grid {
      display: grid;
      grid-template-columns: 1.15fr .85fr;
      gap: 1.3rem;
      align-items: stretch;
    }

    .af-evidence-main {
      overflow: hidden;
      border-radius: 20px;
      min-height: 440px;
      position: relative;
      background: #dfe8db;
    }

    .af-evidence-main img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .af-evidence-caption {
      position: absolute;
      inset: auto 18px 18px;
      padding: 1rem 1.1rem;
      border-radius: 14px;
      background: rgba(23,55,29,.92);
      color: #fff;
    }

    .af-evidence-caption strong {
      display: block;
      font-family: var(--serif);
      font-size: 1.15rem;
    }

    .af-evidence-caption span {
      display: block;
      margin-top: .25rem;
      color: rgba(255,255,255,.58);
      font-size: .75rem;
      line-height: 1.55;
    }

    .af-evidence-side {
      display: grid;
      gap: 1.3rem;
    }

    .af-proof-card {
      padding: 1.3rem;
      border: 1px solid var(--af-border);
      border-radius: 18px;
      background: #fff;
    }

    .af-proof-card h3 {
      margin: 0;
      color: var(--af-deep);
      font-family: var(--serif);
      font-size: 1.15rem;
    }

    .af-proof-list {
      display: grid;
      gap: .65rem;
      margin-top: .9rem;
    }

    .af-proof-row {
      display: grid;
      grid-template-columns: 28px 1fr;
      gap: .65rem;
      align-items: start;
      color: #536058;
      font-size: .78rem;
      line-height: 1.6;
    }

    .af-proof-row-bullet {
      width: 24px;
      height: 24px;
      display: grid;
      place-items: center;
      border-radius: 7px;
      background: var(--af-soft);
      color: var(--af-green);
      font-size: .7rem;
      font-weight: 800;
    }

    .af-legal-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
    }

    .af-legal-card {
      overflow: hidden;
      border: 1px solid var(--af-border);
      border-radius: 14px;
      background: #fff;
      cursor: pointer;
    }

    .af-legal-image {
      aspect-ratio: 16 / 10;
      background: #f0f2ef;
      overflow: hidden;
    }

    .af-legal-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .af-legal-body {
      padding: .85rem;
    }

    .af-legal-title {
      color: var(--af-deep);
      font-weight: 700;
      font-size: .78rem;
    }

    .af-legal-text {
      margin-top: .22rem;
      color: var(--af-muted);
      font-size: .7rem;
      line-height: 1.5;
    }

    .af-faq-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1rem 1.5rem;
    }

    .af-faq-item {
      padding: 1.05rem 0;
      border-bottom: 1px solid var(--af-border);
    }

    .af-faq-q {
      width: 100%;
      border: 0;
      background: none;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      text-align: left;
      color: var(--af-deep);
      font-weight: 700;
      font-size: .9rem;
      cursor: pointer;
    }

    .af-faq-a {
      display: none;
      padding: .75rem 0 0;
      color: var(--af-muted);
      font-size: .82rem;
      line-height: 1.75;
    }

    .af-faq-item.open .af-faq-a {
      display: block;
    }

    .af-faq-icon {
      width: 28px;
      height: 28px;
      flex: 0 0 28px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: var(--af-soft);
      color: var(--af-green);
      font-size: .9rem;
    }

    .af-contact-box {
      padding: 2rem;
      border-radius: 20px;
      background: var(--af-deep);
      color: #fff;
    }

    .af-contact-box h3 {
      margin: 0;
      font-family: var(--serif);
      font-size: 1.75rem;
      color: #fff;
    }

    .af-contact-box p {
      color: rgba(255,255,255,.64);
      line-height: 1.8;
      font-size: .85rem;
    }

    .af-contact-details {
      display: grid;
      gap: .75rem;
      margin-top: 1.35rem;
    }

    .af-contact-detail {
      padding: .9rem 0;
      border-top: 1px solid rgba(255,255,255,.1);
    }

    .af-contact-detail-label {
      color: rgba(255,255,255,.42);
      font-size: .65rem;
      letter-spacing: .1em;
      text-transform: uppercase;
    }

    .af-contact-detail-value {
      margin-top: .2rem;
      color: #fff;
      font-size: .86rem;
    }

    .af-final-cta {
      padding: 72px 0 84px;
      background: #f0f3ed;
      border-top: 1px solid var(--af-border);
    }

    .af-final-cta-inner {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 2rem;
    }

    .af-final-cta h2 {
      margin: 0;
      font-family: var(--serif);
      font-size: clamp(2rem, 3.5vw, 3rem);
      color: var(--af-deep);
    }

    .af-final-cta p {
      margin: .65rem 0 0;
      color: var(--af-muted);
      font-size: .9rem;
      line-height: 1.7;
    }

    footer {
      background: #0f2614;
    }

    .af-footer-title {
      color: #fff !important;
    }

    @media (max-width: 980px) {
      .af-hero-inner,
      .af-intro-grid,
      .af-business-grid,
      .af-contact-grid,
      .af-partner-inner,
      .af-evidence-grid {
        grid-template-columns: 1fr;
        gap: 2.3rem;
      }

      .af-hero {
        min-height: 0;
      }

      .af-card-grid,
      .af-product-grid,
      .af-package-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .af-process {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .af-process-step {
        border-bottom: 1px solid var(--af-border);
      }

      .af-process-step:nth-child(2n) {
        border-right: 0;
      }

      .af-legal-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .af-faq-grid {
        grid-template-columns: 1fr;
      }

      .af-final-cta-inner {
        flex-direction: column;
        align-items: flex-start;
      }
    }

    @media (max-width: 760px) {
      .af-utility-left span:not(:first-child),
      .af-utility-right span:not(:first-child) {
        display: none;
      }

      .af-utility-inner {
        min-height: 30px;
        font-size: .68rem;
      }

      .af-hero-inner {
        padding: 55px 0 64px;
      }

      .af-hero h1 {
        font-size: clamp(2.7rem, 13vw, 4.2rem);
      }

      .af-hero-lead {
        font-size: .94rem;
      }

      .af-hero-facts {
        grid-template-columns: 1fr;
      }

      .af-section {
        padding: 68px 0;
      }

      .af-section-title {
        font-size: 2.15rem;
      }

      .af-card-grid,
      .af-product-grid,
      .af-package-grid,
      .af-legal-grid {
        grid-template-columns: 1fr;
      }

      .af-process {
        grid-template-columns: 1fr;
      }

      .af-process-step,
      .af-process-step:nth-child(2n) {
        border-right: 0;
        border-bottom: 1px solid var(--af-border);
      }

      .af-process-step:last-child {
        border-bottom: 0;
      }

      .af-contact-box {
        padding: 1.45rem;
      }
    }

    @media (max-width: 640px) {
      .af-container,
      .af-hero-inner,
      .af-utility-inner {
        width: min(100% - 24px, 1180px);
      }

      .af-hero-inner {
        padding-left: 0;
        padding-right: 0;
      }

      .af-hero-card {
        border-radius: 18px;
      }

      .af-section {
        padding: 58px 0;
      }

      .af-image-note {
        position: static;
        width: auto;
        margin-top: .8rem;
      }

      .af-image-frame img {
        border-radius: 18px;
      }

      .af-final-cta {
        padding: 58px 0;
      }
    }
  </style>
</head>

<body>

  <!-- ============================================================
       HEADER
  ============================================================ -->
  <header class="site-header">
    <div class="af-utility">
      <div class="af-utility-inner">
        <div class="af-utility-left">
          <span>Philippine Poultry Production</span>
          <span>•</span>
          <span>Based in <strong>Cabatuan, Isabela</strong></span>
        </div>
        <div class="af-utility-right">
          <span><strong>support@altasfarm.com</strong></span>
          <span>Mon–Sat · 8 AM–6 PM</span>
        </div>
      </div>
    </div>

    <nav aria-label="Primary navigation">
      <div class="nav-inner">
        <a href="#hero" class="nav-logo" aria-label="<?= e($siteName) ?> home">
          <img
            src="<?= $frontend ?>/logo.png"
            alt="<?= e($siteName) ?> logo"
            width="auto"
            height="36"
            onerror="this.style.display='none'"
          >
          <span class="nav-logo-text"><?= e($siteName) ?></span>
        </a>

        <ul class="nav-links">
          <li><a href="#farm">Farm</a></li>
          <li><a href="#products">Products</a></li>
          <li><a href="#business">Business</a></li>
          <li><a href="#partners">Partners</a></li>
          <li><a href="#transparency">Transparency</a></li>
          <li><a href="#about">About</a></li>
        </ul>

        <div class="nav-cta">
          <a href="<?= $base ?>/?page=login" class="nav-btn-login">Login</a>
          <a href="<?= $base ?>/?page=register" class="nav-btn-register">Partner Login / Join</a>
        </div>

        <button
          class="nav-mobile-toggle"
          aria-label="Toggle Menu"
          aria-controls="mobileMenu"
          aria-expanded="false"
          onclick="toggleMobileMenu()"
        >☰</button>
      </div>
    </nav>
  </header>

  <div class="mobile-menu" id="mobileMenu" aria-hidden="true">
    <div class="mobile-menu-header">
      <span class="nav-logo-text" style="color:#fff"><?= e($siteName) ?></span>
      <button class="mobile-close-btn" onclick="toggleMobileMenu()" aria-label="Close Menu">✕</button>
    </div>
    <a href="#farm" onclick="toggleMobileMenu()">Farm</a>
    <a href="#products" onclick="toggleMobileMenu()">Products</a>
    <a href="#business" onclick="toggleMobileMenu()">Business</a>
    <a href="#partners" onclick="toggleMobileMenu()">Partners</a>
    <a href="#transparency" onclick="toggleMobileMenu()">Transparency</a>
    <a href="#about" onclick="toggleMobileMenu()">About</a>
    <div style="margin-top:1.5rem;display:flex;flex-direction:column;gap:.75rem;">
      <a href="<?= $base ?>/?page=login" style="color:var(--gold);text-align:center;">Member Login</a>
      <a href="<?= $base ?>/?page=register" class="btn-gold" style="text-align:center;">Join the Partner Program</a>
    </div>
  </div>

  <main class="af-home" id="hero">

    <!-- ============================================================
         HERO
    ============================================================ -->
    <section class="af-hero">
      <div class="af-hero-inner">
        <div class="af-hero-copy fade-up">
          <div class="af-kicker">🐓 Philippine Poultry · Est. 2024</div>

          <h1>
            A working farm,
            <span>built to grow.</span>
          </h1>

          <p class="af-hero-lead">
            <?= e($siteName) ?> is a Philippine poultry business focused on production,
            farm-managed grow-out, bulk orders, and organized distribution. Our digital
            platform helps customers and participating partners keep the commercial
            relationship, production cycle, and account activity clearly organized.
          </p>

          <div class="af-hero-actions">
            <a href="#farm" class="af-btn af-btn-primary">Explore the Farm</a>
            <a href="#products" class="af-btn af-btn-secondary">View Products</a>
          </div>

          <div class="af-hero-facts">
            <div class="af-hero-fact">
              <div class="af-hero-fact-value">Poultry</div>
              <div class="af-hero-fact-label">Production at the center of the business</div>
            </div>
            <div class="af-hero-fact">
              <div class="af-hero-fact-value">Bulk</div>
              <div class="af-hero-fact-label">Orders and organized fulfillment</div>
            </div>
            <div class="af-hero-fact">
              <div class="af-hero-fact-value">Local</div>
              <div class="af-hero-fact-label">Philippine operations and support</div>
            </div>
          </div>
        </div>

        <div class="af-hero-card fade-up">
          <img
            src="<?= $frontend ?>/about.jpg"
            alt="Poultry at an Altas Farm partner operation"
          >
          <div class="af-hero-card-bottom">
            <div>
              <div class="af-hero-card-label">The business starts here</div>
              <div class="af-hero-card-title">Production before platform.</div>
            </div>
            <div class="af-hero-card-meta">
              Rang-ay<br>
              Cabatuan, Isabela
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================================
         TRUST BAR
    ============================================================ -->
    <div class="trust-bar" role="complementary" aria-label="Business information">
      <div class="trust-bar-inner">
        <div class="trust-item"><span class="trust-icon">📍</span> <strong>Isabela, PH</strong></div>
        <div class="trust-item"><span class="trust-icon">✉</span> <strong>support@altasfarm.com</strong></div>
        <div class="trust-item"><span class="trust-icon">🔒</span> <strong>HTTPS</strong> Secured</div>
        <div class="trust-item"><span class="trust-icon">📄</span> Published business disclosures</div>
      </div>
    </div>

    <!-- ============================================================
         FARM
    ============================================================ -->
    <section class="af-section" id="farm">
      <div class="af-container">
        <div class="af-section-head">
          <div class="af-eyebrow">The Farm</div>
          <h2 class="af-section-title">The physical operation comes first.</h2>
          <p class="af-section-lead">
            The clearest way to understand Altas Farm is to start with what actually
            happens on the ground: poultry is sourced, raised, managed through production
            cycles, and prepared for customers and distribution.
          </p>
        </div>

        <div class="af-intro-grid">
          <div class="af-image-frame fade-up">
            <img
              src="<?= $frontend ?>/about.jpg"
              alt="Chickens at an Altas Farm partner operation"
              loading="lazy"
            >
            <div class="af-image-note">
              <strong>Farm-managed production</strong>
              <span>
                Where applicable, customers can leave the production work with the farm
                and remain tied to the underlying production cycle.
              </span>
            </div>
          </div>

          <div class="af-copy fade-up">
            <p>
              Altas Farm is organized around <strong>actual poultry production</strong>.
              The farm coordinates production activity, recurring batches, grow-out
              arrangements, and fulfillment while the digital platform records the
              commercial relationship.
            </p>
            <p>
              The website therefore separates the physical farm operation from the
              optional partner program. A customer can understand the farm without
              having to understand the network first.
            </p>

            <div class="af-checks">
              <div class="af-check">
                <span class="af-check-icon">✓</span>
                <span>Production is organized around defined poultry cycles.</span>
              </div>
              <div class="af-check">
                <span class="af-check-icon">✓</span>
                <span>Eligible arrangements can include farm-managed grow-out.</span>
              </div>
              <div class="af-check">
                <span class="af-check-icon">✓</span>
                <span>Orders and participation are tracked through the platform.</span>
              </div>
              <div class="af-check">
                <span class="af-check-icon">✓</span>
                <span>Partner-network features are disclosed separately from the farm operation.</span>
              </div>
            </div>
          </div>
        </div>

        <div class="af-card-grid" style="margin-top:3rem;">
          <div class="af-info-card fade-up">
            <div class="af-icon">🐣</div>
            <div class="af-info-title">Production</div>
            <div class="af-info-text">
              Poultry enters a defined farm cycle and moves through the actual work of
              brooding, grow-out, management, and fulfillment.
            </div>
          </div>

          <div class="af-info-card fade-up">
            <div class="af-icon">🌾</div>
            <div class="af-info-title">Grow-Out</div>
            <div class="af-info-text">
              Where available, the farm continues raising the birds instead of requiring
              the customer to manage the day-to-day production work.
            </div>
          </div>

          <div class="af-info-card dark fade-up">
            <div class="af-icon">🤝</div>
            <div class="af-info-title">Distribution</div>
            <div class="af-info-text">
              Customer relationships, bulk orders, and participating partners are
              organized through a single operating platform.
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================================
         PRODUCTION CYCLE
    ============================================================ -->
    <section class="af-section af-section-alt">
      <div class="af-container">
        <div class="af-section-head center">
          <div class="af-eyebrow">Production Cycle</div>
          <h2 class="af-section-title">From production to fulfillment.</h2>
          <p class="af-section-lead">
            The farm handles the physical production. The platform provides the records
            and account structure around it.
          </p>
        </div>

        <div class="af-process">
          <div class="af-process-step fade-up">
            <div class="af-step-num">01</div>
            <div class="af-step-title">Sourcing / Hatch</div>
            <div class="af-step-text">
              Chicks enter the production schedule according to the selected arrangement.
            </div>
          </div>
          <div class="af-process-step fade-up">
            <div class="af-step-num">02</div>
            <div class="af-step-title">Brooding</div>
            <div class="af-step-text">
              Early-stage poultry is managed through the farm's brooding process.
            </div>
          </div>
          <div class="af-process-step fade-up">
            <div class="af-step-num">03</div>
            <div class="af-step-title">Grow-Out</div>
            <div class="af-step-text">
              Eligible production arrangements can continue under farm-managed grow-out.
            </div>
          </div>
          <div class="af-process-step fade-up">
            <div class="af-step-num">04</div>
            <div class="af-step-title">Fulfillment</div>
            <div class="af-step-text">
              Poultry is prepared according to the applicable order or participation terms.
            </div>
          </div>
          <div class="af-process-step fade-up">
            <div class="af-step-num">05</div>
            <div class="af-step-title">Distribution</div>
            <div class="af-step-text">
              Completed orders and farm relationships are coordinated for delivery or pickup.
            </div>
          </div>
        </div>

        <div class="af-process-note">
          <strong>Current farm timing:</strong>
          the existing Altas Farm terms describe a normal hatch lead time of 21 days.
          Actual fulfillment remains subject to the applicable production arrangement.
        </div>
      </div>
    </section>

    <!-- ============================================================
         PRODUCTS
    ============================================================ -->
    <section class="af-section" id="products">
      <div class="af-container">
        <div class="af-section-head">
          <div class="af-eyebrow">Products &amp; Services</div>
          <h2 class="af-section-title">What the business actually provides.</h2>
          <p class="af-section-lead">
            Product and service availability can vary by production cycle. The commercial
            site should make the underlying offering understandable before introducing
            the partner platform.
          </p>
        </div>

        <div class="af-product-grid">
          <article class="af-product-card fade-up">
            <div class="af-product-image">
              <img src="<?= $frontend ?>/pkg-starter.jpg" alt="Poultry chicks" loading="lazy">
            </div>
            <div class="af-product-body">
              <div class="af-product-kicker">Poultry</div>
              <div class="af-product-title">Chicks</div>
              <div class="af-product-text">
                Poultry supplied according to the applicable farm order and production schedule.
              </div>
              <div class="af-product-foot">Availability and quantity depend on the current cycle.</div>
            </div>
          </article>

          <article class="af-product-card fade-up">
            <div class="af-product-image">
              <img src="<?= $frontend ?>/about.jpg" alt="Live poultry" loading="lazy">
            </div>
            <div class="af-product-body">
              <div class="af-product-kicker">Poultry</div>
              <div class="af-product-title">Live Poultry</div>
              <div class="af-product-text">
                Live birds supplied or fulfilled according to the applicable order arrangement.
              </div>
              <div class="af-product-foot">Subject to production and fulfillment terms.</div>
            </div>
          </article>

          <article class="af-product-card fade-up">
            <div class="af-product-image">
              <img src="<?= $frontend ?>/why.jpg" alt="Egg production" loading="lazy">
            </div>
            <div class="af-product-body">
              <div class="af-product-kicker">Farm Output</div>
              <div class="af-product-title">Eggs</div>
              <div class="af-product-text">
                Egg production and related farm output can be supplied where available.
              </div>
              <div class="af-product-foot">Contact the farm for current availability and pricing.</div>
            </div>
          </article>

          <article class="af-product-card fade-up">
            <div class="af-product-image">
              <img src="<?= $frontend ?>/hero-bg.jpg" alt="Bulk poultry order" loading="lazy">
            </div>
            <div class="af-product-body">
              <div class="af-product-kicker">Commercial</div>
              <div class="af-product-title">Bulk Orders</div>
              <div class="af-product-text">
                Larger poultry requirements coordinated around production capacity and fulfillment schedules.
              </div>
              <div class="af-product-foot">Lead time and delivery arrangements apply.</div>
            </div>
          </article>

          <article class="af-product-card fade-up">
            <div class="af-product-image">
              <img src="<?= $frontend ?>/why.jpg" alt="Farm-managed poultry grow-out" loading="lazy">
            </div>
            <div class="af-product-body">
              <div class="af-product-kicker">Farm Service</div>
              <div class="af-product-title">Managed Grow-Out</div>
              <div class="af-product-text">
                Where eligible, the farm continues the production work on behalf of the customer.
              </div>
              <div class="af-product-foot">Governed by the terms attached to the applicable arrangement.</div>
            </div>
          </article>

          <article class="af-product-card fade-up">
            <div class="af-product-image">
              <img src="<?= $frontend ?>/about.jpg" alt="Farm partnership" loading="lazy">
            </div>
            <div class="af-product-body">
              <div class="af-product-kicker">Business</div>
              <div class="af-product-title">Farm Partnerships</div>
              <div class="af-product-text">
                Structured relationships with customers, participating partners, and the farm's wider distribution network.
              </div>
              <div class="af-product-foot">Commercial terms vary by the type of relationship.</div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <!-- ============================================================
         BUSINESS MODEL
    ============================================================ -->
    <section class="af-section af-section-alt" id="business">
      <div class="af-container">
        <div class="af-business-grid">
          <div class="af-copy fade-up">
            <div class="af-eyebrow">Business Model</div>
            <h2 class="af-section-title">A simple commercial flow.</h2>
            <p class="af-section-lead">
              The public-facing business model is easiest to understand when production,
              customer transactions, and the optional partner network are shown as distinct
              but connected activities.
            </p>

            <div class="af-business-flow" style="margin-top:2rem;">
              <div class="af-flow-row">
                <div class="af-flow-num">01</div>
                <div>
                  <div class="af-flow-title">Produce</div>
                  <div class="af-flow-text">The farm manages poultry production and recurring production cycles.</div>
                </div>
              </div>
              <div class="af-flow-row">
                <div class="af-flow-num">02</div>
                <div>
                  <div class="af-flow-title">Coordinate</div>
                  <div class="af-flow-text">Orders, customers, production arrangements, and partner activity are recorded through the platform.</div>
                </div>
              </div>
              <div class="af-flow-row">
                <div class="af-flow-num">03</div>
                <div>
                  <div class="af-flow-title">Fulfill</div>
                  <div class="af-flow-text">Poultry is delivered, picked up, or otherwise settled according to the applicable commercial terms.</div>
                </div>
              </div>
              <div class="af-flow-row">
                <div class="af-flow-num">04</div>
                <div>
                  <div class="af-flow-title">Expand</div>
                  <div class="af-flow-text">The partner program provides a separate channel for community and distribution development.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="af-image-frame fade-up">
            <img
              src="<?= $frontend ?>/why.jpg"
              alt="Farmer handling poultry and collecting eggs"
              loading="lazy"
            >
            <div class="af-image-note">
              <strong>Business visibility matters.</strong>
              <span>
                A customer or business partner should be able to understand the farm,
                the products, the fulfillment process, and the partner model independently.
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================================
         PARTNER PROGRAM
    ============================================================ -->
    <section class="af-section af-partner-band" id="partners">
      <div class="af-container af-partner-inner">
        <div>
          <div class="af-eyebrow">Partner Program</div>
          <h2 class="af-section-title">A separate layer for people who want to participate.</h2>
          <p class="af-section-lead">
            Altas Farm also operates a structured member platform. The partner program,
            its packages, compensation rules, eligibility conditions, and payout rules
            are disclosed separately so that participation can be evaluated on its own terms.
          </p>

          <div class="af-hero-actions" style="margin-top:1.5rem;">
            <a href="#packages" class="af-btn af-btn-primary">Review Packages</a>
            <a href="#partner-faq" class="af-btn af-btn-secondary">Read the FAQ</a>
          </div>
        </div>

        <div class="af-partner-points">
          <div class="af-partner-point fade-up">
            <strong>Clear package rules</strong>
            <span>Each package has its own configured features and conditions.</span>
          </div>
          <div class="af-partner-point fade-up">
            <strong>Separate compensation disclosure</strong>
            <span>Referral and network mechanics are not presented as the farm's physical production output.</span>
          </div>
          <div class="af-partner-point fade-up">
            <strong>Risk is part of the explanation</strong>
            <span>Partner compensation depends on applicable rules, qualifying activity, and network conditions.</span>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================================
         PACKAGES
    ============================================================ -->
    <section class="af-section" id="packages">
      <div class="af-container">
        <div class="af-section-head center">
          <div class="af-eyebrow">Partner Packages</div>
          <h2 class="af-section-title">Defined participation levels.</h2>
          <p class="af-section-lead">
            The active package catalog is loaded directly from the system. The cards below
            present the commercial package amount first; detailed partner mechanics remain
            available through the full package breakdown.
          </p>
        </div>

        <div class="af-package-grid">
          <?php foreach ($planFacts as $id => $f): ?>
            <article class="af-package-card fade-up">
              <div class="af-package-image">
                <img
                  src="<?= e($f['image_url']) ?>"
                  alt="<?= e($f['name']) ?> package"
                  loading="lazy"
                >
              </div>

              <div class="af-package-body">
                <div class="af-package-name">
                  <h3><?= e($f['name']) ?></h3>
                  <div class="af-package-price">
                    <?= fmt_money($f['entry']) ?>
                    <small>one-time</small>
                  </div>
                </div>

                <div class="af-package-description">
                  A defined participation level with a corresponding farm arrangement
                  and package-specific partner features.
                </div>

                <ul class="af-package-list">
                  <li>Defined farm participation amount</li>
                  <li>Production cycle governed by the applicable farm terms</li>
                  <li>Farm-managed grow-out where eligible</li>
                  <li>Partner features vary by package</li>
                </ul>

                <div class="af-package-actions">
                  <button
                    type="button"
                    class="af-small-btn"
                    onclick="openPkgDetails(<?= $id ?>)"
                  >Full details</button>

                  <?php if (!$isFull): ?>
                    <a
                      href="<?= $base ?>/?page=register"
                      class="af-small-btn primary"
                    >Continue to registration</a>
                  <?php else: ?>
                    <span class="af-small-btn primary" style="opacity:.55;cursor:not-allowed;">Registration closed</span>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="af-package-disclaimer">
          <strong>Before participating:</strong>
          package amounts, partner features, compensation rules, farm arrangements,
          payout conditions, and applicable limitations are governed by their respective
          terms. Review the complete package details and disclosures before registering.
        </div>
      </div>
    </section>

    <!-- ============================================================
         TRANSPARENCY
    ============================================================ -->
    <section class="af-section af-section-alt" id="transparency">
      <div class="af-container">
        <div class="af-section-head">
          <div class="af-eyebrow">Transparency</div>
          <h2 class="af-section-title">The information a serious counterpart should be able to verify.</h2>
          <p class="af-section-lead">
            Altas Farm presents business identity, operational information, legal documents,
            and program disclosures in one place. The objective is simple: make the business
            understandable before asking anyone to transact or participate.
          </p>
        </div>

        <div class="af-evidence-grid">
          <div class="af-evidence-main fade-up">
            <img
              src="<?= $frontend ?>/about.jpg"
              alt="Poultry operation"
              loading="lazy"
            >
            <div class="af-evidence-caption">
              <strong>Actual farm activity</strong>
              <span>
                Operational claims should be supported by actual production records,
                photographs, fulfillment evidence, and current business documentation.
              </span>
            </div>
          </div>

          <div class="af-evidence-side">
            <div class="af-proof-card fade-up">
              <h3>Business identity</h3>
              <div class="af-proof-list">
                <div class="af-proof-row">
                  <span class="af-proof-row-bullet">01</span>
                  <span><strong>Altas Farm</strong> · Philippine poultry business</span>
                </div>
                <div class="af-proof-row">
                  <span class="af-proof-row-bullet">02</span>
                  <span>Rang-ay, Cabatuan, Isabela 3315, Philippines</span>
                </div>
                <div class="af-proof-row">
                  <span class="af-proof-row-bullet">03</span>
                  <span>Support via support@altasfarm.com</span>
                </div>
                <div class="af-proof-row">
                  <span class="af-proof-row-bullet">04</span>
                  <span>Business and regulatory documents are presented for review.</span>
                </div>
              </div>
            </div>

            <div class="af-proof-card fade-up">
              <h3>Program disclosures</h3>
              <div class="af-proof-list">
                <div class="af-proof-row">
                  <span class="af-proof-row-bullet">01</span>
                  <span>Partner compensation is disclosed separately from farm production.</span>
                </div>
                <div class="af-proof-row">
                  <span class="af-proof-row-bullet">02</span>
                  <span>Network activity is subject to package rules and qualifying conditions.</span>
                </div>
                <div class="af-proof-row">
                  <span class="af-proof-row-bullet">03</span>
                  <span>Farm arrangements are subject to production and fulfillment conditions.</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div style="margin-top:3rem;">
          <div class="af-section-head" style="margin-bottom:1.5rem;">
            <div class="af-eyebrow">Business Documents</div>
            <h3 class="af-section-title" style="font-size:2rem;">Documents currently presented by the business.</h3>
          </div>

          <div class="af-legal-grid">
            <?php foreach ($legalDocs as [$file, $title, $description]): ?>
              <button
                type="button"
                class="af-legal-card legal-img fade-up"
                role="button"
                tabindex="0"
                aria-label="View <?= e($title) ?>"
              >
                <div class="af-legal-image">
                  <img
                    src="<?= $frontend ?>/img/legalities/<?= e($file) ?>"
                    alt="<?= e($title) ?>"
                    loading="lazy"
                  >
                </div>
                <div class="af-legal-body">
                  <div class="af-legal-title"><?= e($title) ?></div>
                  <div class="af-legal-text"><?= e($description) ?></div>
                </div>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================================
         FAQ
    ============================================================ -->
    <section class="af-section" id="partner-faq">
      <div class="af-container">
        <div class="af-section-head center">
          <div class="af-eyebrow">Questions</div>
          <h2 class="af-section-title">Understand the business before you participate.</h2>
          <p class="af-section-lead">
            The homepage gives the broad picture. The detailed terms and package breakdown
            remain available for anyone evaluating the partner program.
          </p>
        </div>

        <div class="af-faq-grid">
          <div class="af-faq-item">
            <button class="af-faq-q" type="button" onclick="toggleHomeFaq(this)">
              What is Altas Farm?
              <span class="af-faq-icon">+</span>
            </button>
            <div class="af-faq-a">
              Altas Farm is a Philippine poultry operation with a digital member platform.
              The public site is centered on poultry production, farm arrangements,
              products, distribution, and a separately disclosed partner program.
            </div>
          </div>

          <div class="af-faq-item">
            <button class="af-faq-q" type="button" onclick="toggleHomeFaq(this)">
              What does a package represent?
              <span class="af-faq-icon">+</span>
            </button>
            <div class="af-faq-a">
              Each package is a defined participation level with its own configured farm
              arrangement and partner-platform features. The complete package breakdown is
              available from the package cards above.
            </div>
          </div>

          <div class="af-faq-item">
            <button class="af-faq-q" type="button" onclick="toggleHomeFaq(this)">
              Is partner compensation guaranteed?
              <span class="af-faq-icon">+</span>
            </button>
            <div class="af-faq-a">
              No. The existing program disclosures state that referral and commission
              earnings depend on package rules, qualifying activity, and network conditions.
              They should not be treated as guaranteed income.
            </div>
          </div>

          <div class="af-faq-item">
            <button class="af-faq-q" type="button" onclick="toggleHomeFaq(this)">
              How does farm-managed grow-out work?
              <span class="af-faq-icon">+</span>
            </button>
            <div class="af-faq-a">
              Where a particular arrangement provides a grow-out option, the farm continues
              raising the birds through the applicable production cycle. The exact conditions
              are governed by the terms attached to that arrangement.
            </div>
          </div>

          <div class="af-faq-item">
            <button class="af-faq-q" type="button" onclick="toggleHomeFaq(this)">
              Where is Altas Farm based?
              <span class="af-faq-icon">+</span>
            </button>
            <div class="af-faq-a">
              The website identifies the business address as Rang-ay, Cabatuan, Isabela
              3315, Philippines. Walk-in visits are by appointment.
            </div>
          </div>

          <div class="af-faq-item">
            <button class="af-faq-q" type="button" onclick="toggleHomeFaq(this)">
              How can I contact the business?
              <span class="af-faq-icon">+</span>
            </button>
            <div class="af-faq-a">
              Contact support@altasfarm.com for customer, account, compliance, or general
              business inquiries. The current support schedule is Monday to Saturday,
              8:00 AM to 6:00 PM Philippine Standard Time.
            </div>
          </div>
        </div>

        <div style="margin-top:2rem;text-align:center;">
          <button
            type="button"
            class="af-small-btn"
            onclick="openModal('modal-faq')"
          >Open full FAQ</button>
        </div>
      </div>
    </section>

    <!-- ============================================================
         ABOUT / CONTACT
    ============================================================ -->
    <section class="af-section af-section-alt" id="about">
      <div class="af-container">
        <div class="af-contact-grid">
          <div class="af-copy fade-up">
            <div class="af-eyebrow">About Altas Farm</div>
            <h2 class="af-section-title">A local business with a digital operating layer.</h2>
            <p class="af-section-lead">
              The public website is intended to make the company easier to understand:
              what the farm produces, how the operation works, how products are handled,
              how the partner program is structured, and where to find the relevant business
              and legal information.
            </p>

            <div class="af-card-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-top:1.8rem;">
              <div class="af-info-card">
                <div class="af-info-title">Founded</div>
                <div class="af-info-text">2024</div>
              </div>
              <div class="af-info-card">
                <div class="af-info-title">Location</div>
                <div class="af-info-text">Cabatuan, Isabela</div>
              </div>
            </div>
          </div>

          <div class="af-contact-box fade-up">
            <h3>Talk to the business.</h3>
            <p>
              For product availability, bulk orders, partnership inquiries, account support,
              or compliance concerns, use the contact information below.
            </p>

            <div class="af-contact-details">
              <div class="af-contact-detail">
                <div class="af-contact-detail-label">Email</div>
                <div class="af-contact-detail-value">support@altasfarm.com</div>
              </div>
              <div class="af-contact-detail">
                <div class="af-contact-detail-label">Address</div>
                <div class="af-contact-detail-value">Rang-ay, Cabatuan, Isabela 3315, Philippines</div>
              </div>
              <div class="af-contact-detail">
                <div class="af-contact-detail-label">Support</div>
                <div class="af-contact-detail-value">Monday–Saturday · 8 AM–6 PM PST</div>
              </div>
            </div>

            <div style="margin-top:1.5rem;">
              <button
                type="button"
                class="af-btn af-btn-primary"
                onclick="openModal('modal-contact')"
              >Contact Information</button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============================================================
         FINAL CTA
    ============================================================ -->
    <section class="af-final-cta">
      <div class="af-container af-final-cta-inner">
        <div>
          <h2>Start with the farm.</h2>
          <p>Explore the operation, products, disclosures, and partner program before deciding how you want to participate.</p>
        </div>
        <div class="af-hero-actions" style="margin-top:0;">
          <a href="#farm" class="af-btn af-btn-primary">Explore the Farm</a>
          <a href="<?= $base ?>/?page=register" class="af-btn" style="background:var(--af-deep);color:#fff;">Partner Registration</a>
        </div>
      </div>
    </section>

  </main>

  <!-- ============================================================
       EXISTING POLICY / SUPPORT MODALS
       These remain on index.php for continuity. The next phase can
       move them to dedicated routes/pages.
  ============================================================ -->

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
        <p>These Terms of Service ("Terms") govern the relationship between <?= e($siteName) ?> ("the Platform," "we," "us") and any individual who registers as a member ("Member," "you"). <?= e($siteName) ?> is operated by its founding administrators, based in Rang-ay, Cabatuan, Isabela, Philippines.</p>

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

        <p class="meta-line"><?= e($siteName) ?> · Rang-ay, Cabatuan, Isabela, Philippines · Version 1.0, effective January 1, 2025</p>
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
          <p>Transparency statement — September 2026</p>
        </div>
        <button class="af-modal-close" onclick="closeModal('modal-compliance')" aria-label="Close">✕</button>
      </div>
      <div class="af-modal-body">

        <div class="highlight-box">
          <p><?= e($siteName) ?> is committed to operating transparently. This page discloses our regulatory standing, the nature of the network, and the risks members should understand before joining.</p>
        </div>

        <h3>1. Business Registration</h3>
        <p><?= e($siteName) ?> is registered as a sole proprietorship with the Philippine Department of Trade and Industry (DTI). In addition to the DTI business name registration, <?= e($siteName) ?> holds all required permits and certificates to operate as a poultry business. Copies of our legal documents are available in the <a href="#legalities" style="color:var(--green-mid);font-weight:600;" onclick="closeModal('modal-compliance')">Legalities</a> section.</p>
        <ul>
          <li><strong>DTI Business Name Registration</strong></li>
          <li><strong>Business Registration</strong></li>
          <li><strong>Mayor's Business Permit</strong> — issued by the local government unit</li>
          <li><strong>Sanitary Permit</strong> — issued by the municipal health office</li>
          <li><strong>Fire Safety Certificate</strong> — issued by the Bureau of Fire Protection</li>
          <li><strong>DENR Environmental Compliance Certificate</strong> — issued by the Department of Environment and Natural Resources</li>
        </ul>
        <p><a href="#legalities" style="color:var(--green-mid);font-weight:600;" onclick="closeModal('modal-compliance')">View our Legal documents →</a></p>

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

        <p class="meta-line">This disclosure is provided in good faith as part of <?= e($siteName) ?>'s commitment to operating transparently. Last reviewed: September 2026.</p>
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


  <!-- ── Legalities Image Viewer Modal ───────────────────────────── -->
  <div class="af-modal-backdrop" id="modal-legal-img" role="dialog" aria-modal="true" aria-labelledby="legal-img-title" onclick="closeModalOnBackdrop(event,'modal-legal-img')">
    <div class="af-modal af-modal--image">
      <div class="af-modal-header">
        <div>
          <h2 id="legal-img-title">Certificate</h2>
        </div>
        <button class="af-modal-close" onclick="closeModal('modal-legal-img')" aria-label="Close">✕</button>
      </div>
      <div class="af-modal-body af-modal-body--image">
        <img id="legalImgFull" src="" alt="Full-size legal document">
      </div>
    </div>
  </div>

  <footer itemscope itemtype="https://schema.org/Organization">
    <div class="footer-inner">
      <div class="footer-top">

        <div>
          <div class="footer-brand-name" itemprop="name"><?= e($siteName) ?></div>
          <div
            class="footer-brand-desc"
            itemprop="description"
          >
            Philippine poultry production, products, farm-managed grow-out,
            distribution, and a transparently disclosed partner program.
            <br>
            <a href="mailto:support@altasfarm.com" style="color:rgba(255,255,255,.75);">support@altasfarm.com</a>
            <br>
            Mon–Sat, 8 AM–6 PM PST
          </div>

          <address
            itemprop="address"
            itemscope
            itemtype="https://schema.org/PostalAddress"
            style="font-style:normal;font-size:.82rem;color:rgba(255,255,255,.45);margin-top:1rem;line-height:1.7;"
          >
            <span itemprop="streetAddress">Rang-ay</span>,
            <span itemprop="addressLocality">Cabatuan</span>,
            <span itemprop="addressRegion">Isabela</span>
            <span itemprop="postalCode">3315</span><br>
            <span itemprop="addressCountry">Philippines</span>
          </address>

          <div class="footer-social" aria-label="Social media links">
            <a
              href="https://www.facebook.com/altasfarm"
              target="_blank"
              rel="noopener"
              aria-label="Facebook"
              title="<?= e($siteName) ?> on Facebook"
            >f</a>
            <?php if ($telegramUrl): ?>
              <a
                href="<?= e($telegramUrl) ?>"
                target="_blank"
                rel="noopener"
                aria-label="Telegram"
                title="<?= e($siteName) ?> on Telegram"
              >✈</a>
            <?php endif; ?>
            <a
              href="mailto:support@altasfarm.com"
              aria-label="Email Support"
              title="Email support@altasfarm.com"
            >✉</a>
          </div>
        </div>

        <div>
          <div class="footer-col-title">Farm</div>
          <ul class="footer-links">
            <li><a href="#farm">Our Farm</a></li>
            <li><a href="#products">Products &amp; Services</a></li>
            <li><a href="#business">Business Model</a></li>
            <li><a href="#transparency">Transparency</a></li>
          </ul>
        </div>

        <div>
          <div class="footer-col-title">Partners</div>
          <ul class="footer-links">
            <li><a href="#partners">Partner Program</a></li>
            <li><a href="#packages">Packages</a></li>
            <li><a href="#partner-faq">Partner FAQ</a></li>
            <li><a href="<?= $base ?>/?page=login">Member Login</a></li>
            <li><a href="<?= $base ?>/?page=register">Partner Registration</a></li>
          </ul>
        </div>

        <div>
          <div class="footer-col-title">Support &amp; Legal</div>
          <ul class="footer-links">
            <li><a href="#" onclick="openModal('modal-faq');return false;">FAQ</a></li>
            <li><a href="#" onclick="openModal('modal-tos');return false;">Terms of Service</a></li>
            <li><a href="#" onclick="openModal('modal-privacy');return false;">Privacy Policy</a></li>
            <li><a href="#" onclick="openModal('modal-compliance');return false;">Compliance</a></li>
            <li><a href="#" onclick="openModal('modal-contact');return false;">Contact</a></li>
          </ul>
        </div>
      </div>

      <div class="footer-bottom">
        <div class="footer-copy">
          © 2024–<script>document.write(new Date().getFullYear())</script>
          <?= e($siteName) ?> · All rights reserved · Philippines 🇵🇭
        </div>
        <div class="footer-legal">
          <a href="#" onclick="openModal('modal-privacy');return false;">Privacy</a>
          <a href="#" onclick="openModal('modal-tos');return false;">Terms</a>
          <a href="#" onclick="openModal('modal-compliance');return false;">Compliance</a>
        </div>
      </div>
    </div>
  </footer>

  <button id="backToTop" aria-label="Back to Top">↑</button>

  <script src="<?= $frontend ?>/script.js?v=<?= $scriptV ?>"></script>

  <script>
    window.PKG_DETAILS = <?= json_encode(
      $planFacts,
      JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>;

    window.PKG_GLOBALS = <?= json_encode(
      [
        'site_name' => $siteName,
        'min_payout' => $minPayout,
      ],
      JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>;

    function toggleHomeFaq(button) {
      const item = button.closest('.af-faq-item');
      if (!item) return;

      const wasOpen = item.classList.contains('open');

      document.querySelectorAll('.af-faq-item.open').forEach(function (node) {
        node.classList.remove('open');
        const icon = node.querySelector('.af-faq-icon');
        if (icon) icon.textContent = '+';
      });

      if (!wasOpen) {
        item.classList.add('open');
        const icon = item.querySelector('.af-faq-icon');
        if (icon) icon.textContent = '−';
      }
    }

    (function () {
      const mobileToggle = document.querySelector('.nav-mobile-toggle');

      if (mobileToggle) {
        document.addEventListener('click', function () {
          mobileToggle.setAttribute(
            'aria-expanded',
            document.getElementById('mobileMenu')?.classList.contains('open') ? 'true' : 'false'
          );
        });
      }

      function syncHeaderH() {
        const hdr = document.querySelector('.site-header');
        if (hdr) {
          document.documentElement.style.setProperty('--header-h', hdr.offsetHeight + 'px');
        }
      }

      syncHeaderH();
      window.addEventListener('resize', syncHeaderH);

      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(syncHeaderH);
      }
    })();
  </script>

</body>
</html>
