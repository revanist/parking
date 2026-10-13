    </main>

    <script>
        // Initialize Icons
        lucide.createIcons();

        // Responsive Collapsible Sidebar Logic (Desktop collapse + Mobile off-canvas drawer)
        (() => {
            const menuToggle = document.getElementById('menu-toggle');
            const sidebarClose = document.getElementById('sidebar-close-btn');
            const backdrop = document.getElementById('sidebar-backdrop');
            const sidebar = document.getElementById('sidebar');
            const body = document.body;

            function isMobile() {
                return window.innerWidth < 1024;
            }

            function openMobileSidebar() {
                body.classList.add('sidebar-mobile-open');
                menuToggle?.setAttribute('aria-expanded', 'true');
            }

            function closeMobileSidebar() {
                body.classList.remove('sidebar-mobile-open');
                menuToggle?.setAttribute('aria-expanded', 'false');
            }

            if (menuToggle) {
                menuToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (isMobile()) {
                        if (body.classList.contains('sidebar-mobile-open')) {
                            closeMobileSidebar();
                        } else {
                            openMobileSidebar();
                        }
                    } else {
                        body.classList.toggle('sidebar-desktop-collapsed');
                    }
                });
            }

            if (sidebarClose) {
                sidebarClose.addEventListener('click', closeMobileSidebar);
            }

            if (backdrop) {
                backdrop.addEventListener('click', closeMobileSidebar);
            }

            // Close on Escape key
            window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && body.classList.contains('sidebar-mobile-open')) {
                    closeMobileSidebar();
                }
            });

            // Close when clicking nav links on mobile
            if (sidebar) {
                sidebar.querySelectorAll('a').forEach(link => {
                    link.addEventListener('click', () => {
                        if (isMobile()) {
                            closeMobileSidebar();
                        }
                    });
                });
            }

            // Clean up mobile drawer state if resized to desktop
            window.addEventListener('resize', () => {
                if (!isMobile() && body.classList.contains('sidebar-mobile-open')) {
                    closeMobileSidebar();
                }
            });
        })();
    </script>
</body>
</html>
