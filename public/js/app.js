/**
 * Too Many Coins - Game Client
 * Complete JavaScript client for the economic competition game
 */
const TMC = {
    // State
    state: {
        player: null,
        seasons: [],
        currentScreen: 'home',
        currentSeason: null,
        currentChat: 'GLOBAL',
        gameState: null,
        pollInterval: null,
        realtimeInterval: null,
        seasonCountdowns: {},
        chatPollInterval: null,
        shopFilter: 'all',
        cosmetics: [],
        myCosmetics: [],
        boostCountdowns: {},
        freezeCountdown: null,
        leaderboardTab: 'global',
        pendingTradeTargetId: null,
        notifications: [],
        notificationsUnread: 0,
        notificationsOpen: false,
    },

    API_BASE: '/api/index.php',
    _seasonDetailLeaderboardExpanded: false,
    _globalSeasonalLeaderboardExpanded: false,

    // ==================== API ====================
    async api(action, data = {}) {
        try {
            const token = localStorage.getItem('tmc_token');
            const body = JSON.stringify({ action, ...data });
            const resp = await fetch(this.API_BASE + '?action=' + action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Session-Token': token || ''
                },
                body: body
            });
            const json = await resp.json();
            if (json.error && json.error.includes('Authentication required')) {
                this.handleLoggedOut();
            }
            return json;
        } catch (e) {
            console.error('API error:', e);
            return { error: 'Network error. Please try again.' };
        }
    },

    // ==================== INIT ====================
    async init() {
        // Check for stored session
        const token = localStorage.getItem('tmc_token');
        if (token) {
            document.cookie = `tmc_session=${token}; path=/; max-age=86400`;
        }

        await this.refreshGameState();
        this.renderUserArea();

        // Restore the last-visited route on refresh; fall back to home if
        // the player is not logged in or the saved screen was the auth page.
        const savedRoute = this._loadRoute();
        if (savedRoute && savedRoute.screen && savedRoute.screen !== 'auth' && this.state.player) {
            this.navigate(savedRoute.screen, savedRoute.data !== undefined ? savedRoute.data : null);
        } else {
            this.navigate('home');
        }

        // Start polling
        this.startPolling();
        this.startRealtimeClock();
        this.setupNotificationCenter();
    },

    startPolling() {
        if (this.state.pollInterval) clearInterval(this.state.pollInterval);
        this.state.pollInterval = setInterval(() => this.refreshGameState(), 3000);
    },

    startRealtimeClock() {
        if (this.state.realtimeInterval) clearInterval(this.state.realtimeInterval);
        this.state.realtimeInterval = setInterval(() => this.tickRealtimeViews(), 1000);
    },

    async refreshGameState() {
        const gs = await this.api('game_state');
        if (gs.error) return;
        this.state.gameState = gs;
        this.state.seasons = gs.seasons || [];
        this.syncSeasonCountdowns(this.state.seasons);
        this.state.player = gs.player || null;
        this.syncNotificationsFromPlayer(this.state.player);
        this.syncBoostCountdowns();
        this.syncFreezeCountdown();
        this.renderUserArea();

        if (this.state.currentScreen === 'auth' && this.state.player) {
            this.navigate('home');
        }

        this.updateHUD();
        this.checkIdleModal();

        // Refresh current screen data (but don't re-render season detail to preserve input state)
        if (this.state.currentScreen === 'seasons') this.renderSeasons();
        if (this.state.currentScreen === 'season-detail' && this.state.currentSeason) {
            this.updateSeasonDetailLive();
        }
        if (this.state.currentScreen === 'global-lb') this.loadGlobalLeaderboard();
        this.updateNotificationUI();
    },

    // ==================== AUTH ====================
    async login(e) {
        e.preventDefault();
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        const result = await this.api('login', { email, password });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_auth' });
            return;
        }
        localStorage.setItem('tmc_token', result.token);
        document.cookie = `tmc_session=${result.token}; path=/; max-age=86400`;
        this.toast('Welcome back, ' + result.handle + '!', 'success', { category: 'auth_login' });
        await this.refreshGameState();
        if (!this.state.player || this.state.player.player_id != result.player_id) {
            await this.refreshGameState();
        }
        if (!this.state.player) {
            this.state.player = {
                player_id: result.player_id,
                handle: result.handle,
                global_stars: 0
            };
        }
        this.renderUserArea();
        localStorage.removeItem('tmc_route');
        this.navigate('home');
    },

    async register(e) {
        e.preventDefault();
        const handle = document.getElementById('reg-handle').value;
        const email = document.getElementById('reg-email').value;
        const password = document.getElementById('reg-password').value;
        const result = await this.api('register', { handle, email, password });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_auth' });
            return;
        }
        localStorage.setItem('tmc_token', result.token);
        document.cookie = `tmc_session=${result.token}; path=/; max-age=86400`;
        this.toast('Account created! Welcome, ' + result.handle + '!', 'success', { category: 'auth_register' });
        await this.refreshGameState();
        if (!this.state.player || this.state.player.player_id != result.player_id) {
            await this.refreshGameState();
        }
        if (!this.state.player) {
            this.state.player = {
                player_id: result.player_id,
                handle: result.handle,
                global_stars: 0
            };
        }
        this.renderUserArea();
        localStorage.removeItem('tmc_route');
        this.navigate('home');
    },

    async logout() {
        await this.api('logout');
        localStorage.removeItem('tmc_token');
        localStorage.removeItem('tmc_route');
        document.cookie = 'tmc_session=; path=/; max-age=0';
        this.state.player = null;
        this.state.gameState = null;
        this.state.notifications = [];
        this.state.notificationsUnread = 0;
        this.state.notificationsOpen = false;
        this.renderUserArea();
        this.updateNotificationUI();
        this.navigate('home');
        this.toast('Logged out.', 'info');
    },

    handleLoggedOut() {
        localStorage.removeItem('tmc_token');
        localStorage.removeItem('tmc_route');
        this.state.player = null;
        this.state.notifications = [];
        this.state.notificationsUnread = 0;
        this.state.notificationsOpen = false;
        this.renderUserArea();
        this.updateNotificationUI();
    },

    showAuthTab(tab) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        if (tab === 'login') {
            document.getElementById('login-form').style.display = '';
            document.getElementById('register-form').style.display = 'none';
            document.querySelectorAll('.auth-tab')[0].classList.add('active');
        } else {
            document.getElementById('login-form').style.display = 'none';
            document.getElementById('register-form').style.display = '';
            document.querySelectorAll('.auth-tab')[1].classList.add('active');
        }
    },

    // ==================== NAVIGATION ====================
    navigate(screen, data) {
        this.state.currentScreen = screen;
        this._saveRoute(screen, data);

        // Hide all screens
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));

        // Update desktop nav
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        const navBtn = document.querySelector(`.nav-btn[data-screen="${screen}"]`);
        if (navBtn) navBtn.classList.add('active');

        // Update mobile bottom nav
        document.querySelectorAll('.bottom-nav-btn').forEach(b => b.classList.remove('active'));
        const bottomBtn = document.querySelector(`.bottom-nav-btn[data-screen="${screen}"]`);
        if (bottomBtn) bottomBtn.classList.add('active');

        // Scroll to top on screen change
        window.scrollTo(0, 0);

        // Show target screen
        const el = document.getElementById('screen-' + screen);
        if (el) el.classList.add('active');

        // Screen-specific logic
        switch (screen) {
            case 'home':
                this.renderHome();
                break;
            case 'auth':
                break;
            case 'seasons':
                this.renderSeasons();
                break;
            case 'season-detail':
                this.state.currentSeason = data;
                this.loadSeasonDetail(data);
                break;
            case 'global-lb':
                if (data && data.tab === 'seasonal') {
                    this.state.leaderboardTab = 'seasonal';
                }
                this.loadGlobalLeaderboard();
                break;
            case 'shop':
                this.loadShop();
                break;
            case 'chat':
                this.initChat();
                break;
            case 'profile':
                this.loadProfile(data);
                break;
            case 'trade':
                this.renderTradeScreen(data);
                break;
        }
    },

    // ==================== ROUTE PERSISTENCE ====================
    // Save the current route to localStorage so page refresh returns the
    // player to the same screen they were on.  The 'auth' screen is never
    // persisted – on refresh an unauthenticated visitor always sees home.
    _saveRoute(screen, data) {
        if (screen === 'auth') {
            try { localStorage.removeItem('tmc_route'); } catch (e) {}
            return;
        }
        try {
            localStorage.setItem('tmc_route', JSON.stringify({ screen, data: data !== undefined ? data : null }));
        } catch (e) {}
    },

    _loadRoute() {
        try {
            const raw = localStorage.getItem('tmc_route');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    },

    // ==================== BOOST COUNTDOWNS ====================
    // Sync active-boost countdowns using the absolute wall-clock expiry
    // timestamp (expires_at_real) returned by the server.  This allows the
    // client to compute accurate remaining time at any point, including after
    // a page refresh or idle/reconnect period.

    _getBoostKey(boost) {
        return boost.id !== undefined ? boost.id : boost.boost_id;
    },

    syncBoostCountdowns() {
        const p = this.state.player;
        if (!p || !p.active_boosts) { this.state.boostCountdowns = {}; return; }
        const allBoosts = [...(p.active_boosts.self || []), ...(p.active_boosts.global || [])];
        allBoosts.forEach(b => {
            const key = this._getBoostKey(b);
            if (key === undefined || key === null) return;
            this.state.boostCountdowns[key] = {
                expiresAtReal: parseInt(b.expires_at_real) || 0
            };
        });
    },

    syncFreezeCountdown() {
        const p = this.state.player;
        const freeze = p && p.participation ? p.participation.freeze : null;
        if (!freeze || !freeze.is_frozen) {
            this.state.freezeCountdown = null;
            return;
        }

        const remainingSeconds = Math.max(0, parseInt(freeze.remaining_real_seconds, 10) || 0);
        this.state.freezeCountdown = {
            remainingSeconds,
            syncedAtMs: Date.now()
        };
    },

    getLiveFreezeRemainingSeconds() {
        const freeze = this.state.freezeCountdown;
        if (!freeze) return 0;
        const elapsedSeconds = Math.floor((Date.now() - freeze.syncedAtMs) / 1000);
        return Math.max(0, freeze.remainingSeconds - elapsedSeconds);
    },

    _formatFreezeTimeLeft(remainingSeconds) {
        return remainingSeconds > 0 ? this.formatSecondsRemaining(remainingSeconds) : 'Expiring\u2026';
    },

    // Compute live remaining seconds for a boost using the authoritative
    // wall-clock expiry timestamp.  Returns 0 (never negative) once expired.
    getLiveBoostRemainingSeconds(boost) {
        const key = this._getBoostKey(boost);
        const entry = key !== undefined ? this.state.boostCountdowns[key] : null;
        const expiresAtReal = entry ? entry.expiresAtReal
            : (parseInt(boost.expires_at_real) || 0);
        if (!expiresAtReal) return 0;
        return Math.max(0, expiresAtReal - Math.floor(Date.now() / 1000));
    },

    _formatBoostTimeLeft(remainingSeconds) {
        return remainingSeconds > 0 ? this.formatSecondsRemaining(remainingSeconds) : 'Expiring\u2026';
    },

    // Called every second by the realtime interval to update boost remaining-
    // time labels without a full re-render.
    _tickBoostCountdowns() {
        const p = this.state.player;
        if (!p || !p.active_boosts) return;
        const allBoosts = [...(p.active_boosts.self || []), ...(p.active_boosts.global || [])];
        allBoosts.forEach(b => {
            const key = this._getBoostKey(b);
            const el = document.querySelector(`.active-boost-item[data-boost-id="${key}"] .ab-time`);
            if (!el) return;
            el.textContent = this._formatBoostTimeLeft(this.getLiveBoostRemainingSeconds(b));
        });
    },

    _tickFreezeCountdowns() {
        const p = this.state.player;
        const freeze = p && p.participation ? p.participation.freeze : null;
        if (!freeze || !freeze.is_frozen) return;

        const freezeTimeText = this._formatFreezeTimeLeft(this.getLiveFreezeRemainingSeconds());
        const hudRateLabel = document.querySelector('.hud-rate .hud-label');
        if (hudRateLabel) {
            hudRateLabel.textContent = `Rate Frozen (${freezeTimeText})`;
        }

        const freezeTimeEls = document.querySelectorAll('.freeze-time');
        freezeTimeEls.forEach((el) => {
            el.textContent = freezeTimeText;
        });
    },

    // ==================== RENDER: USER AREA ====================
    renderUserArea() {
        const area = document.getElementById('user-area');
        if (this.state.player) {
            area.innerHTML = `
                <div class="user-info">
                    <span class="user-global-stars" title="Global Stars">&#11088; ${this.formatNumber(this.state.player.global_stars)}</span>
                    <span class="user-handle" onclick="TMC.navigate('profile', ${this.state.player.player_id})">${this.escapeHtml(this.state.player.handle)}</span>
                    <button class="btn btn-sm btn-outline" onclick="TMC.logout()">Logout</button>
                </div>
            `;
        } else {
            area.innerHTML = `
                <button class="btn btn-primary btn-sm" onclick="TMC.navigate('auth')">Login / Register</button>
            `;
        }
    },

    // ==================== RENDER: HOME ====================
    renderHome() {
        const cta = document.getElementById('hero-cta');
        if (this.state.player) {
            if (this.state.player.joined_season_id) {
                cta.innerHTML = `
                    <button class="btn btn-primary btn-lg" onclick="TMC.navigate('season-detail', ${this.state.player.joined_season_id})">
                        Go to Your Season
                    </button>
                `;
            } else {
                cta.innerHTML = `
                    <button class="btn btn-primary btn-lg" onclick="TMC.navigate('seasons')">
                        Browse Seasons
                    </button>
                `;
            }
        } else {
            cta.innerHTML = `
                <button class="btn btn-primary btn-lg" onclick="TMC.navigate('auth')">
                    Get Started
                </button>
            `;
        }
    },

    // ==================== RENDER: HUD ====================
    updateHUD() {
        const hud = document.getElementById('player-hud');
        const p = this.state.player;
        if (!p || !p.player_id || !p.participation) {
            hud.style.display = 'none';
            return;
        }
        hud.style.display = '';
        document.getElementById('hud-coins').textContent = this.formatNumber(p.participation.coins);
        document.getElementById('hud-seasonal-stars').textContent = this.formatNumber(p.participation.seasonal_stars);
        const totalSigils = p.participation.sigils.reduce((a, b) => a + b, 0);
        document.getElementById('hud-sigils').textContent = totalSigils;
        const ratePerTick = Number(p.participation.rate_per_tick || 0);
        const sinkPerTick = Number(p.participation.hoarding_sink_per_tick || 0);
        // Bug fix: do NOT use `|| ratePerTick` here – when net_rate_per_tick is exactly 0
        // (sink fully absorbs gross), the falsy fallback would display the gross rate as the
        // net rate, misleading users into thinking they are earning coins when they are not.
        const rawNet = p.participation.net_rate_per_tick;
        const netRatePerTick = (rawNet !== undefined && rawNet !== null) ? Number(rawNet) : ratePerTick;
        const rateEl = document.getElementById('hud-rate');
        const rateLabelEl = document.querySelector('.hud-rate .hud-label');
        const freeze = p.participation.freeze || { is_frozen: false };
        if (rateEl) {
            if (freeze.is_frozen) {
                rateEl.textContent = '0';
                rateEl.classList.add('rate-frozen-active');
            } else {
                rateEl.textContent = this.formatNumber(ratePerTick);
                rateEl.classList.remove('rate-frozen-active');
            }
        }
        if (rateLabelEl) {
            if (freeze.is_frozen) {
                const freezeTime = this._formatFreezeTimeLeft(this.getLiveFreezeRemainingSeconds());
                rateLabelEl.textContent = `Rate Frozen (${freezeTime})`;
            } else if (sinkPerTick > 0) {
                rateLabelEl.textContent = `Rate (Gross, -${this.formatNumber(sinkPerTick)} sink, Net ${this.formatNumber(netRatePerTick)})`;
            } else {
                rateLabelEl.textContent = 'Rate (Gross)';
            }
        }

        // Boost total modifier only
        const boosts = p.active_boosts || { self: [], global: [], total_modifier_percent: 0 };
        const totalBoostPercent = Number(boosts.total_modifier_percent || 0);
        const boostEl = document.getElementById('hud-boosts');
        if (boostEl) {
            boostEl.textContent = `${totalBoostPercent}%`;
            if (totalBoostPercent > 0) {
                boostEl.className = 'hud-value boost-active';
            } else {
                boostEl.className = 'hud-value';
            }
        }

        // Check for new sigil drops and show notification
        this.checkSigilDropNotifications(p);
    },

    tickRealtimeViews() {
        if (this.state.currentScreen === 'seasons') {
            const timerValues = document.querySelectorAll('.season-timer-value');
            timerValues.forEach((el) => {
                const seasonId = el.getAttribute('data-season-id');
                const season = this.state.seasons.find(s => s.season_id == seasonId);
                if (season) el.textContent = this.getSeasonTimerText(season);
            });
        }

        if (this.state.currentScreen === 'season-detail' && this.state.currentSeason) {
            const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
            const timerValue = document.querySelector('.timer-value');
            if (season && timerValue) {
                timerValue.textContent = this.getSeasonTimerText(season);
            }
            this._tickBoostCountdowns();
        }

        this._tickFreezeCountdowns();
    },

    syncSeasonCountdowns(seasons) {
        if (!Array.isArray(seasons)) return;
        const syncedAtMs = Date.now();
        seasons.forEach((season) => {
            if (!season || season.season_id === undefined || season.season_id === null) return;
            if (season.time_remaining_real_seconds === undefined || season.time_remaining_real_seconds === null) return;
            const remainingSeconds = Math.max(0, parseInt(season.time_remaining_real_seconds, 10) || 0);
            this.state.seasonCountdowns[season.season_id] = {
                remainingSeconds,
                syncedAtMs
            };
        });
    },

    getLiveSeasonSeconds(season) {
        if (!season || season.season_id === undefined || season.season_id === null) return null;
        const countdown = this.state.seasonCountdowns[season.season_id];
        if (!countdown) return null;
        const elapsedSeconds = Math.floor((Date.now() - countdown.syncedAtMs) / 1000);
        return Math.max(0, countdown.remainingSeconds - elapsedSeconds);
    },

    formatSecondsRemaining(seconds) {
        const total = Math.max(0, parseInt(seconds, 10) || 0);
        if (total <= 0) return 'Ended';

        const days = Math.floor(total / 86400);
        const hours = Math.floor((total % 86400) / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;

        if (days > 0) return `${days}d ${hours}h ${minutes}m`;
        if (hours > 0) return `${hours}h ${minutes}m ${secs}s`;
        if (minutes > 0) return `${minutes}m ${secs}s`;
        return `${secs}s`;
    },

    getSeasonCountdownMode(season) {
        if (season && season.countdown_mode) return season.countdown_mode;
        const status = season && (season.computed_status || season.status);
        if (status === 'Scheduled') return 'scheduled';
        if (status === 'Active' || status === 'Blackout') return 'running';
        return 'ended';
    },

    getSeasonCardTimerLabel(season) {
        const mode = this.getSeasonCountdownMode(season);
        if (mode === 'scheduled') return 'Begins in';
        if (mode === 'running') return 'Time Left';
        return 'Ended';
    },

    getSeasonDetailTimerLabel(season) {
        const mode = this.getSeasonCountdownMode(season);
        if (mode === 'scheduled') return 'Begins in';
        if (mode === 'running') return 'Time Remaining';
        return 'Ended';
    },

    getSeasonTimerText(season) {
        const liveSeconds = this.getLiveSeasonSeconds(season);
        if (liveSeconds !== null) return this.formatSecondsRemaining(liveSeconds);
        return season.time_remaining_formatted || 'Ended';
    },

    // Track last known drop count to detect new drops
    _lastDropCount: 0,
    _notificationOutsideHandler: null,
    checkSigilDropNotifications(p) {
        const drops = p.recent_drops || [];
        const currentCount = drops.length;
        // Gameplay notifications are persisted in the notification center.
        this._lastDropCount = currentCount;
    },

    checkIdleModal() {
        const modal = document.getElementById('idle-modal');
        if (this.state.player && this.state.player.idle_modal_active) {
            modal.style.display = 'flex';
        } else {
            modal.style.display = 'none';
        }
    },

    async idleAck() {
        const result = await this.api('idle_ack');
        if (result.success) {
            document.getElementById('idle-modal').style.display = 'none';
            await this.refreshGameState();
        }
    },

    // ==================== RENDER: SEASONS ====================
    renderSeasons() {
        const container = document.getElementById('seasons-list');
        if (!this.state.seasons || this.state.seasons.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No seasons available yet. Check back soon!</p></div>';
            return;
        }

        let html = '';
        for (const s of this.state.seasons) {
            const status = s.computed_status || s.status;
            const statusClass = status.toLowerCase();
            const timerLabel = this.getSeasonCardTimerLabel(s);
            const timerText = this.getSeasonTimerText(s);
            const canJoin = this.state.player && !this.state.player.joined_season_id &&
                           (status === 'Active' || status === 'Blackout');
            const isMyseason = this.state.player && this.state.player.joined_season_id == s.season_id;
            const playerCount = s.player_count || 0;

            html += `
                <div class="season-card ${statusClass} ${isMyseason ? 'my-season' : ''}" onclick="TMC.navigate('season-detail', ${s.season_id})">
                    <div class="season-card-header">
                        <span class="season-id">Season #${s.season_id}</span>
                        <span class="season-status badge badge-${statusClass}">${status}</span>
                    </div>
                    <div class="season-card-body">
                        <div class="season-stat">
                            <span class="stat-label">Players</span>
                            <span class="stat-value">${playerCount}</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label">Star Price</span>
                            <span class="stat-value">${this.formatNumber(s.current_star_price)} coins</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label season-timer-label" data-season-id="${s.season_id}">${timerLabel}</span>
                            <span class="stat-value season-timer-value" data-season-id="${s.season_id}">${timerText}</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label">Coin Supply</span>
                            <span class="stat-value">${this.formatNumber(s.total_coins_supply)}</span>
                        </div>
                    </div>
                    <div class="season-card-footer">
                        ${isMyseason ? '<span class="badge badge-active">YOUR SEASON</span>' : ''}
                        ${canJoin ? '<button class="btn btn-primary btn-sm" onclick="event.stopPropagation(); TMC.joinSeason(' + s.season_id + ')">Join</button>' : ''}
                        ${status === 'Expired' ? '<span class="badge badge-expired">Completed</span>' : ''}
                    </div>
                </div>
            `;
        }
        container.innerHTML = html;
    },

    // ==================== SEASON DETAIL ====================
    updateSeasonDetailLive() {
        // Update dynamic values without re-rendering the entire season detail
        const seasonId = this.state.currentSeason;
        const season = this.state.seasons.find(s => s.season_id == seasonId);
        if (!season) return;

        // Update timer and label for all viewers (including non-participants)
        const timerLabel = document.querySelector('.timer-label');
        const timerValue = document.querySelector('.timer-value');
        if (timerLabel) timerLabel.textContent = this.getSeasonDetailTimerLabel(season);
        if (timerValue) timerValue.textContent = this.getSeasonTimerText(season);

        const p = this.state.player;
        if (!p || !p.participation) {
            this.loadSeasonLeaderboard(seasonId);
            return;
        }

        const isBlackout = (season.computed_status || season.status) === 'Blackout';

        // Update economy bar values if they exist
        const econValues = document.querySelectorAll('.econ-value');
        if (econValues.length >= 3) {
            econValues[0].textContent = this.formatNumber(season.current_star_price) + ' coins';
            econValues[1].textContent = this.formatNumber(season.total_coins_supply);
            econValues[2].textContent = season.player_count || 0;
        }

        // Update coin display in purchase panel
        const panelInfos = document.querySelectorAll('.panel-info');
        panelInfos.forEach(el => {
            if (el.textContent.includes('Current price:')) {
                el.innerHTML = `Current price: <strong>${this.formatNumber(season.current_star_price)} coins</strong> per star`;
            }
        });

        // Update sigil counts
        const sigilCounts = document.querySelectorAll('.sigil-count');
        const visibleSigils = this.getVisibleSigils(p.participation);
        if (sigilCounts.length === visibleSigils.length) {
            visibleSigils.forEach((row, i) => {
                sigilCounts[i].textContent = row.count;
            });
        }

        // Update lock-in star count and disabled state
        const lockInBtn = document.querySelector('.panel-lockin .btn-danger');
        if (lockInBtn) {
            lockInBtn.textContent = `Lock-In (${this.formatNumber(p.participation.seasonal_stars)} Stars)`;
            lockInBtn.disabled = !p.can_lock_in || isBlackout;
        }

        // Update forge combination buttons' disabled states and card visibility
        const combineRecipes = Array.isArray(p.participation.combine_recipes) ? p.participation.combine_recipes : [];
        const forgeItems = document.querySelectorAll('.sigil-combine-section .vault-item');
        let visibleForgeCount = 0;
        forgeItems.forEach((item) => {
            const btn = item.querySelector('button');
            if (!btn) return;
            const onclick = btn.getAttribute('onclick') || '';
            const match = onclick.match(/combineSigil\((\d+)\)/);
            if (!match) return;
            const fromTier = parseInt(match[1], 10);
            const recipe = combineRecipes.find(r => r.from_tier === fromTier);
            const canCombine = !!(recipe && recipe.can_combine);
            item.style.display = canCombine ? '' : 'none';
            if (canCombine) {
                btn.disabled = isBlackout;
                visibleForgeCount++;
            }
        });
        const forgeEmpty = document.querySelector('.sigil-combine-section .panel-info');
        if (forgeEmpty) forgeEmpty.style.display = visibleForgeCount === 0 ? '' : 'none';

        // Re-render the active boosts panel with fresh data from the latest poll
        this.renderActiveBoosts();

        // Refresh leaderboard
        this.loadSeasonLeaderboard(seasonId);
    },

    async loadSeasonDetail(seasonId) {
        const detail = await this.api('season_detail', { season_id: seasonId });
        if (detail.error) {
            this.toast(detail.error, 'error', { category: 'error_action' });
            document.getElementById('season-detail-content').innerHTML =
                `<div class="error-state"><p>Season details are temporarily unavailable.</p></div>`;
            return;
        }
        this.renderSeasonDetail(seasonId, detail);
    },

    renderSeasonDetail(seasonId, detail) {
        if (!detail) {
            // Use cached data from game state
            detail = this.state.seasons.find(s => s.season_id == seasonId);
            if (!detail) return;
        }

        const p = this.state.player;
        const isParticipating = p && p.joined_season_id == seasonId;
        const part = (isParticipating && p) ? (p.participation || null) : null;
        const status = detail.computed_status || detail.status;
        const isBlackout = status === 'Blackout';
        const isExpired = status === 'Expired';
        const timerLabel = this.getSeasonDetailTimerLabel(detail);
        const sigilRates = Array.isArray(detail?.sigil_drop_rates?.tiers) ? detail.sigil_drop_rates.tiers : [];
        const sigilBasePercent = Number(detail?.sigil_drop_rates?.base_percent || 0);
        const sigilRateByTier = {};
        sigilRates.forEach((rateRow) => {
            if (rateRow && rateRow.tier) {
                sigilRateByTier[rateRow.tier] = Number(rateRow.chance_percent || 0);
            }
        });
        const combineRecipes = (p && p.participation && Array.isArray(p.participation.combine_recipes))
            ? p.participation.combine_recipes
            : [];
        const visibleCombineRecipes = combineRecipes.filter((recipe) => !!recipe.can_combine);

        let html = `
            <div class="season-header">
                <div class="season-header-left">
                    <h2>Season #${seasonId}</h2>
                    <span class="badge badge-${status.toLowerCase()} badge-lg">${status}</span>
                    ${isBlackout ? '<span class="badge badge-warning badge-lg">BLACKOUT - No new actions</span>' : ''}
                </div>
                <div class="season-header-right">
                    <div class="season-timer">
                        <span class="timer-label">${timerLabel}</span>
                        <span class="timer-value">${this.getSeasonTimerText(detail)}</span>
                    </div>
                </div>
            </div>

            <div class="season-economy-bar">
                <div class="economy-stat">
                    <span class="econ-label">Star Price</span>
                    <span class="econ-value">${this.formatNumber(detail.current_star_price)} coins</span>
                </div>
                <div class="economy-stat">
                    <span class="econ-label">Coin Supply</span>
                    <span class="econ-value">${this.formatNumber(detail.total_coins_supply)}</span>
                </div>
                <div class="economy-stat">
                    <span class="econ-label">Players</span>
                    <span class="econ-value">${detail.player_count || 0}</span>
                </div>
            </div>
        `;

        // Action panel (if participating)
        if (isParticipating && !isExpired && part) {
            html += `
                <div class="action-panels">
                    <!-- Purchase Stars Panel -->
                    <div class="action-panel">
                        <h3>Purchase Seasonal Stars</h3>
                        <p class="panel-info">Current price: <strong>${this.formatNumber(detail.current_star_price)} coins</strong> per star</p>
                        <div class="action-row">
                            <input type="number" id="purchase-stars" min="1" placeholder="Star quantity" class="input-field" oninput="TMC.updatePurchaseEstimate()">
                            <button id="purchase-stars-btn" class="btn btn-primary" onclick="TMC.purchaseStarsGated()" ${isBlackout ? 'disabled' : ''}>Buy Stars</button>
                            <button id="purchase-max-btn" class="btn btn-outline" onclick="TMC.buyMaxStars()" ${isBlackout ? 'disabled' : ''}>Buy Max</button>
                        </div>
                        <p id="purchase-estimate" class="panel-info">Enter a star quantity to see estimated coin cost.</p>
                    </div>

                    <!-- Sigils Panel -->
                    <div class="action-panel">
                        <h3>Sigils</h3>
                        <p class="panel-info">Drop chance per eligible tick: <strong>${this.truncatePercent(sigilBasePercent)}%</strong></p>
                        <div class="sigil-display">
                            ${this.getVisibleSigils(part).map((sigil) => `
                                <div class="sigil-item tier-${sigil.tier}">
                                    <span class="sigil-tier">T${sigil.tier}</span>
                                    <span class="sigil-count">${sigil.count}</span>
                                    ${sigil.tier <= 5 ? `<span class="sigil-rate">${this.truncatePercent(sigilRateByTier[sigil.tier] || 0)}%</span>` : '<span class="sigil-rate">Crafted only</span>'}
                                </div>
                            `).join('')}
                        </div>
                        <div class="sigil-combine-section">
                            <h4>Sigil Forge</h4>
                            <div class="vault-grid">
                                ${visibleCombineRecipes.map((recipe) => `
                                    <div class="vault-item tier-${recipe.from_tier}">
                                        <span class="vault-tier">T${recipe.from_tier} x${recipe.required} to T${recipe.to_tier}</span>
                                        <span class="vault-remaining">Owned: ${recipe.owned}</span>
                                        <button class="btn btn-sm btn-primary"
                                            onclick="TMC.combineSigil(${recipe.from_tier})"
                                            ${!recipe.can_combine || isBlackout ? 'disabled' : ''}>
                                            Combine
                                        </button>
                                    </div>
                                `).join('')}
                            </div>
                            ${visibleCombineRecipes.length === 0 ? '<p class="panel-info">No combinations currently available.</p>' : ''}
                        </div>
                        ${part.can_freeze ? `
                            <div class="sigil-freeze-section">
                                <h4>Tier 6 Freeze</h4>
                                <p class="panel-info">Consume 1 Tier 6 sigil to nullify a target's boost percentage while boosts continue to count down.</p>
                                <div class="action-row">
                                    <input type="text" id="freeze-target-handle" class="input-field" placeholder="Target handle">
                                    <button class="btn btn-danger" onclick="TMC.freezeByHandle()" ${isBlackout ? 'disabled' : ''}>Freeze by Handle</button>
                                </div>
                            </div>
                        ` : ''}
                        <div class="sigil-vault-section">
                            <h4>Sigil Vault</h4>
                            <p class="panel-info">Purchase Sigils with Seasonal Stars</p>
                            <div class="vault-grid">
                                ${(detail.vault || []).map(v => {
                                    const remaining = Number(v.remaining_supply ?? 0);
                                    const initial = Number(v.initial_supply ?? 0);
                                    const cost = Number(v.current_cost_stars ?? 0);
                                    return `
                                    <div class="vault-item tier-${v.tier}">
                                        <span class="vault-tier">Tier ${v.tier}</span>
                                        <span class="vault-remaining">${remaining}/${initial} left</span>
                                        <span class="vault-cost">${cost} stars</span>
                                        <button class="btn btn-sm btn-primary" 
                                            onclick="TMC.purchaseVault(${v.tier})"
                                            ${remaining <= 0 || isBlackout ? 'disabled' : ''}>
                                            ${remaining <= 0 ? 'Sold Out' : 'Buy'}
                                        </button>
                                    </div>
                                `;}).join('')}
                            </div>
                        </div>
                    </div>

                    <!-- Boosts Panel -->
                    <div class="action-panel panel-boosts">
                        <h3>Boosts</h3>
                        <p class="panel-info">Consume Sigils to activate temporary UBI modifiers.</p>
                        <div id="active-boosts-display"></div>
                        <div class="boost-catalog-actions">
                            <button id="boost-catalog-toggle" class="btn btn-outline btn-sm" onclick="TMC.toggleBoostCatalog()">Show Boost Catalog</button>
                        </div>
                        <div id="boost-catalog-grid" class="boost-catalog-grid boost-catalog-collapsed"></div>
                    </div>

                    <!-- Lock-In Panel -->
                    <div class="action-panel panel-lockin">
                        <h3>Lock-In</h3>
                        <button class="btn btn-danger btn-lg" onclick="TMC.confirmLockIn()" 
                            ${!p.can_lock_in || isBlackout ? 'disabled' : ''}>
                            Lock-In (${this.formatNumber(part.seasonal_stars)} Stars)
                        </button>
                    </div>
                </div>
            `;
        } else if (!isParticipating && !isExpired && this.state.player) {
            html += `
                <div class="join-panel">
                    <h3>Join This Season</h3>
                    <p>Start earning Coins through UBI and compete for the top of the leaderboard.</p>
                    ${this.state.player.joined_season_id ? 
                        '<p class="panel-warning">You are already participating in another season. Lock-In or wait for it to end first.</p>' :
                        `<button class="btn btn-primary btn-lg" onclick="TMC.joinSeason(${seasonId})">Join Season #${seasonId}</button>`
                    }
                </div>
            `;
        }

        // Leaderboard
        html += `
            <div class="season-leaderboard">
                <h3><button class="season-lb-title-link" type="button" onclick="TMC.openSeasonalLeaderboardTab()">Season Leaderboard</button></h3>
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rate</th>
                            <th>Player</th>
                            <th>Stars</th>
                            <th>Boost</th>
                            <th>Coins</th>
                            <th>Status</th>
                            ${isParticipating && part && part.can_freeze ? '<th>Action</th>' : ''}
                        </tr>
                    </thead>
                    <tbody id="season-lb-body">
                    </tbody>
                </table>
                <div id="season-lb-toggle-wrap" class="leaderboard-toggle-wrap" style="display:none;">
                    <button id="season-lb-toggle-btn" type="button" class="leaderboard-toggle-btn" onclick="TMC.toggleSeasonDetailLeaderboard()"></button>
                </div>
                <div id="season-lb-empty" class="empty-state" style="display:none;">
                    <p>No ranked players yet.</p>
                </div>
            </div>
        `;

        document.getElementById('season-detail-content').innerHTML = html;

        // Load leaderboard
        this.loadSeasonLeaderboard(seasonId);

        // Load trades if participating
        if (isParticipating && part) this.loadMyTrades();

        this.updatePurchaseEstimate();
        this.renderActiveBoosts();
        this.renderBoostCatalogToggle();
        if (this._boostCatalog) this.renderBoostCatalog();
    },

    async loadSeasonLeaderboard(seasonId) {
        const lb = await this.api('leaderboard', { season_id: seasonId, limit: 20 });
        const body = document.getElementById('season-lb-body');
        const empty = document.getElementById('season-lb-empty');
        const toggleWrap = document.getElementById('season-lb-toggle-wrap');
        const toggleBtn = document.getElementById('season-lb-toggle-btn');
        if (!body) return;

        if (!Array.isArray(lb) || lb.length === 0 || lb.error) {
            body.innerHTML = '';
            if (empty) empty.style.display = '';
            if (toggleWrap) toggleWrap.style.display = 'none';
            return;
        }
        if (empty) empty.style.display = 'none';

        const includeActions = !!(this.state.currentScreen === 'season-detail' && this.state.player && this.state.player.participation && this.state.player.participation.can_freeze);
        const capped = lb.slice(0, 20);
        const rows = this._seasonDetailLeaderboardExpanded ? capped : capped.slice(0, 3);
        body.innerHTML = this.renderSeasonLeaderboardRows(rows, includeActions, { firstCol: 'rate' });

        if (toggleWrap && toggleBtn) {
            if (lb.length > 3) {
                toggleWrap.style.display = '';
                toggleBtn.textContent = this._seasonDetailLeaderboardExpanded
                    ? '▴ Hide to Top 3'
                    : '▾ Show Top 20';
            } else {
                toggleWrap.style.display = 'none';
            }
        }
    },

    toggleSeasonDetailLeaderboard() {
        this._seasonDetailLeaderboardExpanded = !this._seasonDetailLeaderboardExpanded;
        if (this.state.currentSeason) this.loadSeasonLeaderboard(this.state.currentSeason);
    },

    openSeasonalLeaderboardTab() {
        this.navigate('global-lb', { tab: 'seasonal' });
    },

    // ==================== ACTIONS ====================
    async joinSeason(seasonId) {
        if (!this.state.player) {
            this.navigate('auth');
            return;
        }
        const result = await this.api('season_join', { season_id: seasonId });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast('Joined the season! Start earning Coins.', 'success', {
            category: 'season_join',
            payload: { season_id: Number(seasonId) || null }
        });
        await this.refreshGameState();
        this.navigate('season-detail', seasonId);
    },

    async purchaseStars() {
        const input = document.getElementById('purchase-stars');
        const starsRequested = parseInt(input.value);
        if (!starsRequested || starsRequested <= 0) {
            this.toast('Enter a valid star quantity.', 'error');
            return;
        }
        const result = await this.api('purchase_stars', { stars_requested: starsRequested });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(`Purchased ${this.formatNumber(result.stars_purchased)} stars for ${this.formatNumber(result.coins_spent)} coins!`, 'success', {
            category: 'purchase_star',
            payload: {
                stars_purchased: Number(result.stars_purchased) || 0,
                coins_spent: Number(result.coins_spent) || 0,
                season_id: Number(this.state.currentSeason) || null
            }
        });
        input.value = '';
        this.updatePurchaseEstimate();
        await this.refreshGameState();
    },

    async buyMaxStars() {
        const input = document.getElementById('purchase-stars');
        if (!input) return;

        const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
        const starPrice = season ? parseInt(season.current_star_price, 10) : 0;
        const coinsOwned = this.state.player && this.state.player.participation ? this.state.player.participation.coins : 0;

        if (!starPrice || starPrice <= 0) {
            this.toast('Cannot calculate max stars right now.', 'error');
            return;
        }

        const maxStars = Math.floor(coinsOwned / starPrice);
        if (maxStars < 1) {
            this.toast('Not enough coins to buy 1 star at current price.', 'error');
            return;
        }

        input.value = maxStars;
        this.updatePurchaseEstimate();
        await this.purchaseStarsGated();
    },

    updatePurchaseEstimate() {
        const input = document.getElementById('purchase-stars');
        const estimateEl = document.getElementById('purchase-estimate');
        const buyButton = document.getElementById('purchase-stars-btn');
        const buyMaxButton = document.getElementById('purchase-max-btn');
        if (!input || !estimateEl) return;

        const starsRequested = parseInt(input.value, 10);
        const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
        const status = season && season.computed_status ? season.computed_status : (season ? season.status : null);
        const isBlackout = status === 'Blackout';

        if (buyButton) {
            buyButton.disabled = isBlackout;
        }
        if (buyMaxButton) {
            buyMaxButton.disabled = isBlackout;
        }

        if (!starsRequested || starsRequested <= 0) {
            estimateEl.classList.remove('panel-warning');
            estimateEl.textContent = 'Enter a star quantity to see estimated coin cost.';
            return;
        }

        const starPrice = season ? parseInt(season.current_star_price, 10) : 0;
        if (!starPrice || starPrice <= 0) {
            if (buyButton && !isBlackout) buyButton.disabled = true;
            if (buyMaxButton && !isBlackout) buyMaxButton.disabled = true;
            estimateEl.classList.remove('panel-warning');
            estimateEl.textContent = 'Coin cost estimate unavailable right now.';
            return;
        }

        const coinsNeeded = starsRequested * starPrice;
        const coinsOwned = this.state.player && this.state.player.participation ? this.state.player.participation.coins : null;
        const maxStars = coinsOwned !== null ? Math.floor(coinsOwned / starPrice) : 0;

        if (buyMaxButton && !isBlackout) {
            buyMaxButton.disabled = maxStars < 1;
        }

        if (coinsOwned !== null && coinsNeeded > coinsOwned) {
            if (buyButton && !isBlackout) buyButton.disabled = true;
            estimateEl.classList.add('panel-warning');
            estimateEl.textContent = `Estimated cost: ${this.formatNumber(coinsNeeded)} coins (${this.formatNumber(starPrice)} each). You need ${this.formatNumber(coinsNeeded - coinsOwned)} more coins.`;
            return;
        }

        if (buyButton && !isBlackout) buyButton.disabled = false;
        estimateEl.classList.remove('panel-warning');
        estimateEl.textContent = `Estimated cost: ${this.formatNumber(coinsNeeded)} coins (${this.formatNumber(starPrice)} each).`;
    },

    async purchaseVault(tier) {
        const result = await this.api('purchase_vault', { tier });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(`Purchased Tier ${tier} Sigil for ${result.cost_stars} stars!`, 'success', {
            category: 'purchase_sigil',
            payload: {
                tier: Number(tier) || null,
                cost_stars: Number(result.cost_stars) || 0,
                season_id: Number(this.state.currentSeason) || null
            }
        });
        await this.refreshGameState();
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    getVisibleSigils(participation) {
        const sigils = Array.isArray(participation?.sigils) ? participation.sigils : [];
        const visible = [];
        for (let tier = 1; tier <= 5; tier++) {
            visible.push({ tier, count: Number(sigils[tier - 1] || 0) });
        }

        const hasTier6 = Number(sigils[5] || 0) > 0;
        if (hasTier6) {
            visible.push({ tier: 6, count: Number(sigils[5] || 0) });
        }

        return visible;
    },

    async combineSigil(fromTier) {
        const result = await this.api('combine_sigil', { from_tier: fromTier });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_action' });
            return;
        }
        this.toast(result.message || `Combined Tier ${fromTier} sigils.`, 'success', {
            category: 'sigil_combine',
            payload: {
                from_tier: Number(result.from_tier) || Number(fromTier),
                to_tier: Number(result.to_tier) || (Number(fromTier) + 1),
                consumed: Number(result.consumed) || 0,
                produced: Number(result.produced) || 1
            }
        });
        await this.refreshGameState();
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    async freezeByPlayerId(targetPlayerId) {
        const result = await this.api('freeze_player_ubi', { target_player_id: targetPlayerId });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_action' });
            return;
        }
        this.toast(result.message || 'Freeze applied.', 'success', {
            category: 'freeze_apply',
            payload: {
                target_player_id: Number(result.target_player_id) || Number(targetPlayerId),
                freeze_duration_ticks: Number(result.freeze_duration_ticks) || 0,
                expires_tick: Number(result.expires_tick) || null
            }
        });
        await this.refreshGameState();
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    async freezeByHandle() {
        const input = document.getElementById('freeze-target-handle');
        const targetHandle = input ? String(input.value || '').trim() : '';
        if (!targetHandle) {
            this.toast('Enter a target handle.', 'error', { category: 'error_validation' });
            return;
        }

        const result = await this.api('freeze_player_ubi', { target_handle: targetHandle });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_action' });
            return;
        }
        this.toast(result.message || 'Freeze applied.', 'success', {
            category: 'freeze_apply',
            payload: {
                target_player_id: Number(result.target_player_id) || null,
                target_handle: result.target_handle || targetHandle,
                freeze_duration_ticks: Number(result.freeze_duration_ticks) || 0,
                expires_tick: Number(result.expires_tick) || null
            }
        });
        if (input) input.value = '';
        await this.refreshGameState();
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    // ==================== BOOSTS ====================
    _boostCatalog: null,
    _boostCatalogCollapsed: true,

    async loadBoostCatalog() {
        const catalog = await this.api('boost_catalog');
        if (catalog.error) {
            this.toast(catalog.error, 'error');
            return;
        }
        this._boostCatalog = catalog;
        this._boostCatalogCollapsed = false;
        this.renderBoostCatalogToggle();
        this.renderBoostCatalog();
        this.renderActiveBoosts();
    },

    toggleBoostCatalog() {
        if (!this._boostCatalog) {
            this.loadBoostCatalog();
            return;
        }
        this._boostCatalogCollapsed = !this._boostCatalogCollapsed;
        this.renderBoostCatalogToggle();
        this.renderBoostCatalog();
    },

    renderBoostCatalogToggle() {
        const toggleBtn = document.getElementById('boost-catalog-toggle');
        if (!toggleBtn) return;

        if (!this._boostCatalog || this._boostCatalog.length === 0) {
            toggleBtn.textContent = 'Show Boost Catalog';
            return;
        }

        toggleBtn.textContent = this._boostCatalogCollapsed ? 'Show Boost Catalog' : 'Hide Boost Catalog';
    },

    renderBoostCatalog() {
        const grid = document.getElementById('boost-catalog-grid');
        if (!grid || !this._boostCatalog) return;

        grid.classList.toggle('boost-catalog-collapsed', this._boostCatalogCollapsed);
        if (this._boostCatalogCollapsed) {
            grid.innerHTML = '';
            return;
        }

        const p = this.state.player;
        const part = p ? p.participation : null;
        const activeSelfBoosts = (p && p.active_boosts && Array.isArray(p.active_boosts.self)) ? p.active_boosts.self : [];
        const tierNames = ['', 'Common', 'Uncommon', 'Rare', 'Epic', 'Legendary'];
        const tierIcons = ['', '&#9672;', '&#9670;', '&#9733;', '&#10038;', '&#9830;'];

        grid.innerHTML = `<h4>Available Boosts</h4>` + this._boostCatalog.map(b => {
            const tier = parseInt(b.tier_required);
            const hasSigil = part && part.sigils[tier - 1] >= parseInt(b.sigil_cost);
            const modPercent = (parseInt(b.modifier_fp) / 10000).toFixed(1);
            const durationTicks = parseInt(b.duration_ticks);
            const timeExtensionTicks = parseInt(b.time_extension_ticks || 0);
            const activeBoost = activeSelfBoosts.find((ab) => parseInt(ab.tier_required) === tier) || null;
            const currentStack = activeBoost ? (parseInt(activeBoost.current_stack || 0) || 0) : 0;
            const maxStack = parseInt(b.max_stack || 0) || 0;
            const canBuyTime = !!activeBoost;
            const description = this.getBoostDescription(b);
            const displayName = this.getBoostDisplayName(b.name);
            const displayIcon = this.getBoostDisplayIcon(b.icon, tierIcons[tier]);
            const boostKey = String(displayName || b.name || '').trim().toLowerCase();
            const durationDisplayByBoost = {
                trickle: '1hr',
                surge: '3hrs',
                flow: '6hrs',
                tide: '12hrs',
                age: '24hrs',
            };
            const timePurchaseLabelByBoost = {
                trickle: '+5 mins',
                surge: '+15 mins',
                flow: '+30 mins',
                tide: '+60 mins',
                age: '+90 mins',
            };
            const durationLabel = durationDisplayByBoost[boostKey] || this.formatBoostDuration(durationTicks, 'short');
            const timePurchaseLabel = timePurchaseLabelByBoost[boostKey] || `+${timeExtensionTicks} mins`;

            return `
                <div class="boost-card tier-${tier} ${hasSigil ? '' : 'boost-locked'}">
                    <div class="boost-card-header">
                        <div class="boost-title">
                            <span class="boost-icon">${displayIcon}</span>
                            <span class="boost-name">${this.escapeHtml(displayName)}</span>
                        </div>
                        <div class="boost-inline-meta">
                            <span class="boost-modifier">+${modPercent}% UBI</span>
                            <span class="boost-duration">${durationLabel}</span>
                        </div>
                        <span class="boost-have boost-have-inline">Power: ${currentStack}/${maxStack}</span>
                        <span class="boost-have boost-have-inline">(You have: ${part ? part.sigils[tier-1] : 0})</span>
                    </div>
                    ${description ? `<p class="boost-desc">${this.escapeHtml(description)}</p>` : ''}
                    <div class="action-row">
                        <button class="btn btn-sm ${hasSigil ? 'btn-primary' : 'btn-outline'}"
                            onclick="TMC.purchaseBoostPowerGated(${b.boost_id})"
                            ${!hasSigil ? 'disabled title="Not enough Sigils"' : ''}>
                            Power +${modPercent}%
                        </button>
                        <button class="btn btn-sm ${hasSigil ? 'btn-outline' : 'btn-outline'}"
                            onclick="TMC.purchaseBoostTimeGated(${b.boost_id})"
                            ${(!hasSigil || !canBuyTime) ? 'disabled title="Activate boost power first"' : ''}>
                            Time ${timePurchaseLabel}
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    },

    getBoostDisplayName(name) {
        const raw = String(name || '').trim();
        if (!raw) return '';
        const withoutLowercaseWords = raw
            .split(/\s+/)
            .filter(part => !/^[a-z][a-z0-9_\-]*$/.test(part));

        const normalized = withoutLowercaseWords.length > 0
            ? withoutLowercaseWords.join(' ')
            : raw;

        const parts = normalized.split(/\s+/);
        if (parts.length <= 1) return normalized;
        return parts.slice(1).join(' ');
    },

    getBoostDisplayIcon(icon, fallbackIcon) {
        const raw = String(icon || '').trim();
        if (!raw) return fallbackIcon || '';

        // Hide plain lowercase icon labels so titles don't show duplicated words like "trickle Trickle".
        if (/^[a-z][a-z0-9_\-]*$/.test(raw)) return fallbackIcon || '';

        return raw;
    },

    formatBoostDuration(durationTicks, style = 'short') {
        const ticks = Math.max(0, parseInt(durationTicks, 10) || 0);
        const minutes = ticks;

        if (minutes >= 60 && minutes % 60 === 0) {
            const hours = minutes / 60;
            if (style === 'long') return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
            return `${hours} ${hours === 1 ? 'hr' : 'hrs'}`;
        }

        if (style === 'long') return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
        return `${minutes} min`;
    },

    getBoostDescription(boost) {
        if (!boost) return '';
        const name = String(boost.name || '').trim();
        const raw = String(boost.description || '').trim();
        if (!raw) return '';

        const normalizedName = name.toLowerCase();
        if (raw.toLowerCase() === normalizedName) return '';

        const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const prefixed = new RegExp(`^${escapedName}\\s*[:\\-]\\s*`, 'i');
        let cleaned = raw.replace(prefixed, '').trim();

        const durationLabel = this.formatBoostDuration(boost.duration_ticks, 'long');
        cleaned = cleaned.replace(/for\s+\d+\s+(?:hour|hours|minute|minutes)(?=\.|,|$)/i, `for ${durationLabel}`);

        if (!cleaned || cleaned.toLowerCase() === normalizedName) return '';
        return cleaned;
    },

    renderActiveBoosts() {
        const container = document.getElementById('active-boosts-display');
        if (!container) return;

        const p = this.state.player;
        const boosts = p ? p.active_boosts : null;
        const freeze = p && p.participation ? p.participation.freeze : null;
        const isFrozen = !!(freeze && freeze.is_frozen);
        if (!boosts || (((boosts.self || []).length === 0 && (boosts.global || []).length === 0) && !isFrozen)) {
            container.innerHTML = '<p class="empty-text">No active boosts.</p>';
            return;
        }

        let html = `<div class="active-boosts-summary">
            <span class="boost-total-mod">Total UBI Modifier: <strong>+${boosts.total_modifier_percent}%</strong></span>
        </div>`;

        const renderBoost = (b, type) => {
            const remainingSeconds = this.getLiveBoostRemainingSeconds(b);
            const modPercent = (parseInt(b.modifier_fp) / 10000).toFixed(1);
            const timeLeft = this._formatBoostTimeLeft(remainingSeconds);
            const displayName = this.getBoostDisplayName(b.name);
            const boostKey = this._getBoostKey(b);
            return `<div class="active-boost-item ${type}" data-boost-id="${boostKey}">
                <span class="ab-name">${this.escapeHtml(displayName)}</span>
                <span class="ab-mod">+${modPercent}%</span>
                <span class="ab-time">${timeLeft}</span>
                ${type === 'global' ? `<span class="ab-by">by ${this.escapeHtml(b.activator_handle || '')}</span>` : ''}
            </div>`;
        };

        if (boosts.self.length > 0) {
            html += '<div class="ab-section"><h4>Your Boosts</h4>' + boosts.self.map(b => renderBoost(b, 'self')).join('') + '</div>';
        }
        if (boosts.global.length > 0) {
            html += '<div class="ab-section"><h4>Season-Wide Boosts</h4>' + boosts.global.map(b => renderBoost(b, 'global')).join('') + '</div>';
        }
        if (isFrozen) {
            const freezeTime = this._formatFreezeTimeLeft(this.getLiveFreezeRemainingSeconds());
            html += `<div class="ab-section"><h4>Freeze Effects</h4>
                <div class="active-boost-item freeze" data-freeze-active="1">
                    <span class="ab-name">Tier 6 Freeze</span>
                    <span class="ab-mod ab-mod-freeze">Rate Frozen</span>
                    <span class="ab-time freeze-time">${freezeTime}</span>
                </div>
            </div>`;
        }

        container.innerHTML = html;
    },

    async purchaseBoostPower(boostId) {
        const boost = this._boostCatalog ? this._boostCatalog.find(b => b.boost_id == boostId) : null;
        const name = boost ? this.getBoostDisplayName(boost.name) : `Boost #${boostId}`;
        if (!confirm(`Purchase ${name} power?\n\nThis will consume ${boost ? boost.sigil_cost : 1} Tier ${boost ? boost.tier_required : '?'} Sigil(s).`)) {
            return;
        }

        const result = await this.api('purchase_boost', { boost_id: boostId, purchase_kind: 'power' });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(result.message, 'success', {
            category: 'boost_activate',
            payload: {
                boost_id: Number(boostId) || null,
                season_id: Number(this.state.currentSeason) || null
            }
        });
        await this.refreshGameState();
        this.loadBoostCatalog();
    },

    async purchaseBoostTime(boostId) {
        const boost = this._boostCatalog ? this._boostCatalog.find(b => b.boost_id == boostId) : null;
        const name = boost ? this.getBoostDisplayName(boost.name) : `Boost #${boostId}`;
        if (!confirm(`Purchase ${name} time extension?\n\nThis will consume ${boost ? boost.sigil_cost : 1} Tier ${boost ? boost.tier_required : '?'} Sigil(s).`)) {
            return;
        }

        const result = await this.api('purchase_boost', { boost_id: boostId, purchase_kind: 'time' });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(result.message, 'success', {
            category: 'boost_activate',
            payload: {
                boost_id: Number(boostId) || null,
                season_id: Number(this.state.currentSeason) || null,
                purchase_kind: 'time'
            }
        });
        await this.refreshGameState();
        this.loadBoostCatalog();
    },

    confirmLockIn() {
        const participation = this.state.player && this.state.player.participation;
        const sigils = participation ? (participation.sigils || []) : [];
        const t6Count = Number(sigils[5] || 0);

        // T6 warning must appear FIRST and ONLY if the player owns any T6 sigils.
        if (t6Count > 0) {
            if (!confirm(
                `⚠️ T6 Sigil Destruction Warning\n\n` +
                `You own ${t6Count} Tier 6 Sigil(s). ` +
                `T6 Sigils will be DESTROYED with NO refund upon Lock-In.\n\n` +
                `Do you wish to continue?`
            )) {
                return;
            }
        }

        const stars = participation ? participation.seasonal_stars : 0;
        if (!confirm(
            `Are you sure you want to Lock-In?\n\n` +
            `This will:\n` +
            `- Refund T1–T5 Sigils back to Seasonal Stars\n` +
            `- Convert all Seasonal Stars to Global Stars at 65% (rounded down)\n` +
            `- Destroy ALL your Coins, T6 Sigils, and Boosts\n` +
            `- Remove you from this season\n\n` +
            `Current Seasonal Stars: ${this.formatNumber(stars)} ` +
            `(final Global Stars payout will be floor(total × 0.65))\n\n` +
            `This action is IRREVERSIBLE.`
        )) {
            return;
        }
        this.lockIn();
    },

    async lockIn() {
        const result = await this.api('lock_in');
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(result.message, 'success', { category: 'season_lock_in' });
        await this.refreshGameState();
        this.navigate('home');
    },

    // ==================== GLOBAL LEADERBOARD ====================
    getActiveJoinedSeason() {
        if (!this.state.player || !this.state.player.joined_season_id) return null;
        const joinedSeasonId = this.state.player.joined_season_id;
        const season = this.state.seasons.find(s => s.season_id == joinedSeasonId);
        if (!season) return null;

        const status = season.computed_status || season.status;
        if (status === 'Active' || status === 'Blackout') return season;
        return null;
    },

    switchLeaderboardTab(tab) {
        this.state.leaderboardTab = tab === 'seasonal' ? 'seasonal' : 'global';
        this.loadGlobalLeaderboard();
    },

    updateLeaderboardTabUI(showSeasonal) {
        const globalTab = document.getElementById('leaderboard-tab-global');
        const seasonalTab = document.getElementById('leaderboard-tab-seasonal');
        if (!globalTab || !seasonalTab) return;

        seasonalTab.style.display = showSeasonal ? '' : 'none';
        if (!showSeasonal && this.state.leaderboardTab === 'seasonal') {
            this.state.leaderboardTab = 'global';
        }

        globalTab.classList.toggle('active', this.state.leaderboardTab !== 'seasonal');
        seasonalTab.classList.toggle('active', this.state.leaderboardTab === 'seasonal');
    },

    getPlayerStatus(entry) {
        if (!entry.online_current) return 'Offline';
        return entry.activity_state || 'Offline';
    },

    renderPlayerStatusBadge(entry) {
        const status = this.getPlayerStatus(entry);
        const key = status.toLowerCase().replace(/[^a-z]+/g, '-');
        let badge = `<span class="status-dot status-dot-${key}" title="${status}"></span>`;
        if (Number(entry.is_frozen || 0) > 0) {
            badge += ` <span class="status-dot status-dot-frozen" title="Frozen"></span>`;
        }
        if (entry.lock_in_effect_tick != null) {
            badge += ` <span class="status-dot status-dot-locked-in" title="Locked-In"></span>`;
        }
        return badge;
    },

    setLeaderboardMeta(title, subtitle) {
        const titleEl = document.getElementById('global-lb-title');
        const subtitleEl = document.getElementById('global-lb-subtitle');
        if (titleEl) titleEl.textContent = title;
        if (subtitleEl) subtitleEl.textContent = subtitle;
    },

    setLeaderboardHeader(columns) {
        const theadRow = document.querySelector('#global-lb-content thead tr');
        if (!theadRow) return;
        theadRow.innerHTML = columns.map((c) => `<th>${c}</th>`).join('');
    },

    renderSeasonLeaderboardRows(entries, includeActions = false, options = {}) {
        const canFreeze = includeActions && !!(this.state.player && this.state.player.participation && this.state.player.participation.can_freeze);
        const firstCol = options.firstCol === 'rate' ? 'rate' : 'rank';
        const showRateNearCoins = !!options.showRateNearCoins;
        return entries.map((entry, i) => {
            // Support rank field under any of the common schema aliases
            // (final_rank for ended seasons, position/rank for live data).
            const rawRank = entry.final_rank ?? entry.position ?? entry.rank ?? null;
            const parsedFinalRank = Number(rawRank);
            const hasFinalRank = rawRank != null && !Number.isNaN(parsedFinalRank) && parsedFinalRank > 0;
            const rank = hasFinalRank ? parsedFinalRank : (i + 1);
            const isMe = this.state.player && entry.player_id == this.state.player.player_id;
            let statusBadge = this.renderPlayerStatusBadge(entry);
            if (entry.badge_awarded) {
                const badgeEmoji = { first: '&#129351;', second: '&#129352;', third: '&#129353;' };
                statusBadge += ` <span class="badge badge-${entry.badge_awarded}">${badgeEmoji[entry.badge_awarded] || ''}</span>`;
            } else if (entry.end_membership) {
                statusBadge += ' <span class="badge badge-ended">End-Finisher</span>';
            }
            const ratePerTick = Number(entry.rate_per_tick || 0);
            const firstColValue = firstCol === 'rate'
                ? `${this.formatPercentCompact(ratePerTick)} /t`
                : `#${rank}`;
            const coinsCell = showRateNearCoins
                ? `${this.formatNumber(entry.coins || 0)}<div class="lb-econ-meta">Rate ${this.formatPercentCompact(ratePerTick)}/t • Boost ${entry.boost_pct != null ? entry.boost_pct : '0'}%</div>`
                : this.formatNumber(entry.coins || 0);

            return `
                <tr class="${isMe ? 'my-row' : ''} ${rank <= 3 ? 'top-three' : ''}">
                    <td class="${firstCol === 'rate' ? 'rate-cell' : 'rank-cell'}">${firstColValue}</td>
                    <td class="player-cell">
                        <span class="player-link" onclick="TMC.navigate('profile', ${entry.player_id})">${this.escapeHtml(entry.handle)}</span>
                    </td>
                    <td class="stars-cell">${this.formatNumber(entry.seasonal_stars)}</td>
                    <td class="boost-cell">${entry.boost_pct != null ? entry.boost_pct + '%' : '0%'}</td>
                    <td class="stars-cell">${coinsCell}</td>
                    <td class="status-cell">${statusBadge}</td>
                    ${canFreeze ? `<td class="status-cell">${(!isMe && entry.activity_state === 'Active') ? `<button class="btn btn-sm btn-danger" onclick="event.stopPropagation(); TMC.freezeByPlayerId(${entry.player_id})">Freeze</button>` : ''}</td>` : ''}
                </tr>
            `;
        }).join('');
    },

    async loadGlobalLeaderboard() {
        const activeSeason = this.getActiveJoinedSeason();
        const body = document.getElementById('global-lb-body');
        const empty = document.getElementById('global-lb-empty');
        const showSeasonalTab = !!activeSeason;
        this.updateLeaderboardTabUI(showSeasonalTab);

        if (this.state.leaderboardTab === 'seasonal' && activeSeason) {
            this.setLeaderboardMeta(
                `Season #${activeSeason.season_id} Leaderboard`,
                'Ranked by Seasonal Stars in your active season.'
            );
            this.setLeaderboardHeader(['Rank', 'Player', 'Stars', 'Boost', 'Coins / Rate', 'Status']);

            const lb = await this.api('leaderboard', { season_id: activeSeason.season_id, limit: this._globalSeasonalLeaderboardExpanded ? 0 : 20 });
            if (!Array.isArray(lb) || lb.length === 0 || lb.error) {
                body.innerHTML = '';
                empty.style.display = '';
                empty.innerHTML = '<p>No ranked players yet in this season.</p>';
                const seasonalToggleWrap = document.getElementById('global-seasonal-lb-toggle-wrap');
                if (seasonalToggleWrap) seasonalToggleWrap.style.display = 'none';
                return;
            }

            empty.style.display = 'none';
            const visibleRows = this._globalSeasonalLeaderboardExpanded ? lb : lb.slice(0, 20);
            body.innerHTML = this.renderSeasonLeaderboardRows(visibleRows, false, { firstCol: 'rank', showRateNearCoins: true });

            const seasonalToggleWrap = document.getElementById('global-seasonal-lb-toggle-wrap');
            const seasonalToggleBtn = document.getElementById('global-seasonal-lb-toggle-btn');
            if (seasonalToggleWrap && seasonalToggleBtn) {
                if (lb.length > 20) {
                    seasonalToggleWrap.style.display = '';
                    seasonalToggleBtn.textContent = this._globalSeasonalLeaderboardExpanded
                        ? '▴ Show Top 20'
                        : '▾ Show All';
                } else {
                    seasonalToggleWrap.style.display = 'none';
                }
            }
            return;
        }

        const seasonalToggleWrap = document.getElementById('global-seasonal-lb-toggle-wrap');
        if (seasonalToggleWrap) seasonalToggleWrap.style.display = 'none';

        this.setLeaderboardMeta(
            'Global Leaderboard',
            'Ranked by Global Stars earned this yearly cycle.'
        );
        this.setLeaderboardHeader(['Rank', 'Player', 'Global Stars', 'Status']);
        const lb = await this.api('global_leaderboard');

        if (!Array.isArray(lb) || lb.length === 0 || lb.error) {
            body.innerHTML = '';
            empty.style.display = '';
            empty.innerHTML = '<p>No players on the leaderboard yet. Earn Global Stars through season outcomes and Lock-In!</p>';
            return;
        }
        empty.style.display = 'none';

        body.innerHTML = lb.map((entry, i) => {
            const rank = i + 1;
            const isMe = this.state.player && entry.player_id == this.state.player.player_id;
            return `
                <tr class="${isMe ? 'my-row' : ''} ${rank <= 3 ? 'top-three' : ''}">
                    <td class="rank-cell">${rank <= 3 ? ['&#129351;', '&#129352;', '&#129353;'][rank-1] : rank}</td>
                    <td class="player-cell">
                        <span class="player-link" onclick="TMC.navigate('profile', ${entry.player_id})">${this.escapeHtml(entry.handle)}</span>
                    </td>
                    <td class="stars-cell">${this.formatNumber(entry.global_stars)}</td>
                    <td class="status-cell">${this.renderPlayerStatusBadge(entry)}</td>
                </tr>
            `;
        }).join('');
    },

    toggleGlobalSeasonalLeaderboard() {
        this._globalSeasonalLeaderboardExpanded = !this._globalSeasonalLeaderboardExpanded;
        this.loadGlobalLeaderboard();
    },

    // ==================== SHOP ====================
    async loadShop() {
        const catalog = await this.api('cosmetic_catalog');
        if (catalog.error) return;
        this.state.cosmetics = catalog;

        if (this.state.player) {
            const mine = await this.api('my_cosmetics');
            if (!mine.error) this.state.myCosmetics = mine;
        }

        this.renderShop();
    },

    filterShop(category) {
        this.state.shopFilter = category;
        document.querySelectorAll('.shop-tab').forEach(t => t.classList.remove('active'));
        event.target.classList.add('active');
        this.renderShop();
    },

    renderShop() {
        const grid = document.getElementById('shop-grid');
        let items = this.state.cosmetics;
        if (this.state.shopFilter !== 'all') {
            items = items.filter(c => c.category === this.state.shopFilter);
        }

        const ownedIds = new Set(this.state.myCosmetics.map(c => c.cosmetic_id));

        grid.innerHTML = items.map(c => {
            const owned = ownedIds.has(c.cosmetic_id);
            const canAfford = this.state.player && this.state.player.global_stars >= c.price_global_stars;
            const categoryLabel = c.category.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());

            return `
                <div class="shop-item ${owned ? 'owned' : ''} ${c.css_class || ''}">
                    <div class="shop-item-header">
                        <span class="shop-item-name">${this.escapeHtml(c.name)}</span>
                        <span class="shop-item-category">${categoryLabel}</span>
                    </div>
                    <p class="shop-item-desc">${this.escapeHtml(c.description || '')}</p>
                    <div class="shop-item-footer">
                        <span class="shop-item-price">&#11088; ${c.price_global_stars}</span>
                        ${owned ? 
                            '<span class="badge badge-owned">Owned</span>' :
                            (this.state.player ? 
                                `<button class="btn btn-sm btn-primary" onclick="TMC.buyCosmetic(${c.cosmetic_id})" ${!canAfford ? 'disabled title="Not enough Global Stars"' : ''}>Buy</button>` :
                                '<span class="shop-item-login">Login to purchase</span>'
                            )
                        }
                    </div>
                </div>
            `;
        }).join('');
    },

    async buyCosmetic(cosmeticId) {
        const cosmetic = this.state.cosmetics.find(c => c.cosmetic_id == cosmeticId);
        if (!confirm(`Purchase "${cosmetic.name}" for ${cosmetic.price_global_stars} Global Stars?`)) return;

        const result = await this.api('purchase_cosmetic', { cosmetic_id: cosmeticId });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast('Cosmetic purchased!', 'success', {
            category: 'purchase_cosmetic',
            payload: { cosmetic_id: Number(cosmeticId) || null }
        });
        await this.refreshGameState();
        this.loadShop();
    },

    // ==================== TRADE ====================
    async renderTradeScreen(seasonContext) {
        const content = document.getElementById('trade-content');
        if (!this.state.player || !this.state.player.joined_season_id) {
            content.innerHTML = '<div class="error-state"><p>You must be in a season to trade.</p></div>';
            return;
        }

        const seasonId = typeof seasonContext === 'object' && seasonContext !== null
            ? (seasonContext.seasonId || this.state.player.joined_season_id)
            : (seasonContext || this.state.player.joined_season_id);
        const prefillTargetId = typeof seasonContext === 'object' && seasonContext !== null
            ? (seasonContext.targetPlayerId || null)
            : (this.state.pendingTradeTargetId || null);

        // Get players in season
        const players = await this.api('season_players', { season_id: seasonId });
        const otherPlayers = (players || []).filter(p => p.player_id != this.state.player.player_id);

        const part = this.state.player.participation;
        const tradeTiers = [1, 2, 3, 4, 5];
        if (Number((part.sigils || [])[5] || 0) > 0) {
            tradeTiers.push(6);
        }

        content.innerHTML = `
            <h2>Trade Center</h2>
            <button class="btn btn-outline btn-sm" onclick="TMC.navigate('season-detail', ${seasonId})">Back to Season</button>

            <div class="trade-form-container">
                <h3>Create New Trade</h3>
                <div class="trade-form">
                    <div class="trade-side">
                        <h4>You Offer</h4>
                        <div class="form-group">
                            <label>Coins (you have ${this.formatNumber(part.coins)})</label>
                            <input type="number" id="trade-a-coins" min="0" max="${part.coins}" value="0" class="input-field">
                        </div>
                        ${tradeTiers.map((tier) => {
                            const count = Number((part.sigils || [])[tier - 1] || 0);
                            return `
                            <div class="form-group">
                                <label>Tier ${tier} Sigils (you have ${count})</label>
                                <input type="number" id="trade-a-sigil-${tier - 1}" min="0" max="${count}" value="0" class="input-field input-sm">
                            </div>
                        `;
                        }).join('')}
                    </div>
                    <div class="trade-arrow">&#8644;</div>
                    <div class="trade-side">
                        <h4>You Request</h4>
                        <div class="form-group">
                            <label>Coins</label>
                            <input type="number" id="trade-b-coins" min="0" value="0" class="input-field">
                        </div>
                        ${tradeTiers.map(t => `
                            <div class="form-group">
                                <label>Tier ${t} Sigils</label>
                                <input type="number" id="trade-b-sigil-${t-1}" min="0" value="0" class="input-field input-sm">
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="form-group">
                    <label>Send To</label>
                    <select id="trade-target" class="input-field">
                        <option value="">Select a player...</option>
                        ${otherPlayers.map(p => `<option value="${p.player_id}">${this.escapeHtml(p.handle)} ${p.online_current ? '(online)' : ''}</option>`).join('')}
                    </select>
                </div>
                <button class="btn btn-primary btn-lg" onclick="TMC.submitTradeGated()">Send Trade Offer</button>
            </div>

            <div class="my-trades-section">
                <h3>Your Trades</h3>
                <div id="trade-list"></div>
            </div>
        `;

        const targetSelect = document.getElementById('trade-target');
        if (targetSelect && prefillTargetId) {
            const targetOption = Array.from(targetSelect.options).find((opt) => String(opt.value) === String(prefillTargetId));
            if (targetOption) {
                targetSelect.value = String(prefillTargetId);
            }
        }
        this.state.pendingTradeTargetId = null;

        this.loadMyTrades();
    },

    async submitTrade() {
        const targetId = document.getElementById('trade-target').value;
        if (!targetId) {
            this.toast('Select a player to trade with.', 'error');
            return;
        }

        const sideACoins = parseInt(document.getElementById('trade-a-coins').value) || 0;
        const sideASigils = [0,1,2,3,4,5].map(i => {
            const el = document.getElementById(`trade-a-sigil-${i}`);
            return el ? (parseInt(el.value) || 0) : 0;
        });
        const sideBCoins = parseInt(document.getElementById('trade-b-coins').value) || 0;
        const sideBSigils = [0,1,2,3,4,5].map(i => {
            const el = document.getElementById(`trade-b-sigil-${i}`);
            return el ? (parseInt(el.value) || 0) : 0;
        });

        const result = await this.api('trade_initiate', {
            acceptor_id: parseInt(targetId),
            side_a_coins: sideACoins,
            side_a_sigils: sideASigils,
            side_b_coins: sideBCoins,
            side_b_sigils: sideBSigils
        });

        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(`Trade offer sent! Fee: ${this.formatNumber(result.fee)} coins.`, 'success', {
            category: 'trade_offer_sent',
            payload: {
                target_player_id: Number(targetId) || null,
                fee: Number(result.fee) || 0
            }
        });
        await this.refreshGameState();
        this.loadMyTrades();
    },

    async loadMyTrades() {
        const trades = await this.api('my_trades');
        const container = document.getElementById('trade-list') || document.getElementById('my-trades-list');
        if (!container || !trades || trades.error) return;

        if (trades.length === 0) {
            container.innerHTML = '<p class="empty-text">No trades yet.</p>';
            return;
        }

        container.innerHTML = trades.map(t => {
            const isInitiator = t.initiator_id == this.state.player.player_id;
            const otherHandle = isInitiator ? t.acceptor_handle : t.initiator_handle;
            const sideASigils = JSON.parse(t.side_a_sigils || '[]');
            const sideBSigils = JSON.parse(t.side_b_sigils || '[]');

            let actions = '';
            if (t.status === 'OPEN') {
                if (isInitiator) {
                    actions = `<button class="btn btn-sm btn-danger" onclick="TMC.cancelTrade(${t.trade_id})">Cancel</button>`;
                } else {
                    actions = `
                        <button class="btn btn-sm btn-primary" onclick="TMC.acceptTrade(${t.trade_id})">Accept</button>
                        <button class="btn btn-sm btn-danger" onclick="TMC.declineTrade(${t.trade_id})">Decline</button>
                    `;
                }
            }

            return `
                <div class="trade-item trade-${t.status.toLowerCase()}">
                    <div class="trade-item-header">
                        <span class="trade-with">${isInitiator ? 'To' : 'From'}: ${this.escapeHtml(otherHandle)}</span>
                        <span class="badge badge-${t.status.toLowerCase()}">${t.status}</span>
                    </div>
                    <div class="trade-item-body">
                        <div class="trade-offer">
                            <span class="trade-label">Offer:</span>
                            ${t.side_a_coins > 0 ? `<span>${this.formatNumber(t.side_a_coins)} coins</span>` : ''}
                            ${sideASigils.some(s => s > 0) ? `<span>Sigils: [${sideASigils.join(',')}]</span>` : ''}
                        </div>
                        <div class="trade-request">
                            <span class="trade-label">Request:</span>
                            ${t.side_b_coins > 0 ? `<span>${this.formatNumber(t.side_b_coins)} coins</span>` : ''}
                            ${sideBSigils.some(s => s > 0) ? `<span>Sigils: [${sideBSigils.join(',')}]</span>` : ''}
                        </div>
                        <div class="trade-fee">Fee: ${this.formatNumber(t.locked_fee_coins)} coins</div>
                    </div>
                    <div class="trade-item-actions">${actions}</div>
                </div>
            `;
        }).join('');
    },

    async acceptTrade(tradeId) {
        const result = await this.api('trade_accept', { trade_id: tradeId });
        if (result.error) { this.toast(result.error, 'error'); return; }
        this.toast('Trade completed!', 'success', {
            category: 'trade_completed',
            payload: { trade_id: Number(tradeId) || null }
        });
        await this.refreshGameState();
        this.loadMyTrades();
    },

    async cancelTrade(tradeId) {
        const result = await this.api('trade_cancel', { trade_id: tradeId });
        if (result.error) { this.toast(result.error, 'error'); return; }
        this.toast('Trade canceled.', 'info');
        await this.refreshGameState();
        this.loadMyTrades();
    },

    async declineTrade(tradeId) {
        const result = await this.api('trade_decline', { trade_id: tradeId });
        if (result.error) { this.toast(result.error, 'error'); return; }
        this.toast('Trade declined.', 'info');
        await this.refreshGameState();
        this.loadMyTrades();
    },

    // ==================== CHAT ====================
    initChat() {
        // Show season tab if in a season
        const seasonTab = document.getElementById('chat-season-tab');
        if (this.state.player && this.state.player.joined_season_id) {
            seasonTab.style.display = '';
        } else {
            seasonTab.style.display = 'none';
        }

        // Show input if logged in
        const inputArea = document.getElementById('chat-input-area');
        inputArea.style.display = this.state.player ? '' : 'none';

        this.loadChat();

        // Start chat polling
        if (this.state.chatPollInterval) clearInterval(this.state.chatPollInterval);
        this.state.chatPollInterval = setInterval(() => {
            if (this.state.currentScreen === 'chat') this.loadChat();
        }, 5000);
    },

    switchChat(channel) {
        this.state.currentChat = channel;
        document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
        event.target.classList.add('active');
        this.loadChat();
    },

    async loadChat() {
        const params = { channel: this.state.currentChat };
        if (this.state.currentChat === 'SEASON' && this.state.player) {
            params.season_id = this.state.player.joined_season_id;
        }
        const messages = await this.api('chat_messages', params);
        const container = document.getElementById('chat-messages');

        if (!messages || messages.error || messages.length === 0) {
            container.innerHTML = '<div class="chat-empty">No messages yet. Be the first to say something!</div>';
            return;
        }

        // Reverse to show oldest first
        const sorted = [...messages].reverse();
        container.innerHTML = sorted.map(m => {
            const isMe = this.state.player && m.sender_id == this.state.player.player_id;
            const isAdmin = m.is_admin_post;
            return `
                <div class="chat-msg ${isMe ? 'chat-msg-mine' : ''} ${isAdmin ? 'chat-msg-admin' : ''}">
                    <span class="chat-handle ${isAdmin ? 'admin-handle' : ''}" onclick="TMC.navigate('profile', ${m.sender_id})">
                        ${isAdmin ? '[ADMIN] ' : ''}${this.escapeHtml(m.handle_snapshot)}
                    </span>
                    <span class="chat-text">${this.escapeHtml(m.content)}</span>
                    <span class="chat-time">${this.formatChatTime(m.created_at)}</span>
                </div>
            `;
        }).join('');

        container.scrollTop = container.scrollHeight;
    },

    async sendChat() {
        const input = document.getElementById('chat-input');
        const content = input.value.trim();
        if (!content) return;

        const params = { channel: this.state.currentChat, content };
        if (this.state.currentChat === 'SEASON') {
            params.season_id = this.state.player.joined_season_id;
        }

        const result = await this.api('chat_send', params);
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        input.value = '';
        this.loadChat();
    },

    // ==================== PROFILE ====================
    async loadProfile(playerId) {
        const profile = await this.api('profile', { player_id: playerId });
        const content = document.getElementById('profile-content');

        if (profile.error) {
            this.toast(profile.error, 'error', { category: 'error_action' });
            content.innerHTML = `<div class="error-state"><p>Profile is temporarily unavailable.</p></div>`;
            return;
        }

        if (profile.deleted) {
            content.innerHTML = `<div class="profile-card"><h2>[Removed]</h2><p>This account has been deleted.</p></div>`;
            return;
        }

        const badges = (profile.badges || []).map(b => {
            const icons = {
                seasonal_first: '&#129351;', seasonal_second: '&#129352;', seasonal_third: '&#129353;',
                yearly_top10: '&#127942;'
            };
            return `<span class="profile-badge" title="${b.badge_type}">${icons[b.badge_type] || '&#127775;'}</span>`;
        }).join('');

        const history = (profile.season_history || []).map(h => `
            <tr>
                <td>Season #${h.season_id}</td>
                <td>${this.formatNumber(h.final_seasonal_stars || h.seasonal_stars || 0)}</td>
                <td>${h.final_rank || '-'}</td>
                <td>${this.formatNumber(h.global_stars_earned || 0)}</td>
                <td>${h.lock_in_effect_tick ? 'Lock-In' : (h.end_membership ? 'End-Finisher' : '-')}</td>
            </tr>
        `).join('');

        const activeParticipation = profile.active_participation;
        const activeSigils = Array.isArray(activeParticipation?.sigils) ? activeParticipation.sigils : [];
        const visibleProfileSigils = activeSigils
            .map((count, idx) => ({ tier: idx + 1, count: Number(count || 0) }))
            .filter((row) => row.tier <= 5 || row.count > 0);
        const season = activeParticipation?.season_id
            ? this.state.seasons.find((s) => s.season_id == activeParticipation.season_id)
            : null;
        const seasonStatus = season ? (season.computed_status || season.status) : null;
        const canOpenTrade = !!(
            this.state.player &&
            this.state.player.player_id != profile.player_id &&
            this.state.player.joined_season_id &&
            activeParticipation &&
            this.state.player.joined_season_id == activeParticipation.season_id &&
            seasonStatus === 'Active'
        );

        const inventoryHtml = activeParticipation ? `
            <div class="profile-inventory">
                <h3>Current Season Inventory</h3>
                <div class="profile-stats profile-stats-season">
                    <div class="profile-stat">
                        <span class="stat-label">Coins</span>
                        <span class="stat-value">${this.formatNumber(activeParticipation.coins)}</span>
                    </div>
                    <div class="profile-stat">
                        <span class="stat-label">Season</span>
                        <span class="stat-value">#${activeParticipation.season_id}</span>
                    </div>
                </div>
                <div class="sigil-display profile-sigil-display">
                    ${visibleProfileSigils.map((row) => `
                        <div class="sigil-item tier-${row.tier}">
                            <span class="sigil-tier">T${row.tier}</span>
                            <span class="sigil-count">${row.count}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        ` : `
            <div class="profile-inventory">
                <h3>Current Season Inventory</h3>
                <p class="panel-info">This player is not currently in an active season.</p>
            </div>
        `;

        content.innerHTML = `
            <div class="profile-card">
                <div class="profile-header">
                    <h2>${this.escapeHtml(profile.handle)}</h2>
                    ${profile.role !== 'Player' ? `<span class="badge badge-staff">${profile.role}</span>` : ''}
                </div>
                <div class="profile-stats">
                    <div class="profile-stat">
                        <span class="stat-label">Global Stars</span>
                        <span class="stat-value">&#11088; ${this.formatNumber(profile.global_stars)}</span>
                    </div>
                    <div class="profile-stat">
                        <span class="stat-label">Member Since</span>
                        <span class="stat-value">${new Date(profile.created_at).toLocaleDateString()}</span>
                    </div>
                </div>
                ${inventoryHtml}
                ${canOpenTrade ? `<div class="profile-actions"><button class="btn btn-primary" onclick="TMC.openTradeRequest(${profile.player_id}, ${activeParticipation.season_id})">Open Trade Request</button></div>` : ''}
                ${badges ? `<div class="profile-badges"><h3>Badges</h3><div class="badges-row">${badges}</div></div>` : ''}
                ${history ? `
                    <div class="profile-history">
                        <h3>Season History</h3>
                        <table class="leaderboard-table">
                            <thead><tr><th>Season</th><th>Stars</th><th>Rank</th><th>Global Earned</th><th>Exit</th></tr></thead>
                            <tbody>${history}</tbody>
                        </table>
                    </div>
                ` : ''}
            </div>
        `;
    },

    openTradeRequest(targetPlayerId, seasonId) {
        if (!this.state.player || !this.state.player.joined_season_id) {
            this.toast('You must be in an active season to trade.', 'error');
            return;
        }
        this.state.pendingTradeTargetId = targetPlayerId;
        this.navigate('trade', { seasonId, targetPlayerId });
    },

    // ==================== NOTIFICATIONS ====================
    setupNotificationCenter() {
        if (this._notificationOutsideHandler) return;
        this._notificationOutsideHandler = (event) => {
            const center = document.getElementById('notification-center');
            if (!center) return;
            if (!center.contains(event.target)) {
                this.closeNotifications();
            }
        };
        document.addEventListener('click', this._notificationOutsideHandler);
        this.updateNotificationUI();
    },

    syncNotificationsFromPlayer(player) {
        if (!player) {
            this.state.notifications = [];
            this.state.notificationsUnread = 0;
            return;
        }

        const incoming = Array.isArray(player.notifications) ? player.notifications : [];
        this.state.notifications = incoming.map((n) => ({
            notification_id: Number(n.notification_id),
            category: n.category || 'system',
            title: n.title || 'Notification',
            body: n.body || '',
            payload: n.payload || null,
            is_read: !!n.is_read,
            created_at: n.created_at,
            read_at: n.read_at || null
        }));

        const fallbackUnread = this.state.notifications.filter((n) => !n.is_read).length;
        this.state.notificationsUnread = Number(player.notifications_unread_count ?? fallbackUnread) || 0;
    },

    updateNotificationUI() {
        this.renderNotificationList();
        this.updateNotificationIndicator();

        const panel = document.getElementById('notification-panel');
        const toggle = document.getElementById('notification-toggle');
        if (panel) panel.style.display = this.state.notificationsOpen ? 'flex' : 'none';
        if (toggle) toggle.setAttribute('aria-expanded', this.state.notificationsOpen ? 'true' : 'false');
    },

    updateNotificationIndicator() {
        const dot = document.getElementById('notification-dot');
        const toggle = document.getElementById('notification-toggle');
        const hasUnread = (Number(this.state.notificationsUnread) || 0) > 0;
        if (dot) dot.style.display = hasUnread ? 'block' : 'none';
        if (toggle) toggle.classList.toggle('has-unread', hasUnread);
    },

    async toggleNotifications() {
        this.state.notificationsOpen = !this.state.notificationsOpen;
        this.updateNotificationUI();
        if (this.state.notificationsOpen) {
            await this.loadNotifications();
            await this.markLoadedNotificationsRead();
        }
    },

    closeNotifications() {
        if (!this.state.notificationsOpen) return;
        this.state.notificationsOpen = false;
        this.updateNotificationUI();
    },

    async loadNotifications() {
        if (!this.state.player) {
            this.state.notifications = [];
            this.state.notificationsUnread = 0;
            this.updateNotificationUI();
            return;
        }

        const result = await this.api('notifications_list', { limit: 50 });
        if (result.error) return;

        this.state.notifications = Array.isArray(result.notifications) ? result.notifications.map((n) => ({
            ...n,
            notification_id: Number(n.notification_id),
            is_read: !!n.is_read
        })) : [];
        const fallbackUnread = this.state.notifications.filter((n) => !n.is_read).length;
        this.state.notificationsUnread = Number(result.unread_count ?? fallbackUnread) || 0;
        this.updateNotificationUI();
    },

    async markLoadedNotificationsRead() {
        if (!this.state.player) return;

        const unreadIds = this.state.notifications
            .filter((n) => !n.is_read)
            .map((n) => Number(n.notification_id))
            .filter((id) => id > 0);

        if (!unreadIds.length) return;

        this.state.notifications = this.state.notifications.map((n) => ({ ...n, is_read: true }));
        this.state.notificationsUnread = 0;
        this.updateNotificationUI();

        const result = await this.api('notifications_mark_all_read');
        if (result.error) {
            await this.loadNotifications();
            this.toast(result.error, 'error');
            return;
        }

        if (typeof result.unread_count !== 'undefined') {
            this.state.notificationsUnread = Number(result.unread_count) || 0;
            this.updateNotificationIndicator();
        }
    },

    renderNotificationList() {
        const list = document.getElementById('notification-list');
        if (!list) return;

        if (!this.state.player) {
            list.innerHTML = '<div class="notification-empty">Login to view notifications.</div>';
            return;
        }

        if (!this.state.notifications.length) {
            list.innerHTML = '<div class="notification-empty">No notifications yet.</div>';
            return;
        }

        list.innerHTML = this.state.notifications.map((n) => {
            const categoryView = this.getNotificationCategoryView(n.category);
            const itemClass = n.is_read
                ? `notification-item notification-${categoryView.tone}`
                : `notification-item unread notification-${categoryView.tone}`;
            const body = n.body ? `<p>${this.escapeHtml(n.body)}</p>` : '';
            return `
                <div class="${itemClass}" data-notification-id="${n.notification_id}" onclick="TMC.handleNotificationClick(event, ${n.notification_id})">
                    <div class="notification-item-head">
                        <span class="notification-category">${this.escapeHtml(categoryView.icon + ' ' + categoryView.label)}</span>
                        <span class="notification-time">${this.formatNotificationTime(n.created_at)}</span>
                    </div>
                    <h4>${this.escapeHtml(n.title || 'Notification')}</h4>
                    ${body}
                </div>
            `;
        }).join('');

        this.bindNotificationSwipeHandlers();
    },

    bindNotificationSwipeHandlers() {
        const items = document.querySelectorAll('.notification-item');
        items.forEach((item) => {
            if (item.dataset.swipeBound === '1') return;
            item.dataset.swipeBound = '1';

            let startX = 0;
            let currentX = 0;
            let dragging = false;
            let pointerId = null;

            const resetVisual = () => {
                item.style.transform = '';
                item.classList.remove('swipe-left', 'swipe-right', 'is-dragging');
                item.dataset.suppressClick = '0';
            };

            const begin = (x) => {
                startX = x;
                currentX = 0;
                dragging = true;
                item.classList.add('is-dragging');
                item.dataset.suppressClick = '0';
            };

            const move = (x) => {
                if (!dragging) return;
                currentX = x - startX;
                if (Math.abs(currentX) > 8) item.dataset.suppressClick = '1';
                item.style.transform = `translateX(${currentX}px)`;
                item.classList.toggle('swipe-left', currentX < -20);
                item.classList.toggle('swipe-right', currentX > 20);
            };

            const finish = () => {
                if (!dragging) return;
                dragging = false;
                item.classList.remove('is-dragging');

                const threshold = Math.max(80, item.offsetWidth * 0.24);
                const id = Number(item.dataset.notificationId || 0);
                if (Math.abs(currentX) >= threshold && id > 0) {
                    const direction = currentX < 0 ? -1 : 1;
                    item.style.transform = `translateX(${direction * (item.offsetWidth + 24)}px)`;
                    item.classList.add('dismissed');
                    item.dataset.suppressClick = '1';
                    setTimeout(() => this.removeNotificationById(id), 120);
                    return;
                }

                resetVisual();
            };

            item.addEventListener('pointerdown', (event) => {
                if (event.pointerType === 'mouse' && event.button !== 0) return;
                pointerId = event.pointerId;
                if (item.setPointerCapture) item.setPointerCapture(pointerId);
                begin(event.clientX);
            });

            item.addEventListener('pointermove', (event) => {
                if (pointerId !== null && event.pointerId !== pointerId) return;
                move(event.clientX);
            });

            item.addEventListener('pointerup', (event) => {
                if (pointerId !== null && event.pointerId !== pointerId) return;
                pointerId = null;
                finish();
            });

            item.addEventListener('pointercancel', () => {
                pointerId = null;
                dragging = false;
                resetVisual();
            });
        });
    },

    async handleNotificationClick(event, notificationId) {
        event.stopPropagation();
        const item = event.currentTarget;
        if (item && item.dataset.suppressClick === '1') return;

        const id = Number(notificationId);
        if (!id) return;

        const found = this.state.notifications.find((n) => Number(n.notification_id) === id);
        if (!found || found.is_read) return;

        found.is_read = true;
        this.state.notificationsUnread = Math.max(0, (Number(this.state.notificationsUnread) || 0) - 1);
        this.updateNotificationUI();

        const result = await this.api('notifications_mark_read', { notification_id: id });
        if (result.error) {
            await this.loadNotifications();
            this.toast(result.error, 'error');
            return;
        }

        if (typeof result.unread_count !== 'undefined') {
            this.state.notificationsUnread = Number(result.unread_count) || 0;
            this.updateNotificationIndicator();
        }
    },

    async removeNotificationById(notificationId) {
        const id = Number(notificationId);
        if (!id) return;

        const before = this.state.notifications.find((n) => Number(n.notification_id) === id);
        const removedUnread = before && !before.is_read;
        this.state.notifications = this.state.notifications.filter((n) => Number(n.notification_id) !== id);
        if (removedUnread) {
            this.state.notificationsUnread = Math.max(0, (Number(this.state.notificationsUnread) || 0) - 1);
        }
        this.updateNotificationUI();

        const result = await this.api('notifications_remove', { notification_id: id });
        if (result.error) {
            await this.loadNotifications();
            this.toast(result.error, 'error');
            return;
        }

        if (typeof result.unread_count !== 'undefined') {
            this.state.notificationsUnread = Number(result.unread_count) || 0;
            this.updateNotificationIndicator();
        }
    },

    formatNotificationTime(dateStr) {
        const d = new Date(dateStr);
        if (Number.isNaN(d.getTime())) return '';
        const now = new Date();
        const sameDay = d.toDateString() === now.toDateString();
        if (sameDay) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    },

    getNotificationCategoryView(category) {
        const key = String(category || '').trim().toLowerCase();
        const map = {
            auth_login: { label: 'Account', icon: 'Key', tone: 'auth' },
            auth_register: { label: 'Account', icon: 'Key', tone: 'auth' },
            season_join: { label: 'Season', icon: 'Flag', tone: 'progress' },
            season_lock_in: { label: 'Lock-In', icon: 'Star', tone: 'progress' },
            purchase_star: { label: 'Stars', icon: 'Star', tone: 'purchase' },
            purchase_sigil: { label: 'Sigils', icon: 'Diamond', tone: 'purchase' },
            purchase_cosmetic: { label: 'Cosmetic', icon: 'Palette', tone: 'purchase' },
            boost_activate: { label: 'Boost', icon: 'Bolt', tone: 'boost' },
            trade_offer_sent: { label: 'Trade', icon: 'Swap', tone: 'trade' },
            trade_completed: { label: 'Trade', icon: 'Check', tone: 'trade' },
            sigil_drop: { label: 'Sigil Drop', icon: 'Gift', tone: 'drop' },
            sigil_combine: { label: 'Sigil Forge', icon: 'Anvil', tone: 'progress' },
            freeze_apply: { label: 'Freeze', icon: 'Snow', tone: 'warning' },
            error_auth: { label: 'Auth Error', icon: 'Alert', tone: 'error' },
            error_action: { label: 'Action Failed', icon: 'X', tone: 'error' },
            error_validation: { label: 'Validation', icon: 'Info', tone: 'error' },
            warning_general: { label: 'Warning', icon: 'Alert', tone: 'warning' },
            info_general: { label: 'Info', icon: 'Info', tone: 'default' },
            idle: { label: 'Idle', icon: 'Pause', tone: 'status' },
            active: { label: 'Active', icon: 'Play', tone: 'status' }
        };

        if (map[key]) return map[key];

        const fallbackLabel = key
            ? key.replace(/_/g, ' ').replace(/\b\w/g, (ch) => ch.toUpperCase())
            : 'Notification';

        return { label: fallbackLabel, icon: 'Info', tone: 'default' };
    },

    // ==================== UTILITIES ====================
    formatNumber(n) {
        if (n === null || n === undefined) return '0';
        return Number(n).toLocaleString();
    },

    formatPercentCompact(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) return '0';
        if (num === 0) return '0';

        if (num >= 1) {
            return num.toFixed(2).replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
        }

        return num.toFixed(4).replace(/\.0+$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
    },

    // Truncate (not round) a percentage to 0.01 precision (two decimal places).
    // Returns a string without a '%' suffix, e.g. 12.349 -> "12.34", 0.009 -> "0.00".
    truncatePercent(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) return '0.00';
        return (Math.floor(num * 100) / 100).toFixed(2);
    },

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    formatChatTime(dateStr) {
        const d = new Date(dateStr);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },

    async pushSuccessNotification(message, options = {}) {
        const text = String(message || '').trim();
        if (!text) return;

        const token = localStorage.getItem('tmc_token');
        if (!token) return;

        const categoryRaw = (options && options.category) ? String(options.category).trim() : '';
        const category = categoryRaw || 'gameplay_success';
        const payload = options && options.payload && typeof options.payload === 'object'
            ? options.payload
            : null;
        const eventKey = options && options.eventKey ? String(options.eventKey) : null;
        const body = options && options.body ? String(options.body) : null;

        const result = await this.api('notifications_create', {
            category,
            title: text,
            body,
            payload,
            event_key: eventKey
        });
        if (result.error) return;

        if (result.notification) {
            const normalized = {
                ...result.notification,
                notification_id: Number(result.notification.notification_id),
                is_read: !!result.notification.is_read
            };
            const existingIdx = this.state.notifications.findIndex(
                (n) => Number(n.notification_id) === normalized.notification_id
            );
            if (existingIdx >= 0) {
                this.state.notifications[existingIdx] = normalized;
            } else {
                this.state.notifications.unshift(normalized);
            }
        } else {
            await this.loadNotifications();
            return;
        }

        if (typeof result.unread_count !== 'undefined') {
            this.state.notificationsUnread = Number(result.unread_count) || 0;
        } else {
            this.state.notificationsUnread = this.state.notifications.filter((n) => !n.is_read).length;
        }
        this.updateNotificationUI();
    },

    toast(message, type = 'info', options = {}) {
        const categoryMap = {
            success: 'gameplay_success',
            error: 'error_action',
            warning: 'warning_general',
            info: 'info_general'
        };
        const category = (options && options.category) ? options.category : (categoryMap[type] || 'info_general');
        this.pushSuccessNotification(message, { ...options, category });
    },

    // ==================== ECONOMIC CONSEQUENCE PREVIEW / CONFIRM / RECEIPT ====================

    // Pending confirmation callback — set before opening the modal.
    _econPendingAction: null,

    /**
     * Render a preview payload into the impact-detail panel.
     */
    _renderEconImpact(preview, title) {
        const risk = preview.risk || { severity: 'low', flags: [], explain: '' };
        const sev = risk.severity || 'low';
        const sevEmoji = sev === 'high' ? '🔴' : sev === 'medium' ? '🟡' : '🟢';

        const titleEl = document.getElementById('econ-confirm-title');
        if (titleEl) titleEl.textContent = title || 'Confirm Action';

        const iconEl = document.getElementById('econ-risk-icon');
        if (iconEl) iconEl.textContent = sev === 'high' ? '⚠️' : sev === 'medium' ? '⚡' : 'ℹ️';

        const detailsEl = document.getElementById('econ-impact-details');
        if (!detailsEl) return;

        const fmt = (n) => this.formatNumber(n);
        const balType = preview.balance_type || 'coins';
        const isSigil = balType.startsWith('sigils_t');
        const balLabel = isSigil ? `Tier ${balType.replace('sigils_t','')} Sigils` : 'Coins';

        let rows = '';
        if (!isSigil) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Total cost</span><span class="econ-impact-value">${fmt(preview.estimated_total_cost)} coins</span></div>`;
            if (preview.estimated_fee > 0) {
                rows += `<div class="econ-impact-row"><span class="econ-impact-label">Fee included</span><span class="econ-impact-value">${fmt(preview.estimated_fee)} coins</span></div>`;
            }
            if (preview.estimated_price_impact_pct != null) {
                const impactClass = preview.estimated_price_impact_pct > 0.5 ? 'warn' : '';
                rows += `<div class="econ-impact-row"><span class="econ-impact-label">Supply impact</span><span class="econ-impact-value ${impactClass}">${preview.estimated_price_impact_pct.toFixed(2)}% (${fmt(preview.estimated_price_impact_bp)} bp)</span></div>`;
            }
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Balance after</span><span class="econ-impact-value ${sev === 'high' ? 'danger' : sev === 'medium' ? 'warn' : ''}">${fmt(preview.post_balance_estimate)} ${balLabel}</span></div>`;
        } else {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Sigil cost</span><span class="econ-impact-value">${fmt(preview.estimated_total_cost)} ${balLabel}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Remaining after</span><span class="econ-impact-value ${sev === 'high' ? 'danger' : sev === 'medium' ? 'warn' : ''}">${fmt(preview.post_balance_estimate)} ${balLabel}</span></div>`;
        }

        detailsEl.innerHTML = `
            <div style="margin-bottom:0.6rem;">
                <span class="econ-risk-badge ${sev}">${sevEmoji} ${sev.toUpperCase()} RISK</span>
            </div>
            ${risk.explain ? `<div class="econ-risk-explain">${this.escapeHtml(risk.explain)}</div>` : ''}
            ${rows}
        `;
    },

    /**
     * Show the economic confirmation modal for a medium/high-risk action.
     * @param {object} preview  Preview payload from the server.
     * @param {string} title    Modal heading.
     * @param {Function} onConfirm  Async callback to execute when confirmed.
     */
    showEconConfirm(preview, title, onConfirm) {
        this._renderEconImpact(preview, title);
        this._econPendingAction = onConfirm;

        const checkbox = document.getElementById('econ-confirm-checkbox');
        const confirmBtn = document.getElementById('econ-confirm-btn');
        if (checkbox) {
            checkbox.checked = false;
            checkbox.onchange = () => { if (confirmBtn) confirmBtn.disabled = !checkbox.checked; };
        }
        if (confirmBtn) confirmBtn.disabled = true;

        const modal = document.getElementById('econ-confirm-modal');
        if (modal) modal.style.display = 'flex';
    },

    closeEconConfirm(skipCancelResolve = false) {
        const modal = document.getElementById('econ-confirm-modal');
        if (modal) modal.style.display = 'none';
        if (!skipCancelResolve && typeof this._econCancelResolver === 'function') {
            const resolver = this._econCancelResolver;
            this._econCancelResolver = null;
            resolver(null);
        }
        this._econPendingAction = null;
    },

    async executeEconConfirmed() {
        const cb = this._econPendingAction;
        this.closeEconConfirm(true);
        if (cb) await cb();
    },

    /**
     * Show a post-action receipt modal.
     */
    showEconReceipt(receipt, label) {
        const detailsEl = document.getElementById('econ-receipt-details');
        if (!detailsEl) return;

        const fmt = (n) => this.formatNumber(n);
        let rows = '';
        if (receipt.stars_purchased != null) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Stars purchased</span><span class="econ-impact-value">${fmt(receipt.stars_purchased)}</span></div>`;
        }
        if (receipt.executed_total_cost != null) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Total spent</span><span class="econ-impact-value">${fmt(receipt.executed_total_cost)}</span></div>`;
        }
        if (receipt.executed_fee > 0) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Fee burned</span><span class="econ-impact-value">${fmt(receipt.executed_fee)}</span></div>`;
        }
        if (receipt.declared_value != null) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Trade value</span><span class="econ-impact-value">${fmt(receipt.declared_value)}</span></div>`;
        }
        if (receipt.sigils_consumed != null) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Sigils consumed</span><span class="econ-impact-value">${fmt(receipt.sigils_consumed)} T${receipt.tier_consumed || '?'}</span></div>`;
        }
        rows += `<div class="econ-impact-row"><span class="econ-impact-label">Balance after</span><span class="econ-impact-value">${fmt(receipt.post_balance_estimate)}</span></div>`;

        detailsEl.innerHTML = `<div class="econ-impact-details">${rows}</div>`;

        const modal = document.getElementById('econ-receipt-modal');
        if (modal) modal.style.display = 'flex';
    },

    /**
     * High-level: preview → if high/medium impact show confirm modal, else execute directly.
     * The executeFn must accept confirm_economic_impact as a boolean argument.
     */
    async runWithEconGate(previewFn, executeFn, title) {
        const openConfirmFlow = async (previewPayload, resolve) => {
            const executeConfirmedAction = async () => {
                try {
                    const confirmedResult = await executeFn(true);
                    resolve(confirmedResult);
                } catch (error) {
                    const actionLabel = title || 'this action';
                    console.error(`Error executing confirmed economic action (${actionLabel}):`, error);
                    this.toast(`Failed to complete ${actionLabel}. Please try again.`, 'error');
                    resolve(null);
                }
            };

            const modal = document.getElementById('econ-confirm-modal');
            if (!modal) {
                const ok = window.confirm('This action has economic impact and requires confirmation. Proceed?');
                if (!ok) {
                    resolve(null);
                    return;
                }
                await executeConfirmedAction();
                return;
            }

            // Guard: if a prior confirmation is still pending (e.g. double-click /
            // rapid re-entry), cancel it so its Promise resolves cleanly rather than
            // leaking and causing a dead-end for that caller.
            if (typeof this._econCancelResolver === 'function') {
                const stale = this._econCancelResolver;
                this._econCancelResolver = null;
                stale(null);
            }

            this._econCancelResolver = resolve;
            this.showEconConfirm(previewPayload, title, async () => {
                this._econCancelResolver = null;
                await executeConfirmedAction();
            });
        };

        const preview = await previewFn();
        if (!preview || preview.error) {
            this.toast(preview ? preview.error : 'Preview failed', 'error');
            return null;
        }

        if (preview.requires_explicit_confirm) {
            return new Promise((resolve) => {
                openConfirmFlow(preview, resolve);
            });
        }

        const directResult = await executeFn(false);
        if (directResult && directResult.error === 'confirmation_required') {
            return new Promise((resolve) => {
                const previewUnavailableMessage = 'Confirmation required but preview is unavailable. Please try again.';
                const serverPreview = directResult.preview && !directResult.preview.error ? directResult.preview : null;
                if (serverPreview) {
                    openConfirmFlow(serverPreview, resolve);
                    return;
                }

                previewFn().then((retriedPreview) => {
                    if (retriedPreview && !retriedPreview.error) {
                        openConfirmFlow(retriedPreview, resolve);
                        return;
                    }
                    // Preview unavailable — resolve null so callers get a clean
                    // "no result" rather than a dead-end confirmation_required object.
                    this.toast(previewUnavailableMessage, 'error');
                    resolve(null);
                }).catch(() => {
                    this.toast(previewUnavailableMessage, 'error');
                    resolve(null);
                });
            });
        }

        return directResult;
    },

    /**
     * Wrap purchaseStars to use the preview/confirm/receipt flow.
     */
    async purchaseStarsGated() {
        const input = document.getElementById('purchase-stars');
        const starsRequested = parseInt(input ? input.value : '0');
        if (!starsRequested || starsRequested <= 0) {
            this.toast('Enter a valid star quantity.', 'error');
            return;
        }

        const result = await this.runWithEconGate(
            () => this.api('star_purchase_preview', { stars_requested: starsRequested }),
            (confirm) => this.api('purchase_stars', { stars_requested: starsRequested, confirm_economic_impact: confirm ? 1 : 0 }),
            'Confirm Star Purchase'
        );

        if (!result) return;
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        if (result.success) {
            this.toast(`Purchased ${this.formatNumber(result.stars_purchased)} stars for ${this.formatNumber(result.coins_spent)} coins!`, 'success', {
                category: 'purchase_star',
                payload: {
                    stars_purchased: Number(result.stars_purchased) || 0,
                    coins_spent: Number(result.coins_spent) || 0,
                    season_id: Number(this.state.currentSeason) || null
                }
            });
            if (result.receipt) this.showEconReceipt(result.receipt, 'Star Purchase Complete');
            if (input) input.value = '';
            this.updatePurchaseEstimate();
            await this.refreshGameState();
        }
    },

    /**
     * Wrap submitTrade to use the preview/confirm/receipt flow.
     */
    async submitTradeGated() {
        const targetId = document.getElementById('trade-target').value;
        if (!targetId) {
            this.toast('Select a player to trade with.', 'error');
            return;
        }

        const sideACoins = parseInt(document.getElementById('trade-a-coins').value) || 0;
        const sideASigils = [0,1,2,3,4,5].map(i => {
            const el = document.getElementById(`trade-a-sigil-${i}`);
            return el ? (parseInt(el.value) || 0) : 0;
        });
        const sideBCoins = parseInt(document.getElementById('trade-b-coins').value) || 0;
        const sideBSigils = [0,1,2,3,4,5].map(i => {
            const el = document.getElementById(`trade-b-sigil-${i}`);
            return el ? (parseInt(el.value) || 0) : 0;
        });

        const tradeParams = {
            acceptor_id: parseInt(targetId),
            side_a_coins: sideACoins,
            side_a_sigils: sideASigils,
            side_b_coins: sideBCoins,
            side_b_sigils: sideBSigils
        };

        const result = await this.runWithEconGate(
            () => this.api('trade_preview', tradeParams),
            (confirm) => this.api('trade_initiate', { ...tradeParams, confirm_economic_impact: confirm ? 1 : 0 }),
            'Confirm Trade Offer'
        );

        if (!result) return;
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        if (result.success) {
            this.toast(`Trade offer sent! Fee: ${this.formatNumber(result.fee)} coins.`, 'success', {
                category: 'trade_offer_sent',
                payload: {
                    target_player_id: Number(targetId) || null,
                    fee: Number(result.fee) || 0
                }
            });
            if (result.receipt) this.showEconReceipt(result.receipt, 'Trade Offer Sent');
            await this.refreshGameState();
            this.loadMyTrades();
        }
    },

    /**
     * Wrap purchaseBoostPower to use the preview/confirm/receipt flow.
     */
    async purchaseBoostPowerGated(boostId) {
        const boost = this._boostCatalog ? this._boostCatalog.find(b => b.boost_id == boostId) : null;
        const name = boost ? this.getBoostDisplayName(boost.name) : `Boost #${boostId}`;

        const result = await this.runWithEconGate(
            () => this.api('boost_activate_preview', { boost_id: boostId, purchase_kind: 'power' }),
            (confirm) => this.api('purchase_boost', { boost_id: boostId, purchase_kind: 'power', confirm_economic_impact: confirm ? 1 : 0 }),
            `Confirm: ${name} Power`
        );

        if (!result) return;
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        if (result.success) {
            this.toast(result.message, 'success', {
                category: 'boost_activate',
                payload: { boost_id: Number(boostId) || null, season_id: Number(this.state.currentSeason) || null }
            });
            if (result.receipt) this.showEconReceipt(result.receipt, `${name} Activated`);
            await this.refreshGameState();
            this.loadBoostCatalog();
        }
    },

    /**
     * Wrap purchaseBoostTime to use the preview/confirm/receipt flow.
     */
    async purchaseBoostTimeGated(boostId) {
        const boost = this._boostCatalog ? this._boostCatalog.find(b => b.boost_id == boostId) : null;
        const name = boost ? this.getBoostDisplayName(boost.name) : `Boost #${boostId}`;

        const result = await this.runWithEconGate(
            () => this.api('boost_activate_preview', { boost_id: boostId, purchase_kind: 'time' }),
            (confirm) => this.api('purchase_boost', { boost_id: boostId, purchase_kind: 'time', confirm_economic_impact: confirm ? 1 : 0 }),
            `Confirm: ${name} Extension`
        );

        if (!result) return;
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        if (result.success) {
            this.toast(result.message, 'success', {
                category: 'boost_activate',
                payload: { boost_id: Number(boostId) || null, season_id: Number(this.state.currentSeason) || null, purchase_kind: 'time' }
            });
            if (result.receipt) this.showEconReceipt(result.receipt, `${name} Extended`);
            await this.refreshGameState();
            this.loadBoostCatalog();
        }
    },
};

// Initialize on load
document.addEventListener('DOMContentLoaded', () => TMC.init());
