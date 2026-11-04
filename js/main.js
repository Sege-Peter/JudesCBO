// Header: switch to the solid style once the page scrolls
const siteHeader = document.getElementById('site-header');
function updateHeader() {
    siteHeader.classList.toggle('scrolled', window.scrollY > 40);
}
updateHeader();
window.addEventListener('scroll', updateHeader, { passive: true });

// Mobile Navigation
const mobileNavToggle = document.querySelector('.mobile-nav-toggle');
const mobileNav = document.querySelector('.mobile-nav');
const navOverlay = document.querySelector('.nav-overlay');
function setMobileNav(open) {
    mobileNav.classList.toggle('active', open);
    navOverlay.classList.toggle('active', open);
    mobileNavToggle.setAttribute('aria-expanded', open);
    document.body.style.overflow = open ? 'hidden' : '';
    document.body.classList.toggle('menu-open', open);
    mobileNavToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    if (open) siteHeader.classList.add('scrolled');
    else updateHeader();
}
mobileNavToggle.addEventListener('click', () => setMobileNav(!mobileNav.classList.contains('active')));
navOverlay.addEventListener('click', () => setMobileNav(false));
document.querySelectorAll('.mobile-nav a').forEach(link => {
    link.addEventListener('click', () => setMobileNav(false));
});

// Footer year
document.getElementById('year').textContent = new Date().getFullYear();

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Scroll progress bar
const progressBar = document.createElement('div');
progressBar.className = 'scroll-progress';
document.body.appendChild(progressBar);
function updateProgress() {
    const max = document.documentElement.scrollHeight - window.innerHeight;
    progressBar.style.transform = `scaleX(${max > 0 ? window.scrollY / max : 0})`;
}
updateProgress();
window.addEventListener('scroll', updateProgress, { passive: true });

// Scroll reveal: cards on inner pages are tagged automatically, unless they're already on screen.
// Runs after the first frame is painted so measuring positions doesn't hold up the page.
let revealEls = [];
function initReveal() {
document.querySelectorAll('.feature-card, .story-card, .partner-card, .article-content, .article-cta').forEach(el => {
    if (!el.classList.contains('reveal') && el.getBoundingClientRect().top > window.innerHeight) {
        el.classList.add('reveal');
    }
});
revealEls = document.querySelectorAll('.reveal');
revealEls.forEach(el => {
    // Stagger siblings in the same grid
    const siblings = [...el.parentElement.children].filter(c => c.classList.contains('reveal'));
    el.style.setProperty('--stagger', `${Math.min(siblings.indexOf(el) * 0.1, 0.5)}s`);
});
pendingReveal = reduceMotion ? [] : [...revealEls];
if (reduceMotion) revealEls.forEach(finishReveal);
if (pendingReveal.length) {
    window.addEventListener('scroll', queueReveal, { passive: true });
    window.addEventListener('resize', queueReveal);
    window.addEventListener('load', queueReveal);
    checkReveal();
}
}
requestAnimationFrame(() => setTimeout(initReveal, 0));
function finishReveal(el) {
    el.classList.add('is-visible');
    // Hand the element back to its own hover transitions once it has arrived
    setTimeout(() => {
        el.classList.remove('reveal', 'reveal-left', 'reveal-right', 'is-visible');
        el.style.removeProperty('--stagger');
    }, 1600);
}
// Checks positions on scroll rather than using IntersectionObserver, so content can never get stuck hidden
let pendingReveal = [];
let revealQueued = false;
function checkReveal() {
    revealQueued = false;
    const limit = window.innerHeight * 0.92;
    pendingReveal = pendingReveal.filter(el => {
        if (el.getBoundingClientRect().top < limit) {
            finishReveal(el);
            return false;
        }
        return true;
    });
    if (!pendingReveal.length) window.removeEventListener('scroll', queueReveal);
}
function queueReveal() {
    if (!revealQueued) {
        revealQueued = true;
        requestAnimationFrame(checkReveal);
    }
}

