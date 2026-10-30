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
}
mobileNavToggle.addEventListener('click', () => setMobileNav(true));
document.querySelector('.close-nav').addEventListener('click', () => setMobileNav(false));
navOverlay.addEventListener('click', () => setMobileNav(false));
document.querySelectorAll('.mobile-nav a, .mobile-nav .mobile-donate').forEach(link => {
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

// Scroll reveal: cards on inner pages are tagged automatically, unless they're already on screen
document.querySelectorAll('.feature-card, .story-card, .partner-card, .contact-info, .contact-form, .article-content, .article-cta').forEach(el => {
    if (!el.classList.contains('reveal') && el.getBoundingClientRect().top > window.innerHeight) {
        el.classList.add('reveal');
    }
});
const revealEls = document.querySelectorAll('.reveal');
revealEls.forEach(el => {
    // Stagger siblings in the same grid
    const siblings = [...el.parentElement.children].filter(c => c.classList.contains('reveal'));
    el.style.setProperty('--stagger', `${Math.min(siblings.indexOf(el) * 0.1, 0.5)}s`);
});
function finishReveal(el) {
    el.classList.add('is-visible');
    // Hand the element back to its own hover transitions once it has arrived
    setTimeout(() => {
        el.classList.remove('reveal', 'reveal-left', 'reveal-right', 'is-visible');
        el.style.removeProperty('--stagger');
    }, 1600);
}
// Checks positions on scroll rather than using IntersectionObserver, so content can never get stuck hidden
let pendingReveal = reduceMotion ? [] : [...revealEls];
if (reduceMotion) revealEls.forEach(finishReveal);
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
if (pendingReveal.length) {
    window.addEventListener('scroll', queueReveal, { passive: true });
    window.addEventListener('resize', queueReveal);
    window.addEventListener('load', queueReveal);
    requestAnimationFrame(checkReveal);
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
    checkCounters();
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
    document.querySelectorAll('.gallery-item').forEach(item => {
        item.addEventListener('click', () => {
            lightboxImg.src = item.dataset.full;
            lightboxImg.alt = item.querySelector('img').alt;
            lightbox.classList.add('open');
            lightbox.querySelector('.lightbox-close').focus();
        });
    });
    lightbox.addEventListener('click', e => {
        if (e.target !== lightboxImg) closeLightbox();
    });
}

// M-Pesa Popup Functions
function openMpesaPopup() {
    document.getElementById("mpesaPopup").style.display = "flex";
}
function closeMpesaPopup() {
    document.getElementById("mpesaPopup").style.display = "none";
}
window.addEventListener('click', function(event) {
    const popup = document.getElementById('mpesaPopup');
    if (event.target === popup) closeMpesaPopup();
});

document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') return;
    closeMpesaPopup();
    setMobileNav(false);
    closeLightbox();
});

// Hero slideshow (slides after the first load their image just before they're shown)
let slides = document.querySelectorAll(".hero-slide");
let index = 0;
function loadSlide(slide) {
    if (slide.dataset.bg) {
        slide.style.backgroundImage = `url('${slide.dataset.bg}')`;
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
if (typedText) typeEffect();
