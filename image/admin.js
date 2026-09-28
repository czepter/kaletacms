/* Kaleta - drobnosti administrace. Bez knihoven, bez build kroku. */

(function () {
	'use strict';

	// překlad textů skriptů administrace: slovník window.KALETA_PREKLAD dodá image/jazyky/admin-<kód>.js, čeština ho nemá
	window.T = function (s) { return (window.KALETA_PREKLAD || {})[s] || s; };
	var T = window.T;

	// datum a čas jako datum() v PHP, v časovém pásmu webu (<html data-pasmo>): česky 25. 9. 2026 09:31, anglicky 25 Sep 2026 09:31
	window.kaletaCas = function (time, timeOnly) {
		var c = {};
		var format = function (timeZone) {
			new Intl.DateTimeFormat('en-GB', { timeZone: timeZone, year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
				.formatToParts(new Date(time)).forEach(function (p) { c[p.type] = p.value; });
		};
		try { format(document.documentElement.getAttribute('data-pasmo') || undefined); } catch (e) { format(undefined); }
		var parseOpeningHours = c.hour + ':' + c.minute;
		if (timeOnly) { return parseOpeningHours; }
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		return (document.documentElement.lang === 'en' ? c.day + ' ' + months[c.month - 1] + ' ' + c.year : c.day + '. ' + c.month + '. ' + c.year) + ' ' + parseOpeningHours;
	};

	// Potvrzení nevratných akcí: data-potvrdit="text" na formuláři nebo tlačítku.
	// Vlastní dialog místo window.confirm(), který vestavěné prohlížeče (např. v aplikacích) potichu potlačují.
	var confirmDialog = null;
	document.addEventListener('submit', function (e) {
		var form = e.target, button = e.submitter;
		var text = (button && button.getAttribute('data-potvrdit')) || form.getAttribute('data-potvrdit');
		if (!text || form.potvrzeno) { return; }
		e.preventDefault();
		if (!confirmDialog) {
			confirmDialog = document.createElement('dialog');
			confirmDialog.className = 'potvrzeni';
			confirmDialog.innerHTML = '<p></p><div><button type="button" class="tl" data-ano>' + T('Ano, provést') + '</button> <button type="button" class="navigace" data-ne>' + T('Zrušit') + '</button></div>';
			document.body.appendChild(confirmDialog);
			confirmDialog.querySelector('[data-ne]').addEventListener('click', function () { confirmDialog.close(); });
		}
		confirmDialog.querySelector('p').textContent = text;
		confirmDialog.querySelector('[data-ano]').onclick = function () {
			confirmDialog.close();
			form.potvrzeno = true;
			if (form.requestSubmit) { form.requestSubmit(button || undefined); } else { form.submit(); }
			form.potvrzeno = false;
		};
		confirmDialog.showModal();
		confirmDialog.querySelector('[data-ne]').focus();
	});

	// Světlý / tmavý režim (výchozí podle systému, volba se pamatuje v prohlížeči; před vykreslením ji nastaví tema.js)
	var schemeButton = document.querySelector('[data-tema-prepinac]');
	if (schemeButton) {
		schemeButton.addEventListener('click', function () {
			var root = document.documentElement;
			var dark = root.getAttribute('data-tema') ? root.getAttribute('data-tema') === 'tmavy' : window.matchMedia('(prefers-color-scheme: dark)').matches;
			root.setAttribute('data-tema', dark ? 'svetly' : 'tmavy');
			try { localStorage.setItem('kaleta-tema', dark ? 'svetly' : 'tmavy'); } catch (e) { /* nic */ }
		});
	}

	// Rozbalení menu na mobilu
	var toggle = document.querySelector('.menu-prepinac');
	if (toggle) {
		toggle.addEventListener('click', function () {
			var openItems = document.body.classList.toggle('menu-otevrene');
			toggle.setAttribute('aria-expanded', openItems ? 'true' : 'false');
		});
	}

	// Záložky uvnitř jedné stránky (Vzhled webu): šipky, Home a End; po uložení se vrátí poslední záložka; pole, které
	// neprojde kontrolou prohlížeče, ukáže svou záložku. Bez skriptu jsou vidět všechny panely pod sebou.
	document.querySelectorAll('[data-zalozky]').forEach(function (wrapper) {
		var buttons = Array.prototype.slice.call(wrapper.querySelectorAll('[role="tab"]'));
		var panels = buttons.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
		var shouldSave = wrapper.querySelector('.vzhled-ulozit');
		var key = 'ka-zalozka' + location.search;
		var show = function (i, focusTarget) {
			buttons.forEach(function (t, j) {
				t.setAttribute('aria-selected', i === j ? 'true' : 'false');
				t.tabIndex = i === j ? 0 : -1;
				if (panels[j]) { panels[j].hidden = i !== j; }
			});
			if (shouldSave) { shouldSave.hidden = !wrapper.querySelector('.vzhled-formular').contains(panels[i]); } // import a export mají vlastní tlačítka
			if (focusTarget) { buttons[i].focus(); }
			try { sessionStorage.setItem(key, buttons[i].id); } catch (error) { /* soukromý režim */ }
		};
		buttons.forEach(function (t, i) {
			t.addEventListener('click', function () { show(i, false); });
			t.addEventListener('keydown', function (e) {
				var target = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: buttons.length - 1 }[e.key];
				if (target === undefined) { return; }
				e.preventDefault();
				show((target + buttons.length) % buttons.length, true);
			});
		});
		wrapper.addEventListener('invalid', function (e) {
			var i = panels.indexOf(e.target.closest('[role="tabpanel"]'));
			if (i >= 0) { show(i, false); }
		}, true);
		var storedValue = null;
		try { storedValue = sessionStorage.getItem(key); } catch (error) { /* soukromý režim */ }
		show(Math.max(0, buttons.findIndex(function (t) { return t.id === storedValue; })), false);
	});

	// Vzhled webu: předvolby a živý náhled skutečné úvodní stránky. CSS tokenů počítá server (akce nahled) – jediný výpočet v PHP.
	var appearance = document.querySelector('[data-vzhled]');
	if (appearance) {
		var preview = document.querySelector('[data-nahled]'), frame2 = document.querySelector('[data-ramec]');
		var device = 'pocitac', timer = null, lastCss = '';
		var insertCss = function () {
			var doc = preview.contentDocument;
			if (!doc || !doc.head || lastCss === '') { return; }
			var style = doc.getElementById('ka-vzhled-nahled');
			if (!style) { style = doc.createElement('style'); style.id = 'ka-vzhled-nahled'; doc.head.appendChild(style); }
			style.textContent = lastCss;
		};
		var recalculate = function () {
			var data = new FormData(appearance);
			fetch(appearance.getAttribute('data-nahled-url'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					lastCss = j.css;
					insertCss();
					appearance.querySelector('[data-kontrasty]').innerHTML = j.kontrasty.map(function (k) {
						var li = document.createElement('li');
						li.className = k.ok ? 'ok' : 'spatne';
						li.innerHTML = '<span></span><strong></strong>';
						li.firstChild.textContent = k.popis;
						li.lastChild.textContent = T('%s : 1').replace('%s', k.pomer.toLocaleString(document.documentElement.lang || 'cs', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));
						return li.outerHTML;
					}).join('');
				})
				.catch(function () {});
		};
		var change = function (e) {
			if (e && e.target && e.target.type === 'color') { e.target.parentNode.querySelector('[data-hex]').textContent = e.target.value; }
			appearance.querySelector('[data-neulozeno]').hidden = false;
			clearTimeout(timer);
			timer = setTimeout(recalculate, 180);
		};
		appearance.addEventListener('input', change);
		appearance.addEventListener('change', change);
		preview.addEventListener('load', insertCss);
		// předvolba vyplní formulář (velikosti jsou ve formuláři v px, v design systému v rem)
		appearance.querySelectorAll('[data-predvolba]').forEach(function (tl) {
			tl.addEventListener('click', function () {
				var ds = JSON.parse(tl.getAttribute('data-predvolba'));
				Object.keys(ds).forEach(function (key) {
					if (typeof ds[key] === 'object') {
						Object.keys(ds[key]).forEach(function (b) {
							var field = appearance.elements['ds[' + key + '][' + b + ']'];
							if (field) { field.value = ds[key][b]; field.parentNode.querySelector('[data-hex]').textContent = ds[key][b]; }
						});
						return;
					}
					var field = appearance.elements['ds[' + key + ']'];
					if (!field) { return; }
					var value = ['zaklad_min', 'zaklad_max', 'sirka', 'sirka_textu'].indexOf(key) !== -1 ? Math.round(ds[key] * 16) : ds[key];
					if (field instanceof RadioNodeList) { field.value = String(value); return; }
					if (field.tagName === 'SELECT') {
						Array.prototype.forEach.call(field.options, function (o) { if (Math.abs(parseFloat(o.value) - value) < 0.001 || o.value === String(value)) { field.value = o.value; } });
						return;
					}
					field.value = value;
				});
				// jako ruční změna: přepočítá náhled a formulář bude hlídat odchod bez uložení
				appearance.dispatchEvent(new Event('input', { bubbles: true }));
			});
		});
		// počítač se vykresluje v šířce 1280 px a zmenší se do rámu, aby platily skutečné breakpointy webu
		var dimension = function () {
			var width = device === 'mobil' ? 390 : 1280, available = frame2.clientWidth, scale = Math.min(1, available / width);
			preview.style.width = width + 'px';
			preview.style.height = (frame2.clientHeight / scale) + 'px';
			preview.style.transform = 'scale(' + scale + ')';
			preview.style.left = Math.max(0, (available - width * scale) / 2) + 'px';
		};
		document.querySelectorAll('[data-zarizeni]').forEach(function (tl) {
			tl.addEventListener('click', function () {
				device = tl.getAttribute('data-zarizeni');
				document.querySelectorAll('[data-zarizeni]').forEach(function (b) { b.setAttribute('aria-pressed', b === tl ? 'true' : 'false'); });
				dimension();
			});
		});
		if (window.ResizeObserver) { new ResizeObserver(dimension).observe(frame2); }
		dimension();
	}

	// Obecné: volba s data-prepni="sekce:1" ukáže (nebo :0 skryje) část formuláře označenou data-sekce="sekce"
	document.querySelectorAll('[data-prepni]').forEach(function (choice) {
		choice.addEventListener('change', function () {
			var p = choice.getAttribute('data-prepni').split(':');
			document.querySelectorAll('[data-sekce="' + p[0] + '"]').forEach(function (s) { s.hidden = p[1] !== '1'; });
		});
	});

	// Obecné: formulář s data-prepinac="pole" ukazuje jen řádky, jejichž data-pro obsahuje zvolenou hodnotu pole
	document.querySelectorAll('form[data-prepinac]').forEach(function (form) {
		var displayName = form.getAttribute('data-prepinac');
		var switchTo = function () {
			var chosen = form.querySelector('[name="' + displayName + '"]:checked') || form.querySelector('select[name="' + displayName + '"]');
			form.querySelectorAll('[data-pro]').forEach(function (row) { row.hidden = !chosen || row.getAttribute('data-pro').split(' ').indexOf(chosen.value) === -1; });
		};
		form.addEventListener('change', function (e) { if (e.target.name === displayName) { switchTo(); } });
		switchTo();
	});

	// Výpis na telefonu jako karty: buňka dostane popisek sloupce z hlavičky (ukáže ho CSS jen v úzkém okně). Role tabulky se
	// doplní výslovně – prohlížeče je jinak při display: block zahazují a čtečka by přišla o sloupce.
	document.querySelectorAll('table.vypis').forEach(function (tab) {
		if (!tab.tHead || !tab.tHead.rows.length) { return; }
		var headers = Array.prototype.map.call(tab.tHead.rows[0].cells, function (th) { th.setAttribute('role', 'columnheader'); return th.textContent.trim(); });
		tab.classList.add('vypis-karty');
		tab.setAttribute('role', 'table');
		Array.prototype.forEach.call(tab.querySelectorAll('thead, tbody'), function (group) { group.setAttribute('role', 'rowgroup'); });
		Array.prototype.forEach.call(tab.rows, function (tr) { tr.setAttribute('role', 'row'); });
		Array.prototype.forEach.call(tab.tBodies, function (body) {
			Array.prototype.forEach.call(body.rows, function (tr) {
				Array.prototype.forEach.call(tr.cells, function (td, i) {
					td.setAttribute('role', td.tagName === 'TH' ? 'rowheader' : 'cell');
					if (headers[i]) { td.setAttribute('data-popisek', headers[i]); }
				});
			});
		});
	});

	// Varování před opuštěním rozepsaného formuláře
	document.querySelectorAll('form.formular').forEach(function (form) {
		var changed = false;
		form.addEventListener('input', function () { changed = true; });
		form.addEventListener('change', function () { changed = true; });
		form.addEventListener('submit', function () { changed = false; });
		window.addEventListener('beforeunload', function (e) {
			if (changed) { e.preventDefault(); e.returnValue = ''; }
		});
	});
	/* ---------- drobné obsluhy místo inline skriptů (administrace má Content-Security-Policy bez 'unsafe-inline') ---------- */

	// data-aktivni-kdyz="pole=hodnota": pole uvnitř bloku jsou aktivní, jen když má pole formuláře danou hodnotu
	// (počet dní jen u četnosti „jednou za N dní“, výběr stránek jen u „jen na vybraných místech“)
	var dependent = document.querySelectorAll('[data-aktivni-kdyz]');
	var refreshDependent = function () {
		dependent.forEach(function (block) {
			var condition = block.getAttribute('data-aktivni-kdyz').split('=');
			var field = block.closest('form') && block.closest('form').elements[condition[0]];
			// zaškrtávací políčko: hodnota jen, když je zaškrtnuté („zobrazit=“ = nezaškrtnuté)
			var value = field && field.type === 'checkbox' ? (field.checked ? field.value : '') : (field ? field.value : '');
			var isEnabled = !field || value === condition[1];
			block.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !isEnabled; });
			block.classList.toggle('neaktivni', !isEnabled);
		});
	};
	if (dependent.length) { document.addEventListener('change', refreshDependent); refreshDependent(); }

	// záhlaví číselného sloupce se zarovná jako čísla pod ním (buňky td.cislo v prvním řádku)
	document.querySelectorAll('table.vypis').forEach(function (table) {
		var row = table.tBodies[0] && table.tBodies[0].rows[0];
		var header = table.tHead && table.tHead.rows[0];
		if (!row || !header || row.cells.length !== header.cells.length) { return; }
		Array.prototype.forEach.call(row.cells, function (cell, i) { if (cell.classList.contains('cislo')) { header.cells[i].classList.add('cislo'); } });
	});

	document.addEventListener('change', function (e) {
		var element = e.target;
		if (element.hasAttribute && element.hasAttribute('data-odeslat-pri-zmene') && element.form) { element.form.submit(); }
		if (element.hasAttribute && element.hasAttribute('data-ukaz-heslo')) {
			var password = document.getElementById(element.getAttribute('data-ukaz-heslo'));
			if (password) { password.type = element.checked ? 'text' : 'password'; }
		}
	});
	document.addEventListener('click', function (e) {
		if (e.target.closest && e.target.closest('[data-neklikat]')) { e.preventDefault(); }
	});
	/* ---------- paleta příkazů: Ctrl/⌘+K – sekce, rychlé akce a hledání novinky ---------- */

	var palette = document.getElementById('paleta');
	if (palette && typeof palette.showModal === 'function') {
		var popupFields = palette.querySelector('.paleta-pole');
		var popupList = palette.querySelector('.paleta-seznam');
		var popupCommands = [];
		try { popupCommands = JSON.parse(document.getElementById('paleta-data').textContent) || []; } catch (e) {}
		var popupNews = [];
		var popupSelected = 0;
		var popupTimer = null;
		var removeDiacritics = function (t) { return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

		var popupItems = function () {
			var q = removeDiacritics(popupFields.value.trim());
			var words = q.split(/\s+/).filter(Boolean);
			var statements = popupCommands.filter(function (p) {
				var whereParts = removeDiacritics(p.n + ' ' + p.s);
				return words.every(function (s) { return whereParts.indexOf(s) !== -1; });
			});
			return (q === '' ? statements.slice(0, 9) : statements.slice(0, 7)).concat(q === '' ? [] : popupNews);
		};
		var popupRender = function () {
			var items = popupItems();
			popupSelected = Math.max(0, Math.min(popupSelected, items.length - 1));
			popupList.textContent = '';
			items.forEach(function (p, i) {
				var li = document.createElement('li');
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', i === popupSelected ? 'true' : 'false');
				var a = document.createElement('a');
				a.href = p.u;
				a.textContent = p.n;
				var s = document.createElement('small');
				s.textContent = p.s;
				a.appendChild(s);
				li.appendChild(a);
				li.addEventListener('mousemove', function () { if (popupSelected !== i) { popupSelected = i; popupRender(); } });
				popupList.appendChild(li);
			});
			if (items.length === 0) {
				var nothing = document.createElement('li');
				nothing.className = 'paleta-nic';
				nothing.textContent = T('Nic takového tu není.');
				popupList.appendChild(nothing);
			}
			var selected = popupList.querySelector('[aria-selected="true"]');
			if (selected && selected.scrollIntoView) { selected.scrollIntoView({ block: 'nearest' }); }
		};
		var popupOpen = function () {
			if (palette.open) { return; }
			popupFields.value = '';
			popupNews = [];
			popupSelected = 0;
			popupRender();
			palette.showModal();
			popupFields.focus();
		};

		document.addEventListener('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault();
				if (palette.open) { palette.close(); } else { popupOpen(); }
			}
		});
		document.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-paleta]')) { popupOpen(); }
			if (e.target === palette) { palette.close(); } // klik mimo okno
		});
		popupFields.addEventListener('input', function () {
			popupSelected = 0;
			popupRender();
			clearTimeout(popupTimer);
			var q = popupFields.value.trim();
			var address = palette.getAttribute('data-clanky');
			if (!address || q.length < 2) { popupNews = []; return; }
			popupTimer = setTimeout(function () {
				fetch(address + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
					if (popupFields.value.trim() !== q) { return; } // mezitím se psalo dál
					popupNews = (d.clanky || []).map(function (c) { return { n: c.titulek, u: c.url, s: c.vydany ? T('novinka') : T('novinka – nevydaná') }; });
					popupRender();
				}).catch(function () {});
			}, 200);
		});
		popupFields.addEventListener('keydown', function (e) {
			var count = popupItems().length;
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				popupSelected = count === 0 ? 0 : (popupSelected + (e.key === 'ArrowDown' ? 1 : count - 1)) % count;
				popupRender();
			} else if (e.key === 'Enter') {
				e.preventDefault();
				var target = popupList.querySelector('[aria-selected="true"] a');
				if (target) { window.location.href = target.href; }
			}
		});
		// na Macu ukázat ⌘K
		if (/Mac|iPhone|iPad/.test(navigator.platform || '')) {
			Array.prototype.forEach.call(document.querySelectorAll('[data-paleta] kbd'), function (k) { k.textContent = '⌘K'; });
		}
	}
	// Rozbalovací nabídky (<details data-zavrit-mimo>): zavře je klepnutí mimo a klávesa Esc
	document.addEventListener('click', function (e) {
		Array.prototype.forEach.call(document.querySelectorAll('details[data-zavrit-mimo][open]'), function (d) {
			if (!d.contains(e.target)) { d.open = false; }
		});
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		Array.prototype.forEach.call(document.querySelectorAll('details[data-zavrit-mimo][open]'), function (d) {
			d.open = false;
			d.querySelector('summary').focus();
		});
	});
	// Média: ohnisko ořezu – klepnutím do náhledu se nastaví obě pole (v procentech)
	document.querySelectorAll('[data-ohnisko]').forEach(function (box) {
		var formEl = box.closest('form');
		box.addEventListener('click', function (e) {
			var r = box.getBoundingClientRect();
			var x = Math.round((e.clientX - r.left) / r.width * 100);
			var y = Math.round((e.clientY - r.top) / r.height * 100);
			formEl.elements.ohnisko_x.value = x;
			formEl.elements.ohnisko_y.value = y;
			box.querySelector('.ohnisko-bod').style.left = x + '%';
			box.querySelector('.ohnisko-bod').style.top = y + '%';
		});
	});
	// Média: popis obrázku (alt) přímo v mřížce – uloží se po opuštění pole, bez znovunačtení stránky
	document.querySelectorAll('[data-popis-media]').forEach(function (field) {
		var previous = field.value;
		var token = document.querySelector('input[name="_csrf"]');
		field.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); field.blur(); } });
		field.addEventListener('change', function () {
			var data = new FormData();
			data.append('_csrf', token ? token.value : '');
			data.append('ido', field.getAttribute('data-popis-media'));
			data.append('popis', field.value);
			field.classList.remove('ulozeno', 'chyba');
			fetch(field.getAttribute('data-adresa'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) { if (!j.ok) { throw new Error(j.chyba); } previous = field.value; field.classList.add('ulozeno'); })
				.catch(function () { field.value = previous; field.classList.add('chyba'); });
		});
	});
	// přihlášení se při otevřené administraci udržuje (jinak by po nečinnosti odeslání formuláře selhalo a rozepsaný text by se ztratil)
	if (document.querySelector('form[method="post"]')) {
		setInterval(function () {
			if (document.visibilityState === 'visible') { fetch('admin.php?akce=token', { credentials: 'same-origin' }).catch(function () { /* bez spojení nic */ }); }
		}, 10 * 60 * 1000);
	}
	// uložení potvrdila hláška o úspěchu: rozepsané kopie odeslaných formulářů (image/editor.js) už nejsou potřeba
	if (document.querySelector('.hlaska-ok')) {
		try {
			Object.keys(localStorage).filter(function (k) { return k.indexOf('kaleta-koncept:') === 0; }).forEach(function (k) {
				var d = JSON.parse(localStorage.getItem(k) || 'null');
				if (d && d.odeslano && Date.now() - d.odeslano < 15 * 60 * 1000) { localStorage.removeItem(k); }
			});
		} catch (e) { /* úložiště nedostupné */ }
	}

	// popisky grafů a údajů (data-tip): hned při najetí myší, při zaměření klávesnicí i po klepnutí na dotykové obrazovce
	var tip = null, tipTarget = null;
	function showTip(el) {
		tipTarget = el;
		if (!tip) {
			tip = document.createElement('div');
			tip.className = 'tip';
			tip.setAttribute('role', 'tooltip');
			document.body.appendChild(tip);
		}
		tip.textContent = el.getAttribute('data-tip');
		tip.hidden = false;
		var r = (el.querySelector('[data-tip-kotva]') || el).getBoundingClientRect(); // sloupec grafu: popisek nad jeho výškou
		var x = Math.min(Math.max(r.left + r.width / 2, tip.offsetWidth / 2 + 8), window.innerWidth - tip.offsetWidth / 2 - 8);
		tip.style.left = x + 'px';
		tip.style.top = Math.max(r.top - 8, tip.offsetHeight + 8) + 'px';
	}
	function hideTip() { tipTarget = null; if (tip) { tip.hidden = true; } }
	document.addEventListener('pointerover', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el) { showTip(el); } });
	document.addEventListener('pointerout', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el && !el.contains(e.relatedTarget)) { hideTip(); } });
	document.addEventListener('focusin', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el) { showTip(el); } });
	document.addEventListener('focusout', hideTip);
	window.addEventListener('scroll', function () { if (tipTarget) { showTip(tipTarget); } }, { passive: true }); // při posunu stránky popisek jde s prvkem
})();
