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

// Animate elements on scroll
function animateOnScroll() {
    document.querySelectorAll('.feature-card, .blog-card, .reveal').forEach(element => {
        const elementPosition = element.getBoundingClientRect().top;
        const screenPosition = window.innerHeight / 1.3;
        if (elementPosition < screenPosition) {
            element.classList.add('active');
        }
    });
}
window.addEventListener('scroll', animateOnScroll);
window.addEventListener('load', animateOnScroll);

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
function showNextSlide() {
    slides[index].classList.remove("active");
    index = (index + 1) % slides.length;
    loadSlide(slides[index]);
    slides[index].classList.add("active");
    loadSlide(slides[(index + 1) % slides.length]);
}
if (slides.length > 1) setInterval(showNextSlide, 5000);

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
