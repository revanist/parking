<?php
if (!isset($pageTitle)) $pageTitle = "Campus Parking";
if (!isset($activePage)) $activePage = "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | Campus Parking</title>
    <script src="/parking/assets/js/tailwindcss.js"></script>
    <link rel="stylesheet" href="/parking/assets/css/fontawesome.min.css">
            <link href="/parking/assets/css/fonts.css" rel="stylesheet">
    <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'system-ui', 'sans-serif'],
            mono: ['JetBrains Mono', 'monospace'],
          },
          colors: {
            ink: {
              50: '#f6f7f9', 100: '#eceef2', 200: '#d4d9e2', 300: '#aeb8c8', 400: '#8391a8',
              500: '#64748b', 600: '#4e5a70', 700: '#404a5c', 800: '#37404e', 900: '#1e2532', 950: '#0f141d',
            },
          }
        }
      }
    }
    </script>
    <style>
      body { font-family: 'Inter', sans-serif; }
      .font-mono { font-family: 'JetBrains Mono', monospace; }
      .grid-bg-light {
        background-image: linear-gradient(rgba(15,20,29,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(15,20,29,0.03) 1px, transparent 1px);
        background-size: 32px 32px;
      }
    </style>
</head>
<body class="min-h-screen bg-ink-50 text-ink-900 grid-bg-light antialiased">
<header class="sticky top-0 z-50 bg-white/85 backdrop-blur-lg border-b border-ink-200/60">
  <div class="max-w-7xl mx-auto px-4 sm:px-6">
    <div class="flex items-center justify-between h-16">
      <a href="/parking/index.php" class="flex items-center gap-2.5">
        <img src="/parking/img/campus_parking_logo.png" alt="Campus Parking Logo" class="w-9 h-9 object-contain">
        <span class="font-bold text-lg tracking-tight">Campus Parking</span>
      </a>

      <nav class="hidden md:flex items-center gap-8">
          <a href="/parking/index.php" class="text-sm font-medium <?= $activePage === 'home' ? 'text-ink-900' : 'text-ink-600 hover:text-ink-900' ?> transition">Home</a>
          <a href="/parking/login.php" class="text-sm font-medium <?= $activePage === 'login' ? 'text-ink-900' : 'text-ink-600 hover:text-ink-900' ?> transition">Sign in</a>
      </nav>

      <div class="flex items-center gap-2">
        <a href="/parking/parking/occupancy.php" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-ink-100/70 hover:bg-ink-100 text-xs font-semibold text-ink-700 transition">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Live Telemetry
        </a>
        <?php if ($activePage === 'register'): ?>
        <a href="/parking/login.php" class="inline-flex items-center gap-1.5 bg-ink-900 hover:bg-ink-800 text-white text-sm font-semibold px-4 py-2.5 rounded-lg transition shadow-sm">
          Sign in
        </a>
        <?php else: ?>
        <a href="/parking/register.php" class="inline-flex items-center gap-1.5 bg-ink-900 hover:bg-ink-800 text-white text-sm font-semibold px-4 py-2.5 rounded-lg transition shadow-sm">
          Register
          <i class="fas fa-arrow-right text-[10px]"></i>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>
<main>