/* Kaleta – editor menu (Vzhled → Menu). Položky: stránka, vlastní odkaz, novinky, skupina; pod položkou jedna úroveň podmenu.
 * Pořadí přetažením nebo šipkami (i z klávesnice), šipka vpravo zařadí položku do podmenu té nad ní.
 * Stav je pole položek; při odeslání formuláře jde jako JSON do skrytého pole – čistí ho server (Core\Menu::vycisti).
 */
// skript je v obsahu stránky, tedy před admin.js se slovníkem překladů (window.T) – začne až po načtení všech skriptů
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	const T = window.T || ((s) => s);
	const formEl = document.querySelector('[data-menu]');
	if (!formEl) { return; }
	const data = JSON.parse(formEl.querySelector('[data-menu-data]').textContent);
	const list = formEl.querySelector('[data-menu-seznam]');
	const empty = formEl.querySelector('[data-menu-prazdne]');
	const pages = Object.fromEntries(data.stranky.map((s) => [s.ids, s]));
	const items = data.polozky.map((p) => Object.assign({ deti: [] }, p, { deti: (p.deti || []).map((d) => Object.assign({}, d)) }));
	const NAMES = { stranka: T('Stránka'), odkaz: T('Odkaz'), novinky: T('Novinky'), skupina: T('Skupina') };
	let dragged = null;

	function el(tag, attributes, ...children) {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(attributes || {})) {
			if (v === null || v === undefined || v === false) { continue; }
			if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); } else { e.setAttribute(k, v === true ? '' : v); }
		}
		children.flat().forEach((d) => { if (d !== null && d !== undefined && d !== false) { e.append(d instanceof Node ? d : String(d)); } });
		return e;
	}

	/** Pole, ve kterém položka leží, a její index: cesta [i] = hlavní úroveň, [i, j] = podmenu položky i. */
	const field = (path) => (path.length === 1 ? items : items[path[0]].deti);
	const item = (path) => field(path)[path[path.length - 1]];

	function move(path, direction) {
		const p = field(path);
		const i = path[path.length - 1];
		const j = i + direction;
		if (j < 0 || j >= p.length) { return; }
		[p[i], p[j]] = [p[j], p[i]];
		render([...path.slice(0, -1), j]);
	}
	function indent(path) {
		const i = path[0];
		if (path.length > 1 || i === 0 || items[i].deti.length) { return; }
		const [p] = items.splice(i, 1);
		items[i - 1].deti.push(p);
		render([i - 1, items[i - 1].deti.length - 1]);
	}
	function outdent(path) {
		if (path.length < 2) { return; }
		const [p] = items[path[0]].deti.splice(path[1], 1);
		p.deti = [];
		items.splice(path[0] + 1, 0, p);
		render([path[0] + 1]);
	}
	function remove(path) {
		const [p] = field(path).splice(path[path.length - 1], 1);
		// podmenu smazané položky se posune o úroveň výš, nezmizí
		if (path.length === 1 && p.deti.length) { items.splice(path[0], 0, ...p.deti.map((d) => Object.assign(d, { deti: [] }))); }
		render(null);
	}

	function row(p, path) {
		const pageName = p.typ === 'stranka' ? (pages[p.ids] || { titulek: T('smazaná stránka') }).titulek : '';
		const tl = (text, description, fn, disabled) => el('button', { type: 'button', class: 'menu-tl', title: description, 'aria-label': description, disabled: disabled, onclick: () => fn(path) }, text);
		const text = el('input', { class: 'textpole', type: 'text', maxlength: 80, value: p.text || '', 'aria-label': T('Text v menu'),
			placeholder: p.typ === 'stranka' ? pageName : p.typ === 'novinky' ? T('Novinky') : T('Text v menu'),
			oninput: (e) => { p.text = e.target.value; } });
		const i = path[path.length - 1];
		const rowEl = el('div', { class: 'menu-radek' },
			el('span', { class: 'menu-uchyt', draggable: 'true', 'aria-hidden': 'true', title: T('Přetažením změníte pořadí') }, '⠿'),
			el('span', { class: 'stitek' }, NAMES[p.typ]),
			p.typ === 'stranka' && pages[p.ids] && pages[p.ids].skryta ? el('span', { class: 'stitek stitek-koncept', title: T('Skrytá stránka se v menu na webu neukáže.') }, T('skrytá')) : null,
			text,
			p.typ === 'odkaz' ? el('input', { class: 'textpole', type: 'text', maxlength: 500, value: p.url || '', placeholder: 'https://… ' + T('nebo') + ' /cesta', 'aria-label': T('Adresa odkazu'), oninput: (e) => { p.url = e.target.value.trim(); } }) : null,
			p.typ === 'odkaz' ? el('label', { class: 'menu-okno' }, el('input', { type: 'checkbox', checked: !!p.nove_okno, onchange: (e) => { p.nove_okno = e.target.checked; } }), ' ' + T('nové okno')) : null,
			el('span', { class: 'menu-akce' },
				tl('↑', T('Posunout výš'), (c) => move(c, -1), i === 0),
				tl('↓', T('Posunout níž'), (c) => move(c, 1), i === field(path).length - 1),
				path.length === 1 ? tl('→', T('Do podmenu položky nad ní'), indent, i === 0 || p.deti.length > 0) : tl('←', T('Z podmenu o úroveň výš'), outdent),
				tl('✕', T('Odebrat z menu'), remove)));
		const li = el('li', { class: 'menu-polozka' }, rowEl);
		li.dataset.cesta = path.join(',');
		// táhne se za úchyt (celá položka by bránila označování textu v polích)
		const handle = rowEl.querySelector('.menu-uchyt');
		handle.addEventListener('dragstart', (e) => { dragged = path; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); e.dataTransfer.setDragImage(rowEl, 10, 10); li.classList.add('menu-tazena'); });
		handle.addEventListener('dragend', () => { dragged = null; li.classList.remove('menu-tazena'); list.querySelectorAll('.menu-cil').forEach((x) => x.classList.remove('menu-cil')); });
		rowEl.addEventListener('dragover', (e) => {
			if (!dragged || (dragged.length === 1 && item(dragged).deti.length && path.length > 1)) { return; } // položka s podmenu nejde do podmenu
			e.preventDefault();
			rowEl.classList.add('menu-cil');
		});
		rowEl.addEventListener('dragleave', () => rowEl.classList.remove('menu-cil'));
		rowEl.addEventListener('drop', (e) => {
			e.preventDefault();
			if (!dragged || dragged.join() === path.join()) { return; }
			// vloží se před cílovou položku, na její úroveň
			const target = item(path);
			const [p2] = field(dragged).splice(dragged[dragged.length - 1], 1);
			if (path.length > 1) { p2.deti = []; }
			const destination = path.length === 1 ? items : items.find((x) => x.deti.includes(target)).deti;
			destination.splice(destination.indexOf(target), 0, p2);
			dragged = null;
			render(null);
		});
		if (path.length === 1 && p.deti.length) {
			li.append(el('ol', { class: 'menu-podmenu' }, p.deti.map((d, j) => row(d, [path[0], j]))));
		}
		return li;
	}

	function render(focusTarget) {
		list.replaceChildren(...items.map((p, i) => row(p, [i])));
		empty.hidden = items.length > 0;
		if (focusTarget) {
			// po přesunu šipkou zůstane fokus na přesunuté položce (ovládání z klávesnice)
			const li = list.querySelector('[data-cesta="' + focusTarget.join(',') + '"]');
			if (li) { li.querySelector('.menu-radek input').focus(); }
		}
	}

	formEl.querySelectorAll('[data-menu-pridej]').forEach((b) => b.addEventListener('click', () => {
		const type = b.dataset.menuPridej;
		const newVersion = { type, text: '', deti: [] };
		if (type === 'stranka') {
			const selection = formEl.querySelector('[data-menu-stranka]');
			if (!selection.value) { return; }
			newVersion.ids = Number(selection.value);
		}
		if (type === 'odkaz') { newVersion.url = ''; newVersion.nove_okno = false; }
		items.push(newVersion);
		render([items.length - 1]);
	}));

	/** Upozornění vlastním dialogem (ne window.alert – ten nejde nastylovat ani přeložit); po zavření se vrátí fokus na chybné pole. */
	function notify(text, field) {
		const d = el('dialog', { class: 'potvrzeni', role: 'alertdialog', 'aria-modal': 'true' },
			el('p', {}, text),
			el('div', {}, el('button', { type: 'button', class: 'tl', onclick: () => d.close() }, T('Rozumím'))));
		d.setAttribute('aria-label', text);
		d.addEventListener('close', () => { d.remove(); field.focus(); });
		document.body.append(d);
		d.showModal();
	}

	formEl.addEventListener('submit', (e) => {
		// odkaz bez adresy nebo textu a skupina bez textu by server zahodil potichu – raději říct hned
		const invalid = list.querySelectorAll('.menu-polozka');
		for (const li of invalid) {
			const p = item(li.dataset.cesta.split(',').map(Number));
			if ((p.typ === 'odkaz' && (!p.text || !p.url)) || (p.typ === 'skupina' && !p.text)) {
				e.preventDefault();
				// chybí text, nebo (u odkazu) adresa: fokus na to pole, které je prázdné
				const empty = [...li.querySelector('.menu-radek').querySelectorAll('input.textpole')].find((x) => !x.value.trim()) || li.querySelector('input');
				empty.setAttribute('aria-invalid', 'true');
				empty.addEventListener('input', () => empty.removeAttribute('aria-invalid'), { once: true });
				notify(p.typ === 'odkaz' ? T('Vlastní odkaz potřebuje text i adresu.') : T('Skupina potřebuje text.'), empty);
				return;
			}
		}
		formEl.querySelector('input[name="polozky"]').value = JSON.stringify(items);
	});

	render(null);
});