// Animated counters
function runCounter(el) {
    const to = parseInt(el.dataset.to, 10);
    const from = parseInt(el.dataset.from || '0', 10);
    const start = performance.now();
    const duration = 1800;
    function tick(now) {
        const t = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - t, 3);
        el.textContent = Math.round(from + (to - from) * eased);
        if (t < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
}
let pendingCounters = reduceMotion ? [] : [...document.querySelectorAll('.counter')];
function checkCounters() {
    pendingCounters = pendingCounters.filter(el => {
        if (el.getBoundingClientRect().top < window.innerHeight * 0.85) {
            runCounter(el);
            return false;
        }
        return true;
    });
    if (!pendingCounters.length) window.removeEventListener('scroll', checkCounters);
}
if (pendingCounters.length) {
    pendingCounters.forEach(el => { el.textContent = el.dataset.from || '0'; });
    window.addEventListener('scroll', checkCounters, { passive: true });
    requestAnimationFrame(() => setTimeout(checkCounters, 0));
}

// About tabs (home page)
document.querySelectorAll('.about-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.about-tab').forEach(t => {
            t.classList.toggle('active', t === tab);
            t.setAttribute('aria-selected', t === tab);
        });
        document.querySelectorAll('.about-panel').forEach(panel => {
            panel.classList.toggle('active', panel.id === `tab-${tab.dataset.tab}`);
        });
    });
});

// Gallery lightbox
const lightbox = document.getElementById('lightbox');
function closeLightbox() {
    if (lightbox) lightbox.classList.remove('open');
}
if (lightbox) {
    const lightboxImg = lightbox.querySelector('img');
    const galleryItems = [...document.querySelectorAll('.gallery-item')];
    const caption = lightbox.querySelector('.lightbox-caption');
    let current = 0;
    function showLightboxPhoto(i) {
        current = (i + galleryItems.length) % galleryItems.length;
        const item = galleryItems[current];
        lightboxImg.src = item.dataset.full;
        lightboxImg.alt = item.querySelector('img').alt;
        if (caption) caption.textContent = `${item.querySelector('img').alt} (${current + 1} of ${galleryItems.length})`;
    }
    galleryItems.forEach((item, i) => {
        item.addEventListener('click', () => {
            showLightboxPhoto(i);
            lightbox.classList.add('open');
            lightbox.querySelector('.lightbox-close').focus();
        });
    });
    // Previous / next buttons, arrow keys and swipe
    if (galleryItems.length > 1) {
        [['prev', 'fa-chevron-left', 'Previous photo', -1], ['next', 'fa-chevron-right', 'Next photo', 1]].forEach(([cls, icon, label, step]) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `lightbox-nav lightbox-${cls}`;
            btn.setAttribute('aria-label', label);
            btn.innerHTML = `<i class="fas ${icon}"></i>`;
            btn.addEventListener('click', () => showLightboxPhoto(current + step));
            lightbox.appendChild(btn);
        });
        document.addEventListener('keydown', e => {
            if (!lightbox.classList.contains('open')) return;
            if (e.key === 'ArrowLeft') showLightboxPhoto(current - 1);
            if (e.key === 'ArrowRight') showLightboxPhoto(current + 1);
        });
        let touchX = null;
        lightbox.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; }, { passive: true });
        lightbox.addEventListener('touchend', e => {
            if (touchX === null) return;
            const dx = e.changedTouches[0].clientX - touchX;
            if (Math.abs(dx) > 50) showLightboxPhoto(current + (dx < 0 ? 1 : -1));
            touchX = null;
        });
    }
    lightbox.addEventListener('click', e => {
        if (e.target === lightbox || e.target.closest('.lightbox-close')) closeLightbox();
    });
}

document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') return;
    setMobileNav(false);
    closeLightbox();
});

// Hero slideshow (slides after the first load their image just before they're shown)
let slides = document.querySelectorAll(".hero-slide");
let index = 0;
function loadSlide(slide) {
    if (slide.dataset.bg) {
        // Phones get the 800px copy of each photo
        const src = window.innerWidth <= 900 ? slide.dataset.bg.replace(/\.webp$/, '-800.webp') : slide.dataset.bg;
        slide.style.backgroundImage = `url('${src}')`;
        delete slide.dataset.bg;
    }
}
if (slides[1]) window.addEventListener('load', () => loadSlide(slides[1]));
const heroDots = document.querySelector('.hero-dots');
const dots = [];
function showSlide(next) {
    slides[index].classList.remove("active");
    if (dots[index]) dots[index].classList.remove("active");
    index = next;
    loadSlide(slides[index]);
    slides[index].classList.add("active");
    if (dots[index]) dots[index].classList.add("active");
    loadSlide(slides[(index + 1) % slides.length]);
}
function showNextSlide() {
    showSlide((index + 1) % slides.length);
}
if (slides.length > 1) {
    let slideTimer = setInterval(showNextSlide, 6000);
    if (heroDots) {
        slides.forEach((_, i) => {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.setAttribute('aria-label', `Show photo ${i + 1}`);
            if (i === 0) dot.classList.add('active');
            dot.addEventListener('click', () => {
                showSlide(i);
                clearInterval(slideTimer);
                slideTimer = setInterval(showNextSlide, 6000);
            });
            heroDots.appendChild(dot);
            dots.push(dot);
        });
        heroDots.removeAttribute('aria-hidden');
    }
}

