        </main>
        <footer class="app-footer">
            <div class="d-flex align-items-center gap-2">
                <span class="brand-footer-dot"></span>
                <span>&copy; <?= date('Y') ?> <strong>PARKIR-APP</strong> &bull; Enterprise Smart Parking Management System.</span>
            </div>
            <div class="footer-links d-none d-sm-flex align-items-center gap-3">
                <span class="text-emerald d-inline-flex align-items-center gap-1">
                    <span class="pulse-indicator pulse-emerald"></span> Server Online
                </span>
                <span class="text-muted">&bull;</span>
                <span class="text-muted">Aman & Terverifikasi</span>
            </div>
        </footer>
    </div>
</div>

<!-- Bootstrap 5.3.3 Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Live Clock JS & Global UI Enhancements -->
<script>
(function() {
    function updateClock() {
        var clockEl = document.getElementById('liveClockTime');
        var dateEl = document.getElementById('liveClockDate');
        var mobClockEl = document.getElementById('mobileClockTime');
        var mobDateEl = document.getElementById('mobileClockDate');
        
        var now = new Date();
        var h = String(now.getHours()).padStart(2, '0');
        var m = String(now.getMinutes()).padStart(2, '0');
        var s = String(now.getSeconds()).padStart(2, '0');
        var timeStr = h + ':' + m + ':' + s + ' WIB';
        
        var days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        var dateStr = days[now.getDay()] + ', ' + now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
        
        if (clockEl) clockEl.textContent = timeStr;
        if (dateEl) dateEl.textContent = dateStr;
        if (mobClockEl) mobClockEl.textContent = timeStr;
        if (mobDateEl) mobDateEl.textContent = dateStr;
    }
    updateClock();
    setInterval(updateClock, 1000);

    // Initialize all Bootstrap tooltips if any
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
})();
</script>
</body>
</html>
