/* Kaleta - skript webu pro návštěvníky. Bez knihoven; vše je volitelné vylepšení, web funguje i bez JavaScriptu.
 * Menu na telefonu, dialogy a rozbalování řeší HTML a CSS (popover, <details>), ne tento skript. */
(function () {
	'use strict';

	/* ---------- přechod mezi stránkami (View Transitions řídí jen CSS šablony): když ho prohlížeč přeruší nebo vynechá
	   (rychlé proklikání, nová stránka přechod nepovolí), je to v pořádku – bez neošetřené chyby v konzoli ---------- */

	window.addEventListener('pagereveal', function (e) {
		if (!e.viewTransition) { return; }
		e.viewTransition.ready.catch(function () { /* přechod vynechán */ });
		e.viewTransition.finished.catch(function () { /* přechod vynechán */ });
	});

	/* ---------- texty: česky v kódu, překlad jazykové verze posílá Front\Seo::hlava() v atributu data-texty značky <script> ---------- */

	var texts = {};
	try {
		var htmlTag = document.currentScript || document.querySelector('script[data-texty]');
		texts = JSON.parse((htmlTag && htmlTag.getAttribute('data-texty')) || '{}') || {};
	} catch (e) { texts = {}; }
	/* bez atributu (vlastní šablona načítá skript jinak) zůstanou texty česky */
	function T(czech) { return typeof texts[czech] === 'string' && texts[czech] !== '' ? texts[czech] : czech; }
	function A(czech) { return T(czech).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

	/* ---------- prohlížečka fotek: fotogalerie i jednotlivé obrázky v textu ---------- */

	var modal = null, photos = [], position = 0;

	function show(i) {
		position = (i + photos.length) % photos.length;
		var source = photos[position];
		modal.querySelector('img').src = source.getAttribute('src');
		modal.querySelector('img').alt = source.alt;
		var description = source.alt || ((source.closest('figure') || document).querySelector('figcaption') || {}).textContent || '';
		modal.querySelector('p').textContent = (photos.length > 1 ? (position + 1) + ' / ' + photos.length + (description ? ' · ' : '') : '') + description;
	}

	function open(list, index) {
		if (!modal) {
			modal = document.createElement('dialog');
			modal.className = 'ka-prohlizecka';
			modal.innerHTML = '<img alt=""><p aria-live="polite"></p><button type="button" data-krok="-1" aria-label="' + A('Předchozí fotka') + '">‹</button>'
				+ '<button type="button" data-krok="1" aria-label="' + A('Další fotka') + '">›</button><button type="button" data-zavrit aria-label="' + A('Zavřít') + '">×</button>';
			document.body.appendChild(modal);
			modal.addEventListener('click', function (e) {
				var step = e.target.getAttribute('data-krok');
				if (step) { show(position + parseInt(step, 10)); } else if (e.target.tagName !== 'IMG') { modal.close(); }
			});
			modal.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowLeft') { show(position - 1); }
				if (e.key === 'ArrowRight') { show(position + 1); }
			});
			var start = null;
			modal.addEventListener('touchstart', function (e) { start = e.changedTouches[0].clientX; }, { passive: true });
			modal.addEventListener('touchend', function (e) {
				var offset = e.changedTouches[0].clientX - start;
				if (Math.abs(offset) > 50 && photos.length > 1) { show(position + (offset < 0 ? 1 : -1)); }
			}, { passive: true });
		}
		photos = list;
		modal.querySelectorAll('[data-krok]').forEach(function (b) { b.hidden = photos.length < 2; });
		show(index);
		modal.showModal();
	}

	document.addEventListener('click', function (e) {
		var img = e.target;
		if (img.tagName !== 'IMG' || img.closest('a') || !img.closest('.text, .perex, figure.galerie, .ka-galerie')) { return; }
		var gallery = img.closest('figure.galerie, .ka-galerie');
		var list = Array.prototype.slice.call((gallery || img.closest('.text, .perex')).querySelectorAll(gallery ? 'img' : 'figure:not(.galerie) img'));
		if (list.indexOf(img) === -1) { list = [img]; }
		open(list, list.indexOf(img));
	});

	/* ---------- podmenu: Esc zavře panel otevřený fokusem nebo myší a vrátí fokus na položku menu (WCAG 1.4.13) ---------- */

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		var li = e.target.closest && e.target.closest('.ka-nav li.podmenu');
		if (li && !li.closest('.ka-nav-menu:popover-open')) {
			li.classList.add('zavreno');
			var top = li.querySelector(':scope > a, :scope > .menu-skupina');
			if (top && top !== e.target) { top.focus(); }
		}
		// panel otevřený jen najetím myši
		document.querySelectorAll('.ka-nav li.podmenu:hover').forEach(function (h) { h.classList.add('zavreno'); });
	});
	['focusout', 'mouseout'].forEach(function (event) {
		document.addEventListener(event, function (e) {
			var li = e.target.closest && e.target.closest('.ka-nav li.podmenu.zavreno');
			if (li && !li.contains(e.relatedTarget)) { li.classList.remove('zavreno'); }
		});
	});

	/* ---------- sdílení novinky: systémové sdílení (telefon) a kopírování odkazu ---------- */

	document.querySelectorAll('[data-sdilet]').forEach(function (tl) {
		if (!navigator.share) { return; }
		tl.hidden = false;
		tl.addEventListener('click', function () {
			navigator.share({ title: tl.getAttribute('data-titulek'), url: tl.getAttribute('data-adresa') }).catch(function () { /* návštěvník sdílení zavřel */ });
		});
	});
	document.addEventListener('click', function (e) {
		var tl = e.target.closest && e.target.closest('[data-kopirovat]');
		if (!tl || !navigator.clipboard) { return; }
		var previous = tl.textContent;
		navigator.clipboard.writeText(tl.getAttribute('data-kopirovat')).then(function () {
			tl.textContent = tl.getAttribute('data-hotovo');
			setTimeout(function () { tl.textContent = previous; }, 2000);
		});
	});

	/* ---------- záložky (ARIA tabs): bez skriptu jsou vidět všechny panely ---------- */

	document.querySelectorAll('[data-zalozky]').forEach(function (z) {
		var cards = Array.prototype.slice.call(z.querySelectorAll('[role="tab"]'));
		function switchTo(card, focusTarget) {
			cards.forEach(function (k) {
				var picked = k === card;
				k.setAttribute('aria-selected', picked ? 'true' : 'false');
				k.tabIndex = picked ? 0 : -1;
				document.getElementById(k.getAttribute('aria-controls')).hidden = !picked;
			});
			if (focusTarget) { card.focus(); }
		}
		cards.forEach(function (k, i) {
			k.addEventListener('click', function () { switchTo(k, false); });
			k.addEventListener('keydown', function (e) {
				var additional = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: cards.length - 1 }[e.key];
				if (additional === undefined) { return; }
				e.preventDefault();
				switchTo(cards[(additional + cards.length) % cards.length], true);
			});
		});
		z.setAttribute('data-zapnuto', '');
		if (cards.length) { switchTo(cards[0], false); }
	});

	/* ---------- karusel: šipky posouvají pás o šířku viditelných snímků ---------- */

	document.querySelectorAll('[data-karusel]').forEach(function (k) {
		var strip = k.querySelector('.ka-karusel-pas');
		var arrows = k.querySelectorAll('[data-krok]');
		function state() {
			arrows[0].disabled = strip.scrollLeft <= 2;
			arrows[1].disabled = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 2;
		}
		arrows.forEach(function (b) {
			b.addEventListener('click', function () { strip.scrollBy({ left: parseInt(b.getAttribute('data-krok'), 10) * strip.clientWidth, behavior: 'smooth' }); });
		});
		strip.addEventListener('scroll', state, { passive: true });
		k.setAttribute('data-zapnuto', '');
		state();
	});

	/* ---------- vyskakovací okno: odkaz #kotva ho otevře, případně samo jednou za návštěvu ---------- */

	// otevřené okno dostane fokus (čtečka ho oznámí, klávesnice pokračuje uvnitř); po zavření se fokus vrátí, odkud přišel
	var openModal = function (modal) {
		if (!modal.showPopover || modal.matches(':popover-open')) { return; }
		var fromUrl = document.activeElement;
		modal.showPopover();
		var target = modal.querySelector('h1, h2, h3, input, select, textarea, a[href], button:not(.ka-okno-zavrit):not(.ka-popup-zavrit)') || modal.querySelector('button');
		if (target) { if (!target.matches('a, button, input, select, textarea')) { target.setAttribute('tabindex', '-1'); } target.focus(); }
		modal.addEventListener('toggle', function revert(e) {
			if (e.newState !== 'closed') { return; }
			modal.removeEventListener('toggle', revert);
			if (fromUrl && fromUrl.focus && document.contains(fromUrl)) { fromUrl.focus(); }
		});
	};
	document.addEventListener('click', function (e) {
		var link = e.target.closest && e.target.closest('a[href^="#"]');
		var modal = link && link.getAttribute('href').length > 1 && document.getElementById(link.getAttribute('href').slice(1));
		if (!modal || !modal.hasAttribute('popover') || !modal.showPopover) { return; }
		e.preventDefault();
		openModal(modal);
	});
	// adresa s kotvou okna nebo prvku v okně (návrat po odeslání formuláře či odběru) okno rovnou otevře, ať je potvrzení vidět
	if (location.hash.length > 1) {
		var anchor = document.getElementById(decodeURIComponent(location.hash.slice(1)));
		var inModal = anchor && anchor.closest('[popover]');
		if (inModal) { openModal(inModal); }
	}
	// okno, které se otevře samo: po čase, po odrolování poloviny stránky nebo při odchodu (myš k liště prohlížeče);
	// jednou za návštěvu (sessionStorage), jednou za týden nebo už nikdy (localStorage) – vždy jen v prohlížeči návštěvníka
	document.querySelectorAll('[popover][data-samo]').forEach(function (modal) {
		var key = 'ka-okno-' + modal.id;
		var retry = modal.getAttribute('data-znovu') || 'relace';
		var storage = function () { try { return retry === 'relace' ? sessionStorage : localStorage; } catch (error) { return null; } };
		try {
			var was = storage() && storage().getItem(key);
			if (was && (retry !== 'tyden' || Date.now() - parseInt(was, 10) < 7 * 864e5)) { return; }
		} catch (error) { /* soukromý režim */ }
		var done = false;
		var open = function () {
			if (done || !modal.showPopover || document.querySelector(':popover-open')) { return; }
			done = true;
			openModal(modal);
			try { storage().setItem(key, String(Date.now())); } catch (error) { /* soukromý režim */ }
		};
		var when = modal.getAttribute('data-samo');
		if (when === 'posun') {
			window.addEventListener('scroll', function () {
				if (window.scrollY + window.innerHeight >= document.documentElement.scrollHeight / 2) { open(); }
			}, { passive: true });
		} else if (when === 'odchod') {
			document.addEventListener('mouseout', function (e) { if (!e.relatedTarget && e.clientY <= 0) { open(); } });
		} else {
			setTimeout(open, parseInt(when, 10) * 1000);
		}
	});

	/* ---------- pop-up okna (Stavitel\Popupy): spouštěč, pravidla prohlížeče, četnost a počitadla – bez cookies ---------- */

	var popups = document.querySelectorAll('.ka-popup[data-popup]');
	if (popups.length) {
		var session = function () { try { return sessionStorage; } catch (error) { return null; } };
		var persistent = function () { try { return localStorage; } catch (error) { return null; } };
		var read = function (u, k) { try { return u ? u.getItem(k) : null; } catch (error) { return null; } };
		var write = function (u, k, v) { try { if (u) { u.setItem(k, v); } } catch (error) { /* soukromý režim */ } };
		// návštěva: počet stránek, kampaň a odkud přišla (první stránka návštěvy) – jen v sessionStorage návštěvníka
		var pageCount = (parseInt(read(session(), 'ka-stranek'), 10) || 0) + 1;
		write(session(), 'ka-stranek', String(pageCount));
		if (read(session(), 'ka-kampan') === null) {
			var utm = [];
			new URLSearchParams(location.search).forEach(function (v, k) { if (k.indexOf('utm_') === 0) { utm.push(v); } });
			write(session(), 'ka-kampan', utm.join(' ').toLowerCase());
			var fromUrl = '';
			try { fromUrl = document.referrer && new URL(document.referrer).host !== location.host ? new URL(document.referrer).host : ''; } catch (error) { /* neplatná adresa */ }
			write(session(), 'ka-odkud', fromUrl.toLowerCase());
		}
		var phone = window.matchMedia('(max-width: 767px)').matches;
		var report = function (modal, event) {
			if (!modal.getAttribute('data-pocitadlo')) { return; } // přihlášený správce – nepočítá se
			var data = new FormData();
			data.append('id', modal.getAttribute('data-popup'));
			data.append('udalost', event);
			try { navigator.sendBeacon(modal.getAttribute('data-pocitadlo'), data); } catch (error) { /* bez počitadla */ }
		};
		var cookiesSeen = function () { var l = document.getElementById('cookies-lista'); return l && !l.hidden; };

		popups.forEach(function (modal) {
			var id = modal.getAttribute('data-popup');
			var key = 'ka-popup-' + id;
			var frequency = modal.getAttribute('data-cetnost');
			var dialog = modal.classList.contains('ka-popup--okno') || modal.classList.contains('ka-popup--cela');
			var conversion = false;
			var openItems = false;

			// konverze: návrat po odeslání formuláře nebo přihlášení k odběru v okně (kotva v adrese míří dovnitř okna)
			var target = location.hash.length > 1 && document.getElementById(decodeURIComponent(location.hash.slice(1)));
			if (target && modal.contains(target) && (modal.querySelector('[data-odeslano]') || new URLSearchParams(location.search).get('odber') === 'ok')) {
				conversion = true;
				report(modal, 'konverze');
				write(persistent(), key + '-odeslano', '1');
			}
			modal.addEventListener('toggle', function (e) {
				if (e.newState === 'open') {
					openItems = true;
					if (!conversion) { report(modal, 'zobrazeni'); } // okno otevřené kvůli poděkování po odeslání se nepočítá znovu
					if (frequency === 'relace' || frequency === 'odeslani') { write(session(), key, '1'); }
					if (frequency === 'dni') { write(persistent(), key, String(Date.now())); }
				} else if (openItems) {
					openItems = false;
					if (!conversion) { report(modal, 'zavreni'); }
					if (frequency === 'zavreni') { write(persistent(), key, 'zavreno'); }
				}
			});
			var open = function () {
				// přes otevřené okno se nic dalšího neotvírá; lišta nebo panel okno nezablokují
				if (!modal.showPopover || modal.matches(':popover-open') || document.querySelector('.ka-popup--okno:popover-open, .ka-popup--cela:popover-open, .ka-okno:popover-open, dialog[open]')) { return false; }
				if (dialog) { openModal(modal); } else { modal.showPopover(); }
				return true;
			};
			if (modal.hasAttribute('data-otevrit')) { open(); return; } // náhled konceptu

			// pravidla prohlížeče: zařízení, kampaň, odkud návštěvník přišel
			var device = modal.getAttribute('data-zarizeni');
			if ((device === 'telefon' && !phone) || (device === 'pocitac' && phone)) { return; }
			var search = function (attribute, sessionKey) {
				var wanted = (modal.getAttribute(attribute) || '').toLowerCase();
				return wanted === '' || (read(session(), sessionKey) || '').indexOf(wanted) !== -1;
			};
			if (!search('data-utm', 'ka-kampan') || !search('data-odkud', 'ka-odkud')) { return; }
			// četnost: kdy se okno samo znovu neukáže
			var was = read(persistent(), key);
			if ((frequency === 'relace' && read(session(), key)) || (frequency === 'odeslani' && (read(session(), key) || read(persistent(), key + '-odeslano')))
				|| (frequency === 'zavreni' && was === 'zavreno')
				|| (frequency === 'dni' && was && Date.now() - parseInt(was, 10) < (parseInt(modal.getAttribute('data-dni'), 10) || 1) * 864e5)) { return; }

			var done = false;
			var run = function () {
				if (done) { return; }
				// okno nepřekryje lištu cookies: počká, až ji návštěvník vyřídí
				if (cookiesSeen()) {
					var l = document.getElementById('cookies-lista');
					new MutationObserver(function (z, observer) { if (l.hidden) { observer.disconnect(); run(); } }).observe(l, { attributes: true, attributeFilter: ['hidden'] });
					return;
				}
				// ani nezavře menu, se kterým návštěvník právě pracuje (na telefonu popover): počká, až ho zavře
				var menu = document.querySelector('.ka-nav [popover]:popover-open');
				if (menu) {
					menu.addEventListener('toggle', function delay(e) {
						if (e.newState !== 'closed') { return; }
						menu.removeEventListener('toggle', delay);
						setTimeout(run, 400);
					});
					return;
				}
				done = open();
			};
			var value = parseInt(modal.getAttribute('data-hodnota'), 10) || 0;
			switch (modal.getAttribute('data-spoustec')) {
			case 'cas':
				setTimeout(run, value * 1000);
				break;
			case 'posun':
				var offset = function () {
					var path = document.documentElement.scrollHeight - window.innerHeight;
					if (path <= 0 || window.scrollY / path * 100 >= value) { window.removeEventListener('scroll', offset); run(); }
				};
				window.addEventListener('scroll', offset, { passive: true });
				break;
			case 'odchod':
				document.addEventListener('mouseout', function (e) { if (!e.relatedTarget && e.clientY <= 0) { run(); } });
				break;
			case 'necinnost':
				var timer;
				var retry = function () { clearTimeout(timer); timer = setTimeout(run, Math.max(1, value) * 1000); };
				['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (u) { window.addEventListener(u, retry, { passive: true }); });
				retry();
				break;
			case 'stranky':
				if (pageCount >= Math.max(1, value)) { setTimeout(run, 1500); }
				break;
			default: // klik – otevře ho odkaz #popup-<adresa> (obsluha odkazů na okna výše)
			}
		});
		// Esc zavře i panel a lištu (popover="manual" se sám nezavírá)
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') { return; }
			document.querySelectorAll('.ka-popup[popover="manual"]:popover-open').forEach(function (o) { o.hidePopover(); });
		});
	}

	/* ---------- počítadlo a odpočet ---------- */

	var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var numbers = new Intl.NumberFormat(document.documentElement.lang || 'cs');
	// počítadlo: číslo je v HTML hotové (do objevení zůstává vidět), při prvním objevení na obrazovce se jednou napočítá od nuly
	document.querySelectorAll('[data-pocitadlo]').forEach(function (c) {
		var target = parseInt(c.getAttribute('data-pocitadlo'), 10);
		if (calm || !target || !('IntersectionObserver' in window)) { return; }
		var observer = new IntersectionObserver(function (records) {
			if (!records[0].isIntersecting) { return; }
			observer.disconnect();
			var start = null;
			var step = function (t) {
				start = start || t;
				var share = Math.min(1, (t - start) / 1400);
				c.textContent = numbers.format(Math.round(target * (1 - Math.pow(1 - share, 3))));
				if (share < 1) { requestAnimationFrame(step); }
			};
			requestAnimationFrame(step);
		}, { threshold: 0.3 });
		observer.observe(c);
	});
	// odpočet: server vypsal stav v okamžiku vykreslení (stránka může být z cache), tady se dopočítává každou sekundu
	document.querySelectorAll('[data-odpocet]').forEach(function (o) {
		var target = Date.parse(o.getAttribute('data-odpocet'));
		var parts = {};
		o.querySelectorAll('[data-cast]').forEach(function (d) { parts[d.getAttribute('data-cast')] = d; });
		var two = function (n) { return (n < 10 ? '0' : '') + n; };
		var tick = function () {
			var remaining = Math.floor((target - Date.now()) / 1000);
			if (remaining <= 0) {
				var end = document.createElement('p');
				end.className = 'ka-odpocet-konec';
				end.textContent = o.getAttribute('data-konec');
				o.replaceWith(end);
				return;
			}
			parts.d.textContent = Math.floor(remaining / 86400);
			parts.h.textContent = two(Math.floor(remaining % 86400 / 3600));
			parts.m.textContent = two(Math.floor(remaining % 3600 / 60));
			parts.s.textContent = two(remaining % 60);
			setTimeout(tick, 1000 - Date.now() % 1000);
		};
		if (!isNaN(target) && parts.s) { tick(); }
	});

	/* ---------- formuláře: po chybě vrátit vyplněné hodnoty, po odeslání ohlásit konverzi ---------- */

	// hodnoty drží jen prohlížeč návštěvníka (sessionStorage) a po úspěšném odeslání zmizí; do adresy se nic nepíše
	document.querySelectorAll('form[data-formular]').forEach(function (f) {
		var key = 'ka-formular-' + f.getAttribute('data-formular');
		var wait = f.querySelector('input[data-cekat]');
		f.addEventListener('submit', function (e) {
			var values = {};
			Array.prototype.forEach.call(f.elements, function (p) {
				if (!/^p\d+$/.test(p.name)) { return; }
				if (p.type === 'checkbox' || p.type === 'radio') { if (p.checked) { values[p.name] = p.value; } } else { values[p.name] = p.value; }
			});
			try { sessionStorage.setItem(key, JSON.stringify(values)); } catch (error) { /* soukromý režim */ }
			// ochrana proti robotům odmítne formulář odeslaný pár vteřin po načtení stránky (s automatickým vyplněním to zvládne
			// i člověk) – místo chyby se odeslání o zbytek odloží
			// počítá se od první odpovědi serveru (stránka vznikla ještě před ní), ne od kliknutí na odkaz
			var navigation = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
			var remaining = wait ? parseInt(wait.getAttribute('data-cekat'), 10) * 1000 + 250 - (performance.now() - (navigation ? navigation.responseStart : 0)) : 0;
			if (remaining > 0) {
				e.preventDefault();
				var button = f.querySelector('[type=submit]');
				if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
				setTimeout(function () { f.submit(); }, remaining);
			}
		});
		if (!f.hasAttribute('data-obnovit')) { return; }
		var storedForm = null;
		try { storedForm = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (error) { /* nic */ }
		if (!storedForm) { return; }
		Array.prototype.forEach.call(f.elements, function (p) {
			if (!(p.name in storedForm)) { return; }
			if (p.type === 'checkbox' || p.type === 'radio') { p.checked = p.value === storedForm[p.name]; } else { p.value = storedForm[p.name]; }
		});
	});
	var sent = new URLSearchParams(location.search).get('odeslano');
	Array.prototype.map.call(document.querySelectorAll('[data-odeslano]'), function (h) { return h.getAttribute('data-odeslano'); }).concat(sent ? [sent] : []).forEach(function (name) {
		try { Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('ka-formular-') === 0) { sessionStorage.removeItem(k); } }); } catch (error) { /* nic */ }
		// měření konverzí: vlastní skript naslouchá události, Google Tag Manager dostane záznam do dataLayer
		window.dispatchEvent(new CustomEvent('kaleta:odeslano', { detail: { formular: name } }));
		if (Array.isArray(window.dataLayer)) { window.dataLayer.push({ event: 'kaleta_formular_odeslan', formular: name }); }
	});

	/* ---------- přehrávač cizí služby se vloží až po kliknutí ---------- */

	document.addEventListener('click', function (e) {
		var tl = e.target.closest && e.target.closest('[data-vlozit]');
		if (!tl) { return; }
		// jen služby, které web sám vkládá (YouTube bez cookies, Vimeo, mapa Google) – nikdy jiná adresa ani javascript:
		var address = tl.getAttribute('data-vlozit') || '';
		if (!/^https:\/\/(www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/|maps\.google\.com\/maps\?)/.test(address)) { return; }
		var border = document.createElement('iframe');
		border.src = address;
		border.title = tl.getAttribute('data-titulek') || '';
		border.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
		border.allowFullscreen = true;
		border.loading = 'lazy';
		tl.replaceWith(border);
	});
	// přepínač vzhledu (views/front/tema.php): volba se pamatuje v prohlížeči, hlavička šablony ji použije před vykreslením
	(function () {
		var options = document.querySelectorAll('[data-tema-volba]');
		if (!options.length) { return; }
		var root = document.documentElement;
		function mark(v) {
			document.querySelectorAll('.ka-tema').forEach(function (n) { n.setAttribute('data-volba', v); });
			options.forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-tema-volba') === v)); });
		}
		var storedValue = null;
		try { storedValue = localStorage.getItem('ka-tema'); } catch (e) { /* úložiště nedostupné */ }
		var defaults = (document.querySelector('.ka-tema') || root).getAttribute('data-tema-vychozi') || 'auto';
		mark(storedValue === 'auto' || storedValue === 'svetly' || storedValue === 'tmavy' ? storedValue : defaults);
		document.addEventListener('click', function (e) {
			var b = e.target.closest && e.target.closest('[data-tema-volba]');
			if (!b) { return; }
			var v = b.getAttribute('data-tema-volba');
			if (v === 'auto') { root.removeAttribute('data-tema'); } else { root.setAttribute('data-tema', v); }
			try { localStorage.setItem('ka-tema', v); } catch (err) { /* volba platí jen pro tuto stránku */ }
			mark(v);
			var offer = b.closest('[popover]');
			if (offer && offer.matches(':popover-open')) { offer.hidePopover(); }
		});
	})();
})();