// Typing animation
const phrases = ["Empowering Communities", "Educating for Change", "Transforming Lives"];
let currentPhrase = 0, currentChar = 0;
const typedText = document.getElementById("typed-text");
function typeEffect() {
    if (currentChar < phrases[currentPhrase].length) {
        typedText.textContent += phrases[currentPhrase].charAt(currentChar);
        currentChar++;
        setTimeout(typeEffect, 100);
    } else {
        setTimeout(eraseEffect, 2000);
    }
}
function eraseEffect() {
    if (currentChar > 0) {
        typedText.textContent = phrases[currentPhrase].substring(0, currentChar - 1);
        currentChar--;
        setTimeout(eraseEffect, 50);
    } else {
        currentPhrase = (currentPhrase + 1) % phrases.length;
        setTimeout(typeEffect, 500);
    }
}
// Starts once the page has loaded so it doesn't compete with the first paint
if (typedText) {
    if (document.readyState === 'complete') setTimeout(typeEffect, 600);
    else window.addEventListener('load', () => setTimeout(typeEffect, 600), { once: true });
}

// Donate page: the amount and cause picker fills in the M-Pesa steps and the WhatsApp confirmation
const donateCard = document.querySelector('.donate-card');
if (donateCard) {
    let amount = 1000;
    const amountBtns = donateCard.querySelectorAll('.amount-btn');
    const customAmount = document.getElementById('custom-amount');
    const confirmLink = document.getElementById('confirm-donation');
    const fmt = n => n.toLocaleString('en-KE');
    function updateDonation() {
        const cause = donateCard.querySelector('input[name="cause"]:checked').value;
        document.getElementById('summary-amount').textContent = amount ? fmt(amount) : '...';
        document.getElementById('summary-cause').textContent = cause;
        document.querySelectorAll('.step-amount').forEach(el => { el.textContent = amount ? fmt(amount) : 'of your choice'; });
        const gift = amount ? `KES ${fmt(amount)}` : 'a gift';
        const message = `Hello Judes CBO! I've sent a donation of ${gift} via M-Pesa towards ${cause}. My M-Pesa confirmation code is: `;
        confirmLink.href = 'https://wa.me/254712109310?text=' + encodeURIComponent(message);
    }
    amountBtns.forEach(btn => btn.addEventListener('click', () => {
        amountBtns.forEach(b => b.classList.toggle('active', b === btn));
        customAmount.value = '';
        customAmount.classList.remove('active');
        amount = parseInt(btn.dataset.amount, 10);
        updateDonation();
    }));
    customAmount.addEventListener('input', () => {
        const value = parseInt(customAmount.value, 10);
        amountBtns.forEach(b => b.classList.remove('active'));
        customAmount.classList.add('active');
        amount = value > 0 ? value : 0;
        updateDonation();
    });
    donateCard.querySelectorAll('input[name="cause"]').forEach(radio => radio.addEventListener('change', updateDonation));
    updateDonation();
}

// Donate page: switch between M-Pesa options (Paybill, Till, Send Money)
document.querySelectorAll('.mpesa-tabs').forEach(list => {
    const tabs = [...list.querySelectorAll('.mpesa-tab')];
    const select = tab => {
        tabs.forEach(t => {
            const on = t === tab;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
        });
    };
    tabs.forEach((tab, i) => {
        tab.tabIndex = i === 0 ? 0 : -1;
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', e => {
            const step = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
            if (!step) return;
            const next = tabs[(i + step + tabs.length) % tabs.length];
            select(next);
            next.focus();
        });
    });
});

// Copy-to-clipboard buttons
document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const label = btn.querySelector('span');
        try {
            await navigator.clipboard.writeText(btn.dataset.copy);
            label.textContent = 'Copied!';
            btn.classList.add('copied');
        } catch {
            label.textContent = btn.dataset.copy;
        }
        setTimeout(() => {
            label.textContent = 'Copy';
            btn.classList.remove('copied');
        }, 2000);
    });
});

