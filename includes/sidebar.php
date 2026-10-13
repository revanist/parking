<?php
$role = $role ?? 'User';
// Keep the security staff navigation identical on every security page.
$sidebarCurrentRole = function_exists('current_role') ? current_role() : strtolower((string)$role);
if ($sidebarCurrentRole === 'security' || strtolower((string)$role) === 'security staff') {
    $sidebarItems = [
        ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'/parking/dashboards/security.php'],
        ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'fa-gauge-high','href'=>'/parking/parking/occupancy.php'],
        ['key'=>'scan','label'=>'Gate Checkpoint & Scan','icon'=>'fa-qrcode','href'=>'/parking/security/scan.php'],
        ['key'=>'entry','label'=>'Manual Plate Lookup','icon'=>'fa-keyboard','href'=>'/parking/security/entry.php'],
        ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'/parking/security/exit.php'],
        ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'/parking/parking/current.php'],
        ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'/parking/security/visitors.php'],
        ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'/parking/parking/logs.php'],
        ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'/parking/violations/index.php'],
    ];
} elseif ($sidebarCurrentRole === 'student' || $sidebarCurrentRole === 'faculty' || in_array(strtolower((string)$role), ['student', 'faculty', 'student / faculty', 'user'], true)) {
    // Keep student and faculty navigation consistent across all dashboard, vehicle, permit, history, violation, and occupancy pages.
    $sidebarItems = [
        ['key'=>'dashboard','label'=>'Dashboard','icon'=>'layout-dashboard','href'=>'/parking/dashboards/users.php'],
        ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'gauge','href'=>'/parking/parking/occupancy.php'],
        ['key'=>'vehicle','label'=>'My Vehicles','icon'=>'car','href'=>'/parking/vehicles/index.php'],
        ['key'=>'permit','label'=>'Parking Permit','icon'=>'credit-card','href'=>'/parking/permits/index.php'],
        ['key'=>'history','label'=>'Parking History','icon'=>'history','href'=>'/parking/parking/history.php'],
        ['key'=>'violations','label'=>'Violations','icon'=>'alert-triangle','href'=>'/parking/violations/index.php'],
    ];
}
?>
    <!-- Mobile Backdrop Overlay -->
    <div id="sidebar-backdrop" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-40 hidden opacity-0 transition-opacity duration-300 lg:hidden" aria-hidden="true"></div>

    <!-- Sidebar Drawer -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-slate-200 flex flex-col justify-between shrink-0 transform -translate-x-full lg:translate-x-0 lg:static transition-transform lg:transition-[width] duration-300 ease-in-out shadow-2xl lg:shadow-none select-none h-full">
        <div class="w-64 h-full flex flex-col overflow-y-auto overscroll-contain">
            <!-- Logo and Mobile Close Button -->
            <div class="p-5 sm:p-6 flex items-center justify-between border-b border-slate-100 lg:border-b-0">
                <div class="flex items-center gap-3">
                    <img src="/parking/img/campus_parking_logo.png" alt="Campus Parking Logo" class="w-8 h-8 object-contain shrink-0">
                    <span class="font-bold text-lg tracking-tight whitespace-nowrap text-slate-900">Campus Parking</span>
                </div>
                <button id="sidebar-close-btn" type="button" class="lg:hidden p-2 -mr-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition" aria-label="Close sidebar">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- User Profile Box -->
            <div class="px-6 mb-8">
                <div class="bg-slate-900 rounded-xl p-4 text-white shadow-md relative overflow-hidden">
                    <div class="absolute -right-4 -top-4 w-16 h-16 bg-white/5 rounded-full blur-xl"></div>
                    <p class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold mb-1">Signed in as</p>
                    <p class="font-semibold text-sm mb-3"><?= htmlspecialchars($role) ?></p>
                    <div class="flex items-center gap-2 text-xs text-slate-300">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.8)]"></span>
                        System online
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="px-4 space-y-1">
                <p class="px-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Navigation</p>
                <?php if (strtolower($role) === 'administrator' || strtolower($role) === 'admin'): ?>
                    <?php
                    $adminItems = [
                        ['key'=>'dashboard','label'=>'Dashboard','icon'=>'chart-line','href'=>'/parking/dashboards/admin.php'],
                        ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'gauge','href'=>'/parking/parking/occupancy.php'],
                        ['key'=>'areas','label'=>'Parking Areas','icon'=>'square-parking','href'=>'/parking/admin/areas.php'],
                        ['key'=>'vehicles','label'=>'Vehicles','icon'=>'car','href'=>'/parking/vehicles/index.php'],
                        ['key'=>'permits','label'=>'Permit Applications','icon'=>'id-card','href'=>'/parking/permits/index.php'],
                        ['key'=>'logs','label'=>'Parking Logs','icon'=>'history','href'=>'/parking/parking/logs.php'],
                        ['key'=>'violations','label'=>'Violations','icon'=>'triangle-alert','href'=>'/parking/violations/index.php'],
                        ['key'=>'reports','label'=>'Reports','icon'=>'chart-column','href'=>'/parking/admin/reports.php'],
                        ['key'=>'users','label'=>'Users','icon'=>'users','href'=>'/parking/admin/users.php'],
                        ['key'=>'audit','label'=>'Audit Log','icon'=>'clipboard-list','href'=>'/parking/admin/audit.php'],
                        ['key'=>'settings','label'=>'Settings','icon'=>'settings','href'=>'/parking/admin/settings.php']
                    ]
                    ?>
                    <?php foreach ($adminItems as $item): ?>
                        <?php $isActive = (($activeItem ?? '') === $item['key']) || (($item['key'] === 'vehicles') && (($activeItem ?? '') === 'vehicle')) || (($item['key'] === 'permits') && (($activeItem ?? '') === 'permit')); ?>
                        <a href="<?= htmlspecialchars($item['href']) ?>" class="flex items-center gap-3 px-3 py-2.5 <?= $isActive ? 'bg-slate-900 text-white' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50' ?> rounded-lg text-sm font-medium transition-colors">
                            <i data-lucide="<?= htmlspecialchars($item['icon']) ?>" class="w-4 h-4 shrink-0"></i>
                            <?= htmlspecialchars($item['label']) ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach (($sidebarItems ?? []) as $item): ?>
                        <?php $isActive = (($activeItem ?? '') === $item['key']) || (($item['key'] === 'vehicle') && in_array($activeItem ?? '', ['vehicle', 'vehicles'], true)) || (($item['key'] === 'permit') && in_array($activeItem ?? '', ['permit', 'permits'], true)); ?>
                        <a href="<?= htmlspecialchars($item['href']) ?>" class="flex items-center gap-3 px-3 py-2.5 <?= $isActive ? 'bg-slate-900 text-white' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50' ?> rounded-lg text-sm font-medium transition-colors">
                            <?php $icon = $item['icon'] ?? 'circle'; if (str_starts_with($icon, 'fa-')): ?><i class="fa-solid <?= htmlspecialchars($icon) ?> w-4 h-4 shrink-0"></i><?php else: ?><i data-lucide="<?= htmlspecialchars($icon) ?>" class="w-4 h-4 shrink-0"></i><?php endif; ?>
                            <?= htmlspecialchars($item['label']) ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>
        </div>
    </aside>
    
    <!-- Main Content Area -->
    <main class="flex-1 flex flex-col h-screen overflow-y-auto bg-grid relative w-full min-w-0">
        
        <!-- Top Header -->
        <header class="h-16 bg-white/80 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 sm:px-6 lg:px-8 sticky top-0 z-20 shrink-0">
            <div class="flex items-center gap-3 sm:gap-4">
                <button id="menu-toggle" type="button" aria-expanded="false" aria-label="Toggle navigation menu" class="p-2 -ml-2 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-slate-300">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div class="flex items-center gap-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                    Campus Parking <span class="hidden xs:inline text-slate-300">•</span> <span class="hidden xs:inline">My Space</span>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <span class="hidden sm:flex items-center gap-2 text-xs font-medium text-slate-600 bg-slate-100 px-3 py-1.5 rounded-full border border-slate-200">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <?= htmlspecialchars($role) ?>
                </span>
                <a href="/parking/logout.php" class="text-sm font-medium text-slate-500 hover:text-slate-900 transition-colors">Sign out</a>
            </div>
        </header>
