<?php
require_once 'db.php'; // adjust to your actual connection file

function render_job_card(array $job): string
{
    $rsf        = htmlspecialchars($job['rsf_number'] ?? '');
    $employer   = htmlspecialchars($job['employer_name'] ?? '');
    $position   = htmlspecialchars($job['position_title'] ?? '');
    $country    = htmlspecialchars($job['worksite_country'] ?? 'Not specified');
    $slots      = (int)($job['slots_available'] ?? 0);
    $salaryMin  = $job['salary_min_php'] ?? null;
    $salaryMax  = $job['salary_max_php'] ?? null;
    $deadline   = $job['deadline'] ?? null;
    $pdfPath    = $job['pdf_path'] ?? null;
    $isPreview  = !empty($job['is_preview']);

    $abbr = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $country), 0, 2)) ?: '--';

    $salaryLine = ($salaryMin && $salaryMax)
        ? '₱' . number_format($salaryMin, 0) . ' - ₱' . number_format($salaryMax, 0) . ' / month'
        : 'Salary not specified';

    $daysLeft = '';
    if ($deadline) {
        $diff = (strtotime($deadline) - strtotime(date('Y-m-d'))) / 86400;
        $daysLeft = $diff >= 0
            ? '<span class="inline-block px-3 py-1 bg-navy-50 text-navy-700 border border-navy-200 text-xs font-medium rounded-full">' . (int)$diff . ' days left to apply</span>'
            : '<span class="inline-block px-3 py-1 bg-gray-200 text-gray-600 text-xs font-medium rounded-full">Closed</span>';
    }

    $viewBtn = $pdfPath
        ? '<a href="' . htmlspecialchars($pdfPath) . '" target="_blank" class="w-full md:w-auto justify-center bg-[var(--primary)] hover:bg-[var(--primary-dark)] text-white px-5 sm:px-8 py-3 sm:py-4 rounded-xl font-semibold flex items-center gap-2 transition shadow-md whitespace-nowrap text-sm sm:text-base">VIEW RSF <i class="fa-solid fa-arrow-right"></i></a>'
        : '<span class="text-sm text-gray-400 italic">RSF document not yet uploaded</span>';

    ob_start(); ?>
    <div class="bg-navy-50 rounded-xl p-4 sm:p-6 border border-navy-200 enhanced-border job-card <?= $isPreview ? 'ring-2 ring-dashed ring-navy-400' : '' ?>"
        data-search="<?= strtolower($employer . ' ' . $position . ' ' . $rsf) ?>"
        data-country="<?= strtolower($country) ?>"
        data-country-label="<?= $country ?>"
        data-slots="<?= $slots ?>">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <div class="w-8 h-8 shrink-0 bg-navy-100 rounded-full flex items-center justify-center text-xs font-bold text-navy-700"><?= $abbr ?></div>
                    <div class="text-xs sm:text-sm text-gray-500 break-all">Control No. <?= $rsf ?: 'N/A' ?></div>
                    <?php if ($isPreview): ?>
                        <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-semibold">PREVIEW</span>
                    <?php endif; ?>
                </div>
                <h5 class="text-base sm:text-lg font-bold text-gray-900 break-words"><?= $slots ?> <?= $position ?: '(Position not set)' ?></h5>
                <p class="text-sm sm:text-base text-gray-600 mt-1 break-words">Employer: <span class="font-semibold"><?= $employer ?: '—' ?></span> · <?= $country ?></p>
                <div class="flex flex-wrap gap-2 mt-2">
                    <span class="inline-block px-3 py-1 bg-navy-100 text-navy-800 text-xs font-medium rounded-full">No Placement Fee</span>
                    <?= $daysLeft ?>
                </div>
                <p class="text-sm text-gray-700 mt-2 font-medium"><?= $salaryLine ?></p>
            </div>
            <?= $viewBtn ?>
        </div>
    </div>
<?php return ob_get_clean();
}

$sql = "SELECT r.*, js.subcategory_name, jc.category_name
        FROM rsf_job_orders r
        LEFT JOIN job_subcategories js ON r.subcategory_id = js.id
        LEFT JOIN job_categories jc ON js.category_id = jc.id
        WHERE (r.deadline IS NULL OR r.deadline >= CURDATE())
        AND (r.slots_available IS NULL OR r.slots_available > 0)
        ORDER BY r.created_at DESC";
$result = $database->query($sql);

$jobsByYear = [];
$totalOpportunities = 0;
$countries = [];
while ($row = $result->fetch_assoc()) {
    $year = date('Y', strtotime($row['created_at']));
    $jobsByYear[$year][] = $row;
    $totalOpportunities += (int)$row['slots_available'];
    if (!empty($row['worksite_country'])) {
        $countries[$row['worksite_country']] = ($countries[$row['worksite_country']] ?? 0) + 1;
    }
}
krsort($jobsByYear);
ksort($countries);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            try {
                var t = localStorage.getItem('dmw_theme');
                if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>
    <title>Department of Migrant Workers | Republic of the Philippines</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        navy: {
                            50: '#F2F5FA',
                            100: '#E3EAF5',
                            200: '#C5D2E8',
                            300: '#9DB1D3',
                            400: '#6C88B8',
                            500: '#44639A',
                            600: '#2A4577',
                            700: '#1B3563',
                            800: '#122A55',
                            900: '#0A1F44',
                            950: '#061330'
                        },
                        // Remap built-in palettes so existing blue-*/gray-* classes follow the footer navy
                        blue: {
                            50: '#F2F5FA',
                            100: '#E3EAF5',
                            200: '#C5D2E8',
                            300: '#9DB1D3',
                            400: '#6C88B8',
                            500: '#44639A',
                            600: '#2A4577',
                            700: '#1B3563',
                            800: '#122A55',
                            900: '#0A1F44',
                            950: '#061330'
                        },
                        gray: {
                            50: '#F5F7FA',
                            100: '#EAEEF4',
                            200: '#D8DFEA',
                            300: '#BCC6D6',
                            400: '#8E9BB1',
                            500: '#64718A',
                            600: '#4A5670',
                            700: '#36415A',
                            800: '#222C44',
                            900: '#121B30'
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/png" href="pictures/dmw_logo.png">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Playfair+Display:wght@700&amp;display=swap"
        rel="stylesheet">
    <style>
        /* Google Fonts */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap');

        /* Root Variables – easy to maintain theme */
        :root {
            --primary: #0A1F44;
            --primary-dark: #00205f;
            --primary-light: #e6efff;
            --accent: #00a0df;
            --text: #1f2937;
            --text-light: #4b5563;
            --bg-light: #f8fafc;
            --shadow-sm: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -2px rgb(0 0 0 / 0.05);
            --shadow-lg: 0 25px 50px -12px rgb(0 0 0 / 0.25);
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Base & Typography */
        .tailwind-content {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text);
        }

        .hero-title {
            font-family: 'Playfair Display', serif;
        }

        html {
            scroll-behavior: smooth;
        }

        section[id],
        footer[id] {
            scroll-margin-top: 5rem;
        }

        /* Hero Background + Parallax */
        .hero-bg {
            background-image: linear-gradient(rgba(10, 31, 68, 0.78), rgba(10, 31, 68, 0.9)),
                url('pictures/dmw.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            /* subtle parallax */
        }

        @media (max-width: 1024px),
        (prefers-reduced-motion: reduce) {
            .hero-bg {
                background-attachment: scroll;
                /* disable parallax on tablets/mobile & motion-sensitive users */
            }
        }

        /* Navigation & Interactive Elements */
        .nav-link {
            transition: var(--transition);
        }

        .nav-link:hover {
            color: var(--primary);
            transform: translateY(-1.5px);
        }

        .card-hover {
            transition: var(--transition);
        }

        .card-hover:hover {
            transform: translateY(-12px) scale(1.035);
            box-shadow: var(--shadow-lg);
        }

        /* Scroll Reveal Animations */
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.9s ease-out, transform 0.9s ease-out;
        }

        .animate-on-scroll.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Stagger children (optional – add delay per card) */
        .animate-on-scroll.stagger-1 {
            transition-delay: 0.1s;
        }

        .animate-on-scroll.stagger-2 {
            transition-delay: 0.2s;
        }

        .animate-on-scroll.stagger-3 {
            transition-delay: 0.3s;
        }

        /* Button Ripple Effect (material-like) */
        .ripple {
            position: relative;
            overflow: hidden;
        }

        .ripple::after {
            content: '';
            position: absolute;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0;
            pointer-events: none;
        }

        .ripple:active::after {
            width: 300px;
            height: 300px;
            opacity: 1;
            transition: width 0.6s, height 0.6s, opacity 0.6s;
        }

        .lang-btn {
            color: #C5D2E8;
            padding: 2px 4px;
            border-bottom: 2px solid transparent;
            transition: color .2s;
        }

        .lang-btn:hover {
            color: #fff;
        }

        .lang-btn[aria-pressed="true"] {
            color: #fff;
            font-weight: 700;
            border-bottom-color: #fff;
        }

        /*  Pulse animation (used for emergency button, alerts, etc.) */
        @keyframes pulse-subtle {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.06);
            }
        }

        .pulse-subtle {
            animation: pulse-subtle 2.2s infinite ease-in-out;
        }

        /* Utility Classes */
        .section-bg-light {
            background: linear-gradient(145deg, var(--bg-light), white);
        }

        .section-bg-blue {
            background: linear-gradient(145deg, #F2F5FA, white);
        }

        .text-glow {
            text-shadow: 0 2px 10px rgba(10, 31, 68, 0.15);
        }

        /* Optional high-contrast mode (can be toggled with JS) */
        @media (prefers-contrast: more) {
            :root {
                --primary: #001f5f;
            }

            .hero-bg {
                background-image: linear-gradient(rgba(6, 19, 48, 0.92), rgba(6, 19, 48, 0.96)),
                    url('pictures/dmw.jpg');
            }
        }

        /* Small-screen refinements */
        @media (max-width: 400px) {
            .hero-title {
                letter-spacing: -0.5px;
            }
        }

        /* ===== Dark mode ===== */
        .theme-btn {
            color: #C5D2E8;
            padding: 2px 6px;
            transition: color .2s;
        }

        .theme-btn:hover {
            color: #fff;
        }

        html.dark {
            color-scheme: dark;
            --primary: #9DB1D3;
            --primary-dark: #44639A;
            --primary-light: #122A55;
            --text: #E3EAF5;
            --text-light: #BCC6D6;
        }

        /* Page + sections */
        html.dark body.bg-white {
            background-color: #0A1224;
        }

        html.dark .section-bg-light,
        html.dark .section-bg-blue,
        html.dark #news {
            background: #0A1224;
        }

        html.dark .bg-gray-50 {
            background-color: #0D182E;
        }

        html.dark #services-section {
            background-image: none;
            background-color: #0D182E;
        }

        html.dark .accordion-summary {
            background-image: none;
            background-color: #122A55;
        }

        html.dark .hero-bg svg {
            fill: #0A1224;
        }

        /* Surfaces */
        html.dark .bg-white {
            background-color: #0F1A33;
        }

        html.dark .bg-navy-50 {
            background-color: #0F1A33;
        }

        html.dark .bg-navy-100 {
            background-color: #1B2A4A;
        }

        html.dark .bg-gray-200 {
            background-color: #22304F;
        }

        html.dark .job-card {
            background-color: #14213D;
        }

        html.dark .hover\:bg-gray-50:hover,
        html.dark .hover\:bg-navy-50:hover,
        html.dark .focus\:bg-navy-50:focus {
            background-color: #1B2A4A;
        }

        /* Text */
        html.dark .text-gray-900,
        html.dark .text-gray-800 {
            color: #F1F5FB;
        }

        html.dark .text-gray-700 {
            color: #D8DFEA;
        }

        html.dark .text-gray-600 {
            color: #BCC6D6;
        }

        html.dark .text-gray-500 {
            color: #A3AEC4;
        }

        html.dark .text-navy-700,
        html.dark .text-navy-800 {
            color: #C5D2E8;
        }

        /* Borders */
        html.dark .border-gray-100,
        html.dark .border-gray-200,
        html.dark .border-gray-300,
        html.dark .border-gray-100\/70,
        html.dark .border-navy-200 {
            border-color: #2A3B5E;
        }

        html.dark nav {
            border-color: #22304F;
        }

        /* Inputs */
        html.dark input,
        html.dark select {
            background-color: #0F1A33;
            color: #F1F5FB;
            border-color: #2A3B5E;
        }

        html.dark input::placeholder {
            color: #8E9BB1;
        }

        /* Buttons that used the (now light) --primary as a background */
        html.dark .bg-\[var\(--primary\)\] {
            background-color: #2A4577;
        }

        html.dark .hover\:bg-\[var\(--primary-dark\)\]:hover {
            background-color: #44639A;
        }

        html.dark nav a.bg-\[\#0A1F44\] {
            background-color: #2A4577;
        }

        html.dark nav a.bg-\[\#0A1F44\]:hover {
            background-color: #44639A;
        }

        html.dark .from-\[var\(--primary\)\] {
            --tw-gradient-from: #2A4577 var(--tw-gradient-from-position);
        }

        /* Hero button stays white */
        html.dark .hero-bg a.bg-white {
            background-color: #fff;
            color: #0A1F44;
        }

        html.dark .hero-bg a.bg-white:hover {
            background-color: #E3EAF5;
        }

        /* Timeline */
        html.dark .z-10.shrink-0.rounded-full.bg-\[\#0A1F44\] {
            background-color: #2A4577;
        }

        html.dark .w-1.bg-\[\#0A1F44\]\/20 {
            background-color: rgb(157 177 211 / .25);
        }

        html[data-theme="dark"] .nav-brand-text,
        html.dark .nav-brand-text,
        html[data-theme="dark"] nav .truncate,
        html.dark nav .truncate {
            color: #fff !important;
        }
    </style>
