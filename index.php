<?php
require_once __DIR__ . '/includes/app.php';

if (isset($_GET['lang'])) {
    $requestedLanguage = strtolower(trim((string) $_GET['lang']));
    if (in_array($requestedLanguage, ['id', 'en'], true)) {
        $_SESSION['landing_language'] = $requestedLanguage;
    }
}
$language = $_SESSION['landing_language'] ?? 'id';
if (!in_array($language, ['id', 'en'], true)) {
    $language = 'id';
}
$translations = require __DIR__ . '/includes/landing_translations.php';
$t = static function (string $key) use ($translations, $language): string {
    return $translations[$language][$key] ?? $translations['id'][$key] ?? $key;
};

if (isset($_GET['slot_data']) && $_GET['slot_data'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        $liveAreas = db('petugas')->query('SELECT id_area, nama_area, kapasitas, terisi FROM tb_area_parkir ORDER BY nama_area')->fetchAll();
        echo json_encode(['areas' => $liveAreas], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (RuntimeException $exception) {
        error_log('Gagal memperbarui slot parkir: ' . $exception->getMessage());
        http_response_code(503);
        echo json_encode(['error' => $t('js.loading_error')], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (empty($_SESSION['id_user']) || empty($_SESSION['role'])) {
    $areaLoadError = false;
    try {
        $areas = db('petugas')->query('SELECT id_area, nama_area, kapasitas, terisi FROM tb_area_parkir ORDER BY nama_area')->fetchAll();
    } catch (RuntimeException $exception) {
        error_log('Gagal memuat area parkir untuk landing page: ' . $exception->getMessage());
        $areas = [];
        $areaLoadError = true;
    }
    $tariffLoadError = false;
    try {
        $tariffs = db('petugas')->query(
            'SELECT jenis_kendaraan, tarif_per_jam
             FROM tb_tarif
             ORDER BY id_tarif'
        )->fetchAll();
    } catch (RuntimeException $exception) {
        error_log('Gagal memuat tarif parkir untuk landing page: ' . $exception->getMessage());
        $tariffs = [];
        $tariffLoadError = true;
    }
    $supportEmail = 'cs@parkir-app.com';
    $supportPhone = '+62 812-0000-0000';
    $supportWhatsapp = preg_replace('/\D+/', '', $supportPhone);
    ?>
    <!doctype html>
    <html lang="<?= e($language) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#ffffff">
        <meta name="description" content="<?= e($t('hero.description')) ?>">
        <title>PARKIR-APP — <?= e($t('hero.heading_before')) ?> Parking Solution</title>
        <link rel="icon" type="image/svg+xml" href="<?= e(app_url('assets/favicon.svg')) ?>">
        <link rel="alternate icon" href="<?= e(app_url('assets/favicon.svg')) ?>">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            :root { --red: #e50914; --red-dark: #bd0710; --ink: #191b20; --muted: #747983; }
            html { scroll-behavior: smooth; scroll-padding-top: 86px; }
            body { margin: 0; color: var(--ink); font-family: Inter, Arial, sans-serif; }
            a { text-decoration: none; }
            .site-header { position: sticky; top: 0; z-index: 1030; border-bottom: 1px solid rgba(28,31,38,.07); background: rgba(255,255,255,.96); backdrop-filter: blur(14px); }
            .navbar { min-height: 78px; }
            .brand { display: inline-flex; align-items: center; gap: 9px; color: var(--ink); }
            .brand:hover { color: var(--ink); }
            .brand-pin { color: var(--red); font-size: 1.8rem; line-height: 1; }
            .brand-name { font-size: 1.13rem; font-weight: 800; letter-spacing: -.055em; }
            .brand-name span { color: var(--red); }
            .nav-center { gap: 30px; }
            .nav-center .nav-link { padding: 10px 0; color: #5e626b; font-size: .84rem; font-weight: 600; transition: color .2s ease; }
            .nav-center .nav-link:hover { color: var(--red); }
            .btn { border-radius: 8px; font-size: .82rem; font-weight: 700; transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease, color .2s ease; }
            .btn:hover { transform: translateY(-1px); }
            .btn-red { border: 1px solid var(--red); color: #fff; background: var(--red); box-shadow: 0 7px 18px rgba(229,9,20,.2); }
            .btn-red:hover { border-color: var(--red-dark); color: #fff; background: var(--red-dark); box-shadow: 0 9px 22px rgba(229,9,20,.27); }
            .language-button { border: 0; color: #4f535b; font-size: .8rem; font-weight: 700; }
            .language-button i { color: var(--red); }
            .language-menu { min-width: 178px; }
            .dropdown-menu { min-width: 110px; padding: 6px; border: 1px solid #eee; border-radius: 10px; box-shadow: 0 12px 30px rgba(22,25,32,.12); }
            .dropdown-item { border-radius: 6px; font-size: .82rem; }
            .dropdown-item:active { background: var(--red); }
            .navbar-toggler { border: 1px solid #e7e8ea; border-radius: 8px; }
            .hero {
                position: relative; min-height: min(740px,calc(100vh - 78px)); display: flex; align-items: center;
                overflow: hidden; color: #fff;
                background: #18212e url("https://images.unsplash.com/photo-1519501025264-65ba15a82390?auto=format&fit=crop&w=2200&q=85") center 54% / cover no-repeat;
                isolation: isolate;
            }
            .hero::before { position: absolute; z-index: -1; inset: 0; content: ""; background: linear-gradient(90deg,rgba(12,16,23,.88) 0%,rgba(12,16,23,.75) 42%,rgba(12,16,23,.33) 100%),linear-gradient(0deg,rgba(12,16,23,.27),rgba(12,16,23,.12)); }
            .hero-content { max-width: 790px; padding: 28px 0 48px; }
            .hero-kicker { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 23px; padding: 9px 13px; border: 1px solid rgba(255,255,255,.22); border-radius: 100px; color: rgba(255,255,255,.9); background: rgba(255,255,255,.09); font-size: .73rem; font-weight: 600; letter-spacing: .025em; backdrop-filter: blur(8px); }
            .hero-kicker i { color: #ff5860; }
            .hero h1 { max-width: 800px; margin: 0; color: #fff; font-size: clamp(2.65rem,5.55vw,4.8rem); font-weight: 800; letter-spacing: -.065em; line-height: 1.08; }
            .hero h1 .accent { color: #ff3944; }
            .hero-copy { max-width: 625px; margin: 23px 0 0; color: rgba(255,255,255,.8); font-size: clamp(.98rem,1.4vw,1.1rem); line-height: 1.8; }
            .hero-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 31px; }
            .hero-actions .btn { min-height: 51px; display: inline-flex; align-items: center; justify-content: center; gap: 9px; padding: 0 21px; }
            .btn-hero-outline { border: 1px solid rgba(255,255,255,.72); color: #fff; background: rgba(255,255,255,.06); }
            .btn-hero-outline:hover { border-color: #fff; color: var(--ink); background: #fff; }
            .hero-note { display: flex; flex-wrap: wrap; gap: 21px; margin-top: 34px; color: rgba(255,255,255,.82); font-size: .77rem; font-weight: 500; }
            .hero-note span { display: inline-flex; align-items: center; gap: 7px; }
            .hero-note i { color: #75e4b1; }
            .hero-scroll { position: absolute; right: max(25px,calc((100vw - 1140px)/2)); bottom: 32px; color: rgba(255,255,255,.8); font-size: .72rem; font-weight: 600; letter-spacing: .11em; text-transform: uppercase; writing-mode: vertical-rl; }
            .hero-scroll::after { width: 1px; height: 40px; display: inline-block; margin-top: 10px; content: ""; background: rgba(255,255,255,.55); }
            .trust-strip { padding: 22px 0; border-bottom: 1px solid #eceef1; background: #fff; }
            .trust-items { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 16px 40px; color: #737984; font-size: .79rem; font-weight: 600; }
            .trust-items span { display: inline-flex; align-items: center; gap: 8px; }
            .trust-items i { color: var(--red); font-size: 1rem; }
            .section-space { padding: 90px 0; }
            .section-eyebrow { margin-bottom: 11px; color: var(--red); font-size: .71rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
            .section-heading { max-width: 700px; margin: 0; color: #1c2027; font-size: clamp(1.9rem,3.8vw,3rem); font-weight: 800; letter-spacing: -.055em; line-height: 1.14; }
            .section-copy { max-width: 680px; margin: 15px 0 0; color: var(--muted); line-height: 1.8; }
            .about-panel { padding: clamp(25px,4vw,42px); border: 1px solid #eceef1; border-radius: 18px; background: #fff; box-shadow: 0 14px 40px rgba(29,32,38,.045); }
            .about-panel h3 { margin: 0 0 10px; font-size: 1.04rem; font-weight: 750; }
            .about-panel p { margin: 0; color: var(--muted); font-size: .88rem; line-height: 1.8; }
            .about-mark { width: 46px; height: 46px; display: grid; place-items: center; margin-bottom: 19px; border-radius: 13px; color: var(--red); background: #fff0f1; font-size: 1.2rem; }
            .section-soft { background: #f8f9fb; }
            .feature-card { height: 100%; padding: 26px; border: 1px solid #eceef1; border-radius: 14px; background: #fff; transition: transform .2s ease,box-shadow .2s ease,border-color .2s ease; }
            .feature-card:hover { transform: translateY(-4px); border-color: #f2c6c9; box-shadow: 0 16px 38px rgba(29,32,38,.08); }
            .feature-icon { width: 48px; height: 48px; display: grid; place-items: center; margin-bottom: 20px; border-radius: 13px; color: var(--red); background: #fff0f1; font-size: 1.25rem; }
            .feature-card h3 { margin-bottom: 9px; font-size: 1rem; font-weight: 750; }
            .feature-card p { margin: 0; color: var(--muted); font-size: .86rem; line-height: 1.75; }
            .area-card { height: 100%; padding: 24px; border: 1px solid #eceef1; border-radius: 14px; background: #fff; }
            .area-icon { width: 44px; height: 44px; display: grid; place-items: center; border-radius: 12px; color: #245d9c; background: #edf4ff; font-size: 1.15rem; }
            .area-icon.motor { color: #9a6500; background: #fff6df; }
            .area-icon.mobil { color: #245d9c; background: #edf4ff; }
            .area-capacity { color: #222c3c; font-size: 1.5rem; font-weight: 800; letter-spacing: -.04em; }
            .area-meta { color: var(--muted); font-size: .8rem; }
            .slot-track { height: 7px; overflow: hidden; border-radius: 20px; background: #edf0f3; }
            .slot-progress { height: 100%; border-radius: inherit; background: #19a877; }
            .slot-progress.full { background: var(--red); }
            .tariff-card { position: relative; height: 100%; padding: 28px; border: 1px solid #e8ebef; border-radius: 18px; background: #fff; box-shadow: 0 14px 40px rgba(29,32,38,.045); transition: transform .2s ease,box-shadow .2s ease,border-color .2s ease; }
            .tariff-card:hover { transform: translateY(-4px); border-color: #f2c6c9; box-shadow: 0 18px 42px rgba(29,32,38,.09); }
            .tariff-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
            .tariff-icon { width: 48px; height: 48px; display: grid; place-items: center; border-radius: 14px; color: var(--red); background: #fff0f1; font-size: 1.3rem; }
            .tariff-badge { padding: 7px 10px; border-radius: 999px; color: #a80d17; background: #fff0f1; font-size: .68rem; font-weight: 750; }
            .tariff-name { margin: 22px 0 8px; color: #222833; font-size: 1.13rem; font-weight: 750; }
            .tariff-price { display: flex; align-items: baseline; flex-wrap: wrap; gap: 5px; color: var(--red); }
            .tariff-price strong { font-size: clamp(1.65rem,3vw,2rem); font-weight: 800; letter-spacing: -.055em; }
            .tariff-price span { color: #747983; font-size: .82rem; font-weight: 600; }
            .tariff-description { min-height: 48px; margin: 13px 0 0; color: var(--muted); font-size: .83rem; line-height: 1.7; }
            .tariff-detail { display: flex; align-items: flex-start; gap: 9px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #edf0f3; color: #656d79; font-size: .76rem; line-height: 1.6; }
            .tariff-detail i { margin-top: 2px; color: #15966d; }
            .location-panel { height: 100%; padding: 28px; border: 1px solid #eceef1; border-radius: 16px; background: #fff; }
            .location-feature { display: flex; align-items: center; gap: 11px; color: #586273; font-size: .84rem; }
            .location-feature i { color: var(--red); font-size: 1.05rem; }
            .contact-panel { padding: clamp(22px,4vw,38px); border: 1px solid #eceef1; border-radius: 16px; background: #fff; box-shadow: 0 12px 35px rgba(29,32,38,.05); }
            .contact-item { display: flex; align-items: flex-start; gap: 13px; margin-bottom: 22px; }
            .contact-icon { width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center; border-radius: 12px; color: var(--red); background: #fff0f1; }
            .contact-item strong { display: block; margin: 1px 0 4px; font-size: .86rem; }
            .contact-item span, .contact-item a { color: var(--muted); font-size: .82rem; }
            .form-label { color: #465165; font-size: .8rem; font-weight: 650; }
            .form-control { min-height: 44px; border-color: #e1e5eb; border-radius: 9px; font-size: .87rem; }
            textarea.form-control { min-height: 125px; resize: vertical; }
            .form-control:focus { border-color: #ed8188; box-shadow: 0 0 0 .2rem rgba(229,9,20,.1); }
            .contact-note { color: #8a919c; font-size: .72rem; line-height: 1.6; }
            .site-footer { color: #c4cad4; background: #1d2736; font-size: .82rem; }
            .footer-main { padding: 68px 0 58px; }
            .footer-brand { color: #fff; }
            .footer-brand:hover { color: #fff; }
            .footer-brand .brand-name { color: #fff; }
            .footer-description { max-width: 340px; margin: 18px 0 21px; color: #aeb8c6; font-size: .86rem; line-height: 1.8; }
            .footer-socials { display: flex; flex-wrap: wrap; gap: 9px; }
            .footer-socials a { width: 37px; height: 37px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,.15); border-radius: 10px; color: #e7ebf1; background: rgba(255,255,255,.045); font-size: 1rem; transition: color .2s ease,background .2s ease,border-color .2s ease,transform .2s ease; }
            .footer-socials a:hover { transform: translateY(-2px); border-color: var(--red); color: #fff; background: var(--red); }
            .footer-heading { margin: 4px 0 20px; color: #fff; font-size: .87rem; font-weight: 750; letter-spacing: .01em; }
            .footer-links { display: grid; gap: 13px; margin: 0; padding: 0; list-style: none; }
            .footer-links a { color: #b7c0cc; font-size: .8rem; line-height: 1.55; transition: color .2s ease; }
            .footer-links a:hover { color: #fff; }
            .footer-contact-link { display: flex; align-items: flex-start; gap: 9px; }
            .footer-contact-link i { margin-top: 2px; color: #ff5860; font-size: .95rem; }
            .footer-regulation { display: inline-flex; gap: 7px; margin-top: 19px; color: #aeb8c6 !important; font-size: .74rem !important; line-height: 1.55; }
            .footer-regulation i { flex: 0 0 auto; color: #ff5860; }
            .site-subfooter { border-top: 1px solid rgba(255,255,255,.1); background: #141c28; }
            .subfooter-inner { min-height: 74px; display: flex; align-items: center; justify-content: space-between; gap: 18px; padding-top: 12px; padding-bottom: 12px; }
            .subfooter-inner p { margin: 0; color: #9ca8b8; font-size: .75rem; }
            .subfooter-actions { display: flex; align-items: center; gap: 17px; }
            .subfooter-actions > a:not(.footer-login) { color: #aeb8c6; font-size: .76rem; }
            .subfooter-actions > a:not(.footer-login):hover { color: #fff; }
            .footer-login { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid var(--red); border-radius: 8px; color: #fff !important; background: var(--red); font-size: .76rem; font-weight: 700; transition: background .2s ease,border-color .2s ease,transform .2s ease; }
            .footer-login:hover { transform: translateY(-1px); border-color: var(--red-dark); color: #fff; background: var(--red-dark); }
            @media (max-width: 991.98px) {
                .navbar { min-height: 70px; }
                .navbar-collapse { padding: 13px 0 18px; }
                .nav-center { gap: 0; padding: 7px 0 14px; }
                .nav-center .nav-link { padding: 10px 0; }
                .nav-actions { align-items: flex-start !important; gap: 9px !important; }
                .hero { min-height: 680px; background-position: 60% center; }
                .hero::before { background: linear-gradient(90deg,rgba(12,16,23,.88),rgba(12,16,23,.61)),linear-gradient(0deg,rgba(12,16,23,.35),transparent); }
            }
            @media (max-width: 575.98px) {
                .hero { min-height: min(740px,calc(100svh - 70px)); background-position: 58% center; }
                .hero::before { background: linear-gradient(90deg,rgba(12,16,23,.83),rgba(12,16,23,.62)),linear-gradient(0deg,rgba(12,16,23,.36),transparent); }
                .hero-content { padding: 18px 0 48px; }
                .hero h1 { font-size: clamp(2.55rem,12vw,3.6rem); }
                .hero-copy { font-size: .95rem; line-height: 1.7; }
                .hero-actions { align-items: stretch; flex-direction: column; }
                .hero-actions .btn { width: 100%; }
                .hero-note { gap: 12px 18px; margin-top: 25px; font-size: .72rem; }
                .hero-scroll { display: none; }
                .section-space { padding: 67px 0; }
                .trust-items { justify-content: flex-start; gap: 13px 22px; font-size: .73rem; }
                .feature-card,.area-card { padding: 22px; }
                .footer-main.container { padding-right: 24px; padding-left: 24px; }
                .footer-main { padding: 49px 0 42px; }
                .footer-heading { margin-bottom: 15px; }
                .subfooter-inner { align-items: flex-start; flex-direction: column; justify-content: center; gap: 13px; padding-top: 19px; padding-bottom: 19px; }
                .subfooter-actions { width: 100%; flex-wrap: wrap; justify-content: space-between; gap: 12px; }
            }
            @media (prefers-reduced-motion: reduce) {
                *,*::before,*::after { scroll-behavior: auto !important; transition-duration: .01ms !important; }
            }
        </style>
    </head>
    <body>
        <header class="site-header">
            <nav class="navbar navbar-expand-lg" aria-label="<?= e($t('nav.primary')) ?>">
                <div class="container">
                    <a class="brand" href="#beranda" aria-label="<?= e($t('brand.home')) ?>">
                        <i class="bi bi-geo-alt-fill brand-pin" aria-hidden="true"></i><span class="brand-name">PARKIR<span>-APP</span></span>
                    </a>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="<?= e($t('nav.menu')) ?>">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="mainNavigation">
                        <ul class="navbar-nav nav-center mx-auto">
                            <li class="nav-item"><a class="nav-link" href="#tentang-kami"><?= e($t('nav.about')) ?></a></li>
                            <li class="nav-item"><a class="nav-link" href="#layanan"><?= e($t('nav.services')) ?></a></li>
                            <li class="nav-item"><a class="nav-link" href="#tarif"><?= e($t('nav.pricing')) ?></a></li>
                            <li class="nav-item"><a class="nav-link" href="#slot-parkir"><?= e($t('nav.slots')) ?></a></li>
                            <li class="nav-item"><a class="nav-link" href="#lokasi"><?= e($t('nav.location')) ?></a></li>
                            <li class="nav-item"><a class="nav-link" href="#kontak"><?= e($t('nav.contact')) ?></a></li>
                        </ul>
                        <div class="nav-actions d-flex align-items-center gap-2">
                            <div class="dropdown">
                                <button class="btn language-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e($t('language.choose')) ?>"><i class="bi bi-globe2 me-1" aria-hidden="true"></i><?= strtoupper(e($language)) ?></button>
                                <ul class="dropdown-menu dropdown-menu-end language-menu">
                                    <li><a class="dropdown-item <?= $language === 'id' ? 'active' : '' ?>" href="<?= e(app_url('index.php?lang=id')) ?>" lang="id"><?= e($t('language.indonesian')) ?></a></li>
                                    <li><a class="dropdown-item <?= $language === 'en' ? 'active' : '' ?>" href="<?= e(app_url('index.php?lang=en')) ?>" lang="en"><?= e($t('language.english')) ?></a></li>
                                </ul>
                            </div>
                            <a class="btn btn-red px-3 py-2" href="login.php"><?= e($t('nav.login')) ?></a>
                        </div>
                    </div>
                </div>
            </nav>
        </header>
        <main>
            <section class="hero" id="beranda">
                <div class="container"><div class="hero-content">
                    <div class="hero-kicker"><i class="bi bi-stars" aria-hidden="true"></i> <?= e($t('hero.kicker')) ?></div>
                    <h1><?= e($t('hero.heading_before')) ?> <span class="accent">Parking Solution</span> <?= e($t('hero.heading_after')) ?></h1>
                    <p class="hero-copy"><?= e($t('hero.description')) ?></p>
                    <div class="hero-actions">
                        <a class="btn btn-red" href="login.php"><?= e($t('hero.login')) ?> <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i></a>
                        <a class="btn btn-hero-outline" href="#slot-parkir"><?= e($t('hero.view_slots')) ?> <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
                    </div>
                    <div class="hero-note" aria-label="<?= e($t('hero.benefit_label')) ?>">
                        <span><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?= e($t('hero.benefit_data')) ?></span>
                        <span><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?= e($t('hero.benefit_capacity')) ?></span>
                        <span><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?= e($t('hero.benefit_easy')) ?></span>
                    </div>
                </div></div>
                <a class="hero-scroll" href="#tentang-kami" aria-label="<?= e($t('hero.scroll_about')) ?>"><?= e($t('hero.explore')) ?></a>
            </section>
            <section class="trust-strip" aria-label="<?= e($t('trust.label')) ?>">
                <div class="container"><div class="trust-items">
                    <span><i class="bi bi-person-badge" aria-hidden="true"></i> <?= e($t('trust.admin')) ?></span>
                    <span><i class="bi bi-person-workspace" aria-hidden="true"></i> <?= e($t('trust.staff')) ?></span>
                    <span><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> <?= e($t('trust.owner')) ?></span>
                    <span><i class="bi bi-shield-check" aria-hidden="true"></i> <?= e($t('trust.audit')) ?></span>
                </div></div>
            </section>
            <section class="section-space" id="tentang-kami">
                <div class="container">
                    <div class="row align-items-end g-4 mb-4">
                        <div class="col-lg-7">
                            <div class="section-eyebrow"><?= e($t('about.eyebrow')) ?></div>
                            <h2 class="section-heading"><?= e($t('about.heading')) ?></h2>
                        </div>
                        <div class="col-lg-5"><p class="section-copy mt-0"><?= e($t('about.description')) ?></p></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4"><article class="about-panel h-100">
                            <span class="about-mark"><i class="bi bi-buildings"></i></span>
                            <h3><?= e($t('about.profile_title')) ?></h3><p><?= e($t('about.profile')) ?></p>
                        </article></div>
                        <div class="col-md-4"><article class="about-panel h-100">
                            <span class="about-mark"><i class="bi bi-eye"></i></span>
                            <h3><?= e($t('about.vision_title')) ?></h3><p><?= e($t('about.vision')) ?></p>
                        </article></div>
                        <div class="col-md-4"><article class="about-panel h-100">
                            <span class="about-mark"><i class="bi bi-lightning-charge"></i></span>
                            <h3><?= e($t('about.strength_title')) ?></h3><p><?= e($t('about.strength')) ?></p>
                        </article></div>
                    </div>
                </div>
            </section>
            <section class="section-space section-soft" id="layanan">
                <div class="container">
                    <div class="text-center mb-5">
                        <div class="section-eyebrow"><?= e($t('services.eyebrow')) ?></div>
                        <h2 class="section-heading mx-auto"><?= e($t('services.heading')) ?></h2>
                        <p class="section-copy mx-auto"><?= e($t('services.description')) ?></p>
                    </div>
                    <div class="row g-3 g-lg-4">
                        <div class="col-sm-6 col-lg-3"><article class="feature-card">
                            <span class="feature-icon"><i class="bi bi-arrow-left-right"></i></span>
                            <h3><?= e($t('services.transaction_title')) ?></h3><p><?= e($t('services.transaction')) ?></p>
                        </article></div>
                        <div class="col-sm-6 col-lg-3"><article class="feature-card">
                            <span class="feature-icon"><i class="bi bi-printer"></i></span>
                            <h3><?= e($t('services.receipt_title')) ?></h3><p><?= e($t('services.receipt')) ?></p>
                        </article></div>
                        <div class="col-sm-6 col-lg-3"><article class="feature-card">
                            <span class="feature-icon"><i class="bi bi-bar-chart-line"></i></span>
                            <h3><?= e($t('services.report_title')) ?></h3><p><?= e($t('services.report')) ?></p>
                        </article></div>
                        <div class="col-sm-6 col-lg-3"><article class="feature-card">
                            <span class="feature-icon"><i class="bi bi-journal-check"></i></span>
                            <h3><?= e($t('services.audit_title')) ?></h3><p><?= e($t('services.audit')) ?></p>
                        </article></div>
                    </div>
                </div>
            </section>
            <section class="section-space section-soft" id="tarif">
                <div class="container">
                    <div class="row align-items-end g-3 mb-4">
                        <div class="col-lg-8">
                            <div class="section-eyebrow"><?= e($t('tariff.eyebrow')) ?></div>
                            <h2 class="section-heading"><?= e($t('tariff.heading')) ?></h2>
                            <p class="section-copy"><?= e($t('tariff.description')) ?></p>
                        </div>
                        <div class="col-lg-4 text-lg-end">
                            <span class="badge rounded-pill text-bg-light px-3 py-2"><?= count($tariffs) ?> <?= e($t('tariff.count')) ?></span>
                        </div>
                    </div>
                    <?php if ($tariffs): ?>
                        <div class="row g-3 g-lg-4">
                            <?php foreach ($tariffs as $tariff):
                                $vehicleType = (string) $tariff['jenis_kendaraan'];
                                $vehicleKey = strtolower($vehicleType);
                                if ($vehicleKey === 'motor') {
                                    $vehicleLabel = $t('tariff.motor');
                                    $vehicleIcon = 'bi-bicycle';
                                    $vehicleDescription = $t('tariff.motor_description');
                                } elseif ($vehicleKey === 'mobil') {
                                    $vehicleLabel = $t('tariff.car');
                                    $vehicleIcon = 'bi-car-front-fill';
                                    $vehicleDescription = $t('tariff.car_description');
                                } else {
                                    $vehicleLabel = ucfirst($vehicleType);
                                    $vehicleIcon = 'bi-truck-front-fill';
                                    $vehicleDescription = sprintf($t('tariff.other_description'), $vehicleLabel);
                                }
                                ?>
                                <div class="col-md-6 col-lg-4">
                                    <article class="tariff-card">
                                        <div class="tariff-card-head">
                                            <span class="tariff-icon"><i class="bi <?= e($vehicleIcon) ?>" aria-hidden="true"></i></span>
                                            <span class="tariff-badge"><?= e($t('tariff.standard')) ?></span>
                                        </div>
                                        <h3 class="tariff-name"><?= e($vehicleLabel) ?></h3>
                                        <div class="tariff-price">
                                            <strong><?= e(format_rupiah($tariff['tarif_per_jam'])) ?></strong>
                                            <span><?= e($t('tariff.per_hour')) ?></span>
                                        </div>
                                        <p class="tariff-description"><?= e($vehicleDescription) ?></p>
                                        <div class="tariff-detail">
                                            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                                            <span><?= e($t('tariff.rule')) ?></span>
                                        </div>
                                    </article>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="contact-panel text-center text-muted">
                            <?php if ($tariffLoadError): ?>
                                <i class="bi bi-exclamation-circle me-1"></i> <?= e($t('tariff.load_error')) ?>
                            <?php else: ?>
                                <i class="bi bi-info-circle me-1"></i> <?= e($t('tariff.empty')) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="section-space" id="slot-parkir">
                <div class="container">
                    <div class="row align-items-end g-3 mb-4">
                        <div class="col-lg-8">
                            <div class="section-eyebrow"><?= e($t('slots.eyebrow')) ?></div>
                            <h2 class="section-heading"><?= e($t('slots.heading')) ?></h2>
                            <p class="section-copy"><?= e($t('slots.description')) ?></p>
                        </div>
                        <div class="col-lg-4 text-lg-end">
                            <span class="badge rounded-pill text-bg-light px-3 py-2"><?= count($areas) ?> <?= e($t('slots.area_count')) ?></span>
                            <div class="area-meta mt-2" id="parkingRefreshStatus" role="status"><?= e($t('slots.refreshing')) ?></div>
                        </div>
                    </div>
                    <div class="row g-3" id="parkingAreaGrid">
                        <?php foreach ($areas as $area):
                            $capacity = max(0, (int) $area['kapasitas']);
                            $occupied = max(0, (int) $area['terisi']);
                            $available = max(0, $capacity - $occupied);
                            $occupancy = $capacity > 0 ? min(100, (int) round(($occupied / $capacity) * 100)) : 0;
                            $areaName = (string) $area['nama_area'];
                            $areaType = stripos($areaName, 'motor') !== false ? 'Motor' : (stripos($areaName, 'mobil') !== false ? 'Mobil' : 'Umum');
                            ?>
                            <div class="col-sm-6 col-lg-4">
                                <article class="area-card">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <span class="area-icon <?= $areaType === 'Motor' ? 'motor' : 'mobil' ?>"><i class="bi <?= $areaType === 'Motor' ? 'bi-bicycle' : ($areaType === 'Mobil' ? 'bi-car-front-fill' : 'bi-truck-front-fill') ?>"></i></span>
                                        <span class="badge <?= $available > 0 ? 'text-bg-success' : 'text-bg-danger' ?>"><?= e($t($available > 0 ? 'slots.available' : 'slots.full')) ?></span>
                                    </div>
                                    <h3 class="h5 mt-3 mb-1 fw-bold"><?= e($areaName) ?></h3>
                                    <?php
                                    $localizedAreaType = $areaType === 'Motor'
                                        ? $t('tariff.motor')
                                        : ($areaType === 'Mobil' ? $t('tariff.car') : $t('tariff.other'));
                                    ?>
                                    <div class="area-meta mb-3"><?= e(sprintf($t('slots.parking_type'), $localizedAreaType)) ?></div>
                                    <div class="d-flex align-items-end justify-content-between mb-2">
                                        <span class="area-capacity"><?= $available ?> <span class="area-meta"><?= e($t('slots.empty_count')) ?></span></span>
                                        <span class="area-meta"><?= e(sprintf($t('slots.occupied_of'), $occupied, $capacity)) ?></span>
                                    </div>
                                    <div class="slot-track" role="progressbar" aria-label="<?= e(sprintf($t('js.capacity'), $areaName)) ?>" aria-valuenow="<?= $occupancy ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="slot-progress <?= $available === 0 ? 'full' : '' ?>" style="width: <?= $occupancy ?>%"></div>
                                    </div>
                                    <div class="area-meta mt-2"><?= e(sprintf($t('slots.percent_full'), $occupancy)) ?></div>
                                </article>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$areas): ?>
                            <div class="col-12"><div class="contact-panel text-center text-muted">
                                <?php if ($areaLoadError): ?>
                                    <i class="bi bi-exclamation-circle me-1"></i> <?= e($t('slots.load_error')) ?>
                                <?php else: ?>
                                    <i class="bi bi-info-circle me-1"></i> <?= e($t('slots.empty')) ?>
                                <?php endif; ?>
                            </div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <section class="section-space section-soft" id="lokasi">
                <div class="container">
                    <div class="row align-items-end g-4 mb-4">
                        <div class="col-lg-8">
                            <div class="section-eyebrow"><?= e($t('location.eyebrow')) ?></div>
                            <h2 class="section-heading"><?= e($t('location.heading')) ?></h2>
                            <p class="section-copy"><?= e($t('location.description')) ?></p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <div class="location-panel">
                                <span class="about-mark"><i class="bi bi-geo-alt-fill"></i></span>
                                <h3 class="h5 fw-bold mb-2"><?= e($t('location.operations_title')) ?></h3>
                                <p class="section-copy mt-0"><?= e($t('location.operations_description')) ?></p>
                                <a class="btn btn-outline-danger mt-3" href="#kontak"><i class="bi bi-map me-1"></i><?= e($t('location.directions')) ?></a>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="location-panel">
                                <h3 class="h5 fw-bold mb-4"><?= e($t('location.facilities_title')) ?></h3>
                                <div class="d-grid gap-3">
                                    <div class="location-feature"><i class="bi bi-signpost-2-fill"></i><span><?= e($t('location.signage')) ?></span></div>
                                    <div class="location-feature"><i class="bi bi-person-check-fill"></i><span><?= e($t('location.staff')) ?></span></div>
                                    <div class="location-feature"><i class="bi bi-receipt-cutoff"></i><span><?= e($t('location.receipt')) ?></span></div>
                                    <div class="location-feature"><i class="bi bi-shield-check"></i><span><?= e($t('location.integrated')) ?></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <section class="section-space section-soft" id="kontak">
                <div class="container">
                    <div class="text-center mb-5">
                        <div class="section-eyebrow"><?= e($t('contact.eyebrow')) ?></div>
                        <h2 class="section-heading mx-auto"><?= e($t('contact.heading')) ?></h2>
                        <p class="section-copy mx-auto"><?= e($t('contact.description')) ?></p>
                    </div>
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="contact-panel h-100">
                                <h3 class="h5 fw-bold mb-4"><?= e($t('contact.info_title')) ?></h3>
                                <div class="contact-item"><span class="contact-icon"><i class="bi bi-geo-alt"></i></span><div><strong><?= e($t('contact.address')) ?></strong><span><?= str_replace('&lt;br&gt;', '<br>', e($t('contact.address_value'))) ?></span></div></div>
                                <div class="contact-item"><span class="contact-icon"><i class="bi bi-envelope"></i></span><div><strong><?= e($t('contact.email')) ?></strong><a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></div></div>
                                <div class="contact-item mb-0"><span class="contact-icon"><i class="bi bi-telephone"></i></span><div><strong><?= e($t('contact.phone')) ?></strong><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $supportPhone)) ?>"><?= e($supportPhone) ?></a></div></div>
                                <p class="contact-note mt-4 mb-0"><?= e($t('contact.note')) ?></p>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <div class="contact-panel">
                                <h3 class="h5 fw-bold mb-4"><?= e($t('contact.message_title')) ?></h3>
                                <form id="contactForm" action="mailto:<?= e($supportEmail) ?>" method="post" enctype="text/plain">
                                    <div class="row g-3">
                                        <div class="col-md-6"><label class="form-label" for="contactName"><?= e($t('contact.name')) ?></label><input class="form-control" id="contactName" name="Nama" autocomplete="name" required></div>
                                        <div class="col-md-6"><label class="form-label" for="contactEmail"><?= e($t('contact.your_email')) ?></label><input class="form-control" id="contactEmail" name="Email" type="email" autocomplete="email" required></div>
                                        <div class="col-12"><label class="form-label" for="contactMessage"><?= e($t('contact.message')) ?></label><textarea class="form-control" id="contactMessage" name="Pesan" required></textarea></div>
                                        <div class="col-12"><button class="btn btn-red px-4 py-2" type="submit"><?= e($t('contact.send')) ?> <i class="bi bi-arrow-right ms-1"></i></button></div>
                                    </div>
                                </form>
                                <p class="contact-note mt-3 mb-0"><?= e($t('contact.mail_note')) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
        <footer class="site-footer">
            <div class="container footer-main">
                <div class="row g-4 g-xl-5">
                    <div class="col-12 col-lg-4">
                        <a class="brand footer-brand" href="#beranda" aria-label="<?= e($t('brand.home')) ?>">
                            <i class="bi bi-geo-alt-fill brand-pin" aria-hidden="true"></i>
                            <span class="brand-name">PARKIR<span>-APP</span></span>
                        </a>
                        <p class="footer-description"><?= e($t('footer.description')) ?></p>
                        <div class="footer-socials" aria-label="Social media">
                            <a href="https://www.facebook.com/" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="bi bi-facebook" aria-hidden="true"></i></a>
                            <a href="https://www.instagram.com/" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><i class="bi bi-instagram" aria-hidden="true"></i></a>
                            <a href="https://x.com/" target="_blank" rel="noopener noreferrer" aria-label="X"><i class="bi bi-twitter-x" aria-hidden="true"></i></a>
                            <a href="https://www.linkedin.com/" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"><i class="bi bi-linkedin" aria-hidden="true"></i></a>
                        </div>
                    </div>
                    <div class="col-6 col-lg-2">
                        <h2 class="footer-heading"><?= e($t('footer.quick_links')) ?></h2>
                        <ul class="footer-links">
                            <li><a href="#tentang-kami"><?= e($t('nav.about')) ?></a></li>
                            <li><a href="#layanan"><?= e($t('nav.services')) ?></a></li>
                            <li><a href="#tarif"><?= e($t('nav.pricing')) ?></a></li>
                            <li><a href="#slot-parkir"><?= e($t('nav.slots')) ?></a></li>
                            <li><a href="#lokasi"><?= e($t('nav.location')) ?></a></li>
                            <li><a href="#kontak"><?= e($t('nav.contact')) ?></a></li>
                        </ul>
                    </div>
                    <div class="col-6 col-lg-3">
                        <h2 class="footer-heading"><?= e($t('footer.solutions')) ?></h2>
                        <ul class="footer-links">
                            <li><a href="#layanan"><?= e($t('footer.mall')) ?></a></li>
                            <li><a href="#layanan"><?= e($t('footer.office')) ?></a></li>
                            <li><a href="#layanan"><?= e($t('footer.hospital')) ?></a></li>
                            <li><a href="#layanan"><?= e($t('footer.open_lot')) ?></a></li>
                        </ul>
                    </div>
                    <div class="col-12 col-lg-3">
                        <h2 class="footer-heading"><?= e($t('footer.consumer')) ?></h2>
                        <ul class="footer-links">
                            <li><a class="footer-contact-link" href="mailto:<?= e($supportEmail) ?>?subject=<?= rawurlencode($t('footer.complaint')) ?>"><i class="bi bi-headset" aria-hidden="true"></i><span><?= e($t('footer.complaint')) ?></span></a></li>
                            <li><a class="footer-contact-link" href="mailto:<?= e($supportEmail) ?>"><i class="bi bi-envelope" aria-hidden="true"></i><span><?= e($t('footer.help_email')) ?>: <?= e($supportEmail) ?></span></a></li>
                            <li><a class="footer-contact-link" href="https://wa.me/<?= e($supportWhatsapp) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp" aria-hidden="true"></i><span><?= e($t('footer.whatsapp')) ?>: <?= e($supportPhone) ?></span></a></li>
                        </ul>
                        <a class="footer-regulation" href="https://ditjenpktn.kemendag.go.id/" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span><?= e($t('footer.regulation')) ?></span>
                        </a>
                    </div>
                </div>
            </div>
            <div class="site-subfooter">
                <div class="container subfooter-inner">
                    <p>© <?= date('Y') ?> PARKIR-APP. <?= e($t('footer.copyright')) ?></p>
                    <div class="subfooter-actions">
                        <a href="#beranda"><?= e($t('brand.home')) ?></a>
                        <a class="footer-login" href="<?= e(app_url('login.php')) ?>"><?= e($t('footer.login')) ?> <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
        </footer>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            var parkingAreaGrid = document.getElementById('parkingAreaGrid');
            var parkingRefreshStatus = document.getElementById('parkingRefreshStatus');
            var pageLanguage = <?= json_encode($language, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            var parkingText = <?= json_encode([
                'empty' => $t('js.area_empty'),
                'motor' => $t('js.type_motor'),
                'car' => $t('js.type_car'),
                'other' => $t('js.type_other'),
                'parking' => $t('js.parking_type'),
                'emptyCount' => $t('js.empty_count'),
                'occupiedOf' => $t('js.occupied_of'),
                'percentFull' => $t('js.percent_full'),
                'available' => $t('js.available'),
                'full' => $t('js.full'),
                'refreshing' => $t('js.refreshing'),
                'updated' => $t('js.updated'),
                'refreshFailed' => $t('js.refresh_failed'),
                'loadError' => $t('js.load_error'),
                'loadingError' => $t('js.loading_error'),
                'capacity' => $t('js.capacity'),
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
            var numberFormatter = new Intl.NumberFormat(pageLanguage === 'en' ? 'en-US' : 'id-ID');

            function formatText(template, values) {
                return template.replace(/%(\d+\$)?s/g, function (placeholder) {
                    var index = placeholder.match(/\d+/);
                    return String(values[index ? Number(index[0]) - 1 : 0]);
                });
            }

            function makeNode(tag, className, text) {
                var element = document.createElement(tag);
                if (className) element.className = className;
                if (text !== undefined) element.textContent = text;
                return element;
            }
            function renderParkingAreas(areas) {
                parkingAreaGrid.replaceChildren();
                if (!areas.length) {
                    var empty = makeNode('div', 'col-12');
                    empty.appendChild(makeNode('div', 'contact-panel text-center text-muted', parkingText.empty));
                    parkingAreaGrid.appendChild(empty);
                    return;
                }
                areas.forEach(function (area) {
                    var capacity = Math.max(0, Number(area.kapasitas) || 0);
                    var occupied = Math.max(0, Number(area.terisi) || 0);
                    var available = Math.max(0, capacity - occupied);
                    var percentage = capacity > 0 ? Math.min(100, Math.round(occupied / capacity * 100)) : 0;
                    var name = String(area.nama_area || '');
                    var typeKey = name.toLowerCase().includes('motor') ? 'motor' : (name.toLowerCase().includes('mobil') ? 'car' : 'other');
                    var type = parkingText[typeKey];
                    var column = makeNode('div', 'col-sm-6 col-lg-4');
                    var card = makeNode('article', 'area-card');
                    var top = makeNode('div', 'd-flex justify-content-between align-items-start gap-3');
                    top.appendChild(makeNode('span', 'area-icon ' + (typeKey === 'motor' ? 'motor' : 'mobil')));
                    top.firstChild.appendChild(makeNode('i', 'bi ' + (typeKey === 'motor' ? 'bi-bicycle' : (typeKey === 'car' ? 'bi-car-front-fill' : 'bi-truck-front-fill'))));
                    top.appendChild(makeNode('span', 'badge ' + (available > 0 ? 'text-bg-success' : 'text-bg-danger'), available > 0 ? parkingText.available : parkingText.full));
                    card.appendChild(top);
                    card.appendChild(makeNode('h3', 'h5 mt-3 mb-1 fw-bold', name));
                    card.appendChild(makeNode('div', 'area-meta mb-3', formatText(parkingText.parking, [type])));
                    var counts = makeNode('div', 'd-flex align-items-end justify-content-between mb-2');
                    counts.appendChild(makeNode('span', 'area-capacity', numberFormatter.format(available) + ' ' + parkingText.emptyCount));
                    counts.appendChild(makeNode('span', 'area-meta', formatText(parkingText.occupiedOf, [numberFormatter.format(occupied), numberFormatter.format(capacity)])));
                    card.appendChild(counts);
                    var progress = makeNode('div', 'slot-track');
                    progress.setAttribute('role', 'progressbar');
                    progress.setAttribute('aria-label', formatText(parkingText.capacity, [name]));
                    progress.setAttribute('aria-valuenow', String(percentage));
                    progress.setAttribute('aria-valuemin', '0');
                    progress.setAttribute('aria-valuemax', '100');
                    var bar = makeNode('div', 'slot-progress' + (available === 0 ? ' full' : ''));
                    bar.style.width = percentage + '%';
                    progress.appendChild(bar);
                    card.appendChild(progress);
                    card.appendChild(makeNode('div', 'area-meta mt-2', formatText(parkingText.percentFull, [percentage])));
                    column.appendChild(card);
                    parkingAreaGrid.appendChild(column);
                });
            }
            function refreshParkingAreas() {
                if (parkingRefreshStatus) parkingRefreshStatus.textContent = parkingText.refreshing;
                fetch('index.php?slot_data=1', { cache: 'no-store', headers: { Accept: 'application/json' } })
                    .then(function (response) {
                        if (!response.ok) throw new Error(parkingText.loadError);
                        return response.json();
                    })
                    .then(function (data) {
                        if (!Array.isArray(data.areas)) throw new Error(parkingText.loadingError);
                        renderParkingAreas(data.areas);
                        if (parkingRefreshStatus) parkingRefreshStatus.textContent = formatText(parkingText.updated, [
                            new Date().toLocaleTimeString(pageLanguage === 'en' ? 'en-US' : 'id-ID', { hour: '2-digit', minute: '2-digit' })
                        ]);
                    })
                    .catch(function (error) {
                        if (parkingRefreshStatus) parkingRefreshStatus.textContent = parkingText.refreshFailed;
                        console.error(error);
                    });
            }
            refreshParkingAreas();
            window.setInterval(refreshParkingAreas, 30000);
            document.querySelectorAll('#mainNavigation .nav-link').forEach(function (link) {
                link.addEventListener('click', function () {
                    var navigation = document.getElementById('mainNavigation');
                    if (navigation.classList.contains('show')) {
                        bootstrap.Collapse.getOrCreateInstance(navigation).hide();
                    }
                });
            });
            document.querySelectorAll('.language-menu a').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    window.location.href = link.href + window.location.hash;
                });
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'petugas', 'owner'], true)) {
    unset($_SESSION['id_user'], $_SESSION['nama_lengkap'], $_SESSION['role']);
    redirect('login.php');
}

if ($_SESSION['role'] === 'admin') {
    redirect('admin/dashboard.php');
}
if ($_SESSION['role'] === 'petugas') {
    redirect('petugas/dashboard.php');
}
if ($_SESSION['role'] === 'owner') {
    redirect('owner/dashboard.php');
}

$pdo = db();
$pageTitle = 'Dashboard';

if ($_SESSION['role'] === 'admin') {
    $capacity = (int) $pdo->query('SELECT COALESCE(SUM(kapasitas), 0) FROM tb_area_parkir')->fetchColumn();
    $occupied = (int) $pdo->query('SELECT COALESCE(SUM(terisi), 0) FROM tb_area_parkir')->fetchColumn();
    $stats = [
        ['label' => 'Total slot parkir', 'value' => $capacity, 'icon' => 'bi-p-square', 'color' => 'red'],
        ['label' => 'Slot terisi', 'value' => $occupied, 'icon' => 'bi-car-front-fill', 'color' => 'orange'],
        ['label' => 'Transaksi hari ini', 'value' => $pdo->query('SELECT COUNT(*) FROM tb_transaksi WHERE waktu_masuk >= CURDATE() AND waktu_masuk < CURDATE() + INTERVAL 1 DAY')->fetchColumn(), 'icon' => 'bi-arrow-left-right', 'color' => 'blue'],
        ['label' => 'Pendapatan hari ini', 'value' => format_rupiah($pdo->query("SELECT COALESCE(SUM(biaya_total), 0) FROM tb_transaksi WHERE status = 'keluar' AND waktu_keluar >= CURDATE() AND waktu_keluar < CURDATE() + INTERVAL 1 DAY")->fetchColumn()), 'icon' => 'bi-wallet2', 'color' => 'green'],
    ];
} elseif ($_SESSION['role'] === 'petugas') {
    $capacity = (int) $pdo->query('SELECT COALESCE(SUM(kapasitas), 0) FROM tb_area_parkir')->fetchColumn();
    $occupied = (int) $pdo->query('SELECT COALESCE(SUM(terisi), 0) FROM tb_area_parkir')->fetchColumn();
    $stats = [
        ['label' => 'Total slot parkir', 'value' => $capacity, 'icon' => 'bi-p-square', 'color' => 'red'],
        ['label' => 'Slot terisi', 'value' => $occupied, 'icon' => 'bi-car-front-fill', 'color' => 'orange'],
        ['label' => 'Transaksi hari ini', 'value' => $pdo->query('SELECT COUNT(*) FROM tb_transaksi WHERE waktu_masuk >= CURDATE() AND waktu_masuk < CURDATE() + INTERVAL 1 DAY')->fetchColumn(), 'icon' => 'bi-arrow-left-right', 'color' => 'blue'],
        ['label' => 'Pendapatan hari ini', 'value' => format_rupiah($pdo->query("SELECT COALESCE(SUM(biaya_total), 0) FROM tb_transaksi WHERE status = 'keluar' AND waktu_keluar >= CURDATE() AND waktu_keluar < CURDATE() + INTERVAL 1 DAY")->fetchColumn()), 'icon' => 'bi-wallet2', 'color' => 'green'],
    ];
} elseif ($_SESSION['role'] === 'owner') {
    $ownerAreas = db('petugas');
    $capacity = (int) $ownerAreas->query('SELECT COALESCE(SUM(kapasitas), 0) FROM tb_area_parkir')->fetchColumn();
    $occupied = (int) $ownerAreas->query('SELECT COALESCE(SUM(terisi), 0) FROM tb_area_parkir')->fetchColumn();
    $stats = [
        ['label' => 'Total slot parkir', 'value' => $capacity, 'icon' => 'bi-p-square', 'color' => 'red'],
        ['label' => 'Slot terisi', 'value' => $occupied, 'icon' => 'bi-car-front-fill', 'color' => 'orange'],
        ['label' => 'Transaksi hari ini', 'value' => $pdo->query('SELECT COUNT(*) FROM v_rekap_transaksi WHERE waktu_masuk >= CURDATE() AND waktu_masuk < CURDATE() + INTERVAL 1 DAY')->fetchColumn(), 'icon' => 'bi-arrow-left-right', 'color' => 'blue'],
        ['label' => 'Pendapatan hari ini', 'value' => format_rupiah($pdo->query("SELECT COALESCE(SUM(biaya_total), 0) FROM v_rekap_transaksi WHERE status = 'keluar' AND waktu_keluar >= CURDATE() AND waktu_keluar < CURDATE() + INTERVAL 1 DAY")->fetchColumn()), 'icon' => 'bi-wallet2', 'color' => 'green'],
    ];
}

require __DIR__ . '/includes/header.php';
?>
<div class="dashboard-welcome">
    <div><h1>Selamat datang, <?= e($_SESSION['nama_lengkap']) ?></h1><p>Berikut ringkasan aktivitas parkir Anda.</p></div>
    <?php if ($_SESSION['role'] === 'petugas'): ?>
        <a class="btn btn-primary quick-link" href="<?= e(app_url('petugas/transaksi_masuk.php')) ?>"><i class="bi bi-plus-lg"></i> Catat kendaraan</a>
    <?php elseif ($_SESSION['role'] === 'owner'): ?>
        <a class="btn btn-primary quick-link" href="<?= e(app_url('owner/rekap_transaksi.php')) ?>"><i class="bi bi-bar-chart"></i> Lihat laporan</a>
    <?php endif; ?>
</div>
<div class="row g-3 summary-grid">
    <?php foreach ($stats as $stat): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card h-100"><div class="card-body">
                <div><div class="stat-label"><?= e($stat['label']) ?></div><div class="stat-value"><?= e($stat['value']) ?></div></div>
                <span class="stat-icon <?= e($stat['color']) ?>"><i class="bi <?= e($stat['icon']) ?>"></i></span>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($_SESSION['role'] === 'petugas'): ?>
    <div class="mt-4 d-flex gap-2">
        <a class="btn btn-outline-primary quick-link" href="<?= e(app_url('petugas/transaksi_keluar.php')) ?>"><i class="bi bi-box-arrow-up-right"></i> Proses kendaraan keluar</a>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
