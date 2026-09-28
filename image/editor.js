/* Kaleta - editor textu (novinky, stránky) a práce s obrázky. Bez knihoven, bez build kroku.
 *
 *   <textarea data-editor>            WYSIWYG editor (data-editor="maly" = zkrácená lišta)
 *   <input data-obrazek>              pole s adresou obrázku + tlačítko "Vybrat z galerie" a náhled
 *   <form data-nahravani>             nahrávání přetažením souborů
 *
 * Do formuláře se vždy odesílá obsah původní <textarea> - bez JavaScriptu zůstane obyčejným polem pro HTML.
 */

(function () {
	'use strict';

	var T = window.T || function (s) { return s; }; // překlad textů administrace (image/jazyky/admin-*.js)

	var SCRIPT = document.querySelector('script[data-admin-url]');
	var ADMIN = SCRIPT.getAttribute('data-admin-url');
	var MAX_FILE = parseInt(SCRIPT.getAttribute('data-max-soubor') || '0', 10); // limit serveru na soubor v bajtech (0 = bez limitu)
	var MAX_SIDE = parseInt(SCRIPT.getAttribute('data-max-strana') || '2000', 10);
	var CSRF = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	var GALLERY = ADMIN + '?module=media';
	var NEWS_ID = parseInt((document.querySelector('form[data-koncept] input[name="idc"]') || {}).value || '0', 10);
	var LANGUAGE = document.documentElement.lang || 'cs'; // formát data a času podle jazyka stránky
	var time = function (t, timeOnly) { return window.kaletaCas ? window.kaletaCas(t, timeOnly) : new Date(t).toLocaleString(LANGUAGE); }; // image/admin.js

	function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

	// Oznámení chyby vlastním dialogem - systémový alert() vestavěné prohlížeče potlačují stejně jako confirm().
	var noticeDialog = null;
	function announce(text) {
		if (!noticeDialog) {
			noticeDialog = document.createElement('dialog');
			noticeDialog.className = 'potvrzeni';
			noticeDialog.setAttribute('role', 'alertdialog');
			noticeDialog.innerHTML = '<p></p><div><button type="button" class="tl" data-zavri>' + T('Zavřít') + '</button></div>';
			document.body.appendChild(noticeDialog);
			noticeDialog.querySelector('[data-zavri]').addEventListener('click', function () { noticeDialog.close(); });
		}
		noticeDialog.querySelector('p').textContent = text;
		if (!noticeDialog.open) { noticeDialog.showModal(); }
	}

	/* ---------- čištění HTML (vkládání z Wordu a webu) ---------- */

	var ALLOWED = { P: [], H2: [], H3: [], H4: [], STRONG: [], EM: [], B: [], I: [], U: [], S: [], SUB: [], SUP: [], BR: [], HR: [],
		A: ['href', 'title', 'target', 'rel'], UL: [], OL: [], LI: [], BLOCKQUOTE: [], CODE: [], PRE: [],
		FIGURE: ['class'], FIGCAPTION: [], IMG: ['src', 'alt', 'width', 'height', 'loading', 'data-id'],
		TABLE: [], THEAD: [], TBODY: [], TR: [], TH: ['colspan', 'rowspan'], TD: ['colspan', 'rowspan'], IFRAME: ['src', 'width', 'height', 'allowfullscreen', 'title'] };
	var TAG_REPLACEMENTS = { DIV: 'P', H1: 'H2', H5: 'H4', H6: 'H4' };

	function sanitize(node) {
		Array.prototype.slice.call(node.childNodes).forEach(function (n) {
			if (n.nodeType === 8) { n.remove(); return; }
			if (n.nodeType !== 1) { return; }
			var tag = n.tagName;
			if (/^(SCRIPT|STYLE|META|LINK|TITLE|HEAD|O:P|XML)$/.test(tag)) { n.remove(); return; }
			sanitize(n);
			if (TAG_REPLACEMENTS[tag]) {
				var fresh = document.createElement(TAG_REPLACEMENTS[tag]);
				while (n.firstChild) { fresh.appendChild(n.firstChild); }
				n.replaceWith(fresh);
				return;
			}
			if (!ALLOWED[tag]) {
				while (n.firstChild) { n.parentNode.insertBefore(n.firstChild, n); }
				n.remove();
				return;
			}
			Array.prototype.slice.call(n.attributes).forEach(function (a) {
				if (ALLOWED[tag].indexOf(a.name) === -1 || /^\s*javascript:/i.test(a.value)) { n.removeAttribute(a.name); }
			});
		});
	}

	function cleanHtml(html) {
		var box = document.createElement('div');
		box.innerHTML = html;
		sanitize(box);
		return box.innerHTML.replace(/<p>(\s|&nbsp;|<br>)*<\/p>/g, '').replace(/&nbsp;/g, ' ').trim();
	}

	/* ---------- nahrávání ---------- */

	// Fotka z telefonu (5–10 MB) se zmenší už v prohlížeči na MAX_STRANA px – stejně by ji zmenšil server – a nenarazí tak
	// na limit hostingu. Otočení podle EXIF zachová createImageBitmap; údaje EXIF (i poloha) zmizí, jako při zpracování na serveru.
	function shrink(file) {
		if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) { return Promise.resolve(file); }
		return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bitmap) {
			// stejné pravidlo jako Core\Obrazky::pomer: vysoký obrázek (celostránkový snímek) se měří šířkou, ne delší stranou
			var w = bitmap.width, h = bitmap.height;
			var ratio = h > 2 * w ? Math.min(1, MAX_SIDE / w, 3 * MAX_SIDE / h) : Math.min(1, MAX_SIDE / Math.max(w, h));
			if (ratio === 1 && (!MAX_FILE || file.size <= MAX_FILE)) { bitmap.close(); return file; }
			var canvas = document.createElement('canvas');
			canvas.width = Math.round(bitmap.width * ratio);
			canvas.height = Math.round(bitmap.height * ratio);
			canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
			bitmap.close();
			// kvalita 0,9; když je výsledek pořád nad limitem serveru, zkusí se nižší (PNG kvalitu nemá)
			var write = function (quality) {
				return new Promise(function (done) { canvas.toBlob(done, file.type, quality); }).then(function (blob) {
					if (blob && MAX_FILE && blob.size > MAX_FILE && file.type !== 'image/png' && quality > 0.6) { return write(Math.round((quality - 0.1) * 10) / 10); }
					// prohlížeč, který typ neumí zapsat (WebP v Safari), vrátí jiný – pak raději původní soubor
					return blob && blob.type === file.type && (ratio < 1 || blob.size < file.size)
						? new File([blob], file.name, { type: file.type, lastModified: file.lastModified }) : file;
				});
			};
			return write(0.9);
		}).catch(function () { return file; });
	}

	// Zmenší obrázky a odloží soubory, které by server i tak odmítl (celý požadavek nad limitem by skončil chybou bez vysvětlení).
	function prepareFiles(files) {
		return Promise.all(Array.prototype.map.call(files, shrink)).then(function (finished) {
			var errors = [];
			var ok = finished.filter(function (s) {
				if (!MAX_FILE || s.size <= MAX_FILE) { return true; }
				errors.push(s.name + ': ' + T('Soubor je větší, než server dovoluje nahrát (nejvýš %s). Zmenšete ho, nebo požádejte správce hostingu o vyšší limit.').replace('%s', SCRIPT.getAttribute('data-max-soubor-text') || ''));
				return false;
			});
			if (errors.length) { announce(errors.join('\n')); }
			return ok;
		});
	}

	function upload(selected) {
		return prepareFiles(selected).then(function (files) { return files.length ? send(files) : []; });
	}

	function send(files) {
		var data = new FormData();
		var folder = document.querySelector('.galerie-okno[open] select');
		data.append('_csrf', CSRF);
		data.append('sekce', folder && /^\d+$/.test(folder.value) ? folder.value : '0');
		files.forEach(function (s) { data.append('soubory[]', s); });
		return fetch(GALLERY + '&action=upload&format=json', { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (j.chyby && j.chyby.length) { announce(j.chyby.join('\n')); }
				return j.obrazky || [];
			})
			.catch(function () { announce(T('Nahrání se nezdařilo. Zkontrolujte připojení a zkuste to znovu.')); return []; });
	}

	function hasImages(transfer) {
		return transfer && transfer.files && transfer.files.length && Array.prototype.every.call(transfer.files, function (f) { return /^image\//.test(f.type); });
	}

	/* ---------- okno galerie ---------- */

	var modal = null;

	// vice = true: klepnutím se obrázky označují a vloží se najednou jako fotogalerie
	function pickImage(backwards, more, withAttachments) {
		var selected = [];
		if (!modal) {
			modal = document.createElement('dialog');
			modal.className = 'galerie-okno';
			modal.innerHTML = '<div class="galerie-okno-hlava"><strong>' + T('Média') + '</strong>'
				+ '<label class="tl">' + T('Nahrát nový') + '<input type="file" multiple hidden></label>'
				// na telefonu a tabletu: vyfotit přímo do textu (tlačítko ukazuje CSS jen na dotykových zařízeních)
				+ '<label class="navigace galerie-vyfotit">' + T('Vyfotit') + '<input type="file" accept="image/*" capture="environment" hidden></label>'
				+ '<button type="button" class="tl" data-vlozit hidden></button>' // „Vložit galerii (n)“ – jen při výběru více fotek
				+ '<button type="button" class="navigace" data-zavri>' + T('Zavřít') + '</button></div>'
				+ '<div class="galerie-okno-filtr"><select aria-label="' + T('Složka') + '"></select>'
				+ '<input class="textpole" type="search" placeholder="' + T('Hledat v médiích…') + '" aria-label="' + T('Hledat v médiích') + '"></div>'
				+ '<p class="napoveda"></p><div class="galerie-mrizka"></div>' // text nápovědy se nastavuje při každém otevření;
			document.body.appendChild(modal);
			modal.querySelector('[data-zavri]').addEventListener('click', function () { modal.close(); });
			modal.querySelector('[data-vlozit]').addEventListener('click', function () { modal.close(); modal.zpetne(modal.vybrane.slice()); });
			modal.querySelector('select').addEventListener('change', function () { load(this.value); });
			var waiting = null; // hledá se až po krátké pauze v psaní, ne po každém písmenu
			modal.querySelector('input[type=search]').addEventListener('input', function () {
				clearTimeout(waiting);
				waiting = setTimeout(function () { load(modal.querySelector('select').value); }, 300);
			});
			Array.prototype.forEach.call(modal.querySelectorAll('input[type=file]'), function (inputEl) { inputEl.addEventListener('change', function () {
				upload(this.files).then(function (newItems) { newItems.reverse().forEach(function (o) { add(o, true); }); });
				this.value = '';
			}); });
			modal.addEventListener('dragover', function (e) { e.preventDefault(); });
			modal.addEventListener('drop', function (e) {
				e.preventDefault();
				if (e.dataTransfer.files.length) { upload(e.dataTransfer.files).then(function (newItems) { newItems.reverse().forEach(function (o) { add(o, true); }); }); }
			});
		}
		var grid = modal.querySelector('.galerie-mrizka');
		function add(o, upward) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'galerie-polozka';
			if (o.soubor && !modal.sPrilohami) { return; } // hlavní obrázek, logo, galerie: jen obrázky
			b.innerHTML = o.soubor ? '<span class="galerie-soubor"><span></span></span><span></span>' : '<img loading="lazy" alt=""><span></span>';
			if (o.soubor) { b.firstChild.firstChild.textContent = o.pripona; } else { b.firstChild.src = o.nahled; }
			b.lastChild.textContent = o.nazev || T('bez názvu');
			b.addEventListener('click', function () {
				if (!modal.vice) { modal.close(); modal.zpetne(o); return; }
				var i = modal.vybrane.indexOf(o);
				if (i === -1) { modal.vybrane.push(o); } else { modal.vybrane.splice(i, 1); }
				b.classList.toggle('vybrana', i === -1);
				b.setAttribute('aria-pressed', i === -1 ? 'true' : 'false');
				modal.oznac();
			});
			if (upward) { grid.prepend(b); } else { grid.appendChild(b); }
		}
		// filtr: "" = vše, "clanek" = obrázky této novinky, číslo = složka (0 = nezařazené)
		function load(filter) {
			var query = filter === 'clanek' ? '&clanek=' + NEWS_ID : (filter !== '' ? '&sekce=' + filter : '');
			var search = modal.querySelector('input[type=search]').value.trim();
			if (search !== '') { query += '&hledat=' + encodeURIComponent(search); }
			grid.textContent = T('Načítám…');
			fetch(GALLERY + '&action=listing' + query, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				var selection = modal.querySelector('select');
				selection.textContent = '';
				[['', T('Všechna média')]].concat(NEWS_ID ? [['clanek', T('V tomto textu')]] : [], [['0', T('Nezařazené')]], j.slozky.map(function (s) { return [String(s.id), T('Složka: ') + s.nazev]; })).forEach(function (v) {
					var o = document.createElement('option');
					o.value = v[0]; o.textContent = v[1]; o.selected = v[0] === filter;
					selection.appendChild(o);
				});
				grid.textContent = j.obrazky.length ? '' : (search !== '' ? T('Hledanému textu nic neodpovídá.') : T('Tady zatím žádné obrázky nejsou.'));
				j.obrazky.forEach(function (o) { add(o, false); });
				additional(query, 2, j.obrazky.length);
			});
		}
		// server vrací 60 položek na stránku: plná stránka = nabídnout další, ať jsou dosažitelné i starší soubory
		function additional(query, pageNumber, loaded) {
			if (loaded < 60) { return; }
			var tl = document.createElement('button');
			tl.type = 'button';
			tl.className = 'navigace media-dalsi';
			tl.textContent = T('Načíst další');
			tl.addEventListener('click', function () {
				tl.disabled = true;
				fetch(GALLERY + '&action=listing' + query + '&strana=' + pageNumber, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
					tl.remove();
					j.obrazky.forEach(function (o) { add(o, false); });
					additional(query, pageNumber + 1, j.obrazky.length);
				});
			});
			grid.appendChild(tl);
		}
		modal.zpetne = backwards;
		modal.vice = !!more;
		modal.sPrilohami = !!withAttachments && !more;
		modal.vybrane = selected;
		modal.oznac = function () {
			var tl = modal.querySelector('[data-vlozit]');
			tl.hidden = !modal.vice;
			tl.disabled = modal.vybrane.length < 2;
			tl.textContent = modal.vybrane.length < 2 ? T('Označte aspoň 2 fotky') : T('Vložit galerii (') + modal.vybrane.length + ')';
		};
		modal.oznac();
		modal.querySelector('.napoveda').textContent = more
			? T('Klepnutím označte fotky v pořadí, v jakém mají jít za sebou. Soubory sem můžete i přetáhnout.')
			: T('Klepnutím obrázek vložíte. Soubory sem můžete i přetáhnout - nahrají se do zvolené složky.');
		modal.showModal();
		load(modal.querySelector('select').value || '');
	}

	function imageHtml(o) {
		return '<figure><img src="' + esc(o.url) + '" alt="' + esc(o.nazev) + '" width="' + o.sirka + '" height="' + o.vyska + '" loading="lazy" data-id="' + o.id + '">'
			+ (o.popis ? '<figcaption>' + esc(o.popis) + '</figcaption>' : '') + '</figure><p><br></p>';
	}

	function attachmentHtml(o) {
		return '<p><a href="' + esc(o.url) + '" title="' + esc(o.pripona + ', ' + o.velikost) + '">' + esc(o.nazev || o.pripona) + '</a> (' + esc(o.pripona + ', ' + o.velikost) + ')</p>';
	}

	function galleryHtml(images) {
		return '<figure class="galerie">' + images.map(function (o) {
			return '<img src="' + esc(o.url) + '" alt="' + esc(o.popis || o.nazev) + '" width="' + o.sirka + '" height="' + o.vyska + '" loading="lazy" data-id="' + o.id + '">';
		}).join('') + '</figure><p><br></p>';
	}

	/* ---------- editor ---------- */

	var BUTTONS = [
		['¶', T('Odstavec'), function () { statement('formatBlock', 'P'); }],
		['H2', T('Mezititulek'), function () { statement('formatBlock', 'H2'); }, 'velky'],
		['H3', T('Menší mezititulek'), function () { statement('formatBlock', 'H3'); }, 'velky'],
		['B', T('Tučně (Ctrl+B)'), function () { statement('bold'); }],
		['I', T('Kurzíva (Ctrl+I)'), function () { statement('italic'); }],
		[T('odkaz'), T('Vložit odkaz (Ctrl+K)'), link],
		[T('• seznam'), T('Odrážkový seznam'), function () { statement('insertUnorderedList'); }],
		[T('1. seznam'), T('Číslovaný seznam'), function () { statement('insertOrderedList'); }, 'velky'],
		[T('„citace“'), T('Citace'), function () { statement('formatBlock', 'BLOCKQUOTE'); }, 'velky'],
		[T('obrázek'), T('Vložit obrázek z médií'), null, 'velky'],
		[T('galerie'), T('Vložit fotogalerii - návštěvník si fotky prolistuje přes celou obrazovku'), 'galerie', 'velky'],
		[T('tabulka'), T('Vložit tabulku 3 × 3 se záhlavím; řádky a sloupce pak přidáte tlačítky nad tabulkou'), function () {
			var row = function (tag) { return '<tr><' + tag + '><br></' + tag + '><' + tag + '><br></' + tag + '><' + tag + '><br></' + tag + '></tr>'; };
			statement('insertHTML', '<table><thead>' + row('th') + '</thead><tbody>' + row('td') + row('td') + '</tbody></table><p><br></p>');
		}, 'velky'],
		['—', T('Oddělovací čára'), function () { statement('insertHorizontalRule'); }, 'velky'],
		['Tx', T('Odstranit formátování'), function () { statement('removeFormat'); statement('unlink'); }]
	];

	function statement(name, value) { document.execCommand(name, false, value || null); }

	/* Dialog odkazu: adresa, nebo vlastní novinka vyhledaná podle titulku. Systémový prompt() vestavěné prohlížeče potlačují. */
	var linkDialog = null;

	function link() {
		var selection = window.getSelection();
		var scope = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
		var node = selection.anchorNode && (selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement);
		var anchor = node && node.closest('a');
		var surface = node && node.closest('.editor-plocha');
		if (!surface || !scope) { return; }
		if (!linkDialog) {
			linkDialog = document.createElement('dialog');
			linkDialog.className = 'galerie-okno odkaz-okno';
			linkDialog.innerHTML = '<form method="dialog"><div class="galerie-okno-hlava"><strong>' + T('Odkaz') + '</strong></div>'
				+ '<label>' + T('Adresa') + '<input class="textpole siroke" type="text" name="adresa" placeholder="https://… ' + T('nebo') + ' /o-nas" autocomplete="off"></label>'
				+ '<label>' + T('…nebo najděte novinku webu') + '<input class="textpole siroke" type="search" name="hledat" placeholder="' + T('část titulku') + '" autocomplete="off"></label>'
				+ '<div class="odkaz-vysledky" aria-live="polite"></div>'
				+ '<label class="odkaz-volba"><input type="checkbox" name="nove"> ' + T('otevřít v novém okně') + '</label>'
				+ '<div class="odkaz-tlacitka"><button type="submit" class="tl" value="ok">' + T('Vložit odkaz') + '</button> <button type="button" class="navigace" data-zrusit>' + T('Zrušit odkaz') + '</button> <button type="button" class="navigace" data-zavri>' + T('Zavřít') + '</button></div></form>';
			document.body.appendChild(linkDialog);
			var timer = null;
			linkDialog.querySelector('[name=hledat]').addEventListener('input', function () {
				var q = this.value.trim(), results = linkDialog.querySelector('.odkaz-vysledky');
				clearTimeout(timer);
				if (q.length < 2) { results.textContent = ''; return; }
				timer = setTimeout(function () {
					fetch(ADMIN + '?module=news&action=search_json&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
						results.textContent = j.clanky.length ? '' : T('Nic nenalezeno.');
						j.clanky.forEach(function (c) {
							var b = document.createElement('button');
							b.type = 'button';
							b.textContent = c.titulek + (c.vydany ? '' : ' (' + T('nevydaný') + ')');
							b.addEventListener('click', function () { linkDialog.querySelector('[name=adresa]').value = c.url; linkDialog.querySelector('[name=adresa]').focus(); });
							results.appendChild(b);
						});
					});
				}, 250);
			});
			linkDialog.querySelector('[data-zavri]').addEventListener('click', function () { linkDialog.close('zavrit'); });
			linkDialog.querySelector('[data-zrusit]').addEventListener('click', function () { linkDialog.close('zrusit'); });
			linkDialog.addEventListener('close', function () { linkDialog.hotovo(linkDialog.returnValue); });
		}
		var f = linkDialog.querySelector('form');
		f.adresa.value = anchor ? anchor.getAttribute('href') : '';
		f.hledat.value = '';
		f.nove.checked = !!(anchor && anchor.target === '_blank');
		linkDialog.querySelector('.odkaz-vysledky').textContent = '';
		linkDialog.querySelector('[data-zrusit]').hidden = !anchor;
		linkDialog.returnValue = '';
		linkDialog.hotovo = function (result) {
			surface.focus();
			selection.removeAllRanges();
			if (anchor) { var r = document.createRange(); r.selectNode(anchor); selection.addRange(r); } else { selection.addRange(scope); }
			var url = f.adresa.value.trim();
			if (result === 'zrusit') { statement('unlink'); } else if (result === 'ok' && url !== '' && !/^\s*javascript:/i.test(url)) {
				var target = f.nove.checked ? ' target="_blank" rel="noopener"' : '';
				var text = selection.isCollapsed ? url : (anchor ? anchor.innerHTML : selection.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;'));
				statement('insertHTML', '<a href="' + url.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"' + target + '>' + (anchor || !selection.isCollapsed ? text : url.replace(/&/g, '&amp;').replace(/</g, '&lt;')) + '</a>');
			}
			surface.dispatchEvent(new Event('input', { bubbles: true }));
		};
		linkDialog.showModal();
		f.adresa.focus();
	}

	function createEditor(field) {
		var small = field.getAttribute('data-editor') === 'maly';
		var wrapper = document.createElement('div');
		wrapper.className = 'editor' + (small ? ' editor-maly' : '');
		var tabList = document.createElement('div');
		tabList.className = 'editor-nastroje';
		tabList.setAttribute('role', 'toolbar');
		var surface = document.createElement('div');
		surface.className = 'editor-plocha';
		surface.contentEditable = 'true';
		surface.style.setProperty('--ed-popis-galerie', JSON.stringify(T('Fotogalerie'))); // štítek nad fotogalerií kreslí editor.css; text v CSS by přeložit nešel
		surface.setAttribute('role', 'textbox');
		surface.setAttribute('aria-multiline', 'true');
		surface.setAttribute('aria-label', (field.labels && field.labels[0] ? field.labels[0].textContent : 'Text'));
		var state = document.createElement('div');
		state.className = 'editor-stav';
		var source = false;

		function toField() { if (!source) { field.value = cleanHtml(surface.innerHTML); } count(); field.dispatchEvent(new Event('input', { bubbles: true })); }
		function fromField() { surface.innerHTML = field.value.trim() || '<p><br></p>'; }
		function count() {
			var wordCount = (surface.innerText.trim().match(/\S+/g) || []).length;
			state.firstChild.textContent = wordCount + T(' slov') + (small ? '' : T(' · čtení asi ') + Math.max(1, Math.round(wordCount / 200)) + ' min');
		}
		function insertImage(gallery) {
			var scope = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;
			pickImage(function (o) {
				surface.focus();
				if (scope && surface.contains(scope.startContainer)) { window.getSelection().removeAllRanges(); window.getSelection().addRange(scope); }
				statement('insertHTML', gallery ? galleryHtml(o) : (o.soubor ? attachmentHtml(o) : imageHtml(o)));
				toField();
			}, gallery, true);
		}

		BUTTONS.forEach(function (t) {
			if (small && t[3] === 'velky') { return; }
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = t[0];
			b.title = t[1];
			if (t[0] === 'B') { b.style.fontWeight = 'bold'; }
			if (t[0] === 'I') { b.style.fontStyle = 'italic'; }
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			b.addEventListener('click', function () { if (source) { return; } surface.focus(); if (typeof t[2] === 'function') { t[2](); } else { insertImage(t[2] === 'galerie'); } toField(); });
			tabList.appendChild(b);
		});
		var html = document.createElement('button');
		html.type = 'button';
		html.textContent = 'HTML';
		html.title = T('Přepnout na zdrojový kód');
		html.className = 'editor-html';
		html.setAttribute('aria-pressed', 'false');
		html.addEventListener('click', function () {
			source = !source;
			if (source) { field.value = cleanHtml(surface.innerHTML).replace(/<\/(p|h2|h3|h4|ul|ol|li|blockquote|figure)>/g, '</$1>\n'); } else { fromField(); }
			wrapper.classList.toggle('editor-zdroj', source);
			html.setAttribute('aria-pressed', source ? 'true' : 'false');
			(source ? field : surface).focus();
		});
		tabList.appendChild(html);
		state.appendChild(document.createElement('span'));
		state.appendChild(document.createElement('span'));

		field.parentNode.insertBefore(wrapper, field);
		wrapper.appendChild(tabList);
		wrapper.appendChild(surface);
		wrapper.appendChild(field);
		wrapper.appendChild(state);
		field.classList.add('editor-pole');
		fromField();
		statement('defaultParagraphSeparator', 'p');

		// úpravy tabulky: lišta se ukáže, když je kurzor v tabulce
		var tabBar = document.createElement('div');
		tabBar.className = 'editor-tabulka-lista';
		tabBar.hidden = true;
		var cell = function () {
			var node = window.getSelection().anchorNode;
			node = node && (node.nodeType === 1 ? node : node.parentElement);
			var b = node && node.closest('td, th');
			return b && surface.contains(b) ? b : null;
		};
		[[T('+ řádek'), function (b) {
			var fresh = b.parentNode.cloneNode(true);
			Array.prototype.forEach.call(fresh.children, function (c) { var td = document.createElement('td'); td.innerHTML = '<br>'; c.replaceWith(td); });
			var body = b.closest('table').querySelector('tbody') || b.closest('table');
			if (b.parentNode.parentNode.tagName === 'THEAD') { body.prepend(fresh); } else { b.parentNode.after(fresh); }
		}], [T('+ sloupec'), function (b) {
			var i = b.cellIndex;
			Array.prototype.forEach.call(b.closest('table').rows, function (r) { var c = document.createElement(r.cells[i].tagName); c.innerHTML = '<br>'; r.cells[i].after(c); });
		}], [T('− řádek'), function (b) {
			var t = b.closest('table');
			if (t.rows.length > 1) { b.parentNode.remove(); } else { t.remove(); }
		}], [T('− sloupec'), function (b) {
			var i = b.cellIndex, t = b.closest('table');
			if (t.rows[0].cells.length > 1) { Array.prototype.forEach.call(t.rows, function (r) { r.deleteCell(i); }); } else { t.remove(); }
		}], [T('smazat tabulku'), function (b) { b.closest('table').remove(); }]].forEach(function (a) {
			var tl = document.createElement('button');
			tl.type = 'button';
			tl.textContent = a[0];
			tl.addEventListener('mousedown', function (e) { e.preventDefault(); });
			tl.addEventListener('click', function () { var b = cell(); if (b) { a[1](b); toField(); tabBar.hidden = !cell(); } });
			tabBar.appendChild(tl);
		});
		tabList.after(tabBar);
		document.addEventListener('selectionchange', function () { tabBar.hidden = source || !cell(); });

		surface.addEventListener('input', toField);
		surface.addEventListener('blur', toField);
		surface.addEventListener('keydown', function (e) {
			// stopPropagation: stejnou zkratku má paleta příkazů (admin.js) - v editoru znamená „vložit odkaz“
			if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); e.stopPropagation(); link(); toField(); }
		});
		surface.addEventListener('paste', function (e) {
			var transfer = e.clipboardData;
			if (hasImages(transfer)) {
				e.preventDefault();
				upload(transfer.files).then(function (newItems) { newItems.forEach(function (o) { statement('insertHTML', imageHtml(o)); }); toField(); });
				return;
			}
			var inserted = transfer.getData('text/html');
			if (inserted) { e.preventDefault(); statement('insertHTML', cleanHtml(inserted)); toField(); }
		});
		surface.addEventListener('dragover', function (e) { if (hasImages(e.dataTransfer) || (e.dataTransfer.types || []).indexOf('Files') !== -1) { e.preventDefault(); wrapper.classList.add('editor-pretazeni'); } });
		surface.addEventListener('dragleave', function () { wrapper.classList.remove('editor-pretazeni'); });
		surface.addEventListener('drop', function (e) {
			wrapper.classList.remove('editor-pretazeni');
			// jiný soubor než obrázek (PDF…): nenahrává se, ale prohlížeč ho nesmí otevřít místo formuláře - rozepsaný text by byl pryč
			if (!hasImages(e.dataTransfer)) { if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); } return; }
			e.preventDefault();
			upload(e.dataTransfer.files).then(function (newItems) { surface.focus(); newItems.forEach(function (o) { statement('insertHTML', imageHtml(o)); }); toField(); });
		});
		if (field.form) { field.form.addEventListener('submit', function () { if (!source) { field.value = cleanHtml(surface.innerHTML); } }); }
		count();
		// pomocník editoru (kontrola přístupnosti, AI asistent) po zápisu do pole editor překreslí
		window.kaletaEditory = window.kaletaEditory || {};
		if (field.id) { window.kaletaEditory[field.id] = { obnov: function () { fromField(); count(); } }; }
		return { obnov: fromField, stav: state.lastChild };
	}

	/* ---------- automatické ukládání rozepsaného textu do prohlížeče ---------- */

	function autosave(form, editors) {
		var key = 'kaleta-koncept:' + form.getAttribute('data-koncept');
		var field = Array.prototype.filter.call(form.elements, function (p) { return p.name && p.name !== '_csrf' && p.type !== 'password' && p.type !== 'file' && p.type !== 'submit'; });
		var timer = null;

		function save() {
			var data = { cas: Date.now(), pole: {} };
			field.forEach(function (p) { if (p.type === 'checkbox' || p.type === 'radio') { if (p.checked) { data.pole[p.name] = p.value; } else if (p.type === 'checkbox') { data.pole[p.name] = null; } } else { data.pole[p.name] = p.value; } });
			try { localStorage.setItem(key, JSON.stringify(data)); } catch (e) { /* prohlížeč úložiště nedovolil - zbývá server */ }
			editors.forEach(function (ed) { ed.stav.textContent = T('rozepsaný text uložen v prohlížeči ') + time(Date.now(), true); });
			lastData = data;
			if (!serverTimer) { serverTimer = setTimeout(saveToServer, 15000); }
		}
		// na server jde rozepsaný stav nejvýš jednou za 15 vteřin: dá se v něm pokračovat z jiného zařízení
		var draftUrl = form.getAttribute('data-koncept-url'), serverTimer = null, lastData = null;
		function saveToServer() {
			serverTimer = null;
			if (!draftUrl || !lastData) { return; }
			var fd = new FormData();
			fd.append('_csrf', CSRF);
			fd.append('idc', String(NEWS_ID || 0));
			fd.append('pole', JSON.stringify(lastData.pole));
			fetch(draftUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				if (j.ok) { editors.forEach(function (ed) { ed.stav.textContent = T('rozepsaný text uložen i na serveru ') + time(Date.now(), true); }); }
			}).catch(function () { /* bez spojení zůstává kopie v prohlížeči */ });
		}
		function discardOnServer() {
			if (!draftUrl) { return; }
			var fd = new FormData();
			fd.append('_csrf', CSRF);
			fd.append('idc', String(NEWS_ID || 0));
			fetch(draftUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () { /* nic */ });
		}
		form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(save, 1500); });
		// při odeslání se kopie nemaže, jen označí: když uložení selže (vypršelé přihlášení, výpadek spojení), text zůstane k obnovení.
		// Smaže se až po potvrzeném uložení (hláška o úspěchu, image/admin.js), nebo když se shoduje s uloženým obsahem.
		form.addEventListener('submit', function () { clearTimeout(timer); save(); try { var d = JSON.parse(localStorage.getItem(key) || 'null'); if (d) { d.odeslano = Date.now(); localStorage.setItem(key, JSON.stringify(d)); } } catch (e) { /* nic */ } });

		var storedForm = null;
		try { storedForm = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { /* nic */ }
		// novější z obou kopií: prohlížeč tohoto zařízení, nebo server (psaní z jiného zařízení)
		var fromServer = null;
		try { fromServer = JSON.parse((document.getElementById('koncept-server') || {}).textContent || 'null'); } catch (e) { /* nic */ }
		var isFromServer = !!(fromServer && fromServer.pole && (!storedForm || !storedForm.cas || fromServer.cas > storedForm.cas));
		if (isFromServer) { storedForm = fromServer; }
		if (!storedForm || !storedForm.pole || Date.now() - storedForm.cas > 14 * 86400000) { return; }
		var differs = field.some(function (p) { return (p.tagName === 'TEXTAREA' || p.type === 'text') && storedForm.pole[p.name] !== undefined && storedForm.pole[p.name] !== p.value; });
		if (!differs) { if (!isFromServer) { try { localStorage.removeItem(key); } catch (e) { /* nic */ } } return; }
		var tabList = document.createElement('p');
		tabList.className = 'hlaska';
		tabList.innerHTML = T(isFromServer ? 'Na serveru je neuložená rozepsaná verze z ' : 'V prohlížeči je neuložená rozepsaná verze z ') + time(storedForm.cas) + '. <button type="button" class="navigace">' + T('Obnovit ji') + '</button> <button type="button" class="navigace">' + T('Zahodit') + '</button>';
		form.parentNode.insertBefore(tabList, form);
		tabList.children[0].addEventListener('click', function () {
			field.forEach(function (p) {
				if (!(p.name in storedForm.pole)) { return; }
				if (p.type === 'checkbox') { p.checked = storedForm.pole[p.name] !== null; } else if (p.type === 'radio') { p.checked = p.value === storedForm.pole[p.name]; } else { p.value = storedForm.pole[p.name]; }
			});
			editors.forEach(function (ed) { ed.obnov(); });
			document.querySelectorAll('[data-obrazek]').forEach(function (p) { p.dispatchEvent(new Event('change')); });
			tabList.remove();
		});
		tabList.children[1].addEventListener('click', function () { try { localStorage.removeItem(key); } catch (e) { /* nic */ } discardOnServer(); tabList.remove(); });
	}

	/* ---------- pole "Hlavní obrázek" ---------- */

	document.querySelectorAll('[data-obrazek]').forEach(function (field) {
		var tl = document.createElement('button');
		tl.type = 'button';
		tl.className = 'navigace';
		tl.textContent = T('Vybrat z médií');
		var preview = document.createElement('img');
		preview.className = 'obrazek-nahled';
		preview.alt = '';
		function show() { preview.hidden = field.value.trim() === ''; if (!preview.hidden) { preview.src = field.value; } }
		field.after(tl, preview);
		tl.addEventListener('click', function () { pickImage(function (o) { field.value = o.url; show(); field.dispatchEvent(new Event('input', { bubbles: true })); }); });
		field.addEventListener('change', show);
		preview.addEventListener('error', function () { preview.hidden = true; });
		show();
	});

	/* ---------- nahrávání přetažením na stránce galerie ---------- */

	document.querySelectorAll('[data-nahravani]').forEach(function (form) {
		var inputEl = form.querySelector('input[type=file]');
		form.addEventListener('dragover', function (e) { e.preventDefault(); form.classList.add('nahravani-aktivni'); });
		form.addEventListener('dragleave', function () { form.classList.remove('nahravani-aktivni'); });
		form.addEventListener('drop', function (e) {
			e.preventDefault();
			form.classList.remove('nahravani-aktivni');
			if (e.dataTransfer.files.length) { inputEl.files = e.dataTransfer.files; form.requestSubmit(); }
		});
		// před odesláním zmenšit fotky a odložit soubory nad limit serveru (form.submit() už tuto obsluhu nespustí)
		form.addEventListener('submit', function (e) {
			if (!window.DataTransfer) { return; }
			e.preventDefault();
			var button = form.querySelector('[type=submit]');
			if (button) { button.disabled = true; }
			prepareFiles(inputEl.files).then(function (files) {
				if (button) { button.disabled = false; }
				if (!files.length) { inputEl.value = ''; return; }
				var transfer = new DataTransfer();
				files.forEach(function (s) { transfer.items.add(s); });
				inputEl.files = transfer.files;
				form.submit();
			});
		});
	});

	window.kaletaVytvorEditor = createEditor; // builder stránek si editor vytváří sám nad dynamickým polem
	window.kaletaVyberObrazek = pickImage; // výběr obrázku z Médií pro builder (zpětné volání dostane {url, nazev, …})

	var editors = Array.prototype.map.call(document.querySelectorAll('textarea[data-editor]'), createEditor);
	var draftForm = document.querySelector('form[data-koncept]');
	if (draftForm) { autosave(draftForm, editors); }
})();