/* ---------- jazykové verze: při první návštěvě verze v jazyce prohlížeče, pak vždy volba návštěvníka ----------
 * Bez cookies – volba je v localStorage. Přesměruje jen při vstupu na web (ne při procházení), jen na stránku, která má
 * v tom jazyce překlad (odkazy hreflang v hlavičce), a nikdy roboty vyhledávačů. Klik v přepínači jazyků volbu změní. */
(function () {
	var alternatives = document.querySelectorAll('link[rel="alternate"][hreflang]:not([hreflang="x-default"])');
	if (alternatives.length < 2 || navigator.webdriver || /bot|crawl|spider|slurp|facebookexternalhit|preview|lighthouse|headless/i.test(navigator.userAgent)) { return; }
	var save = function (language) { try { localStorage.setItem('ka-jazyk', language); } catch (e) { /* úložiště nedostupné – nic */ } };
	document.addEventListener('click', function (e) {
		var link = e.target.closest && e.target.closest('.ka-jazyky a[hreflang], .ka-jazyky-vyber a[hreflang]');
		if (link) { save(link.getAttribute('hreflang')); }
	});
	var storedItem = null;
	try { storedItem = localStorage.getItem('ka-jazyk'); } catch (e) { return; }
	if (storedItem) { return; }
	var version = {};
	alternatives.forEach(function (l) { version[l.getAttribute('hreflang').toLowerCase().slice(0, 2)] = l.href; });
	var current = (document.documentElement.lang || '').toLowerCase().slice(0, 2);
	var wanted = null;
	(navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || '']).some(function (j) {
		j = String(j).toLowerCase().slice(0, 2);
		if (version[j]) { wanted = j; return true; }
		return false;
	});
	save(wanted || current);
	var fromSite = document.referrer !== '' && document.referrer.indexOf(location.origin + '/') === 0;
	if (wanted && wanted !== current && !fromSite) { location.replace(version[wanted]); }
})();
