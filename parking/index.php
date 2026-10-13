<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CampusPark — Campus Parking System</title>
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
              50: '#f6f7f9',
              100: '#eceef2',
              200: '#d4d9e2',
              300: '#aeb8c8',
              400: '#8391a8',
              500: '#64748b',
              600: '#4e5a70',
              700: '#404a5c',
              800: '#37404e',
              900: '#1e2532',
              950: '#0f141d',
            },
          },
          boxShadow: {
            'soft': '0 1px 3px rgba(16,24,40,0.04), 0 8px 24px -8px rgba(16,24,40,0.08)',
            'elevated': '0 4px 12px -2px rgba(16,24,40,0.06), 0 16px 40px -12px rgba(16,24,40,0.12)',
          },
        }
      }
    }
  </script>
  <style>
    body {
      font-family: 'Inter', sans-serif;
    }

    .font-mono {
      font-family: 'JetBrains Mono', monospace;
    }



    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(16px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .fade-up {
      animation: fadeUp 0.6s ease-out forwards;
      opacity: 0;
    }

    .grid-bg {
      background-image:
        linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
      background-size: 40px 40px;
    }

    .grid-bg-light {
      background-image:
        linear-gradient(rgba(15, 20, 29, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(15, 20, 29, 0.03) 1px, transparent 1px);
      background-size: 32px 32px;
    }

    .spot {
      transition: all 0.2s ease;
    }

    .spot:hover {
      transform: scale(1.08);
    }
  </style>
</head>

<body class="bg-white text-ink-900 antialiased">

  <!-- ═══════════════════ NAVBAR ═══════════════════ -->
  <header class="sticky top-0 z-50 bg-white/85 backdrop-blur-lg border-b border-ink-200/60">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">
      <div class="flex items-center justify-between h-16">
        <a href="index.php" class="flex items-center gap-2.5">
          <img src="img/campus_parking_logo.png" alt="Campus Parking Logo" class="w-9 h-9 object-contain">
          <span class="font-bold text-lg tracking-tight">Campus Parking</span>
        </a>

        <nav class="hidden sm:flex items-center gap-6">
          <a href="parking/occupancy.php"
            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-ink-50 hover:bg-ink-100 border border-ink-200/80 text-xs font-semibold text-ink-700 hover:text-ink-950 transition">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            Live Occupancy Dashboard
          </a>
        </nav>

        <div class="flex items-center gap-2">
          <a href="login.php"
            class="hidden sm:inline-block text-sm font-medium text-ink-600 hover:text-ink-900 px-3 py-2 transition">Sign
            in</a>
          <a href="register.php"
            class="inline-flex items-center gap-1.5 bg-ink-900 hover:bg-ink-800 text-white text-sm font-semibold px-4 py-2.5 rounded-lg transition shadow-sm">
            Register
            <i class="fas fa-arrow-right text-[10px]"></i>
          </a>
        </div>
      </div>
    </div>
  </header>
  <main>

    <!-- ═══════════════════ HERO ═══════════════════ -->
    <section class="relative overflow-hidden bg-ink-950 text-white">
      <div class="absolute inset-0 grid-bg opacity-50"></div>
      <div
        class="absolute -top-40 -right-40 w-[600px] h-[600px] bg-blue-500 rounded-full filter blur-[160px] opacity-20">
      </div>
      <div
        class="absolute -bottom-40 -left-40 w-[500px] h-[500px] bg-cyan-400 rounded-full filter blur-[140px] opacity-15">
      </div>

      <div class="relative max-w-7xl mx-auto px-4 sm:px-6 py-20 sm:py-28">
        <div class="max-w-3xl">

          <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-[1.05] mb-6 fade-up"
            style="animation-delay: 0.1s;">
            Campus parking,<br>
            made simple.
          </h1>

          <p class="text-lg text-ink-300 leading-relaxed mb-8 fade-up" style="animation-delay: 0.15s;">
            Check which campus lots have open spots, reserve one for later, and get directions
            to your parking space — all from your phone.
          </p>

          <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-10 fade-up"
            style="animation-delay: 0.2s;">
            <a href="login.php"
              class="inline-flex items-center justify-center gap-2 bg-white text-ink-900 font-semibold px-6 py-3.5 rounded-lg hover:bg-ink-100 hover:-translate-y-0.5 hover:shadow-lg transition-all duration-300">
              <i class="fas fa-car text-sm"></i>
              Sign in
            </a>
            <a href="register.php"
              class="inline-flex items-center justify-center gap-2 bg-white/5 border border-white/15 hover:bg-white/10 text-white font-semibold px-6 py-3.5 rounded-lg hover:-translate-y-0.5 hover:shadow-lg transition-all duration-300">
              <i class="fas fa-user-plus text-xs"></i>
              Register
            </a>
          </div>


        </div>
      </div>
    </section>

    <!-- ═══════════════════ QUICK INFO BAR ═══════════════════ -->
    <section class="bg-ink-50 border-y border-ink-200 py-6">
      <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 max-w-2xl mx-auto">
          <div
            class="flex items-center gap-3 p-3 rounded-xl hover:bg-white hover:shadow-sm transition-all duration-300">
            <div
              class="w-10 h-10 rounded-lg bg-white border border-ink-200 flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
              <i class="fas fa-id-card text-ink-700 text-sm"></i>
            </div>
            <div>
              <p class="text-sm font-semibold">Permit required</p>
              <p class="text-xs text-ink-500">Student or faculty</p>
            </div>
          </div>
          <div
            class="flex items-center gap-3 p-3 rounded-xl hover:bg-white hover:shadow-sm transition-all duration-300">
            <div
              class="w-10 h-10 rounded-lg bg-white border border-ink-200 flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
              <i class="fas fa-headset text-ink-700 text-sm"></i>
            </div>
            <div>
              <p class="text-sm font-semibold">Campus support</p>
              <p class="text-xs text-ink-500">Mon–Fri, 8am–6pm</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ═══════════════════ FEATURES ═══════════════════ -->
    <section id="features" class="py-20 sm:py-24 bg-white">
      <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="max-w-2xl mb-14">
          <span class="text-xs font-bold text-ink-500 uppercase tracking-widest">Features</span>
          <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight mt-3 mb-4">
            What you can do
          </h2>
          <p class="text-lg text-ink-500">
            The basics you need to park on campus without the hassle.
          </p>
        </div>

        <div class="grid md:grid-cols-3 gap-5">
          <div
            class="bg-ink-50 rounded-2xl p-6 border border-ink-100 hover:-translate-y-1 hover:shadow-md transition-all duration-300 cursor-default">
            <div class="w-11 h-11 rounded-xl bg-ink-900 flex items-center justify-center mb-5">
              <i class="fas fa-satellite-dish text-white text-sm"></i>
            </div>
            <h3 class="font-bold text-lg mb-2">Live spot availability</h3>
            <p class="text-sm text-ink-500 leading-relaxed">
              See which spots are free in each campus lot, updated every few seconds.
            </p>
          </div>

          <div
            class="bg-ink-50 rounded-2xl p-6 border border-ink-100 hover:-translate-y-1 hover:shadow-md transition-all duration-300 cursor-default">
            <div class="w-11 h-11 rounded-xl bg-ink-900 flex items-center justify-center mb-5">
              <i class="fas fa-bookmark text-white text-sm"></i>
            </div>
            <h3 class="font-bold text-lg mb-2">Reserve a spot</h3>
            <p class="text-sm text-ink-500 leading-relaxed">
              Hold a spot for up to 15 minutes while you drive to campus.
            </p>
          </div>

          <div
            class="bg-ink-50 rounded-2xl p-6 border border-ink-100 hover:-translate-y-1 hover:shadow-md transition-all duration-300 cursor-default">
            <div class="w-11 h-11 rounded-xl bg-ink-900 flex items-center justify-center mb-5">
              <i class="fas fa-route text-white text-sm"></i>
            </div>
            <h3 class="font-bold text-lg mb-2">Directions to your spot</h3>
            <p class="text-sm text-ink-500 leading-relaxed">
              Get walking or driving directions straight to the spot you reserved.
            </p>
          </div>

          <div
            class="bg-ink-50 rounded-2xl p-6 border border-ink-100 hover:-translate-y-1 hover:shadow-md transition-all duration-300 cursor-default">
            <div class="w-11 h-11 rounded-xl bg-ink-900 flex items-center justify-center mb-5">
              <i class="fas fa-qrcode text-white text-sm"></i>
            </div>
            <h3 class="font-bold text-lg mb-2">Digital permits</h3>
            <p class="text-sm text-ink-500 leading-relaxed">
              Your student or faculty permit lives in the app. No more paper tags.
            </p>
          </div>

          <div
            class="bg-ink-50 rounded-2xl p-6 border border-ink-100 hover:-translate-y-1 hover:shadow-md transition-all duration-300 cursor-default">
            <div class="w-11 h-11 rounded-xl bg-ink-900 flex items-center justify-center mb-5">
              <i class="fas fa-bell text-white text-sm"></i>
            </div>
            <h3 class="font-bold text-lg mb-2">Spot alerts</h3>
            <p class="text-sm text-ink-500 leading-relaxed">
              Get notified when a spot opens up in the lot you usually park in.
            </p>
          </div>

          <div
            class="bg-ink-50 rounded-2xl p-6 border border-ink-100 hover:-translate-y-1 hover:shadow-md transition-all duration-300 cursor-default">
            <div class="w-11 h-11 rounded-xl bg-ink-900 flex items-center justify-center mb-5">
              <i class="fas fa-clock-rotate-left text-white text-sm"></i>
            </div>
            <h3 class="font-bold text-lg mb-2">Parking history</h3>
            <p class="text-sm text-ink-500 leading-relaxed">
              View your past reservations and spots you've parked in.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ═══════════════════ HOW IT WORKS ═══════════════════ -->
    <section id="how" class="py-20 sm:py-24 bg-ink-50 grid-bg-light">
      <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="text-center max-w-2xl mx-auto mb-14">
          <span class="text-xs font-bold text-ink-500 uppercase tracking-widest">How it works</span>
          <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight mt-3 mb-4">
            Three simple steps
          </h2>
          <p class="text-lg text-ink-500">
            From your phone to your parking spot.
          </p>
        </div>

        <div class="relative grid md:grid-cols-3 gap-8 lg:gap-12">
          <div
            class="hidden md:block absolute top-12 left-[16%] right-[16%] h-px bg-gradient-to-r from-ink-200 via-ink-400 to-ink-200">
          </div>

          <!-- Step 1 -->
          <div class="relative text-center">
            <div class="relative inline-flex items-center justify-center w-24 h-24 rounded-3xl bg-ink-900 mb-6">
              <i class="fas fa-mobile-screen text-white text-2xl"></i>
              <div
                class="absolute -top-2 -right-2 w-8 h-8 rounded-full bg-white border-2 border-ink-900 flex items-center justify-center">
                <span class="font-bold text-sm font-mono">1</span>
              </div>
            </div>
            <h3 class="font-bold text-xl mb-2">Check availability</h3>
            <p class="text-sm text-ink-500 max-w-xs mx-auto leading-relaxed">
              Open the site and see which lot is open.
            </p>
          </div>

          <!-- Step 2 -->
          <div class="relative text-center">
            <div class="relative inline-flex items-center justify-center w-24 h-24 rounded-3xl bg-ink-900 mb-6">
              <i class="fas fa-hand-pointer text-white text-2xl"></i>
              <div
                class="absolute -top-2 -right-2 w-8 h-8 rounded-full bg-white border-2 border-ink-900 flex items-center justify-center">
                <span class="font-bold text-sm font-mono">2</span>
              </div>
            </div>
            <h3 class="font-bold text-xl mb-2">Reserve a spot</h3>
            <p class="text-sm text-ink-500 max-w-xs mx-auto leading-relaxed">
              Tap any available spot to reserve it. We'll hold it for 15 minutes.
            </p>
          </div>

          <!-- Step 3 -->
          <div class="relative text-center">
            <div class="relative inline-flex items-center justify-center w-24 h-24 rounded-3xl bg-ink-900 mb-6">
              <i class="fas fa-circle-check text-white text-2xl"></i>
              <div
                class="absolute -top-2 -right-2 w-8 h-8 rounded-full bg-white border-2 border-ink-900 flex items-center justify-center">
                <span class="font-bold text-sm font-mono">3</span>
              </div>
            </div>
            <h3 class="font-bold text-xl mb-2">Park your car</h3>
            <p class="text-sm text-ink-500 max-w-xs mx-auto leading-relaxed">
              Drive to your spot and scan the QR code on arrival to check in.
            </p>
          </div>
        </div>
      </div>
    </section>

    <?php include __DIR__ . '/layouts/footer.php'; ?>