// Forms send to Formspree in the background and show the result inline
function ajaxForm(form, onSuccess) {
    form.addEventListener('submit', async e => {
        e.preventDefault();
        let valid = true;
        form.querySelectorAll('[required]').forEach(input => {
            const ok = input.checkValidity();
            const field = input.closest('.field');
            if (field) field.classList.toggle('invalid', !ok);
            if (!ok && valid) {
                input.focus();
                valid = false;
            }
        });
        const status = form.querySelector('.form-status');
        if (!valid) {
            if (status) {
                status.className = 'form-status error';
                status.textContent = 'Please fill in the highlighted fields.';
            }
            return;
        }
        const button = form.querySelector('[type="submit"]');
        const label = button.querySelector('.btn-label') || button;
        const original = label.textContent;
        button.disabled = true;
        label.textContent = 'Sending...';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) throw new Error('Request failed');
            form.reset();
            onSuccess(form, status);
        } catch {
            if (status) {
                status.className = 'form-status error';
                status.textContent = 'Sorry, your message could not be sent. Please try again, or reach us on WhatsApp at +254 712 109310.';
            }
        } finally {
            button.disabled = false;
            label.textContent = original;
        }
    });
}
const contactForm = document.getElementById('contact-form');
if (contactForm) {
    const reason = document.getElementById('reason');
    const subject = document.getElementById('form-subject');
    reason.addEventListener('change', () => { subject.value = `Website enquiry: ${reason.value}`; });
    contactForm.querySelectorAll('[required]').forEach(input => {
        input.addEventListener('input', () => input.closest('.field').classList.remove('invalid'));
    });
    // "Volunteer with us" / "Discuss a partnership" links preselect the topic
    document.querySelectorAll('[data-reason]').forEach(link => {
        link.addEventListener('click', () => {
            reason.value = link.dataset.reason;
            reason.dispatchEvent(new Event('change'));
            setTimeout(() => document.getElementById('name').focus({ preventScroll: true }), 600);
        });
    });
    ajaxForm(contactForm, (form, status) => {
        subject.value = 'Website enquiry: General enquiry';
        status.className = 'form-status success';
        status.textContent = "Thank you! Your message has been sent. We'll get back to you soon.";
    });
}
const subscribeForm = document.getElementById('subscribe-form');
if (subscribeForm) {
    ajaxForm(subscribeForm, form => {
        const input = form.querySelector('input[type="email"]');
        input.placeholder = "Thanks! You're subscribed.";
    });
}

// Programs page: highlight the program currently in view in the sticky menu
const programLinks = document.querySelectorAll('.program-nav a');
if (programLinks.length) {
    const programSections = [...programLinks].map(link => document.querySelector(link.getAttribute('href')));
    function updateProgramNav() {
        let current = -1;
        programSections.forEach((section, i) => {
            if (section.getBoundingClientRect().top < window.innerHeight * 0.4) current = i;
        });
        programLinks.forEach((link, i) => link.classList.toggle('active', i === current));
        if (current >= 0) {
            const active = programLinks[current];
            const bar = active.parentElement;
            if (bar.scrollWidth > bar.clientWidth) {
                bar.scrollTo({ left: active.offsetLeft - bar.clientWidth / 2 + active.offsetWidth / 2, behavior: 'smooth' });
            }
        }
    }
    updateProgramNav();
    window.addEventListener('scroll', updateProgramNav, { passive: true });
}

// WhatsApp greeting: shows once per visit after a short delay
const waGreeting = document.querySelector('.wa-greeting');
if (waGreeting) {
    let seen = false;
    try { seen = sessionStorage.getItem('waGreetingSeen') === '1'; } catch {}
    function hideGreeting() {
        waGreeting.classList.remove('show');
        try { sessionStorage.setItem('waGreetingSeen', '1'); } catch {}
    }
    if (!seen) {
        setTimeout(() => waGreeting.classList.add('show'), 4000);
        setTimeout(hideGreeting, 14000);
    }
    waGreeting.querySelector('.wa-greeting-close').addEventListener('click', hideGreeting);
    document.querySelector('.whatsapp-float').addEventListener('click', hideGreeting);
}

// Offline support: cache the site's files and show offline.html when there's no connection
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {});
    });
}