</head>

<body class="tailwind-content bg-white text-gray-900">
    <!-- Top Bar -->
    <div class="bg-[#0A1F44] text-white py-2 text-xs sm:text-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 flex items-center justify-between gap-2 overflow-x-auto">
            <div class="flex items-center gap-x-4 sm:gap-x-8 shrink-0">
                <a href="tel:0287221144" class="flex items-center gap-x-1.5 sm:gap-x-2 hover:text-blue-200 transition-colors whitespace-nowrap">
                    <i class="fa-solid fa-phone"></i>
                    <span class="hidden xs:inline sm:inline">(02) 8722-1144</span>
                </a>
                <a href="mailto:info@dmw.gov.ph"
                    class="hidden sm:flex items-center gap-x-2 hover:text-blue-200 transition-colors whitespace-nowrap">
                    <i class="fa-solid fa-envelope"></i>
                    <span>info@dmw.gov.ph</span>
                </a>
            </div>
            <div class="flex items-center gap-x-3 sm:gap-x-6 text-xs shrink-0">
                <div class="hidden md:flex items-center gap-x-2 whitespace-nowrap">
                    <i class="fa-solid fa-clock"></i>
                    <span>Mon - Fri: 8:00 AM - 5:00 PM</span>
                </div>
                <div class="hidden md:block h-4 w-px bg-white/30"></div>
                <button type="button" id="theme-toggle" class="theme-btn" aria-label="Switch to dark mode" aria-pressed="false">
                    <i id="theme-icon" class="fa-solid fa-moon"></i>
                </button>
                <div class="flex items-center gap-x-1.5 text-xs uppercase tracking-widest whitespace-nowrap" role="group" aria-label="Language">
                    <button type="button" class="lang-btn" data-lang="en" aria-pressed="true">EN</button>
                    <span class="text-blue-200" aria-hidden="true">|</span>
                    <button type="button" class="lang-btn" data-lang="fil" aria-pressed="false">FIL</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="bg-white border-b sticky top-0 z-50 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="flex items-center justify-between h-16 sm:h-20">
                <!-- Logo -->
                <div class="flex items-center gap-x-2 sm:gap-x-4 min-w-0">
                    <img src="pictures/dmw_logo.png"
                        alt="Department of Migrant Workers Logo" class="h-9 sm:h-14 w-auto object-contain shrink-0">
                    <div class="min-w-0">
                        <div class="nav-brand-text text-sm sm:text-2xl font-bold leading-none text-[#0A1F44] truncate">Department of</div>
                        <div class="nav-brand-text text-sm sm:text-2xl font-bold leading-none text-[#0A1F44] truncate">Migrant Workers</div>
                        <div class="hidden sm:block text-[10px] text-gray-500 tracking-widest -mt-0.5">REPUBLIC OF THE PHILIPPINES</div>
                    </div>
                </div>

                <!-- Desktop Menu -->
                <div class="hidden md:flex items-center gap-x-6 lg:gap-x-10 text-sm font-medium">
                    <a href="#" class="nav-link text-gray-700">Home</a>

                    <!-- About Dropdown (unchanged) -->
                    <div class="relative group">
                        <button class="nav-link text-gray-700 flex items-center gap-x-1">
                            About
                            <i class="fa-solid fa-chevron-down text-xs transition-transform group-hover:rotate-180"></i>
                        </button>
                        <div class="absolute hidden group-hover:block pt-4">
                            <div class="bg-white shadow-xl rounded-2xl py-6 px-8 w-64 text-sm border border-gray-100">
                                <a href="https://dmw.gov.ph/about/mission-vision-and-core-values"
                                    class="block py-3 px-4 hover:bg-gray-50 rounded-xl">Our Mission, Vision & Core
                                    Values</a>
                                <a href="https://dmw.gov.ph/about/mandate"
                                    class="block py-3 px-4 hover:bg-gray-50 rounded-xl">Our Mandate</a>
                                <a href="https://dmw.gov.ph/about/organizational-overview"
                                    class="block py-3 px-4 hover:bg-gray-50 rounded-xl">Organizational
                                    Structure</a>
                            </div>
                        </div>
                    </div>

                    <a href="#vacancies" class="nav-link text-gray-700">Vacancies</a>
                    <a href="#how-it-works" class="nav-link text-gray-700">How It Works</a>
                    <a href="#news" class="nav-link text-gray-700">News</a>
                    <a href="#services-section" class="nav-link text-gray-700">Services</a>
                    <a href="#contact" class="nav-link text-gray-700">Contact</a>
                </div>

                <!-- Right Side -->
                <div class="flex items-center gap-x-2 sm:gap-x-4 shrink-0">
                    <a href="login.php" onclick="showPortalModal()"
                        class="bg-[#0A1F44] hover:bg-navy-700 text-white px-3 py-2 sm:px-8 sm:py-3 rounded-xl sm:rounded-2xl text-xs sm:text-sm font-semibold transition-all active:scale-95 shadow-lg shadow-blue-900/30 whitespace-nowrap">
                        <span class="hidden sm:inline">Applicant Portal</span>
                        <span class="sm:hidden">Portal</span>
                    </a>

                    <!-- Mobile Menu Button -->
                    <button id="mobile-menu-button" aria-expanded="false" aria-controls="mobile-menu"
                        class="md:hidden w-10 h-10 sm:w-11 sm:h-11 flex items-center justify-center text-xl sm:text-2xl text-gray-700 shrink-0">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div id="mobile-menu" class="hidden md:hidden bg-white border-t">
            <div class="px-6 py-8 flex flex-col gap-y-6 text-lg">
                <a href="#" class="font-medium">Home</a>

                <!-- About (expandable) -->
                <details class="group">
                    <summary class="font-medium flex items-center justify-between cursor-pointer list-none">
                        About
                        <i class="fa-solid fa-chevron-down text-xs transition-transform group-open:rotate-180"></i>
                    </summary>
                    <div class="mt-4 ml-4 flex flex-col gap-y-4 text-base text-gray-600">
                        <a href="https://dmw.gov.ph/about/mission-vision-and-core-values">Our Mission, Vision & Core Values</a>
                        <a href="https://dmw.gov.ph/about/mandate">Our Mandate</a>
                        <a href="https://dmw.gov.ph/about/organizational-overview">Organizational Structure</a>
                    </div>
                </details>

                <a href="#vacancies" class="font-medium">Vacancies</a>
                <a href="#how-it-works" class="font-medium">How It Works</a>
                <a href="#news" class="font-medium">News</a>
                <a href="#services-section" class="font-medium">Services</a>
                <a href="#contact" class="font-medium">Contact</a>
                <a href="login.php"
                    class="bg-[#0A1F44] text-white py-4 text-center rounded-2xl font-semibold">Applicant Portal</a>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="hero-bg min-h-[85vh] sm:min-h-screen flex items-center relative overflow-hidden pb-16 sm:pb-20 md:pb-28 lg:pb-36 pt-8 sm:pt-0"
        id="main-content">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-12 w-full z-10">
            <div class="max-w-3xl text-white space-y-6 sm:space-y-8 md:space-y-12">

                <!-- Tagline pill – with fade-in -->
                <div
                    class="animate-on-scroll opacity-0 translate-y-8 transition-all duration-700 inline-flex items-center gap-2 sm:gap-3 bg-white/15 backdrop-blur-md border border-white/30 px-4 sm:px-6 py-2 sm:py-3 rounded-full text-xs sm:text-sm font-medium tracking-wide">
                    <div class="w-2.5 h-2.5 sm:w-3 sm:h-3 bg-navy-200 rounded-full animate-pulse shrink-0"></div>
                    <span>PROTECTING FILIPINO WORKERS WORLDWIDE</span>
                </div>

                <!-- Main title – dramatic entrance -->
                <h1
                    class="hero-title animate-on-scroll opacity-0 translate-y-12 transition-all duration-900 text-3xl xs:text-4xl sm:text-5xl lg:text-6xl xl:text-7xl font-bold leading-tight tracking-tight">
                    Department of<br class="sm:hidden"> Migrant Workers
                </h1>

                <!-- Subtitle -->
                <p
                    class="animate-on-scroll opacity-0 translate-y-10 transition-all duration-1000 delay-200 text-base sm:text-lg md:text-xl lg:text-2xl text-white/90 leading-relaxed max-w-2xl">
                    Empowering and protecting Overseas Filipino Workers through comprehensive services,
                    welfare programs, and policy advocacy for a dignified and safe migration experience.
                </p>

                <!-- CTA Buttons – simplified & appealing animation -->
                <div class="flex flex-col sm:flex-row gap-4 sm:gap-5 pt-4 sm:pt-6 md:pt-10">
                    <!-- Primary Button -->
                    <a href="services.html"
                        class="ripple group relative overflow-hidden bg-white hover:bg-navy-100 text-[var(--primary)] px-6 sm:px-9 py-4 sm:py-5 rounded-2xl font-semibold text-base sm:text-lg shadow-xl shadow-blue-900/30 transition-all duration-300 ease-out transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-navy-300 focus:ring-offset-2 focus:ring-offset-blue-900 inline-flex items-center justify-center text-center w-full sm:w-auto">
                        <span class="relative z-10">Access OFW Services →</span>
                        <span
                            class="absolute inset-0 bg-gradient-to-r from-transparent via-navy-900/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-out"></span>
                    </a>

                    <!-- Secondary Button -->
                    <button
                        class="ripple group relative overflow-hidden border-2 border-white/70 hover:bg-white/10 text-white px-6 sm:px-9 py-4 sm:py-5 rounded-2xl font-semibold text-base sm:text-lg transition-all duration-300 ease-out transform hover:scale-105 active:scale-95 w-full sm:w-auto">
                        <span class="relative z-10">Learn More</span>
                    </button>
                </div>

                <!-- Certification Banner -->
                <div
                    class="animate-on-scroll opacity-0 translate-y-8 transition-all duration-1100 delay-500 mt-8 sm:mt-10 border-t border-b border-white/20 bg-white/10 backdrop-blur-md">
                    <div
                        class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-5 flex flex-col items-center justify-between gap-3 sm:gap-4 text-white">

                        <!-- Left Label -->
                        <div class="text-[10px] sm:text-xs uppercase tracking-widest text-white/70 font-medium text-center md:text-left">
                            Certified & Committed to Excellence
                        </div>

                        <!-- Certifications -->
                        <div
                            class="flex flex-wrap sm:flex-nowrap items-center justify-center gap-4 sm:gap-5 md:gap-8 text-xs sm:text-sm">

                            <!-- ISO -->
                            <div class="flex items-center gap-2 sm:gap-3 hover:opacity-100 opacity-90 transition">
                                <img src="pictures/image.png" class="h-6 w-6 sm:h-8 sm:w-8 object-contain"
                                    alt="ISO 9001:2015 Certified">
                                <div>
                                    <div class="font-semibold">ISO 9001:2015</div>
                                    <div class="text-[10px] sm:text-xs text-white/70">Quality Management</div>
                                </div>
                            </div>

                            <div class="hidden sm:block h-6 w-px bg-white/30"></div>

                            <!-- Transparency Seal -->
                            <div class="flex items-center gap-2 sm:gap-3 hover:opacity-100 opacity-90 transition">
                                <img src="pictures/image copy.png" class="h-6 w-6 sm:h-8 sm:w-8 object-contain"
                                    alt="Philippine Transparency Seal">
                                <div>
                                    <div class="font-semibold">Transparency Seal</div>
                                    <div class="text-[10px] sm:text-xs text-white/70">Full Compliance</div>
                                </div>
                            </div>

                            <div class="hidden sm:block h-6 w-px bg-white/30"></div>

                            <!-- Citizen's Charter -->
                            <div class="flex items-center gap-2 sm:gap-3 hover:opacity-100 opacity-90 transition">
                                <img src="pictures/image copy 2.png" class="h-6 w-6 sm:h-8 sm:w-8 object-contain"
                                    alt="Citizen's Charter">
                                <div>
                                    <div class="font-semibold">Citizen's Charter</div>
                                    <div class="text-[10px] sm:text-xs text-white/70">Service Excellence</div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Floating stats badges (desktop only – avoids crowding small screens) -->
        <div id="stats-badge" class="absolute bottom-10 lg:bottom-16 right-6 lg:right-12 
            bg-white/10 backdrop-blur-lg border border-white/25 
            rounded-3xl px-6 py-4 lg:px-8 lg:py-6 
            hidden lg:block z-20 shadow-lg">

            <div class="flex items-center gap-x-6 lg:gap-x-10 text-white">

                <!-- OFWs Protected -->
                <div class="text-center">
                    <div class="text-4xl lg:text-5xl font-bold count-up tracking-tight" data-target="4200000">0</div>
                    <div class="text-xs lg:text-sm tracking-widest text-white/90 mt-1 font-medium">
                        OFWs PROTECTED
                    </div>
                </div>

                <!-- Divider -->
                <div class="h-12 lg:h-14 w-px bg-white/30"></div>

                <!-- Countries -->
                <div class="text-center">
                    <div class="text-4xl lg:text-5xl font-bold count-up tracking-tight" data-target="128">0</div>
                    <div class="text-xs lg:text-sm tracking-widest text-white/90 mt-1 font-medium">
                        COUNTRIES
                    </div>
                </div>

            </div>

            <!-- Small source note -->
            <div class="text-center mt-3">
                <p class="text-xs text-white/70 italic tracking-wide">
                    Based on latest DMW reports
                </p>
            </div>

        </div>


        <!-- Gentle ripple / wave bottom divider -->
        <div class="absolute bottom-0 left-0 w-full overflow-hidden z-0 pointer-events-none">
            <!-- Layer 1 -->
            <svg class="relative block w-full h-14 sm:h-20 md:h-28 lg:h-36 xl:h-44" viewBox="0 0 1200 120"
                preserveAspectRatio="none" fill="white">
                <path d="M0,60 C300,20 500,100 800,60 C1000,30 1200,90 1200,60 L1200,120 L0,120 Z" />
            </svg>
            <!-- Layer 2 -->
            <svg class="absolute bottom-0 left-0 block w-full h-12 sm:h-16 md:h-20 lg:h-24 xl:h-28 opacity-80"
                viewBox="0 0 1200 120" preserveAspectRatio="none" fill="white">
                <path d="M0,80 C250,40 450,110 700,80 C950,50 1200,100 1200,80 L1200,120 L0,120 Z" />
            </svg>
            <!-- Layer 3 -->
            <svg class="absolute bottom-0 left-0 block w-full h-10 sm:h-12 md:h-16 lg:h-20 xl:h-24 opacity-60"
                viewBox="0 0 1200 120" preserveAspectRatio="none" fill="white">
                <path d="M0,100 C200,70 400,110 600,100 C800,80 1000,105 1200,100 L1200,120 L0,120 Z" />
            </svg>
        </div>
    </section>


    <!-- Enhanced Quick Access – G2G Vacancies with Search/Filter & Accordion -->
    <section id="vacancies" class="py-12 sm:py-16 md:py-20 enhanced-bg section-bg-light">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-end justify-between mb-6 sm:mb-8 md:mb-12 gap-4 sm:gap-6">
                <div>
                    <span
                        class="inline-block text-[var(--primary)] font-mono text-xs md:text-sm tracking-[0.2em] sm:tracking-[0.25em] uppercase mb-2 sm:mb-3">
                        G2G OPPORTUNITIES
                    </span>
                    <h2 class="text-2xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900">
                        Government-to-Government Vacancies
                    </h2>
                    <p class="mt-2 sm:mt-3 text-base sm:text-lg text-gray-600 max-w-2xl">Browse ethical, no-fee overseas jobs through
                        bilateral government partnerships. Updated from official DMW listings.</p>
                </div>
                <a href="https://dmw.gov.ph/pegpb/job-opportunities" target="_blank" rel="noopener noreferrer"
                    class="bg-[var(--primary)] hover:bg-[var(--primary-dark)] text-white px-5 sm:px-6 py-3 rounded-xl font-medium flex items-center justify-center gap-2 transition-all shadow-md hover:shadow-lg text-sm sm:text-base w-full md:w-auto text-center">
                    View All on DMW Site →
                </a>
            </div>

            <!-- Search & Filter Bar -->
            <div class="bg-white enhanced-shadow enhanced-border rounded-2xl p-4 sm:p-6 mb-8 sm:mb-10">
                <div class="flex flex-col md:flex-row md:items-center gap-3 sm:gap-4 md:gap-6">
                    <!-- Search Input -->
                    <div class="flex-1 relative">
                        <label for="g2g-search" class="sr-only">Search vacancies</label>
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fa-solid fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="g2g-search"
                            class="block w-full pl-11 pr-4 py-3 sm:py-4 border border-gray-300 rounded-xl focus:ring-[var(--primary)] focus:border-[var(--primary)] text-gray-900 placeholder-gray-500 transition text-sm sm:text-base"
                            placeholder="Search by position, employer, control number...">
                    </div>

                    <!-- Jobsite Dropdown -->
                    <div id="jobsite-wrap" class="w-full md:w-64 relative">
                        <label for="jobsite-btn" class="sr-only">Jobsite</label>

                        <!-- Hidden native select (keeps the filter logic working) -->
                        <select id="jobsite" class="sr-only" tabindex="-1" aria-hidden="true">
                            <option value="all">All Jobsites</option>
                            <?php foreach ($countries as $c => $n): ?>
                                <option value="<?= strtolower(htmlspecialchars($c)) ?>"><?= htmlspecialchars($c) ?> (<?= (int)$n ?>)</option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Custom button -->
                        <button type="button" id="jobsite-btn" aria-haspopup="listbox" aria-expanded="false"
                            class="w-full flex items-center justify-between gap-3 py-3 sm:py-4 pl-4 pr-4 bg-white border border-gray-300 rounded-xl text-gray-900 text-sm sm:text-base text-left focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-[var(--primary)]">
                            <span class="flex items-center gap-2 min-w-0">
                                <i class="fa-solid fa-earth-asia text-[var(--primary)] shrink-0"></i>
                                <span id="jobsite-label" class="truncate">All Jobsites</span>
                            </span>
                            <i id="jobsite-arrow" class="fa-solid fa-chevron-down text-gray-500 text-xs shrink-0 transition-transform duration-200"></i>
                        </button>

                        <!-- Styled list -->
                        <ul id="jobsite-panel" role="listbox"
                            class="hidden absolute left-0 right-0 top-full mt-2 z-30 max-h-64 overflow-y-auto bg-white border border-navy-200 rounded-xl shadow-xl py-1"></ul>
                    </div>

                    <!-- Opportunity Badge -->
                    <div class="flex items-center justify-center md:justify-start gap-3 bg-[var(--primary-light)] px-4 sm:px-5 py-3 rounded-xl">
                        <i class="fa-solid fa-briefcase text-[var(--primary)] text-lg sm:text-xl"></i>
                        <span class="font-semibold text-[var(--primary)] text-sm sm:text-base whitespace-nowrap"><span id="opportunity-number"><?= $totalOpportunities ?></span> opportunities</span>
                    </div>
                </div>
            </div>

            <!-- Accordion by Year -->
            <div class="space-y-6 sm:space-y-8">
                <?php if (empty($jobsByYear)): ?>
                    <p class="text-center text-gray-500 py-12">No active job orders at the moment. Please check back soon.</p>
                <?php endif; ?>
                <?php foreach ($jobsByYear as $year => $jobs): ?>
                    <details <?= $year == date('Y') ? 'open' : '' ?> class="year-group bg-white enhanced-shadow enhanced-border rounded-2xl overflow-hidden">
                        <summary class="accordion-summary flex items-center justify-between px-4 sm:px-6 py-4 sm:py-5 cursor-pointer bg-gradient-to-r from-[var(--primary-light)] to-white">
                            <div class="flex items-center gap-3 sm:gap-4">
                                <i class="fa-solid fa-calendar-day text-[var(--primary)] text-xl sm:text-2xl"></i>
                                <h3 class="text-lg sm:text-2xl font-bold text-gray-900"><?= $year ?></h3>
                            </div>
                            <i class="fa-solid fa-chevron-down text-gray-600 transition-transform"></i>
                        </summary>
                        <div class="px-3 sm:px-6 pb-6 sm:pb-8 pt-4 space-y-4">
                            <?php foreach ($jobs as $job): ?>
                                <?= render_job_card($job) ?>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
                <p id="no-results" class="hidden text-center text-gray-500 py-12">No vacancies match your search.</p>
            </div>
        </div>
    </section>


    <!-- How It Works Timeline – Adapted for G2G Placement -->
    <section id="how-it-works" class="py-12 sm:py-16 md:py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-10 sm:mb-16">
                <span
                    class="inline-block text-[var(--primary)] font-mono text-xs md:text-sm tracking-[0.2em] sm:tracking-[0.25em] uppercase mb-3 sm:mb-4">
                    STEP-BY-STEP GUIDE
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tighter text-gray-900">
                    Your G2G Journey with DMW
                </h2>
                <p class="mt-3 sm:mt-4 text-base sm:text-xl text-gray-600 max-w-3xl mx-auto">
                    Safe, ethical, and no-fee overseas employment through government-to-government partnerships.
                </p>
            </div>
            <div class="relative">
                <!-- Vertical timeline line (desktop only) -->
                <div class="absolute left-1/2 transform -translate-x-1/2 h-full w-1 bg-[#0A1F44]/20 hidden md:block">
                </div>

                <div class="space-y-8 sm:space-y-16 md:space-y-24">
                    <!-- Step 1: Register on DMW Portal -->
                    <div class="flex flex-col md:flex-row items-center gap-4 sm:gap-8 animate-on-scroll">
                        <div
                            class="w-12 h-12 sm:w-16 sm:h-16 md:w-20 md:h-20 bg-[#0A1F44] text-white rounded-full flex items-center justify-center text-lg sm:text-2xl md:text-3xl font-bold shrink-0 z-10 shadow-lg">
                            1
                        </div>
                        <div
                            class="bg-white p-5 sm:p-8 md:p-10 rounded-3xl shadow-lg flex-1 enhanced-border relative md:ml-12 w-full">
                            <h3 class="text-xl sm:text-2xl md:text-3xl font-bold mb-3 sm:mb-4 text-gray-900">Register on the DMW Portal
                            </h3>
                            <p class="text-sm sm:text-base md:text-lg text-gray-600 leading-relaxed">
                                Create or log in to your account. Complete your profile with personal info, education,
                                work experience, skills, and job preferences.
                            </p>
                        </div>
                    </div>

                    <!-- Step 2: Browse G2G Vacancies -->
                    <div class="flex flex-col md:flex-row items-center gap-4 sm:gap-8 animate-on-scroll">
                        <div
                            class="w-12 h-12 sm:w-16 sm:h-16 md:w-20 md:h-20 bg-[#0A1F44] text-white rounded-full flex items-center justify-center text-lg sm:text-2xl md:text-3xl font-bold shrink-0 z-10 shadow-lg">
                            2
                        </div>
                        <div
                            class="bg-white p-5 sm:p-8 md:p-10 rounded-3xl shadow-lg flex-1 enhanced-border relative md:mr-12 md:order-first w-full">
                            <h3 class="text-xl sm:text-2xl md:text-3xl font-bold mb-3 sm:mb-4 text-gray-900">Browse & Select G2G Vacancies
                            </h3>
                            <p class="text-sm sm:text-base md:text-lg text-gray-600 leading-relaxed">
                                Visit the <a href="https://dmw.gov.ph/pegpb/job-opportunities" target="_blank"
                                    class="text-[var(--primary)] font-medium hover:underline">G2G Job Opportunities
                                    page</a> to view current Recruitment Specification Forms (RSF) from partner
                                countries.
                                Filter by country, position, or control number and choose roles that match your skills.
                            </p>
                        </div>
                    </div>

                    <!-- Step 3: Apply & Submit Requirements -->
                    <div class="flex flex-col md:flex-row items-center gap-4 sm:gap-8 animate-on-scroll">
                        <div
                            class="w-12 h-12 sm:w-16 sm:h-16 md:w-20 md:h-20 bg-[#0A1F44] text-white rounded-full flex items-center justify-center text-lg sm:text-2xl md:text-3xl font-bold shrink-0 z-10 shadow-lg">
                            3
                        </div>
                        <div
                            class="bg-white p-5 sm:p-8 md:p-10 rounded-3xl shadow-lg flex-1 enhanced-border relative md:ml-12 w-full">
                            <h3 class="text-xl sm:text-2xl md:text-3xl font-bold mb-3 sm:mb-4 text-gray-900">Apply & Upload Documents</h3>
                            <p class="text-sm sm:text-base md:text-lg text-gray-600 leading-relaxed">
                                Submit your application through the G2G portal or specified process. Upload required
                                documents such as valid passport, resume, certificates (TESDA/training), employment
                                history, and any language proficiency proofs.
                            </p>
                        </div>
                    </div>

                    <!-- Step 4: Screening & Interview -->
                    <div class="flex flex-col md:flex-row items-center gap-4 sm:gap-8 animate-on-scroll">
                        <div
                            class="w-12 h-12 sm:w-16 sm:h-16 md:w-20 md:h-20 bg-[#0A1F44] text-white rounded-full flex items-center justify-center text-lg sm:text-2xl md:text-3xl font-bold shrink-0 z-10 shadow-lg">
                            4
                        </div>
                        <div
                            class="bg-white p-5 sm:p-8 md:p-10 rounded-3xl shadow-lg flex-1 enhanced-border relative md:mr-12 md:order-first w-full">
                            <h3 class="text-xl sm:text-2xl md:text-3xl font-bold mb-3 sm:mb-4 text-gray-900">Screening, Shortlisting &
                                Interview</h3>
                            <p class="text-sm sm:text-base md:text-lg text-gray-600 leading-relaxed">
                                DMW and/or the partner country's agency reviews applications. Qualified candidates may
                                undergo skills assessment, interviews (virtual or in-person), and further evaluation.
                                Stay updated via your DMW account.
                            </p>
                        </div>
                    </div>

                    <!-- Step 5: Job Offer & Final Processing -->
                    <div class="flex flex-col md:flex-row items-center gap-4 sm:gap-8 animate-on-scroll">
                        <div
                            class="w-12 h-12 sm:w-16 sm:h-16 md:w-20 md:h-20 bg-[#0A1F44] text-white rounded-full flex items-center justify-center text-lg sm:text-2xl md:text-3xl font-bold shrink-0 z-10 shadow-lg">
                            5
                        </div>
                        <div
                            class="bg-white p-5 sm:p-8 md:p-10 rounded-3xl shadow-lg flex-1 enhanced-border relative md:ml-12 w-full">
                            <h3 class="text-xl sm:text-2xl md:text-3xl font-bold mb-3 sm:mb-4 text-gray-900">Receive Offer & Complete
                                Processing</h3>
                            <p class="text-sm sm:text-base md:text-lg text-gray-600 leading-relaxed">
                                If selected, receive a job offer. Undergo medical exam, contract validation,
                                pre-departure orientation (if required), and final document processing. DMW issues your
                                Overseas Employment Certificate (OEC) for deployment.
                            </p>
                        </div>
                    </div>

                    <!-- Step 6: Deployment & On-site Support -->
                    <div class="flex flex-col md:flex-row items-center gap-4 sm:gap-8 animate-on-scroll">
                        <div
                            class="w-12 h-12 sm:w-16 sm:h-16 md:w-20 md:h-20 bg-[#0A1F44] text-white rounded-full flex items-center justify-center text-lg sm:text-2xl md:text-3xl font-bold shrink-0 z-10 shadow-lg">
                            6
                        </div>
                        <div
                            class="bg-white p-5 sm:p-8 md:p-10 rounded-3xl shadow-lg flex-1 enhanced-border relative md:mr-12 md:order-first w-full">
                            <h3 class="text-xl sm:text-2xl md:text-3xl font-bold mb-3 sm:mb-4 text-gray-900">Deployment & Continued
                                Protection</h3>
                            <p class="text-sm sm:text-base md:text-lg text-gray-600 leading-relaxed">
                                Depart safely with full government protection. DMW provides on-site welfare support
                                through embassies/consulates and 24/7 hotlines. Enjoy ethical employment with no
                                placement fees.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Call to Action at bottom -->
            <div class="text-center mt-10 sm:mt-16">
                <a href="login.php" target="_blank" rel="noopener noreferrer"
                    class="inline-flex items-center justify-center gap-3 bg-[var(--primary)] hover:bg-[var(--primary-dark)] text-white px-6 sm:px-10 py-4 sm:py-5 rounded-2xl font-semibold text-base sm:text-lg shadow-xl transition-all transform hover:scale-[1.03] w-full sm:w-auto text-center">
                    Start Your G2G Journey Now <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- News & Announcements -->
    <section class="py-12 sm:py-16 md:py-20 bg-white" id="news">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between mb-8 sm:mb-12 gap-4">
                <div>
                    <span class="text-[var(--primary)] font-mono text-xs sm:text-sm tracking-[3px]">UPDATES</span>
                    <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tighter text-gray-900">News & Announcements</h2>
                </div>
                <a href="https://dmw.gov.ph/news-releases" target="_blank" rel="noopener noreferrer"
                    class="text-[var(--primary)] font-medium flex items-center gap-x-2 group text-sm sm:text-base">
                    View all news on DMW site <span class="transition-transform group-hover:translate-x-1">→</span>
                </a>
            </div>

            <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-6 sm:gap-8">
                <!-- News 1 -->
                <article class="animate-on-scroll bg-white border border-navy-200 rounded-3xl overflow-hidden shadow hover:shadow-xl transition flex flex-col">
                    <div class="h-40 sm:h-48 bg-gradient-to-br from-[var(--primary)] to-navy-700 flex items-center justify-center text-white text-3xl">
                        <i class="fa-solid fa-handshake" aria-hidden="true"></i>
                    </div>
                    <div class="p-6 sm:p-8 flex flex-col flex-1">
                        <span class="self-start mb-3 px-3 py-1 bg-navy-100 text-navy-800 text-xs font-medium rounded-full">Bilateral Cooperation</span>
                        <time datetime="2026-09-16" class="text-sm text-gray-500 mb-2">September 16, 2026</time>
                        <h3 class="text-lg sm:text-xl font-bold mb-3 text-gray-900">DMW Meets Mongolian Delegation on Proposed Labor Agreement</h3>
                        <p class="text-gray-600 text-sm sm:text-base">Discussions focused on a proposed bilateral labor agreement to protect OFWs in Mongolia, held at the DMW Central Office in Mandaluyong City.</p>
                        <a href="https://dmw.gov.ph/news-releases" target="_blank" rel="noopener noreferrer"
                            class="mt-auto pt-4 inline-block text-[var(--primary)] font-medium hover:underline">Read More →</a>
                    </div>
                </article>

                <!-- News 2 -->
                <article class="animate-on-scroll stagger-1 bg-white border border-navy-200 rounded-3xl overflow-hidden shadow hover:shadow-xl transition flex flex-col">
                    <div class="h-40 sm:h-48 bg-gradient-to-br from-[var(--primary)] to-navy-700 flex items-center justify-center text-white text-3xl">
                        <i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i>
                    </div>
                    <div class="p-6 sm:p-8 flex flex-col flex-1">
                        <span class="self-start mb-3 px-3 py-1 bg-navy-100 text-navy-800 text-xs font-medium rounded-full">AKSYON Fund</span>
                        <time datetime="2026-09-16" class="text-sm text-gray-500 mb-2">September 16, 2026</time>
                        <h3 class="text-lg sm:text-xl font-bold mb-3 text-gray-900">DMW-NCR Releases ₱5.95M AKSYON Fund Aid to 118 OFWs</h3>
                        <p class="text-gray-600 text-sm sm:text-base">Financial assistance was distributed to 118 overseas Filipino workers at the DMW Central Office.</p>
                        <a href="https://dmw.gov.ph/news-releases" target="_blank" rel="noopener noreferrer"
                            class="mt-auto pt-4 inline-block text-[var(--primary)] font-medium hover:underline">Read More →</a>
                    </div>
                </article>

                <!-- News 3 -->
                <article class="animate-on-scroll stagger-2 bg-white border border-navy-200 rounded-3xl overflow-hidden shadow hover:shadow-xl transition flex flex-col">
                    <div class="h-40 sm:h-48 bg-gradient-to-br from-[var(--primary)] to-navy-700 flex items-center justify-center text-white text-3xl">
                        <i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i>
                    </div>
                    <div class="p-6 sm:p-8 flex flex-col flex-1">
                        <span class="self-start mb-3 px-3 py-1 bg-navy-100 text-navy-800 text-xs font-medium rounded-full">OFW Assistance</span>
                        <time datetime="2026-09-14" class="text-sm text-gray-500 mb-2">September 14, 2026</time>
                        <h3 class="text-lg sm:text-xl font-bold mb-3 text-gray-900">Over ₱10M AKSYON Fund Aid Delivered to 200 OFWs in Pasay</h3>
                        <p class="text-gray-600 text-sm sm:text-base">OFWs and their families received assistance during the "Handog ng Pangulo: Serbisyong Sapat Para sa Lahat" event at the Pasay City Astrodome.</p>
                        <a href="https://dmw.gov.ph/news-releases" target="_blank" rel="noopener noreferrer"
                            class="mt-auto pt-4 inline-block text-[var(--primary)] font-medium hover:underline">Read More →</a>
                    </div>
                </article>
            </div>

            <p class="mt-8 text-xs sm:text-sm text-gray-500 text-center">
                Source: Department of Migrant Workers.
                <a href="https://dmw.gov.ph/news-releases" target="_blank" rel="noopener noreferrer" class="text-[var(--primary)] hover:underline">Visit the official News Releases page</a>
                for the full articles and the latest updates.
            </p>
        </div>
    </section>

    <!-- Services Section -->
    <section id="services-section"
        class="py-14 sm:py-20 md:py-24 lg:py-32 bg-gradient-to-b from-gray-50 to-white overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-10 sm:gap-12 lg:gap-16 items-start">
                <!-- Left - Sticky Info (sticky only on large screens to avoid mobile overlap) -->
                <div class="lg:col-span-5">
                    <div class="relative lg:sticky lg:top-28 z-10">
                        <span
                            class="inline-block bg-[var(--primary)] text-white text-xs font-mono tracking-[0.2em] sm:tracking-[0.25em] uppercase px-4 sm:px-5 py-2 sm:py-2.5 rounded-3xl mb-4 sm:mb-6 shadow-sm">
                            WHAT WE OFFER
                        </span>

                        <h2
                            class="animate-on-scroll text-3xl sm:text-4xl lg:text-5xl xl:text-6xl font-bold tracking-tight leading-tight mb-4 sm:mb-6 lg:mb-8 text-gray-900">
                            Comprehensive<br class="hidden sm:inline"> Services for OFWs
                        </h2>

                        <p
                            class="animate-on-scroll delay-200 text-base sm:text-lg md:text-xl text-gray-700 leading-relaxed mb-6 sm:mb-10 max-w-xl">
                            From pre-departure preparation to safe return and reintegration,
                            the Department of Migrant Workers stands with every OFW at every step.
                        </p>

                        <div class="animate-on-scroll delay-300 mt-4 sm:mt-8 grid grid-cols-2 gap-3 sm:gap-5">
                            <div class="bg-white rounded-2xl p-4 sm:p-6 shadow-md border border-gray-100 text-center">
                                <div class="text-[var(--primary)] text-3xl sm:text-4xl md:text-5xl font-bold mb-1">24/7</div>
                                <div class="text-xs sm:text-sm md:text-base font-medium text-gray-600">Response Time</div>
                            </div>
                            <div class="bg-white rounded-2xl p-4 sm:p-6 shadow-md border border-gray-100 text-center">
                                <div class="text-[var(--primary)] text-3xl sm:text-4xl md:text-5xl font-bold mb-1">150+</div>
                                <div class="text-xs sm:text-sm md:text-base font-medium text-gray-600">Partner Countries</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right - Service Cards -->
                <div class="lg:col-span-7 grid sm:grid-cols-2 gap-5 sm:gap-6 lg:gap-8">
                    <!-- Card 1 -->
                    <div
                        class="animate-on-scroll stagger-1 ripple bg-white rounded-3xl p-6 sm:p-8 lg:p-10 shadow-md hover:shadow-xl transition-all duration-300 group border border-gray-100/70">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-navy-100 text-[var(--primary)] flex items-center justify-center text-2xl sm:text-3xl mb-4 sm:mb-6 lg:mb-8 transition-transform duration-300 group-hover:scale-110">
                            <i class="fa-solid fa-earth-asia"></i>
                        </div>
                        <h3 class="text-xl sm:text-2xl font-bold mb-3 sm:mb-4 text-gray-900">Pre-Employment</h3>
                        <ul class="space-y-2.5 sm:space-y-3.5 text-gray-700 text-sm sm:text-[15px]">
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Document verification & authentication</li>
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Skills matching & training</li>
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Employment contract review</li>
                        </ul>
                    </div>

                    <!-- Card 2 -->
                    <div
                        class="animate-on-scroll stagger-2 ripple bg-white rounded-3xl p-6 sm:p-8 lg:p-10 shadow-md hover:shadow-xl transition-all duration-300 group border border-gray-100/70">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-navy-100 text-[var(--primary)] flex items-center justify-center text-2xl sm:text-3xl mb-4 sm:mb-6 lg:mb-8 transition-transform duration-300 group-hover:scale-110">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <h3 class="text-xl sm:text-2xl font-bold mb-3 sm:mb-4 text-gray-900">On-Site Protection</h3>
                        <ul class="space-y-2.5 sm:space-y-3.5 text-gray-700 text-sm sm:text-[15px]">
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                24/7 OWWA-DMW hotline support</li>
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Philippine Embassy / Consulate assistance</li>
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Real-time welfare case monitoring</li>
                        </ul>
                    </div>

                    <!-- Card 3 -->
                    <div
                        class="animate-on-scroll stagger-3 ripple bg-white rounded-3xl p-6 sm:p-8 lg:p-10 shadow-md hover:shadow-xl transition-all duration-300 group border border-gray-100/70">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-navy-100 text-[var(--primary)] flex items-center justify-center text-2xl sm:text-3xl mb-4 sm:mb-6 lg:mb-8 transition-transform duration-300 group-hover:scale-110">
                            <i class="fa-solid fa-house-circle-check"></i>
                        </div>
                        <h3 class="text-xl sm:text-2xl font-bold mb-3 sm:mb-4 text-gray-900">Reintegration</h3>
                        <ul class="space-y-2.5 sm:space-y-3.5 text-gray-700 text-sm sm:text-[15px]">
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Livelihood & entrepreneurship programs</li>
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Skills upgrading & re-training</li>
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Financial literacy & reintegration grants</li>
                        </ul>
                    </div>

                    <!-- Card 4 -->
                    <div
                        class="animate-on-scroll stagger-4 ripple bg-white rounded-3xl p-6 sm:p-8 lg:p-10 shadow-md hover:shadow-xl transition-all duration-300 group border border-gray-100/70">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-navy-100 text-[var(--primary)] flex items-center justify-center text-2xl sm:text-3xl mb-4 sm:mb-6 lg:mb-8 transition-transform duration-300 group-hover:scale-110">
                            <i class="fa-solid fa-scale-balanced"></i>
                        </div>
                        <h3 class="text-xl sm:text-2xl font-bold mb-3 sm:mb-4 text-gray-900">Policy & Advocacy</h3>
                        <ul class="space-y-2.5 sm:space-y-3.5 text-gray-700 text-sm sm:text-[15px]">
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Bilateral labor agreements negotiation</li>
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Representation in international forums</li>
                            <li class="flex items-start gap-x-3"><span class="text-emerald-500 text-lg mt-0.5">•</span>
                                Protection of OFW rights & welfare</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact" class="bg-[#0A1F44] text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-14 sm:pt-20">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-y-10 sm:gap-y-16 gap-x-8">
                <!-- Logo Column -->
                <div class="sm:col-span-2 lg:col-span-4">
                    <div class="flex items-center gap-x-3 sm:gap-x-4 mb-6 sm:mb-8">
                        <div class="w-11 h-11 sm:w-14 sm:h-14 flex items-center justify-center overflow-hidden shrink-0">
                            <img src="pictures/dmw_logo.png"
                                alt="Department of Migrant Workers Logo"
                                class="w-full h-full object-contain p-1.5 sm:p-2">
                        </div>
                        <div class="text-lg sm:text-2xl font-bold">Department of Migrant Workers</div>
                    </div>
                    <p class="text-blue-200 max-w-xs text-sm sm:text-base">Serving and protecting the modern-day heroes of the Philippines.
                    </p>

                    <div class="mt-6 sm:mt-10 flex gap-x-5 sm:gap-x-6 text-xl sm:text-2xl">
                        <a href="https://www.facebook.com/DMWRegion4a" class="hover:text-blue-300"><i class="fa-brands fa-facebook"></i></a>
                        <a href="https://x.com/dmwphl" class="hover:text-blue-300"><i class="fa-brands fa-x-twitter"></i></a>
                        <a href="https://www.tiktok.com/@dmwphl" class="hover:text-blue-300"><i class="fa-brands fa-tiktok"></i></a>
                        <a href="https://www.youtube.com/@DMWPHL" class="hover:text-blue-300"><i class="fa-brands fa-youtube"></i></a>
                    </div>
                </div>

                <!-- Links -->
                <div class="lg:col-span-2">
                    <div class="uppercase text-xs tracking-widest text-blue-300 mb-4 sm:mb-6">Quick Links</div>
                    <div class="space-y-3 sm:space-y-4 text-blue-100 text-sm sm:text-base">
                        <a href="login.php" class="block hover:text-white">OFW Portal</a>
                        <a href="#" class="block hover:text-white">OEC Application</a>
                        <a href="#" class="block hover:text-white">OFW ID</a>
                        <a href="#" class="block hover:text-white">Hotline: 1348</a>
                    </div>
                </div>

                <div class="lg:col-span-2">
                    <div class="uppercase text-xs tracking-widest text-blue-300 mb-4 sm:mb-6">Resources</div>
                    <div class="space-y-3 sm:space-y-4 text-blue-100 text-sm sm:text-base">
                        <a href="#" class="block hover:text-white">Laws &amp; Policies</a>
                        <a href="#" class="block hover:text-white">Download Forms</a>
                        <a href="#" class="block hover:text-white">Statistics</a>
                        <a href="#" class="block hover:text-white">Research</a>
                    </div>
                </div>

                <div class="sm:col-span-2 lg:col-span-4">
                    <div class="uppercase text-xs tracking-widest text-blue-300 mb-4 sm:mb-6">Get in touch</div>
                    <div class="bg-white/10 backdrop-blur-lg rounded-3xl p-6 sm:p-8">
                        <div class="flex flex-col xs:flex-row gap-4 sm:gap-x-4 text-sm">
                            <div>
                                <div class="text-blue-300 text-xs">MAIN OFFICE</div>
                                <div class="mt-2 leading-tight">Andenson Bldg 3<br>Brgy. Parian<br>Calamba, Philippines, 4027</div>
                            </div>
                            <div>
                                <div class="text-blue-300 text-xs">HOTLINES</div>
                                <div class="mt-2 space-y-1">
                                    <div>Domestic: 1348</div>
                                    <div>International: +63 2 8722 1144</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom -->
            <div
                class="border-t border-blue-800 mt-12 sm:mt-20 py-6 sm:py-8 text-xs text-blue-300 flex flex-col md:flex-row justify-between items-center gap-y-4 text-center md:text-left">
                <div>© 2026 Department of Migrant Workers. All Rights Reserved.</div>
                <div class="flex flex-wrap justify-center gap-x-6 sm:gap-x-8">
                    <a href="#" class="hover:text-white">Privacy Policy</a>
                    <a href="#" class="hover:text-white">Terms of Use</a>
                    <a href="#" class="hover:text-white">Accessibility</a>
                </div>
            </div>
        </div>
    </footer>


    <script>
        //  HELPERS
        // Simple class toggle helper
        function toggleClass(el, className) {
            el?.classList.toggle(className);
        }

        // Close modal helper
        function closeModal(modal) {
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.style.overflow = ''; // restore scroll
            }
        }

        //  MOBILE MENU

        const mobileBtn = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');

        if (mobileBtn && mobileMenu) {
            mobileBtn.addEventListener('click', () => {
                const isOpen = !mobileMenu.classList.contains('hidden');

                toggleClass(mobileMenu, 'hidden');
                mobileBtn.setAttribute('aria-expanded', !isOpen);

                mobileBtn.innerHTML = isOpen ?
                    `<i class="fa-solid fa-bars"></i>` :
                    `<i class="fa-solid fa-xmark"></i>`;
            });

            // Close mobile menu when clicking a link (optional UX improvement)
            mobileMenu.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    mobileMenu.classList.add('hidden');
                    mobileBtn.innerHTML = `<i class="fa-solid fa-bars"></i>`;
                    mobileBtn.setAttribute('aria-expanded', 'false');
                });
            });
        }

        //  ESC KEY + ACCESSIBILITY
        document.addEventListener('keydown', (e) => {
            if (e.key === "Escape") {
                if (!portalModal?.classList.contains('hidden')) {
                    hidePortalModal();
                }
                // Could also close mobile menu here if desired
            }
        });

        //  SCROLL ANIMATIONS (for .animate-on-scroll)
        const animateObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        // Optional: stop observing after animation
                        // animateObserver.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.12,
                rootMargin: "0px 0px -8% 0px"
            }
        );

        document.querySelectorAll('.animate-on-scroll').forEach((el) => {
            animateObserver.observe(el);
        });

        /* Smooth 3-Second Count-Up System */

        function formatNumber(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1).replace(/\.0$/, '') + "M"; // removes .0 for clean 4.2M → 4.2M, 4.0M → 4M
            }
            if (num >= 1000) {
                return (num / 1000).toFixed(1).replace(/\.0$/, '') + "K";
            }
            return num.toString();
        }

        function animateValue(el, start, end, duration = 5000) { // ← duration changed to 5000ms
            let startTimestamp = null;

            function step(timestamp) {
                if (!startTimestamp) startTimestamp = timestamp;

                const progress = timestamp - startTimestamp;
                let percentage = progress / duration;

                if (percentage > 1) percentage = 1;

                // Ease-out cubic for smooth finish
                percentage = 1 - Math.pow(1 - percentage, 3);

                const value = Math.floor(start + (end - start) * percentage);

                el.textContent = formatNumber(value);

                if (progress < duration) {
                    requestAnimationFrame(step);
                } else {
                    el.textContent = formatNumber(end);
                }
            }

            requestAnimationFrame(step);
        }

        document.addEventListener("DOMContentLoaded", function() {
            const statsBadge = document.getElementById("stats-badge");
            if (!statsBadge) return;

            const counterObserver = new IntersectionObserver(
                (entries) => {
                    if (entries[0].isIntersecting) {
                        document.querySelectorAll(".count-up").forEach((el) => {
                            const target = parseInt(el.getAttribute("data-target"), 10);
                            animateValue(el, 0, target, 5000); // ← also set 5 seconds here
                        });
                        counterObserver.disconnect();
                    }
                }, {
                    threshold: 0.4
                }
            );

            counterObserver.observe(statsBadge);
        })

        //  RIPPLE EFFECT ACTIVATION (for .ripple elements)
        document.addEventListener('click', (e) => {
            const el = e.target.closest('.ripple');
            if (!el) return;

            const rect = el.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;

            const ripple = document.createElement('span');
            ripple.className = 'absolute bg-white/30 rounded-full pointer-events-none transform scale-0 opacity-100';
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            ripple.style.width = '0';
            ripple.style.height = '0';

            el.appendChild(ripple);

            setTimeout(() => {
                ripple.style.transition = 'all 0.6s';
                ripple.style.width = '300px';
                ripple.style.height = '300px';
                ripple.style.marginLeft = '-150px';
                ripple.style.marginTop = '-150px';
                ripple.style.opacity = '0';
            }, 10);

            setTimeout(() => ripple.remove(), 700);
        });
    </script>
    <script>
        (function() {
            const searchInput = document.getElementById('g2g-search');
            const jobsiteSelect = document.getElementById('jobsite');
            const cards = Array.from(document.querySelectorAll('.job-card'));
            const yearGroups = document.querySelectorAll('.year-group');
            const countEl = document.getElementById('opportunity-number');
            const noResults = document.getElementById('no-results');
            if (!searchInput || !jobsiteSelect) return;

            function addOption(value, text) {
                const opt = document.createElement('option');
                opt.value = value;
                opt.textContent = text;
                jobsiteSelect.appendChild(opt);
            }

            // Rebuild the dropdown from the cards that match the search text
            function rebuildJobsites(term) {
                const current = jobsiteSelect.value;
                const counts = new Map();
                let matched = 0;

                cards.forEach(card => {
                    if (!card.dataset.search.includes(term)) return;
                    matched++;
                    const key = card.dataset.country;
                    if (!key) return;
                    const entry = counts.get(key) || {
                        label: card.dataset.countryLabel,
                        n: 0
                    };
                    entry.n++;
                    counts.set(key, entry);
                });

                jobsiteSelect.innerHTML = '';
                addOption('all', 'All Jobsites (' + matched + ')');
                Array.from(counts.entries())
                    .sort((a, b) => a[1].label.localeCompare(b[1].label))
                    .forEach(([key, e]) => addOption(key, e.label + ' (' + e.n + ')'));

                jobsiteSelect.value = counts.has(current) ? current : 'all';
            }

            function applyFilters() {
                const term = searchInput.value.toLowerCase().trim();
                rebuildJobsites(term);
                const country = jobsiteSelect.value;

                let slots = 0,
                    visible = 0;
                cards.forEach(card => {
                    const show = card.dataset.search.includes(term) &&
                        (country === 'all' || card.dataset.country === country);
                    card.style.display = show ? '' : 'none';
                    if (show) {
                        visible++;
                        slots += parseInt(card.dataset.slots, 10) || 0;
                    }
                });

                yearGroups.forEach(group => {
                    const hasVisible = Array.from(group.querySelectorAll('.job-card'))
                        .some(c => c.style.display !== 'none');
                    group.style.display = hasVisible ? '' : 'none';
                });

                if (countEl) countEl.textContent = slots;
                if (noResults) noResults.classList.toggle('hidden', visible > 0);
            }

            searchInput.addEventListener('input', applyFilters);
            jobsiteSelect.addEventListener('change', applyFilters);

            // Custom dropdown UI
            const wrap = document.getElementById('jobsite-wrap');
            const btn = document.getElementById('jobsite-btn');
            const btnLabel = document.getElementById('jobsite-label');
            const panel = document.getElementById('jobsite-panel');
            const arrow = document.getElementById('jobsite-arrow');

            function setOpen(state) {
                panel.classList.toggle('hidden', !state);
                btn.setAttribute('aria-expanded', state);
                arrow.classList.toggle('rotate-180', state);
            }

            function choose(value) {
                jobsiteSelect.value = value;
                jobsiteSelect.dispatchEvent(new Event('change'));
                renderPanel();
                setOpen(false);
                btn.focus();
            }

            function renderPanel() {
                panel.innerHTML = '';
                Array.from(jobsiteSelect.options).forEach(opt => {
                    const selected = opt.value === jobsiteSelect.value;
                    const m = opt.textContent.match(/^(.*) \((\d+)\)$/);
                    const name = m ? m[1] : opt.textContent;
                    const count = m ? m[2] : '';

                    const li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-selected', selected);
                    li.tabIndex = -1;
                    li.dataset.value = opt.value;
                    li.className = 'flex items-center justify-between gap-3 px-4 py-2.5 text-sm cursor-pointer transition-colors focus:outline-none ' +
                        (selected ?
                            'bg-navy-100 text-navy-800 font-semibold' :
                            'text-gray-700 hover:bg-navy-50 focus:bg-navy-50');

                    li.innerHTML =
                        '<span class="flex items-center gap-2 min-w-0">' +
                        '<i class="fa-solid fa-check text-xs text-[var(--primary)] w-3 ' + (selected ? '' : 'invisible') + '"></i>' +
                        '<span class="truncate"></span></span>' +
                        (count ? '<span class="text-xs bg-navy-100 text-navy-700 rounded-full px-2 py-0.5 shrink-0">' + count + '</span>' : '');
                    li.querySelector('.truncate').textContent = name; // textContent avoids XSS

                    li.addEventListener('click', () => choose(opt.value));
                    panel.appendChild(li);
                });

                const current = jobsiteSelect.options[jobsiteSelect.selectedIndex];
                btnLabel.textContent = current ? current.textContent : 'All Jobsites';
            }

            // Re-draw the list whenever the filter code rebuilds the options
            new MutationObserver(renderPanel).observe(jobsiteSelect, {
                childList: true
            });

            btn.addEventListener('click', () => setOpen(panel.classList.contains('hidden')));

            document.addEventListener('click', (e) => {
                if (!wrap.contains(e.target)) setOpen(false);
            });

            wrap.addEventListener('keydown', (e) => {
                const items = Array.from(panel.children);
                const idx = items.indexOf(document.activeElement);
                if (e.key === 'Escape') {
                    setOpen(false);
                    btn.focus();
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setOpen(true);
                    items[Math.min(idx + 1, items.length - 1)]?.focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    items[Math.max(idx - 1, 0)]?.focus();
                } else if (e.key === 'Enter' && idx > -1) {
                    e.preventDefault();
                    choose(items[idx].dataset.value);
                }
            });

            renderPanel();
        })();
    </script>
    <script>
        (function() {
            const DICT = {
                // Top bar & nav
                "Mon - Fri: 8:00 AM - 5:00 PM": "Lun - Biy: 8:00 NU - 5:00 NH",
                "Department of": "Kagawaran ng",
                "Migrant Workers": "Manggagawang Migrante",
                "Department of Migrant Workers": "Kagawaran ng Manggagawang Migrante",
                "Department of Migrant Workers Logo": "Logo ng Kagawaran ng Manggagawang Migrante",
                "REPUBLIC OF THE PHILIPPINES": "REPUBLIKA NG PILIPINAS",
                "Home": "Tahanan",
                "About": "Tungkol sa Amin",
                "Our Mission, Vision & Core Values": "Aming Misyon, Bisyon, at Pangunahing Pagpapahalaga",
                "Our Mandate": "Aming Mandato",
                "Organizational Structure": "Estruktura ng Organisasyon",
                "Vacancies": "Mga Bakante",
                "How It Works": "Paano Ito Gumagana",
                "News": "Balita",
                "Services": "Mga Serbisyo",
                "Contact": "Makipag-ugnayan",
                "Applicant Portal": "Portal ng Aplikante",
                "Language": "Wika",

                // Hero
                "PROTECTING FILIPINO WORKERS WORLDWIDE": "PINOPROTEKTAHAN ANG MGA MANGGAGAWANG PILIPINO SA BUONG MUNDO",
                "Empowering and protecting Overseas Filipino Workers through comprehensive services, welfare programs, and policy advocacy for a dignified and safe migration experience.": "Pinapalakas at pinoprotektahan ang mga Overseas Filipino Worker sa pamamagitan ng komprehensibong serbisyo, mga programang pangkapakanan, at adbokasiya sa patakaran para sa marangal at ligtas na karanasan sa migrasyon.",
                "Access OFW Services →": "Ma-access ang mga Serbisyo para sa OFW →",
                "Learn More": "Alamin Pa",
                "Certified & Committed to Excellence": "Sertipikado at Nakatuon sa Kahusayan",
                "ISO 9001:2015 Certified": "Sertipikado ng ISO 9001:2015",
                "Quality Management": "Pamamahala ng Kalidad",
                "Philippine Transparency Seal": "Transparency Seal ng Pilipinas",
                "Full Compliance": "Ganap na Pagsunod",
                "Service Excellence": "Kahusayan sa Serbisyo",
                "OFWs PROTECTED": "MGA OFW NA PROTEKTADO",
                "COUNTRIES": "MGA BANSA",
                "Based on latest DMW reports": "Batay sa pinakabagong ulat ng DMW",

                // Vacancies
                "G2G OPPORTUNITIES": "MGA OPORTUNIDAD SA G2G",
                "Government-to-Government Vacancies": "Mga Bakanteng Government-to-Government",
                "Browse ethical, no-fee overseas jobs through bilateral government partnerships. Updated from official DMW listings.": "Maghanap ng etikal at walang-bayad na trabaho sa ibang bansa sa pamamagitan ng bilateral na pakikipagtulungan ng mga pamahalaan. Binabago mula sa opisyal na listahan ng DMW.",
                "View All on DMW Site →": "Tingnan Lahat sa Site ng DMW →",
                "Search vacancies": "Maghanap ng bakante",
                "Search by position, employer, control number...": "Maghanap ayon sa posisyon, employer, control number...",
                "Jobsite": "Lugar ng Trabaho",
                "opportunities": "mga oportunidad",
                "No active job orders at the moment. Please check back soon.": "Walang aktibong job order sa ngayon. Bumalik muli sa lalong madaling panahon.",
                "No vacancies match your search.": "Walang bakanteng tumutugma sa iyong paghahanap.",

                // Job cards
                "No Placement Fee": "Walang Bayad sa Placement",
                "Closed": "Sarado",
                "Salary not specified": "Hindi tinukoy ang sahod",
                "VIEW RSF": "TINGNAN ANG RSF",
                "RSF document not yet uploaded": "Hindi pa naka-upload ang dokumento ng RSF",
                "(Position not set)": "(Hindi pa naitakda ang posisyon)",

                // Timeline
                "STEP-BY-STEP GUIDE": "GABAY NA HAKBANG-HAKBANG",
                "Your G2G Journey with DMW": "Ang Iyong G2G Journey Kasama ang DMW",
                "Safe, ethical, and no-fee overseas employment through government-to-government partnerships.": "Ligtas, etikal, at walang-bayad na trabaho sa ibang bansa sa pamamagitan ng government-to-government na pakikipagtulungan.",
                "Register on the DMW Portal": "Magrehistro sa DMW Portal",
                "Create or log in to your account. Complete your profile with personal info, education, work experience, skills, and job preferences.": "Gumawa o mag-log in sa iyong account. Kumpletuhin ang iyong profile gamit ang personal na impormasyon, edukasyon, karanasan sa trabaho, kasanayan, at piniling trabaho.",
                "Browse & Select G2G Vacancies": "Mag-browse at Pumili ng G2G na Bakante",
                "Visit the": "Bisitahin ang",
                "G2G Job Opportunities page": "pahina ng G2G Job Opportunities",
                "to view current Recruitment Specification Forms (RSF) from partner countries. Filter by country, position, or control number and choose roles that match your skills.": "para makita ang kasalukuyang Recruitment Specification Forms (RSF) mula sa mga katuwang na bansa. I-filter ayon sa bansa, posisyon, o control number at pumili ng mga trabahong angkop sa iyong kasanayan.",
                "Apply & Upload Documents": "Mag-apply at Mag-upload ng mga Dokumento",
                "Submit your application through the G2G portal or specified process. Upload required documents such as valid passport, resume, certificates (TESDA/training), employment history, and any language proficiency proofs.": "Isumite ang iyong aplikasyon sa pamamagitan ng G2G portal o itinakdang proseso. I-upload ang mga kinakailangang dokumento tulad ng balidong pasaporte, resume, mga sertipiko (TESDA/pagsasanay), kasaysayan ng pagtatrabaho, at anumang patunay ng kasanayan sa wika.",
                "Screening, Shortlisting & Interview": "Screening, Shortlisting, at Panayam",
                "DMW and/or the partner country's agency reviews applications. Qualified candidates may undergo skills assessment, interviews (virtual or in-person), and further evaluation. Stay updated via your DMW account.": "Susuriin ng DMW at/o ng ahensya ng katuwang na bansa ang mga aplikasyon. Ang mga kwalipikadong kandidato ay maaaring sumailalim sa pagtatasa ng kasanayan, panayam (virtual o personal), at karagdagang pagsusuri. Manatiling updated sa pamamagitan ng iyong DMW account.",
                "Receive Offer & Complete Processing": "Tumanggap ng Alok at Kumpletuhin ang Proseso",
                "If selected, receive a job offer. Undergo medical exam, contract validation, pre-departure orientation (if required), and final document processing. DMW issues your Overseas Employment Certificate (OEC) for deployment.": "Kung mapili, makatatanggap ka ng alok na trabaho. Sasailalim ka sa medical exam, pagpapatunay ng kontrata, pre-departure orientation (kung kinakailangan), at huling pagproseso ng mga dokumento. Ilalabas ng DMW ang iyong Overseas Employment Certificate (OEC) para sa deployment.",
                "Deployment & Continued Protection": "Deployment at Patuloy na Proteksyon",
                "Depart safely with full government protection. DMW provides on-site welfare support through embassies/consulates and 24/7 hotlines. Enjoy ethical employment with no placement fees.": "Umalis nang ligtas na may buong proteksyon ng pamahalaan. Nagbibigay ang DMW ng suportang pangkapakanan sa lugar ng trabaho sa pamamagitan ng mga embahada/konsulado at 24/7 na hotline. Magtrabaho nang etikal at walang placement fee.",
                "Start Your G2G Journey Now": "Simulan Na ang Iyong G2G Journey",

                // News
                "UPDATES": "MGA UPDATE",
                "News & Announcements": "Balita at Anunsyo",
                "View all news on DMW site": "Tingnan ang lahat ng balita sa site ng DMW",
                "Bilateral Cooperation": "Bilateral na Kooperasyon",
                "DMW Meets Mongolian Delegation on Proposed Labor Agreement": "Nakipagpulong ang DMW sa Delegasyon ng Mongolia Ukol sa Iminungkahing Kasunduan sa Paggawa",
                "Discussions focused on a proposed bilateral labor agreement to protect OFWs in Mongolia, held at the DMW Central Office in Mandaluyong City.": "Nakatuon ang talakayan sa iminungkahing bilateral na kasunduan sa paggawa para protektahan ang mga OFW sa Mongolia, na ginanap sa Central Office ng DMW sa Lungsod ng Mandaluyong.",
                "DMW-NCR Releases ₱5.95M AKSYON Fund Aid to 118 OFWs": "Naglabas ang DMW-NCR ng ₱5.95M na Tulong mula sa AKSYON Fund sa 118 OFW",
                "Financial assistance was distributed to 118 overseas Filipino workers at the DMW Central Office.": "Namahagi ng tulong-pinansyal sa 118 overseas Filipino worker sa Central Office ng DMW.",
                "OFW Assistance": "Tulong sa OFW",
                "Over ₱10M AKSYON Fund Aid Delivered to 200 OFWs in Pasay": "Mahigit ₱10M na Tulong mula sa AKSYON Fund, Naihatid sa 200 OFW sa Pasay",
                "OFWs and their families received assistance during the \"Handog ng Pangulo: Serbisyong Sapat Para sa Lahat\" event at the Pasay City Astrodome.": "Tumanggap ng tulong ang mga OFW at kanilang pamilya sa programang \"Handog ng Pangulo: Serbisyong Sapat Para sa Lahat\" sa Pasay City Astrodome.",
                "Read More →": "Basahin Pa →",
                "Source: Department of Migrant Workers.": "Pinagmulan: Kagawaran ng Manggagawang Migrante.",
                "Visit the official News Releases page": "Bisitahin ang opisyal na pahina ng News Releases",
                "for the full articles and the latest updates.": "para sa buong artikulo at pinakabagong balita.",

                // Services
                "WHAT WE OFFER": "ALOK NAMIN",
                "Comprehensive": "Komprehensibong",
                "Services for OFWs": "Serbisyo para sa mga OFW",
                "From pre-departure preparation to safe return and reintegration, the Department of Migrant Workers stands with every OFW at every step.": "Mula sa paghahanda bago umalis hanggang sa ligtas na pag-uwi at reintegrasyon, kasama ng Kagawaran ng Manggagawang Migrante ang bawat OFW sa bawat hakbang.",
                "Response Time": "Oras ng Pagtugon",
                "Partner Countries": "Mga Katuwang na Bansa",
                "Pre-Employment": "Bago ang Pagtatrabaho",
                "Document verification & authentication": "Pagberipika at pagpapatunay ng dokumento",
                "Skills matching & training": "Pagtutugma ng kasanayan at pagsasanay",
                "Employment contract review": "Pagsusuri ng kontrata sa trabaho",
                "On-Site Protection": "Proteksyon sa Lugar ng Trabaho",
                "24/7 OWWA-DMW hotline support": "24/7 na suporta ng hotline ng OWWA-DMW",
                "Philippine Embassy / Consulate assistance": "Tulong ng Embahada / Konsulado ng Pilipinas",
                "Real-time welfare case monitoring": "Real-time na pagsubaybay sa mga kaso ng kapakanan",
                "Reintegration": "Reintegrasyon",
                "Livelihood & entrepreneurship programs": "Mga programang pangkabuhayan at pangnegosyo",
                "Skills upgrading & re-training": "Pagpapahusay ng kasanayan at muling pagsasanay",
                "Financial literacy & reintegration grants": "Kaalamang pampinansyal at mga grant sa reintegrasyon",
                "Policy & Advocacy": "Patakaran at Adbokasiya",
                "Bilateral labor agreements negotiation": "Negosasyon ng mga bilateral na kasunduan sa paggawa",
                "Representation in international forums": "Representasyon sa mga pandaigdigang forum",
                "Protection of OFW rights & welfare": "Proteksyon sa karapatan at kapakanan ng mga OFW",

                // Footer
                "Serving and protecting the modern-day heroes of the Philippines.": "Naglilingkod at nagpoprotekta sa mga makabagong bayani ng Pilipinas.",
                "Quick Links": "Mabilisang Link",
                "OFW Portal": "Portal ng OFW",
                "OEC Application": "Aplikasyon sa OEC",
                "Resources": "Mga Mapagkukunan",
                "Laws & Policies": "Mga Batas at Patakaran",
                "Download Forms": "Mag-download ng mga Form",
                "Statistics": "Estadistika",
                "Research": "Pananaliksik",
                "Get in touch": "Makipag-ugnayan",
                "MAIN OFFICE": "PANGUNAHING OPISINA",
                "Calamba, Philippines, 4027": "Calamba, Pilipinas, 4027",
                "HOTLINES": "MGA HOTLINE",
                "Domestic: 1348": "Lokal: 1348",
                "International: +63 2 8722 1144": "Internasyonal: +63 2 8722 1144",
                "© 2026 Department of Migrant Workers. All Rights Reserved.": "© 2026 Kagawaran ng Manggagawang Migrante. Nakareserba ang Lahat ng Karapatan.",
                "Privacy Policy": "Patakaran sa Privacy",
                "Terms of Use": "Mga Tuntunin sa Paggamit",
                "Accessibility": "Pagiging Naa-access"
            };

            const MONTHS = {
                January: 'Enero',
                February: 'Pebrero',
                March: 'Marso',
                April: 'Abril',
                May: 'Mayo',
                June: 'Hunyo',
                July: 'Hulyo',
                August: 'Agosto',
                September: 'Setyembre',
                October: 'Oktubre',
                November: 'Nobyembre',
                December: 'Disyembre'
            };

            // Dynamic strings (numbers / values built by PHP and JS)
            const PATTERNS = [
                [/^Control No\. (.*)$/, m => 'Control Blg. ' + m[1]],
                [/^(\d+) days left to apply$/, m => m[1] + ' araw na lang para mag-apply'],
                [/^(.*) \/ month$/, m => m[1] + ' / buwan'],
                [/^All Jobsites(?: \((\d+)\))?$/, m => 'Lahat ng Lugar ng Trabaho' + (m[1] !== undefined ? ' (' + m[1] + ')' : '')],
                [new RegExp('^(' + Object.keys(MONTHS).join('|') + ') (\\d{1,2}), (\\d{4})$'), m => MONTHS[m[1]] + ' ' + m[2] + ', ' + m[3]]
            ];

            const ATTRS = ['placeholder', 'alt', 'aria-label'];
            const SKIP = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA', 'OPTION']);
            const ORIG_TITLE = document.title;
            const norm = s => s.replace(/\s+/g, ' ').trim();
            const nodeOrig = new WeakMap();
            let lang = 'en';

            function tr(str) {
                const core = norm(str);
                if (!core) return str;
                let t = DICT[core];
                if (t === undefined) {
                    for (const [re, fn] of PATTERNS) {
                        const m = core.match(re);
                        if (m) {
                            t = fn(m);
                            break;
                        }
                    }
                }
                if (t === undefined) return str;
                return str.match(/^\s*/)[0] + t + str.match(/\s*$/)[0];
            }

            function applyNode(node) {
                const rec = nodeOrig.get(node);
                if (lang === 'fil') {
                    if (rec && node.nodeValue === rec.shown) return;
                    const orig = node.nodeValue,
                        t = tr(orig);
                    if (t !== orig) {
                        nodeOrig.set(node, {
                            orig,
                            shown: t
                        });
                        node.nodeValue = t;
                    } else nodeOrig.delete(node);
                } else {
                    if (rec && node.nodeValue === rec.shown) node.nodeValue = rec.orig;
                    nodeOrig.delete(node);
                }
            }

            function applyAttrs(root) {
                const els = root.querySelectorAll ? root.querySelectorAll('[placeholder],[alt],[aria-label]') : [];
                const list = root.matches && root.matches('[placeholder],[alt],[aria-label]') ? [root, ...els] : els;
                list.forEach(el => ATTRS.forEach(a => {
                    if (!el.hasAttribute(a)) return;
                    const key = 'data-i18n-' + a;
                    if (lang === 'fil') {
                        const orig = el.hasAttribute(key) ? el.getAttribute(key) : el.getAttribute(a);
                        el.setAttribute(key, orig);
                        el.setAttribute(a, tr(orig));
                    } else if (el.hasAttribute(key)) {
                        el.setAttribute(a, el.getAttribute(key));
                    }
                }));
            }

            function walk(root) {
                if (root.nodeType === 3) {
                    applyNode(root);
                    return;
                }
                if (root.nodeType !== 1) return;
                const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                    acceptNode: n => SKIP.has(n.parentNode.nodeName) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT
                });
                const nodes = [];
                while (w.nextNode()) nodes.push(w.currentNode);
                nodes.forEach(applyNode);
                applyAttrs(root);
            }

            function setLang(next) {
                lang = next;
                document.documentElement.lang = next;
                document.title = next === 'fil' ? tr(ORIG_TITLE) : ORIG_TITLE;
                walk(document.body);
                document.querySelectorAll('.lang-btn').forEach(b =>
                    b.setAttribute('aria-pressed', b.dataset.lang === next ? 'true' : 'false'));
                try {
                    localStorage.setItem('dmw_lang', next);
                } catch (e) {}
            }

            // Translate content that JavaScript adds later (job filter, dropdown list, counters)
            new MutationObserver(muts => {
                if (lang !== 'fil') return;
                muts.forEach(m => {
                    if (m.type === 'characterData') {
                        if (m.target.parentNode && !SKIP.has(m.target.parentNode.nodeName)) applyNode(m.target);
                    } else m.addedNodes.forEach(n => {
                        if (n.nodeType === 3) {
                            if (!SKIP.has(n.parentNode.nodeName)) applyNode(n);
                        } else walk(n);
                    });
                });
            }).observe(document.body, {
                childList: true,
                subtree: true,
                characterData: true
            });

            document.querySelectorAll('.lang-btn').forEach(b =>
                b.addEventListener('click', () => setLang(b.dataset.lang)));

            let saved = 'en';
            try {
                saved = localStorage.getItem('dmw_lang') || 'en';
            } catch (e) {}
            if (saved === 'fil') setLang('fil');
        })();
    </script>
    <script>
        (function() {
            const root = document.documentElement;
            const btn = document.getElementById('theme-toggle');
            const icon = document.getElementById('theme-icon');
            if (!btn || !icon) return;

            function sync() {
                const dark = root.classList.contains('dark');
                icon.className = 'fa-solid ' + (dark ? 'fa-sun' : 'fa-moon');
                btn.setAttribute('aria-pressed', dark);
                btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            }

            btn.addEventListener('click', () => {
                root.classList.toggle('dark');
                try {
                    localStorage.setItem('dmw_theme', root.classList.contains('dark') ? 'dark' : 'light');
                } catch (e) {}
                sync();
            });

            sync();
        })();
    </script>
</body>

</html>