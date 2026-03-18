'use strict';

const IDLE_MS = 2 * 60 * 1000;
const IDLE_WARN = 15;

const DAYS = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
const MONTHS = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

let screen = 'splash';
let settings = {};
let idleRem = IDLE_MS;
let idleInterval = null;
let clockInterval = null;
let ipcInitialized = false;
let toastTm = null;

function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

function updateClock() {
    const d = new Date();
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    document.getElementById('clock').textContent = `${hh}:${mm}`;
    document.getElementById('date-display').textContent = `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
    document.getElementById('day-display').textContent = DAYS[d.getDay()];
}

function showScreen(name) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    const el = document.getElementById(`screen-${name}`);
    if (el) requestAnimationFrame(() => el.classList.add('active'));
    screen = name;

    if (name === 'splash') {
        stopIdle();
        if (clockInterval) { clearInterval(clockInterval); clockInterval = null; }
        document.getElementById('timeout-overlay').style.display = 'none';
    } else {
        if (!clockInterval) {
            updateClock();
            clockInterval = setInterval(updateClock, 1000);
        }
        notifyActivity();
    }
}

function startSession() {
    if (screen === 'splash') showScreen('main');
}

function notifyActivity() {
    if (screen === 'splash') return;
    window.kioskAPI?.notifyActivity();
    idleRem = IDLE_MS;
    document.getElementById('timeout-overlay').style.display = 'none';
    clearInterval(idleInterval);
    idleInterval = setInterval(idleTick, 1000);
}

function stopIdle() {
    clearInterval(idleInterval);
}

function idleTick() {
    idleRem = Math.max(0, idleRem - 1000);
    const sec = Math.ceil(idleRem / 1000);
    document.getElementById('idle-countdown').textContent = `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;
    if (idleRem <= IDLE_WARN * 1000 && idleRem > 0) {
        document.getElementById('timeout-overlay').style.display = '';
        document.getElementById('timeout-count').textContent = sec;
    } else if (idleRem <= 0) {
        showScreen('splash');
    }
}

function cancelTimeout() { notifyActivity(); }

['pointerdown', 'pointermove', 'touchstart', 'keydown'].forEach(e => document.addEventListener(e, notifyActivity, { passive: true }));
document.addEventListener('contextmenu', e => e.preventDefault());
document.addEventListener('dragstart', e => e.preventDefault());

function setupIPC() {
    if (!window.kioskAPI || ipcInitialized) return;
    ipcInitialized = true;
    window.kioskAPI.on('browserview-opened', url => {
        document.getElementById('kiosk-navbar').classList.add('visible');
        document.body.classList.add('bv-active');
        try { document.getElementById('active-url-text').textContent = new URL(url).hostname; } catch { }
    });
    window.kioskAPI.on('browserview-closed', () => {
        document.getElementById('kiosk-navbar').classList.remove('visible');
        document.body.classList.remove('bv-active');
    });
    window.kioskAPI.on('url-blocked', () => showToast('Erişim kısıtlandı.'));
    window.kioskAPI.on('idle-timeout', () => {
        showScreen('splash');
        document.getElementById('kiosk-navbar').classList.remove('visible');
        document.body.classList.remove('bv-active');
    });
}

async function goHome() { try { await window.kioskAPI?.goHome(); } catch { } notifyActivity(); }

function showToast(msg) {
    clearTimeout(toastTm);
    const t = document.getElementById('toast');
    document.getElementById('toast-text').textContent = msg;
    t.classList.add('on');
    toastTm = setTimeout(() => t.classList.remove('on'), 3500);
}

async function loadSettings() {
    try {
        const d = await window.kioskAPI?.fetchApi({ action: 'settings' });
        if (d?.success) settings = d.data;
    } catch { }
}

function applySettings() {
    const raw = settings.background_image;
    if (!raw) return;
    try {
        const u = new URL(raw);
        if (u.protocol !== 'https:') return;
        const safe = u.href.replace(/"/g, '%22').replace(/[()]/g, encodeURIComponent);
        document.getElementById('splash-bg').style.backgroundImage = `url("${safe}")`;
    } catch { /* Geçersiz URL — yoksay */ }
}

async function fetchServices() {
    const grid = document.getElementById('service-grid');
    const loader = document.getElementById('grid-loading');
    const error = document.getElementById('grid-error');
    loader.style.display = '';
    error.style.display = 'none';
    grid.querySelectorAll('.card').forEach(c => c.remove());
    try {
        const d = await window.kioskAPI?.fetchApi({ action: 'urls' });
        if (!d?.success) throw new Error(d?.error || 'Sunucu hatası');

        const list = d.data || [];
        loader.style.display = 'none';

        if (!list.length) {
            grid.innerHTML = '<div class="status-box"><p>Henüz tanımlı bir hizmet kartı bulunmuyor.<br>Yönetim panelinden en az bir hizmet ekleyip etkinleştirmeniz gerekiyor.</p></div>';
            return;
        }

        const fragment = document.createDocumentFragment();
        list.forEach((svc, i) => fragment.appendChild(mkCard(svc, i)));
        grid.appendChild(fragment);
    } catch (err) {
        console.error('Fetch services error:', err);
        loader.style.display = 'none';
        error.style.display = '';
        const errP = document.querySelector('#grid-error p');
        if (errP) errP.textContent = err?.message ? `Bağlantı kurulamadı: ${err.message}` : 'Bağlantı kurulamadı';
    }
}

function mkCard(svc, i) {
    const btn = document.createElement('a');
    btn.style.animationDelay = `${i * 60}ms`;
    if (svc.service_type) btn.setAttribute('data-type', svc.service_type);

    btn.className = 'card';
    btn.innerHTML = `<div class="card-icon-wrap"><i class="${esc(svc.icon_class || 'bi bi-globe')}"></i></div>
                     <span class="card-label">${esc(svc.title)}</span>`;

    btn.addEventListener('click', async (e) => {
        e.preventDefault();
        notifyActivity();
        if (svc.url) {
            btn.classList.add('loading');
            try {
                const navTitle = document.getElementById('nav-title');
                if (navTitle) navTitle.textContent = svc.title;
                await window.kioskAPI?.openUrl(svc.url, svc.id);
            } catch { showToast('Erişim engellendi'); }
            setTimeout(() => btn.classList.remove('loading'), 400);
        }
    });
    return btn;
}

async function init() {
    setupIPC();
    updateClock();
    document.getElementById('screen-splash')?.addEventListener('click', startSession);
    document.getElementById('btn-go-home')?.addEventListener('click', goHome);
    document.getElementById('btn-retry-services')?.addEventListener('click', () => fetchServices());
    document.getElementById('btn-cancel-timeout')?.addEventListener('click', cancelTimeout);

    loadSettings().then(applySettings);
    fetchServices();
}

init();