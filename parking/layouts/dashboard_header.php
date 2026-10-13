<?php
if (!isset($pageTitle)) $pageTitle = 'Dashboard';
if (!isset($role)) $role = 'User';
if (!isset($activeItem)) $activeItem = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — CampusPark</title>
    <script src="/parking/assets/js/tailwindcss.js"></script>
    <script src="/parking/assets/js/lucide.js"></script>
    <link rel="stylesheet" href="/parking/assets/css/fontawesome.min.css">
    <style>
        @import url('/parking/assets/css/fonts.css');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; -webkit-tap-highlight-color: transparent; }
        .bg-grid { background-size: 40px 40px; background-image: linear-gradient(to right, #f1f5f9 1px, transparent 1px), linear-gradient(to bottom, #f1f5f9 1px, transparent 1px); }
        .card-shadow { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); }
        .card-shadow:hover { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025); transition: all 0.2s ease-in-out; }
        #sidebar { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        @media (min-width: 1024px) {
            body.sidebar-desktop-collapsed #sidebar {
                width: 0 !important;
                min-width: 0 !important;
                overflow: hidden !important;
                border-right-width: 0 !important;
            }
        }
        body.sidebar-mobile-open #sidebar { transform: translateX(0) !important; }
        body.sidebar-mobile-open #sidebar-backdrop { display: block !important; opacity: 1 !important; }
        .overflow-x-auto { -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
    </style>
</head>
<body class="text-slate-800 h-screen flex overflow-hidden">
