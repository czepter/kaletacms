/* Kaleta - pomocník editoru: kontrola přístupnosti obsahu a AI asistent. Bez knihoven.
 *
 *   <fieldset data-kontrola>          sem se vypisuje průběžná kontrola (alt texty, nadpisy, odkazy, tabulky)
 *   <form data-asistent="adresa">     u polí formuláře přibydou tlačítka "✦ Navrhnout" (jen se zapnutým rozšířením)
 *
 * Asistent nic neukládá - návrh se jen vloží do pole formuláře a člověk ho může dál upravit.
 */
// skript je v obsahu stránky, tedy před admin.js se slovníkem překladů (window.T) – začne až po načtení všech skriptů
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	var T = window.T || function (s) { return s; }; // překlad textů administrace (image/jazyky/admin-*.js)

	var form = document.querySelector('form.formular-clanek');
	if (!form) { return; }
	var field = function (id) { return form.querySelector('#' + id); };

	function set(id, value) {
		var p = field(id);
		p.value = value;
		if (window.kaletaEditory && window.kaletaEditory[id]) { window.kaletaEditory[id].obnov(); }
		p.dispatchEvent(new Event('input', { bubbles: true }));
	}
	function tree(html) { return new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html').body.firstChild; }
	function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
	function element(tag, className, text) { var e = document.createElement(tag); if (className) { e.className = className; } if (text) { e.textContent = text; } return e; }

	/* ---------- kontrola přístupnosti ---------- */

	var panel = form.querySelector('[data-kontrola]');
	var assistantUrl = form.getAttribute('data-asistent') || '';

	function check() {
		if (!panel) { return; }
		var output = panel.querySelector('[data-kontrola-vysledek]');
		var findings = [];
		output.textContent = '';

		['uvod', 'text'].forEach(function (id) {
			var root = tree(field(id).value);
			Array.prototype.forEach.call(root.querySelectorAll('img'), function (img, i) {
				if ((img.getAttribute('alt') || '').trim() !== '') { return; }
				var row = element('div', 'kontrola-obrazek');
				var preview = element('img'); preview.src = img.getAttribute('src'); preview.alt = '';
				var inputEl = element('input', 'textpole'); inputEl.type = 'text'; inputEl.maxLength = 200; inputEl.placeholder = T('co je na obrázku vidět');
				inputEl.setAttribute('aria-label', T('Popis obrázku pro nevidomé návštěvníky'));
				var save = function () {
					if (inputEl.value.trim() === '') { return; }
					var k = tree(field(id).value);
					k.querySelectorAll('img')[i].setAttribute('alt', inputEl.value.trim());
					set(id, k.innerHTML);
				};
				inputEl.addEventListener('change', save);
				inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });
				row.appendChild(preview); row.appendChild(inputEl);
				if (assistantUrl) {
					var ai = element('button', 'navigace ai-tl', '✦'); ai.type = 'button'; ai.title = T('Navrhnout popis asistentem'); ai.setAttribute('aria-label', ai.title);
					ai.addEventListener('click', function () {
						ai.disabled = true; ai.textContent = '…';
						ask('alt', { obrazek: img.getAttribute('src') }).then(function (j) {
							if (j.navrhy && j.navrhy[0]) { inputEl.value = j.navrhy[0]; inputEl.focus(); } else { announce(j.chyba || T('Asistent nic nenavrhl.')); }
						}).finally(function () { ai.disabled = false; ai.textContent = '✦'; });
					});
					row.appendChild(ai);
				}
				findings.push([T('Obrázek bez popisu – nevidomý návštěvník ani vyhledávač neví, co na něm je. Popis doplňte a potvrďte Enterem:'), row]);
			});

			var level = 1;
			Array.prototype.forEach.call(root.querySelectorAll('h2, h3, h4'), function (h) {
				var u = parseInt(h.tagName.charAt(1), 10);
				if (u > level + 1) { findings.push([T('Mezititulek „') + h.textContent.trim().slice(0, 50) + T('“ přeskakuje úroveň (H') + u + ' bez H' + (u - 1) + T(' nad sebou). Čtečky podle úrovní skládají osnovu textu.')]); }
				if (h.textContent.trim() === '') { findings.push([T('Prázdný mezititulek – smažte ho.')]); }
				level = u;
			});
			Array.prototype.forEach.call(root.querySelectorAll('a'), function (a) {
				var t = a.textContent.trim().toLowerCase();
				if (/^(zde|tady|tu|sem|klikn[ěe]te( zde)?|více|vice|odkaz|link|here|click here)$/.test(t) || /^https?:\/\//.test(t)) {
					findings.push([T('Odkaz „') + a.textContent.trim().slice(0, 40) + T('“ neříká, kam vede. Odkazujte slovy, která dávají smysl i sama o sobě.')]);
				}
			});
			Array.prototype.forEach.call(root.querySelectorAll('table'), function (t) { if (!t.querySelector('th')) { findings.push([T('Tabulka nemá záhlaví (buňky TH) – čtečka neumí říct, co který sloupec znamená.')]); } });
			Array.prototype.forEach.call(root.querySelectorAll('iframe'), function (f) { if (!(f.getAttribute('title') || '').trim()) { findings.push([T('Vložené video nebo rámec nemá název (atribut title).')]); } });
		});
		if (field('titulek').value.length > 110) { findings.push([T('Titulek má přes 110 znaků – ve výsledcích hledání i na sítích se ořízne.')]); }
		if (field('titulek').value.length > 12 && field('titulek').value === field('titulek').value.toUpperCase()) { findings.push([T('Titulek psaný VERZÁLKAMI se špatně čte a čtečky ho mohou hláskovat.')]); }
		if (tree(field('uvod').value).textContent.trim() === '') { findings.push([T('Chybí perex – výpis novinek a sdílení na sítích ho potřebují.')]); }

		panel.classList.toggle('kontrola-ok', findings.length === 0);
		panel.querySelector('legend').textContent = T('Kontrola přístupnosti') + (findings.length ? ' (' + findings.length + ')' : '');
		if (!findings.length) { output.appendChild(element('p', 'kontrola-vporadku', T('✓ Obrázky mají popisy, nadpisy i odkazy jsou v pořádku.'))); return; }
		var ul = element('ul', 'kontrola-seznam');
		findings.forEach(function (n) { var li = element('li', '', n[0]); if (n[1]) { li.appendChild(n[1]); } ul.appendChild(li); });
		output.appendChild(ul);
	}

	var timer = null;
	form.addEventListener('input', function (e) { if (panel && panel.contains(e.target)) { return; } clearTimeout(timer); timer = setTimeout(check, 900); });
	check();

	/* ---------- AI asistent ---------- */

	if (!assistantUrl) { return; }

	var modal = null;
	function dialog(heading) {
		if (!modal) {
			modal = element('dialog', 'galerie-okno ai-okno');
			modal.innerHTML = '<div class="galerie-okno-hlava"><strong></strong><button type="button" class="navigace" data-zavri>' + T('Zavřít') + '</button></div><div class="ai-obsah"></div>';
			document.body.appendChild(modal);
			modal.querySelector('[data-zavri]').addEventListener('click', function () { modal.close(); });
		}
		modal.querySelector('strong').textContent = '✦ ' + heading;
		var content = modal.querySelector('.ai-obsah');
		content.textContent = '';
		if (!modal.open) { modal.showModal(); }
		return content;
	}
	function announce(text) { dialog(T('Asistent')).appendChild(element('p', 'hlaska hlaska-chyba', text)); }

	function ask(task, additional) {
		var data = new FormData();
		data.append('_csrf', form.querySelector('input[name="_csrf"]').value);
		data.append('ukol', task);
		['titulek', 'uvod', 'text'].forEach(function (id) { data.append(id, field(id).value); });
		Object.keys(additional || {}).forEach(function (k) { data.append(k, additional[k]); });
		return fetch(assistantUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return { chyba: T('Spojení s asistentem selhalo. Zkuste to znovu.') }; });
	}

	/* úkol => [pole, popisek tlačítka, nadpis okna, jak návrh zapsat do pole] */
	var TASKS = {
		titulky: ['titulek', T('Navrhnout'), T('Návrhy titulku'), function (n) { set('titulek', n); }],
		perex: ['uvod', T('Navrhnout'), T('Návrhy perexu'), function (n) { set('uvod', '<p>' + esc(n) + '</p>'); }],
		korektura: ['text', T('Korektura'), T('Korektura'), null],
		seo: ['seo_popis', T('Navrhnout'), T('Popis pro vyhledávače'), function (n) { set('seo_popis', n); }],
		stitky: ['stitky', T('Navrhnout'), T('Návrh štítků'), function (n) {
			var have = field('stitky').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
			n.split(',').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (s) { if (have.map(function (m) { return m.toLowerCase(); }).indexOf(s.toLowerCase()) === -1) { have.push(s); } });
			set('stitky', have.join(', '));
		}]
	};

	function showSuggestions(task, j) {
		var u = TASKS[task];
		var content = dialog(u[2]);
		if (j.chyba || !j.navrhy || !j.navrhy.length) { content.appendChild(element('p', 'hlaska hlaska-chyba', j.chyba || T('Asistent nic nenavrhl. Zkuste to znovu.'))); return; }
		j.navrhy.forEach(function (n) {
			var row = element('div', 'ai-navrh');
			row.appendChild(element('p', '', n));
			var b = element('button', 'tl', T('Použít')); b.type = 'button';
			b.addEventListener('click', function () { u[3](n); modal.close(); });
			row.appendChild(b);
			content.appendChild(row);
		});
		content.appendChild(element('p', 'napoveda', T('Návrh se jen vloží do pole – můžete ho dál upravit. Nic se neuloží, dokud formulář neuložíte.')));
	}

	function showProofreading(j) {
		var content = dialog(T('Korektura'));
		if (j.chyba) { content.appendChild(element('p', 'hlaska hlaska-chyba', j.chyba)); return; }
		if (!j.opravy.length) { content.appendChild(element('p', 'kontrola-vporadku', T('✓ Asistent nenašel nic k opravě.'))); return; }
		var items = j.opravy.map(function (o) {
			// oprava jde provést jen tam, kde se původní úsek v poli najde přesně (a nejde přes formátování)
			var whereParts = ['titulek', 'uvod', 'text'].filter(function (id) { return field(id).value.indexOf(id === 'titulek' ? o.puvodni : esc(o.puvodni)) !== -1; })[0];
			var row = element('label', 'ai-navrh ai-oprava');
			var box = element('input'); box.type = 'checkbox'; box.checked = !!whereParts; box.disabled = !whereParts;
			var text = element('span');
			text.appendChild(element('del', '', o.puvodni)); text.appendChild(document.createTextNode(' → ')); text.appendChild(element('ins', '', o.oprava));
			text.appendChild(element('small', '', (o.duvod ? ' ' + o.duvod : '') + (whereParts ? '' : T(' – úsek prochází formátováním, opravte ho prosím ručně'))));
			row.appendChild(box); row.appendChild(text);
			content.appendChild(row);
			return { o: o, kde: whereParts, box: box };
		});
		var b = element('button', 'tl', T('Opravit označené')); b.type = 'button';
		b.addEventListener('click', function () {
			var values = {};
			items.forEach(function (p) {
				if (!p.kde || !p.box.checked) { return; }
				var h = values[p.kde] !== undefined ? values[p.kde] : field(p.kde).value;
				values[p.kde] = p.kde === 'titulek' ? h.replace(p.o.puvodni, function () { return p.o.oprava; }) : h.replace(esc(p.o.puvodni), function () { return esc(p.o.oprava); });
			});
			Object.keys(values).forEach(function (id) { set(id, values[id]); });
			modal.close();
		});
		content.appendChild(b);
	}

	Object.keys(TASKS).forEach(function (task) {
		var u = TASKS[task];
		var badge = form.querySelector('label[for="' + u[0] + '"]');
		if (!badge || !field(u[0])) { return; }
		var b = element('button', 'ai-tl', '✦ ' + u[1]); b.type = 'button';
		b.title = task === 'korektura' ? T('Asistent zkontroluje pravopis, překlepy a typografii') : T('Asistent navrhne znění podle textu');
		b.addEventListener('click', function () {
			b.disabled = true; b.textContent = T('✦ přemýšlím…');
			ask(task).then(function (j) { if (task === 'korektura') { showProofreading(j); } else { showSuggestions(task, j); } })
				.finally(function () { b.disabled = false; b.textContent = '✦ ' + u[1]; });
		});
		badge.appendChild(document.createTextNode(' '));
		badge.appendChild(b);
	});
});