// Events page: move events that have finished since the last rebuild into "Past events"
const upcomingList = document.getElementById('events-upcoming');
const pastList = document.getElementById('events-past');
if (upcomingList && pastList) {
    const now = Date.now();
    upcomingList.querySelectorAll('.event-card[data-ends]').forEach(card => {
        if (Date.parse(card.dataset.ends) < now) {
            card.querySelectorAll('.event-actions').forEach(a => a.remove());
            const placeholder = pastList.querySelector('.events-none');
            if (placeholder) placeholder.remove();
            pastList.prepend(card);
        }
    });
    if (!upcomingList.querySelector('.event-card') && !upcomingList.querySelector('.events-empty')) {
        upcomingList.insertAdjacentHTML('afterbegin', '<div class="events-empty"><i class="far fa-calendar"></i><p>No upcoming events right now. Check back soon.</p></div>');
    }
}

// Visitor statistics for the dashboard: no cookies and nothing personal is sent.
// Only works where the dashboard runs (PHP hosting), so it stays quiet on GitHub Pages.
(() => {
    const script = document.currentScript;
    if (!script || !/^https?:$/.test(location.protocol) || /github\.io$/.test(location.hostname)) return;
    const endpoint = new URL('../admin/api/track.php', script.src).href;
    const send = (data, beacon) => {
        const body = JSON.stringify(data);
        if (beacon && navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
            return Promise.resolve({});
        }
        return fetch(endpoint, { method: 'POST', body, keepalive: true, headers: { 'Content-Type': 'text/plain' } })
            .then(r => (r.ok ? r.json() : {}))
            .catch(() => ({}));
    };

    const params = new URLSearchParams(location.search);
    let viewId = 0;
    send({
        t: 'view', p: location.pathname, ti: document.title, r: document.referrer,
        w: screen.width, s: params.get('utm_source') || params.get('ref') || ''
    }).then(d => { viewId = d.id || 0; });

    // Time on page counts only while the page is on screen
    let shownAt = Date.now();
    let visibleMs = 0;
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            visibleMs += Date.now() - shownAt;
            if (viewId) send({ t: 'leave', id: viewId, s: Math.round(visibleMs / 1000) }, true);
        } else {
            shownAt = Date.now();
        }
    });

    // Calls, WhatsApp, emails, donate buttons, copied M-Pesa numbers and links to other websites
    document.addEventListener('click', e => {
        const el = e.target.closest('a[href], .copy-btn');
        if (!el) return;
        let name = '';
        let label = '';
        if (el.classList.contains('copy-btn')) {
            name = 'copy';
            label = el.dataset.copy || '';
        } else {
            const href = el.getAttribute('href');
            const url = new URL(el.href, location.href);
            if (href.startsWith('tel:')) [name, label] = ['call', href.slice(4)];
            else if (href.startsWith('mailto:')) [name, label] = ['email', href.slice(7).split('?')[0]];
            else if (/(^|\.)(wa\.me|whatsapp\.com)$/.test(url.hostname)) [name, label] = ['whatsapp', url.pathname.replace(/\D/g, '') || url.hostname];
            else if (/^https?:$/.test(url.protocol) && url.hostname !== location.hostname) [name, label] = ['outbound', url.hostname.replace(/^www\./, '')];
            else if (/\/donate(\.html)?$/.test(url.pathname) && !/\/donate(\.html)?$/.test(location.pathname)) [name, label] = ['donate', el.textContent.trim().slice(0, 60)];
        }
        if (name) send({ t: 'event', n: name, l: label, p: location.pathname }, true);
    });
})();


// Install the website as an app (footer badge and the /app page)
(function () {
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (standalone) document.documentElement.classList.add('is-app');
    const status = document.getElementById('app-status');
    if (standalone && status) status.hidden = false;

    // Highlight the steps for the visitor's device on the /app page
    const ua = navigator.userAgent;
    const platform = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) ? 'ios' : /Android/.test(ua) ? 'android' : 'desktop';
    const mine = document.querySelector(`.app-step[data-platform="${platform}"]`);
    if (mine) { mine.classList.add('is-you'); mine.parentElement.prepend(mine); }

    let deferred = null;
    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        deferred = e;
        document.querySelectorAll('button[data-install]').forEach(b => { b.hidden = false; });
    });
    document.addEventListener('click', async e => {
        const trigger = e.target.closest('[data-install]');
        if (!trigger || !deferred) return; // no prompt available: the link opens the /app page with instructions
        e.preventDefault();
        deferred.prompt();
        try { await deferred.userChoice; } catch { /* dismissed */ }
        deferred = null;
    });
    window.addEventListener('appinstalled', () => {
        document.querySelectorAll('button[data-install]').forEach(b => { b.hidden = true; });
        if (status) status.hidden = false;
    });
})();
