class ParkingGridVisualizer {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        if (!this.container) {
            console.warn('ParkingGridVisualizer: Container not found:', containerId);
            return;
        }

        this.options = Object.assign({
            onSlotClick: null,
            onAreaChange: null,
            pollUrl: null,
            pollInterval: 8000,
            activeAreaId: 0,
            isPetugasMode: false,
            readOnly: false,
            showRefreshButton: false
        }, options);

        this.areas = [];
        this.activeAreaId = Number(this.options.activeAreaId) || 0;
        this.selectedSlotNum = null;
        this.pollTimer = null;
        this.abortController = null;
        this.tooltipEl = null;

        if (this.options.readOnly) this.container.classList.add('parking-grid-read-only');
        if (this.options.isPetugasMode) this.container.classList.add('parking-grid-petugas');

        this.initDOM();
        this.setupVisibilityHandler();
    }

    initDOM() {
        this.container.innerHTML = `
            <div class="parking-grid-container">
                <div class="parking-grid-toolbar">
                    <div class="parking-grid-filter">
                        <span class="parking-grid-filter-label">Area:</span>
                        <div class="parking-grid-area-tabs" id="parkingGridAreaTabs" role="group" aria-label="Filter area parkir"></div>
                    </div>
                    <section class="parking-grid-stats" aria-live="polite">
                        <strong class="parking-grid-stats-title" id="parkingGridStatsTitle">Semua Area</strong>
                        <div class="parking-grid-stats-items">
                            <div><span>Kapasitas</span><strong id="parkingGridCapacity">0</strong></div>
                            <div><span>Terisi</span><strong class="text-danger" id="parkingGridOccupied">0</strong></div>
                            <div><span>Kosong</span><strong class="text-success" id="parkingGridAvailable">0</strong></div>
                        </div>
                    </section>
                    ${this.options.showRefreshButton ? '<button type="button" class="btn btn-sm btn-light border parking-grid-refresh" id="parkingGridRefresh" title="Segarkan data sekarang" aria-label="Segarkan data sekarang"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>' : ''}
                </div>
                <div class="parking-grid-stage">
                    <div class="parking-grid-sync" role="status">
                        <span class="parking-grid-sync-dot"></span>
                        <span id="parkingGridSyncLabel">Memuat data...</span>
                    </div>
                    <div class="parking-grid-platform" id="parkingGridAreas"></div>
                </div>
                <div class="parking-grid-footer">
                    <div class="parking-grid-legend">
                        <span><i class="parking-grid-legend-dot available"></i>Tersedia</span>
                        <span><i class="parking-grid-legend-dot occupied"></i>Terisi</span>
                        ${this.options.isPetugasMode ? '<span class="parking-grid-instruction"><i class="bi bi-hand-index-thumb me-1" aria-hidden="true"></i>Klik petak hijau untuk memilih slot</span>' : ''}
                        ${this.options.readOnly ? '<span class="parking-grid-instruction"><i class="bi bi-eye me-1" aria-hidden="true"></i>Mode pemantauan read-only</span>' : ''}
                    </div>
                    <span class="parking-grid-note">Status petak terisi merupakan pemetaan visual transaksi aktif; nomor slot fisik belum dicatat di database.</span>
                </div>
            </div>
            <div class="parking-grid-tooltip" id="parkingGridTooltip" role="tooltip"></div>
        `;

        this.tooltipEl = this.container.querySelector('#parkingGridTooltip');
        const refreshButton = this.container.querySelector('#parkingGridRefresh');
        if (refreshButton) refreshButton.addEventListener('click', () => this.fetchLive(true));
    }

    setupVisibilityHandler() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopPolling();
                return;
            }
            if (this.options.pollUrl) {
                this.fetchLive(false);
                this.startPolling();
            }
        });
    }

    setData(areasData) {
        this.areas = Array.isArray(areasData) ? areasData : [];
        if (this.activeAreaId && !this.areas.some(area => Number(area.id_area) === this.activeAreaId)) {
            this.activeAreaId = 0;
        }
        this.renderAreaTabs();
        this.updateStats();
        this.renderAreas();
    }

    setActiveArea(areaId) {
        this.activeAreaId = Number(areaId) || 0;
        this.renderAreaTabs();
        this.updateStats();
        this.renderAreas();
    }

    renderAreaTabs() {
        const tabs = this.container.querySelector('#parkingGridAreaTabs');
        if (!tabs) return;
        tabs.replaceChildren();

        const addTab = (label, areaId) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `parking-grid-area-tab${Number(areaId) === this.activeAreaId ? ' active' : ''}`;
            button.textContent = label;
            button.setAttribute('aria-pressed', Number(areaId) === this.activeAreaId ? 'true' : 'false');
            button.addEventListener('click', () => {
                this.setActiveArea(areaId);
                if (typeof this.options.onAreaChange === 'function') {
                    this.options.onAreaChange(Number(areaId) || 0);
                }
            });
            tabs.appendChild(button);
        };

        addTab(`Semua (${this.areas.length})`, 0);
        this.areas.forEach(area => addTab(area.nama_area, area.id_area));
    }

    getAreaCapacity(area) {
        return Math.max(0, Math.floor(Number(area.kapasitas) || 0));
    }

    getAreaOccupiedCount(area, capacity) {
        const vehicles = Array.isArray(area.kendaraan_aktif)
            ? area.kendaraan_aktif
            : (Array.isArray(area.vehicles) ? area.vehicles : []);
        const reportedOccupied = Math.max(0, Math.floor(Number(area.terisi) || 0));
        return Math.min(capacity, Math.max(vehicles.length, reportedOccupied));
    }

    updateStats() {
        const targetAreas = this.activeAreaId
            ? this.areas.filter(area => Number(area.id_area) === this.activeAreaId)
            : this.areas;
        const capacity = targetAreas.reduce((sum, area) => sum + this.getAreaCapacity(area), 0);
        const occupied = targetAreas.reduce((sum, area) => sum + this.getAreaOccupiedCount(area, this.getAreaCapacity(area)), 0);
        const title = targetAreas.length === 1 ? targetAreas[0].nama_area : 'Semua Area';

        this.container.querySelector('#parkingGridStatsTitle').textContent = title;
        this.container.querySelector('#parkingGridCapacity').textContent = capacity;
        this.container.querySelector('#parkingGridOccupied').textContent = occupied;
        this.container.querySelector('#parkingGridAvailable').textContent = Math.max(0, capacity - occupied);
    }

    escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[character]);
    }

    renderAreas() {
        const container = this.container.querySelector('#parkingGridAreas');
        if (!container) return;
        container.replaceChildren();

        const targetAreas = this.activeAreaId
            ? this.areas.filter(area => Number(area.id_area) === this.activeAreaId)
            : this.areas;
        if (!targetAreas.length) {
            container.innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-info-circle fs-3 d-block mb-2"></i><p class="m-0 small">Belum ada data area parkir untuk ditampilkan.</p></div>';
            return;
        }

        targetAreas.forEach((area, index) => {
            const areaName = String(area.nama_area || '');
            const safeAreaName = this.escapeHtml(areaName);
            const loweredName = areaName.toLowerCase();
            const isMotor = loweredName.includes('motor') || loweredName.includes('roda dua') || loweredName.includes('bike');
            const isLarge = loweredName.includes('besar') || loweredName.includes('truk') || loweredName.includes('bus');
            const iconClass = isMotor ? 'bi-bicycle' : (isLarge ? 'bi-truck' : 'bi-car-front-fill');
            const capacity = this.getAreaCapacity(area);
            const vehicles = Array.isArray(area.kendaraan_aktif) ? area.kendaraan_aktif : (area.vehicles || []);
            const occupied = this.getAreaOccupiedCount(area, capacity);
            const prefixMatch = areaName.match(/area\s+([a-z0-9]+)/i);
            const prefix = prefixMatch ? prefixMatch[1].toUpperCase() : `Z${area.id_area}`;

            const zone = document.createElement('section');
            zone.className = 'parking-grid-zone';
            zone.innerHTML = `
                <h3 class="parking-grid-zone-title"><i class="bi ${iconClass}" aria-hidden="true"></i><span>${safeAreaName}</span></h3>
                <div class="parking-grid-slots ${isMotor ? 'motor' : 'vehicle'}"></div>
                ${capacity === 0 ? '<p class="small text-muted mb-0 mt-2">Area ini belum memiliki kapasitas slot.</p>' : ''}
            `;
            const slots = zone.querySelector('.parking-grid-slots');
            for (let indexSlot = 0; indexSlot < capacity; indexSlot++) {
                const vehicle = vehicles[indexSlot] || null;
                const isOccupied = indexSlot < occupied;
                const slotNum = `${prefix}-${String(indexSlot + 1).padStart(2, '0')}`;
                const plate = isOccupied ? String(vehicle?.plat_nomor || 'TERISI') : '';
                const details = isOccupied
                    ? `Slot #${slotNum}: TERISI\nPlat: ${plate}`
                    : `Slot #${slotNum}: TERSEDIA`;
                const slot = document.createElement('button');
                slot.type = 'button';
                slot.className = `parking-grid-slot ${isOccupied ? 'slot-occupied' : 'slot-available'}${this.selectedSlotNum === slotNum ? ' selected' : ''}`;
                slot.dataset.slotNum = slotNum;
                slot.dataset.areaId = String(area.id_area);
                slot.dataset.areaName = areaName;
                slot.dataset.occupied = isOccupied ? '1' : '0';
                slot.title = details;
                slot.setAttribute('aria-label', details.replace(/\n/g, ', '));
                slot.innerHTML = `
                    <span class="parking-grid-slot-status">${isOccupied ? 'TERISI' : 'KOSONG'}</span>
                    <i class="bi ${iconClass}" aria-hidden="true"></i>
                    <span class="parking-grid-slot-number">${this.escapeHtml(slotNum)}</span>
                    ${plate && plate !== 'TERISI' ? `<span class="parking-grid-slot-plate">${this.escapeHtml(plate)}</span>` : ''}
                `;
                slots.appendChild(slot);
            }

            container.appendChild(zone);
            if (index < targetAreas.length - 1 || targetAreas.length === 1) {
                const road = document.createElement('div');
                road.className = 'parking-grid-road';
                road.innerHTML = '<i class="bi bi-arrow-left" aria-hidden="true"></i><span>JALUR LALU LINTAS KENDARAAN</span><i class="bi bi-arrow-right" aria-hidden="true"></i>';
                container.appendChild(road);
            }
        });

        this.bindInteractions();
    }

    bindInteractions() {
        this.container.querySelectorAll('.parking-grid-slot').forEach(slot => {
            slot.addEventListener('mouseenter', event => {
                if (!this.tooltipEl) return;
                const lines = slot.title.split('\n');
                this.tooltipEl.replaceChildren(...lines.map(line => {
                    const span = document.createElement('span');
                    span.textContent = line;
                    return span;
                }));
                this.tooltipEl.classList.add('show');
                this.positionTooltip(event);
            });
            slot.addEventListener('mousemove', event => this.positionTooltip(event));
            slot.addEventListener('mouseleave', () => this.tooltipEl?.classList.remove('show'));
            slot.addEventListener('click', () => {
                if (this.options.readOnly || slot.dataset.occupied === '1') return;
                this.selectedSlotNum = slot.dataset.slotNum;
                this.container.querySelectorAll('.parking-grid-slot.selected').forEach(selected => selected.classList.remove('selected'));
                slot.classList.add('selected');
                if (typeof this.options.onSlotClick === 'function') {
                    this.options.onSlotClick({
                        slotNum: slot.dataset.slotNum,
                        areaId: slot.dataset.areaId,
                        areaName: slot.dataset.areaName
                    });
                }
            });
        });
    }

    positionTooltip(event) {
        if (!this.tooltipEl) return;
        const rect = this.container.getBoundingClientRect();
        this.tooltipEl.style.left = `${event.clientX - rect.left}px`;
        this.tooltipEl.style.top = `${event.clientY - rect.top - 12}px`;
    }

    clearSelection() {
        this.selectedSlotNum = null;
        this.container?.querySelectorAll('.parking-grid-slot.selected').forEach(slot => slot.classList.remove('selected'));
    }

    async fetchLive(manual = false) {
        if (!this.options.pollUrl) return;
        const label = this.container.querySelector('#parkingGridSyncLabel');
        if (manual && label) label.textContent = 'Memuat...';

        this.abortController?.abort();
        this.abortController = new AbortController();
        try {
            const response = await fetch(this.options.pollUrl, {
                signal: this.abortController.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (!Array.isArray(data.areas)) throw new Error('Invalid area data');
            this.setData(data.areas);
            if (label) label.textContent = `Diperbarui ${data.timestamp || new Date().toLocaleTimeString('id-ID')}`;
        } catch (error) {
            if (error.name === 'AbortError') return;
            console.error('Parking grid update failed:', error);
            if (label) label.textContent = 'Gagal memperbarui data';
        }
    }

    startPolling() {
        if (!this.options.pollUrl) return;
        this.stopPolling();
        this.pollTimer = window.setInterval(() => {
            if (!document.hidden) this.fetchLive(false);
        }, this.options.pollInterval);
    }

    stopPolling() {
        if (this.pollTimer) {
            window.clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }
}
