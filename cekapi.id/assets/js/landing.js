(() => {
  'use strict';

  // Menu mobile & tombol aktif navigasi
  const burger = document.getElementById('burger'), menu = document.getElementById('menu');
  const navLinks = [...menu.querySelectorAll('a')];
  const sections = ['#beranda', '#alat-iot', '#tim-galeri']
    .map(sel => document.querySelector(sel))
    .filter(Boolean);

  burger.addEventListener('click', () => {
    const open = menu.classList.toggle('open');
    burger.setAttribute('aria-expanded', String(open));
  });

  menu.addEventListener('click', e => {
    const link = e.target.closest('a');
    if (!link) return;
    menu.classList.remove('open');
    burger.setAttribute('aria-expanded', 'false');

    const href = link.getAttribute('href');
    if (href && href.startsWith('#')) {
      navLinks.forEach(item => item.classList.remove('on'));
      link.classList.add('on');
    }
  });

  if (sections.length) {
    const updateActiveNav = () => {
      const scrollPos = window.scrollY + 180;
      let currentId = sections[0].id;

      for (const sec of sections) {
        if (scrollPos >= sec.offsetTop) {
          currentId = sec.id;
        }
      }

      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 40) {
        currentId = sections[sections.length - 1].id;
      }

      navLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && href.startsWith('#')) {
          link.classList.toggle('on', href === `#${currentId}`);
        }
      });
    };

    window.addEventListener('scroll', updateActiveNav, { passive: true });
    updateActiveNav();
  }

  // Filter kategori alat
  const tabs = [...document.querySelectorAll('[role=tab]')];
  const cards = [...document.querySelectorAll('.tcard')];
  const track = document.getElementById('track');
  tabs.forEach(t => t.addEventListener('click', () => {
    tabs.forEach(x => x.setAttribute('aria-selected', String(x === t)));
    cards.forEach(c => { c.hidden = c.dataset.cat !== t.dataset.cat; });
    track.scrollTo({ left: 0 });
  }));

  // Panah carousel
  const step = () => (cards.find(c => !c.hidden) || { offsetWidth: 220 }).offsetWidth + 22;
  document.getElementById('prev').addEventListener('click', () => track.scrollBy({ left: -step() }));
  document.getElementById('next').addEventListener('click', () => track.scrollBy({ left: step() }));
})();