/* YMLK Store Manager app. Plain JavaScript, no libraries. */
(function () {
	'use strict';
	var C = window.YSM || {};
	var root = document.getElementById('ysm-app');
	if (!root) return;
	document.documentElement.classList.add('ysm-html');

	/* ------------------------------------------------------------ helpers */
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function money(v) {
		var n = Number(v || 0), d = Number(C.decimals || 0);
		return esc(C.currency || '') + n.toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
	}
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
	function debounce(fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; }

	function url(path, q) {
		var u = (C.rest || '/wp-json/') + 'ysm/v1' + path;
		var parts = [];
		Object.keys(q || {}).forEach(function (k) {
			if (q[k] !== '' && q[k] != null) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(q[k]));
		});
		if (parts.length) u += (u.indexOf('?') > -1 ? '&' : '?') + parts.join('&');
		return u;
	}
	function api(path, opts) {
		opts = opts || {};
		var method = opts.method || 'GET', q = opts.query || {};
		if (method === 'DELETE') { q._method = 'DELETE'; method = 'POST'; }
		var init = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce, 'Accept': 'application/json' } };
		if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		return fetch(url(path, q), init).then(function (r) {
			return r.text().then(function (t) {
				var j = null;
				try { j = t ? JSON.parse(t) : null; } catch (e) { /* not json */ }
				if (!r.ok) throw new Error((j && j.message) ? j.message : 'Something went wrong (' + r.status + '). Please try again.');
				return j;
			});
		});
	}

	var I = {
		home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
		bag: '<path d="M6 7h12l1 13H5L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
		tag: '<path d="M3 12V3h9l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
		box: '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
		ext: '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M19 14v6H4V5h6"/>',
		out: '<path d="M14 4h5v16h-5"/><path d="M9 8l-4 4 4 4"/><path d="M5 12h11"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		minus: '<path d="M5 12h14"/>',
		search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
		back: '<path d="M15 5l-7 7 7 7"/>',
		next: '<path d="M9 5l7 7-7 7"/>',
		phone: '<path d="M5 4h4l2 5-3 2a11 11 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
		chat: '<path d="M4 20l1.4-4.2A8 8 0 1 1 8.6 19L4 20z"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
		print: '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
		trash: '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
		edit: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/>',
		image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/>',
		x: '<path d="M6 6l12 12M18 6L6 18"/>',
		check: '<path d="M5 12l5 5L20 7"/>',
		globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		alert: '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4M12 17.5v.5"/>',
		wp: '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18M9 21V9"/>'
	};
	function icon(n) { return '<svg class="ysm-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (I[n] || '') + '</svg>'; }
	function spinner(t) { return '<div class="ysm-loading"><span class="ysm-spin"></span>' + esc(t || 'Loading…') + '</div>'; }
	function empty(t, sub) { return '<div class="ysm-empty"><strong>' + esc(t) + '</strong>' + (sub ? '<span>' + esc(sub) + '</span>' : '') + '</div>'; }
	function thumb(src, cls) { return src ? '<img class="' + (cls || 'ysm-thumb') + '" src="' + esc(src) + '" alt="" loading="lazy">' : '<span class="' + (cls || 'ysm-thumb') + ' ysm-noimg">' + icon('image') + '</span>'; }
	function badge(status, labels) {
		var l = (labels && labels[status]) || status.replace(/-/g, ' ');
		return '<span class="ysm-badge ysm-st-' + esc(status) + '">' + esc(l) + '</span>';
	}

	var toastEl;
	function toast(msg, type) {
		if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'ysm-toast'; document.body.appendChild(toastEl); }
		toastEl.textContent = msg;
		toastEl.className = 'ysm-toast is-on' + (type === 'error' ? ' is-error' : '');
		clearTimeout(toastEl._t);
		toastEl._t = setTimeout(function () { toastEl.className = 'ysm-toast'; }, type === 'error' ? 5000 : 2600);
	}

	/** Small in-app confirmation dialog (no browser pop-ups). */
	function ask(title, text, ok, danger) {
		return new Promise(function (resolve) {
			var m = document.createElement('div');
			m.className = 'ysm ysm-modal';
			m.innerHTML = '<div class="ysm-dialog" role="dialog" aria-modal="true"><h3>' + esc(title) + '</h3><p>' + esc(text) + '</p><div class="ysm-dialog-actions"><button class="ysm-btn" data-a="no">Cancel</button><button class="ysm-btn ' + (danger ? 'ysm-btn-danger' : 'ysm-btn-primary') + '" data-a="yes">' + esc(ok || 'OK') + '</button></div></div>';
			document.body.appendChild(m);
			m.addEventListener('click', function (e) {
				var b = e.target.closest('[data-a]');
				if (b || e.target === m) { m.remove(); resolve(!!b && b.getAttribute('data-a') === 'yes'); }
			});
			$('[data-a="yes"]', m).focus();
		});
	}

	function pager(page, pages, total, noun) {
		if (pages <= 1) return total ? '<div class="ysm-pager"><span>' + total + ' ' + esc(noun) + '</span></div>' : '';
		return '<div class="ysm-pager"><button class="ysm-btn ysm-btn-sm" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>' + icon('back') + ' Previous</button><span>Page ' + page + ' of ' + pages + ' · ' + total + ' ' + esc(noun) + '</span><button class="ysm-btn ysm-btn-sm" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next ' + icon('next') + '</button></div>';
	}

	/* -------------------------------------------------------------- shell */
	var NAV = [
		['overview', 'Overview', 'home'],
		['orders', 'Orders', 'bag'],
		['products', 'Products', 'tag'],
		['inventory', 'Inventory', 'box']
	];
	var brand = C.logo ? '<img src="' + esc(C.logo) + '" alt="' + esc(C.site) + '">' : '<span>' + esc(C.site) + '</span>';
	root.innerHTML =
		'<div class="ysm-shell">' +
		'<aside class="ysm-side"><div class="ysm-brand">' + brand + '</div><nav class="ysm-nav">' +
		NAV.map(function (n) { return '<a href="#/' + n[0] + '" data-nav="' + n[0] + '">' + icon(n[2]) + '<span>' + n[1] + '</span></a>'; }).join('') +
		'</nav><div class="ysm-side-foot">' +
		'<a href="' + esc(C.siteUrl) + '" target="_blank" rel="noopener">' + icon('globe') + '<span>View shop</span></a>' +
		(C.isAdmin ? '<a href="' + esc(C.wpAdmin) + '">' + icon('wp') + '<span>WordPress admin</span></a>' : '') +
		'<a href="' + esc(C.logout) + '">' + icon('out') + '<span>Log out</span></a>' +
		'<p class="ysm-credit">Developed by <a href="' + esc(C.credit) + '" target="_blank" rel="noopener">YMLK Technologies</a></p>' +
		'</div></aside>' +
		'<div class="ysm-main"><header class="ysm-top"><div class="ysm-top-brand">' + brand + '</div><h1 id="ysm-title">Overview</h1><div class="ysm-top-actions">' +
		'<a class="ysm-iconbtn" href="' + esc(C.siteUrl) + '" target="_blank" rel="noopener" title="View shop">' + icon('globe') + '</a>' +
		(C.isAdmin ? '<a class="ysm-iconbtn" href="' + esc(C.wpAdmin) + '" title="WordPress admin">' + icon('wp') + '</a>' : '') +
		'<a class="ysm-iconbtn" href="' + esc(C.logout) + '" title="Log out">' + icon('out') + '</a></div></header>' +
		'<main id="ysm-view" class="ysm-view"></main>' +
		'<footer class="ysm-foot">Developed by <a href="' + esc(C.credit) + '" target="_blank" rel="noopener">YMLK Technologies</a></footer></div>' +
		'<nav class="ysm-tabbar">' + NAV.map(function (n) { return '<a href="#/' + n[0] + '" data-nav="' + n[0] + '">' + icon(n[2]) + '<span>' + n[1] + '</span></a>'; }).join('') + '</nav>' +
		'</div>';
	var view = $('#ysm-view');
	function title(t) { $('#ysm-title').textContent = t; document.title = t + ' ‹ ' + C.site; }

	var state = {
		ov: { preset: '30' },
		orders: { status: 'all', page: 1, search: '' },
		products: { status: '', page: 1, search: '' },
		inv: { filter: 'all', page: 1, search: '' },
		meta: null
	};
	var leaveGuard = null, lastHash = null, bouncing = false, seq = 0;

	function route() {
		var h = location.hash || '#/overview';
		if (bouncing) { bouncing = false; if (h === lastHash) return; }
		if (leaveGuard && lastHash !== null && h !== lastHash) {
			var target = h;
			bouncing = true; location.hash = lastHash; // stay on the editor until confirmed
			ask('Leave without saving?', 'You have changes that are not saved yet.', 'Leave', true).then(function (yes) {
				if (yes) { leaveGuard = null; location.hash = target; }
			});
			return;
		}
		lastHash = h; leaveGuard = null; seq++;
		view.onclick = view.oninput = view.onchange = view.onkeydown = null;
		var m, sec = (h.match(/^#\/(\w+)/) || [])[1] || 'overview';
		$$('[data-nav]').forEach(function (a) { a.classList.toggle('is-on', a.getAttribute('data-nav') === sec); });
		window.scrollTo(0, 0);
		if ((m = h.match(/^#\/orders\/(\d+)$/))) return orderView(+m[1]);
		if (h === '#/orders') return ordersList();
		if (h === '#/products/new') return productEdit(0);
		if ((m = h.match(/^#\/products\/(\d+)$/))) return productEdit(+m[1]);
		if (h === '#/products') return productsList();
		if (h === '#/inventory') return inventory();
		return overview();
	}
	window.addEventListener('hashchange', route);
	window.addEventListener('beforeunload', function (e) { if (leaveGuard) { e.preventDefault(); e.returnValue = ''; } });
	function current(n) { return n === seq; } // ignore late responses after navigating away
	function fail(err) { view.innerHTML = '<div class="ysm-card ysm-error">' + icon('alert') + '<div><strong>Could not load this page.</strong><span>' + esc(err.message) + '</span><button class="ysm-btn ysm-btn-sm" onclick="location.reload()">Try again</button></div></div>'; }

	/* ----------------------------------------------------------- overview */
	var PRESETS = [['today', 'Today'], ['7', 'Last 7 days'], ['30', 'Last 30 days'], ['month', 'This month'], ['lastmonth', 'Last month'], ['year', 'This year']];
	function presetRange(p) {
		var now = new Date(), from = new Date(now), to = new Date(now);
		if (p === '7') from.setDate(now.getDate() - 6);
		else if (p === '30') from.setDate(now.getDate() - 29);
		else if (p === 'month') from = new Date(now.getFullYear(), now.getMonth(), 1);
		else if (p === 'lastmonth') { from = new Date(now.getFullYear(), now.getMonth() - 1, 1); to = new Date(now.getFullYear(), now.getMonth(), 0); }
		else if (p === 'year') from = new Date(now.getFullYear(), 0, 1);
		return { from: ymd(from), to: ymd(to) };
	}

	function chart(series) {
		var pts = series, monthly = series.length > 62;
		if (monthly) {
			var by = {};
			series.forEach(function (s) { var k = s.date.slice(0, 7); by[k] = by[k] || { date: k, revenue: 0, orders: 0 }; by[k].revenue += s.revenue; by[k].orders += s.orders; });
			pts = Object.keys(by).sort().map(function (k) { return by[k]; });
		}
		var W = 640, H = 200, n = Math.max(pts.length, 1), max = 0;
		pts.forEach(function (p) { if (p.revenue > max) max = p.revenue; });
		var bw = W / n, gap = Math.min(6, bw * 0.25), bars = '';
		var mnames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		function label(d) { var a = d.split('-'); return monthly ? mnames[+a[1] - 1] : (+a[2]) + ' ' + mnames[+a[1] - 1]; }
		pts.forEach(function (p, i) {
			var h = max ? Math.max(p.revenue ? 3 : 0, (p.revenue / max) * (H - 12)) : 0;
			bars += '<rect x="' + (i * bw + gap / 2).toFixed(1) + '" y="' + (H - h).toFixed(1) + '" width="' + Math.max(1, bw - gap).toFixed(1) + '" height="' + h.toFixed(1) + '" rx="' + Math.min(4, bw / 4).toFixed(1) + '"><title>' + label(p.date) + ': ' + (C.currency || '') + p.revenue.toLocaleString() + ' · ' + p.orders + ' order' + (p.orders === 1 ? '' : 's') + '</title></rect>';
		});
		var ticks = [0, Math.floor((n - 1) / 2), n - 1].filter(function (v, i, a) { return a.indexOf(v) === i; });
		var axis = ticks.map(function (i) { return '<span style="left:' + ((i + 0.5) / n * 100).toFixed(2) + '%">' + (pts[i] ? label(pts[i].date) : '') + '</span>'; }).join('');
		return '<div class="ysm-chart"><div class="ysm-chart-max">' + (max ? money(max) : '') + '</div><svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Sales chart"><line x1="0" y1="' + H + '" x2="' + W + '" y2="' + H + '"/>' + bars + '</svg><div class="ysm-chart-axis">' + axis + '</div>' + (max ? '' : '<div class="ysm-chart-empty">No sales in this period yet</div>') + '</div>';
	}

	function overview() {
		title('Overview');
		var s = state.ov, my = seq;
		var r = s.preset === 'custom' ? { from: s.from, to: s.to } : presetRange(s.preset);
		view.innerHTML =
			'<div class="ysm-hello"><h2>Hello, ' + esc((C.user || '').split(' ')[0]) + '</h2><p>Here is how ' + esc(C.site) + ' is doing.</p></div>' +
			'<div class="ysm-filters"><div class="ysm-chips">' + PRESETS.map(function (p) { return '<button class="ysm-chip' + (s.preset === p[0] ? ' is-on' : '') + '" data-preset="' + p[0] + '">' + p[1] + '</button>'; }).join('') +
			'<button class="ysm-chip' + (s.preset === 'custom' ? ' is-on' : '') + '" data-preset="custom">Custom</button></div>' +
			'<div class="ysm-custom"' + (s.preset === 'custom' ? '' : ' hidden') + '><input type="date" id="ov-from" value="' + esc(r.from) + '"><span>to</span><input type="date" id="ov-to" value="' + esc(r.to) + '"><button class="ysm-btn ysm-btn-sm ysm-btn-primary" id="ov-apply">Show</button></div></div>' +
			'<div id="ov-body">' + spinner('Loading your sales…') + '</div>';
		view.onclick = function (e) {
			var b = e.target.closest('[data-preset]');
			if (b) {
				s.preset = b.getAttribute('data-preset');
				if (s.preset === 'custom') { s.from = r.from; s.to = r.to; }
				overview(); return;
			}
			if (e.target.closest('#ov-apply')) { s.from = $('#ov-from').value; s.to = $('#ov-to').value; if (s.from > s.to) { var t = s.from; s.from = s.to; s.to = t; } overview(); return; }
			var st = e.target.closest('[data-status]');
			if (st) { state.orders.status = st.getAttribute('data-status'); state.orders.page = 1; location.hash = '#/orders'; }
		};
		api('/overview', { query: r }).then(function (d) {
			if (!current(my)) return;
			var c = d.counts || {};
			var need = (c.processing || 0) + (c['on-hold'] || 0);
			var statuses = ['processing', 'on-hold', 'pending', 'completed', 'cancelled'];
			$('#ov-body').innerHTML =
				(need ? '<a class="ysm-alert" href="#/orders" data-status="processing">' + icon('bag') + '<span><strong>' + need + ' order' + (need === 1 ? '' : 's') + ' need' + (need === 1 ? 's' : '') + ' attention.</strong> Tap to view and update them.</span>' + icon('next') + '</a>' : '') +
				'<div class="ysm-stats">' +
				'<div class="ysm-stat"><span>Sales</span><strong>' + money(d.revenue) + '</strong></div>' +
				'<div class="ysm-stat"><span>Orders</span><strong>' + d.orders + '</strong></div>' +
				'<div class="ysm-stat"><span>Average order</span><strong>' + money(d.average) + '</strong></div>' +
				'</div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Sales</h3><span class="ysm-muted">' + esc(d.from) + ' – ' + esc(d.to) + '</span></div>' + chart(d.series || []) + '</div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Orders by status</h3><span class="ysm-muted">All time</span></div><div class="ysm-statusgrid">' +
				statuses.map(function (k) { return '<button class="ysm-statusbox" data-status="' + k + '">' + badge(k, d.labels) + '<strong>' + (c[k] || 0) + '</strong></button>'; }).join('') + '</div></div>' +
				'<div class="ysm-grid2">' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Best sellers</h3><span class="ysm-muted">This period</span></div>' +
				((d.top && d.top.length) ? '<ul class="ysm-list">' + d.top.map(function (p) { return '<li><a href="#/products/' + p.product_id + '">' + thumb(p.image) + '<span class="ysm-grow"><strong>' + esc(p.name) + '</strong><small>' + p.qty + ' sold</small></span><b>' + money(p.revenue) + '</b></a></li>'; }).join('') + '</ul>' : empty('No sales yet in this period')) + '</div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Running low</h3><a href="#/inventory" class="ysm-link" id="ov-inv">Inventory</a></div>' +
				((d.lowStock && d.lowStock.length) ? '<ul class="ysm-list">' + d.lowStock.map(function (p) { return '<li><a href="#/products/' + (p.parentId || p.id) + '">' + thumb(p.image) + '<span class="ysm-grow"><strong>' + esc(p.name) + '</strong></span><span class="ysm-badge ' + (p.stock <= 0 ? 'ysm-st-cancelled' : 'ysm-st-on-hold') + '">' + (p.stock <= 0 ? 'Out of stock' : p.stock + ' left') + '</span></a></li>'; }).join('') + '</ul>' : empty('Nothing is running low', 'Items with stock tracking appear here when they are almost sold out.')) + '</div>' +
				'</div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Latest orders</h3><a href="#/orders" class="ysm-link">See all</a></div>' + orderRows(d.recent, d.labels) + '</div>';
			var inv = $('#ov-inv'); if (inv) inv.onclick = function () { state.inv.filter = 'low'; state.inv.page = 1; };
		}).catch(function (e) { if (current(my)) fail(e); });
	}

	/* ------------------------------------------------------------- orders */
	function orderRows(list, labels) {
		if (!list || !list.length) return empty('No orders here yet');
		return '<ul class="ysm-orders">' + list.map(function (o) {
			return '<li><a href="#/orders/' + o.id + '"><span class="ysm-o-main"><strong>#' + esc(o.number) + ' · ' + esc(o.customer) + '</strong><small>' + esc(o.date) + ' · ' + o.items + ' item' + (o.items === 1 ? '' : 's') + '</small></span><span class="ysm-o-side">' + badge(o.status, labels) + '<b>' + money(o.total) + '</b></span></a></li>';
		}).join('') + '</ul>';
	}

	var ORDER_TABS = ['all', 'processing', 'on-hold', 'pending', 'completed', 'cancelled', 'refunded', 'failed'];
	var orderLabels = null;
	function ordersList() {
		title('Orders');
		var s = state.orders, my = seq;
		var labels = orderLabels || { processing: 'Processing', 'on-hold': 'On hold', pending: 'Pending payment', completed: 'Completed', cancelled: 'Cancelled', refunded: 'Refunded', failed: 'Failed' };
		view.innerHTML =
			'<div class="ysm-toolbar"><label class="ysm-search">' + icon('search') + '<input type="search" id="o-search" placeholder="Search name, phone, email or order number" value="' + esc(s.search) + '"></label></div>' +
			'<div class="ysm-tabs">' + ORDER_TABS.map(function (k) { return '<button class="ysm-tab' + (s.status === k ? ' is-on' : '') + '" data-tab="' + k + '">' + esc(k === 'all' ? 'All' : (labels[k] || k)) + '</button>'; }).join('') + '</div>' +
			'<div class="ysm-card ysm-card-flush" id="o-list">' + spinner('Loading orders…') + '</div>';
		$('#o-search').addEventListener('input', debounce(function () { s.search = this.value.trim(); s.page = 1; load(); }, 400));
		view.onclick = function (e) {
			var t = e.target.closest('[data-tab]');
			if (t) { s.status = t.getAttribute('data-tab'); s.page = 1; $$('.ysm-tab', view).forEach(function (b) { b.classList.toggle('is-on', b === t); }); load(); }
			var p = e.target.closest('[data-page]');
			if (p && !p.disabled) { s.page = +p.getAttribute('data-page'); load(); window.scrollTo(0, 0); }
		};
		function load() {
			var box = $('#o-list'); box.innerHTML = spinner('Loading orders…');
			api('/orders', { query: { status: s.status, page: s.page, search: s.search } }).then(function (d) {
				if (!current(my)) return;
				orderLabels = d.labels || orderLabels;
				box.innerHTML = orderRows(d.items, d.labels) + pager(s.page, d.pages, d.total, d.total === 1 ? 'order' : 'orders');
			}).catch(function (e) { if (current(my)) box.innerHTML = empty('Could not load orders', e.message); });
		}
		load();
	}

	function waLink(phone, o) {
		var d = String(phone || '').replace(/[^\d+]/g, '');
		if (!d) return '';
		if (d.charAt(0) === '+') d = d.slice(1);
		else if (d.slice(0, 2) === '00') d = d.slice(2);
		else if (d.charAt(0) === '0' && C.calling) d = String(C.calling).replace(/\D/g, '') + d.slice(1);
		var first = (o.customer || '').split(' ')[0];
		var msg = 'Hello ' + first + ', this is ' + C.site + ' about your order #' + o.number + '.';
		return 'https://wa.me/' + d + '?text=' + encodeURIComponent(msg);
	}

	function orderView(id) {
		title('Order');
		var my = seq;
		view.innerHTML = spinner('Loading order…');
		api('/orders/' + id).then(function (o) { if (current(my)) drawOrder(o); }).catch(function (e) { if (current(my)) fail(e); });

		function drawOrder(o) {
			title('Order #' + o.number);
			var next = { pending: 'processing', 'on-hold': 'processing', processing: 'completed' }[o.status];
			var opts = Object.keys(o.labels).filter(function (k) { return k !== 'checkout-draft'; }).map(function (k) { return '<option value="' + esc(k) + '"' + (k === o.status ? ' selected' : '') + '>' + esc(o.labels[k]) + '</option>'; }).join('');
			var wa = waLink(o.phone, o);
			view.innerHTML =
				'<div class="ysm-pagehead"><a href="#/orders" class="ysm-back">' + icon('back') + ' Orders</a><div class="ysm-pagehead-title"><h2>Order #' + esc(o.number) + '</h2>' + badge(o.status, o.labels) + '</div><span class="ysm-muted">' + esc(o.date) + (o.payment ? ' · ' + esc(o.payment) : '') + (o.paid ? ' · Paid' : '') + '</span></div>' +
				'<div class="ysm-order-grid"><div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Order status</h3></div>' +
				(next ? '<button class="ysm-btn ysm-btn-primary ysm-btn-block" data-set="' + next + '">' + icon('check') + ' Mark as ' + esc(o.labels[next] || next) + '</button>' : '') +
				'<div class="ysm-row ysm-mt"><select id="od-status" class="ysm-input">' + opts + '</select><button class="ysm-btn" id="od-save">Update</button></div>' +
				'<p class="ysm-hint">Your customer gets the usual WooCommerce email when the status changes.</p></div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Items</h3><span class="ysm-muted">' + o.items.length + '</span></div><ul class="ysm-items">' +
				o.items.map(function (it) { return '<li>' + thumb(it.image) + '<span class="ysm-grow"><strong>' + esc(it.name) + '</strong>' + (it.meta.length ? '<small>' + esc(it.meta.join(' · ')) + '</small>' : '') + (it.sku ? '<small>SKU ' + esc(it.sku) + '</small>' : '') + '</span><span class="ysm-qty">× ' + it.qty + '</span><b>' + money(it.total) + '</b></li>'; }).join('') +
				'</ul><dl class="ysm-totals"><dt>Subtotal</dt><dd>' + money(o.subtotal) + '</dd>' +
				(o.discount > 0 ? '<dt>Discount</dt><dd>−' + money(o.discount) + '</dd>' : '') +
				(o.shippingMethod || o.shippingTotal > 0 ? '<dt>Delivery' + (o.shippingMethod ? ' (' + esc(o.shippingMethod) + ')' : '') + '</dt><dd>' + money(o.shippingTotal) + '</dd>' : '') +
				(o.tax > 0 ? '<dt>Tax</dt><dd>' + money(o.tax) + '</dd>' : '') +
				'<dt class="ysm-total">Total</dt><dd class="ysm-total">' + money(o.total) + '</dd>' +
				(o.refunded > 0 ? '<dt>Refunded</dt><dd>−' + money(o.refunded) + '</dd>' : '') + '</dl></div>' +
				'</div><div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Customer</h3></div><p class="ysm-cust">' + esc(o.customer) + '</p>' +
				'<div class="ysm-contact">' +
				(o.phone ? '<a class="ysm-btn ysm-btn-sm" href="tel:' + esc(o.phone.replace(/[^\d+]/g, '')) + '">' + icon('phone') + ' Call</a>' : '') +
				(wa ? '<a class="ysm-btn ysm-btn-sm ysm-btn-wa" href="' + esc(wa) + '" target="_blank" rel="noopener">' + icon('chat') + ' WhatsApp</a>' : '') +
				(o.email ? '<a class="ysm-btn ysm-btn-sm" href="mailto:' + esc(o.email) + '">' + icon('mail') + ' Email</a>' : '') + '</div>' +
				'<dl class="ysm-info">' + (o.phone ? '<dt>Phone</dt><dd>' + esc(o.phone) + '</dd>' : '') + (o.email ? '<dt>Email</dt><dd>' + esc(o.email) + '</dd>' : '') +
				(o.billing ? '<dt>Address</dt><dd>' + esc(o.billing).replace(/\n/g, '<br>') + '</dd>' : '') +
				(o.shipping ? '<dt>Deliver to</dt><dd>' + esc(o.shipping).replace(/\n/g, '<br>') + '</dd>' : '') + '</dl>' +
				(o.note ? '<div class="ysm-custnote"><strong>Customer note</strong>' + esc(o.note) + '</div>' : '') + '</div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Notes</h3></div>' +
				'<textarea id="od-note" class="ysm-input" rows="2" placeholder="Add a note about this order"></textarea>' +
				'<div class="ysm-row ysm-mt ysm-between"><label class="ysm-check"><input type="checkbox" id="od-cust"> Send to customer</label><button class="ysm-btn ysm-btn-sm" id="od-addnote">Add note</button></div>' +
				'<ul class="ysm-notes">' + o.notes.map(function (n) { return '<li class="' + (n.customer ? 'is-cust' : '') + '"><p>' + esc(n.text) + '</p><small>' + esc(n.date) + (n.customer ? ' · sent to customer' : '') + (n.by && n.by !== 'system' ? ' · ' + esc(n.by) : '') + '</small></li>'; }).join('') + '</ul></div>' +
				'<div class="ysm-row ysm-wrap"><button class="ysm-btn" id="od-print">' + icon('print') + ' Print receipt</button><a class="ysm-btn" href="' + esc(o.editUrl) + '">' + icon('wp') + ' Open in WooCommerce</a></div>' +
				'</div></div>';

			function update(body, btn) {
				if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
				return api('/orders/' + o.id, { method: 'POST', body: body }).then(function (n) {
					if (!current(my)) return;
					toast(body.status ? 'Order updated to ' + (n.labels[n.status] || n.status) : 'Note added');
					drawOrder(n);
				}).catch(function (e) { toast(e.message, 'error'); if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); } });
			}
			view.onclick = function (e) {
				var b = e.target.closest('[data-set]');
				if (b) return update({ status: b.getAttribute('data-set') }, b);
				if (e.target.closest('#od-save')) { var v = $('#od-status').value; if (v !== o.status) update({ status: v }, $('#od-save')); return; }
				if (e.target.closest('#od-addnote')) {
					var t = $('#od-note').value.trim();
					if (!t) { $('#od-note').focus(); return; }
					update({ note: t, toCustomer: $('#od-cust').checked }, $('#od-addnote')); return;
				}
				if (e.target.closest('#od-print')) printReceipt(o);
			};
		}
	}

	function printReceipt(o) {
		var w = $('#ysm-print') || document.body.appendChild(Object.assign(document.createElement('div'), { id: 'ysm-print' }));
		w.innerHTML = '<div class="ysm-receipt">' + (C.logo ? '<img src="' + esc(C.logo) + '" alt="">' : '<h1>' + esc(C.site) + '</h1>') +
			'<h2>Receipt · Order #' + esc(o.number) + '</h2><p>' + esc(o.date) + (o.payment ? ' · ' + esc(o.payment) : '') + '</p>' +
			'<p><strong>' + esc(o.customer) + '</strong><br>' + esc(o.phone) + (o.email ? '<br>' + esc(o.email) : '') + '</p>' +
			(o.shipping || o.billing ? '<p>' + esc(o.shipping || o.billing).replace(/\n/g, '<br>') + '</p>' : '') +
			'<table><thead><tr><th>Item</th><th>Qty</th><th>Total</th></tr></thead><tbody>' +
			o.items.map(function (it) { return '<tr><td>' + esc(it.name) + (it.meta.length ? '<br><small>' + esc(it.meta.join(' · ')) + '</small>' : '') + '</td><td>' + it.qty + '</td><td>' + money(it.total) + '</td></tr>'; }).join('') +
			'</tbody><tfoot><tr><td colspan="2">Subtotal</td><td>' + money(o.subtotal) + '</td></tr>' +
			(o.discount > 0 ? '<tr><td colspan="2">Discount</td><td>−' + money(o.discount) + '</td></tr>' : '') +
			(o.shippingTotal > 0 ? '<tr><td colspan="2">Delivery</td><td>' + money(o.shippingTotal) + '</td></tr>' : '') +
			'<tr><th colspan="2">Total</th><th>' + money(o.total) + '</th></tr></tfoot></table><p class="ysm-thanks">Thank you for shopping with ' + esc(C.site) + '!</p></div>';
		document.body.classList.add('ysm-printing');
		var imgs = $$('img', w), left = imgs.length;
		function go() { window.print(); setTimeout(function () { document.body.classList.remove('ysm-printing'); }, 500); }
		if (!left) return go();
		imgs.forEach(function (im) { if (im.complete) { if (!--left) go(); } else { im.onload = im.onerror = function () { if (!--left) go(); }; } });
	}

	/* ----------------------------------------------------------- products */
	function priceText(p) {
		if (p.type === 'variable') {
			if (!p.range || p.range[0] === '' || p.range[0] == null) return '<span class="ysm-muted">No price yet</span>';
			return p.range[0] === p.range[1] ? money(p.range[0]) : money(p.range[0]) + ' – ' + money(p.range[1]);
		}
		if (p.regular === '') return '<span class="ysm-muted">No price yet</span>';
		return p.sale !== '' ? '<s>' + money(p.regular) + '</s> ' + money(p.sale) : money(p.regular);
	}
	function stockText(p) {
		if (p.type === 'variable') return (p.stockTotal != null ? p.stockTotal + ' in stock · ' : '') + p.variants + ' option' + (p.variants === 1 ? '' : 's');
		if (p.manage) return p.stock <= 0 ? '<span class="ysm-red">Out of stock</span>' : p.stock + ' in stock';
		return p.inStock ? 'In stock' : '<span class="ysm-red">Out of stock</span>';
	}

	function productsList() {
		title('Products');
		var s = state.products, my = seq;
		view.innerHTML =
			'<div class="ysm-toolbar"><label class="ysm-search">' + icon('search') + '<input type="search" id="p-search" placeholder="Search products" value="' + esc(s.search) + '"></label><a class="ysm-btn ysm-btn-primary" href="#/products/new">' + icon('plus') + ' Add product</a></div>' +
			'<div class="ysm-tabs">' + [['', 'All'], ['publish', 'Live'], ['draft', 'Drafts']].map(function (t) { return '<button class="ysm-tab' + (s.status === t[0] ? ' is-on' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' +
			'<div class="ysm-card ysm-card-flush" id="p-list">' + spinner('Loading products…') + '</div>';
		$('#p-search').addEventListener('input', debounce(function () { s.search = this.value.trim(); s.page = 1; load(); }, 400));
		var items = {};
		view.onclick = function (e) {
			var t = e.target.closest('[data-tab]');
			if (t) { s.status = t.getAttribute('data-tab'); s.page = 1; $$('.ysm-tab', view).forEach(function (b) { b.classList.toggle('is-on', b === t); }); load(); return; }
			var p = e.target.closest('[data-page]');
			if (p && !p.disabled) { s.page = +p.getAttribute('data-page'); load(); window.scrollTo(0, 0); return; }
			var q = e.target.closest('[data-quick]');
			if (q) { e.preventDefault(); quick(+q.getAttribute('data-quick')); return; }
			var sv = e.target.closest('[data-qsave]');
			if (sv) { e.preventDefault(); qsave(+sv.getAttribute('data-qsave'), sv); return; }
			var qc = e.target.closest('[data-qcancel]');
			if (qc) { e.preventDefault(); var li = qc.closest('li'); li.querySelector('.ysm-quick').remove(); }
		};
		function row(p) {
			return '<li data-id="' + p.id + '"><a class="ysm-prod" href="#/products/' + p.id + '">' + thumb(p.image, 'ysm-thumb ysm-thumb-lg') +
				'<span class="ysm-grow"><strong>' + esc(p.name) + '</strong><small>' + priceText(p) + '</small><small>' + stockText(p) + '</small></span>' +
				(p.status !== 'publish' ? '<span class="ysm-badge ysm-st-draft">' + (p.status === 'draft' ? 'Draft' : esc(p.status)) + '</span>' : '') + '</a>' +
				(p.type === 'simple' ? '<button class="ysm-iconbtn" data-quick="' + p.id + '" title="Quick edit price and stock">' + icon('edit') + '</button>' : '') + '</li>';
		}
		function quick(id) {
			var p = items[id], li = $('li[data-id="' + id + '"]', view);
			if (!p || !li || li.querySelector('.ysm-quick')) return;
			var f = document.createElement('div');
			f.className = 'ysm-quick';
			f.innerHTML = '<label>Price<input class="ysm-input" inputmode="decimal" data-f="regular" value="' + esc(p.regular) + '"></label><label>Sale price<input class="ysm-input" inputmode="decimal" data-f="sale" value="' + esc(p.sale) + '" placeholder="None"></label><label>Stock<input class="ysm-input" inputmode="numeric" data-f="stock" value="' + esc(p.manage ? p.stock : '') + '" placeholder="Not tracked"></label><div class="ysm-row"><button class="ysm-btn ysm-btn-sm" data-qcancel>Cancel</button><button class="ysm-btn ysm-btn-sm ysm-btn-primary" data-qsave="' + id + '">Save</button></div>';
			li.appendChild(f);
			f.querySelector('input').focus();
		}
		function qsave(id, btn) {
			var li = $('li[data-id="' + id + '"]', view), body = {};
			$$('[data-f]', li).forEach(function (i) { body[i.getAttribute('data-f')] = i.value.trim(); });
			if (body.sale !== '' && Number(body.sale) >= Number(body.regular)) { toast('The sale price must be lower than the price.', 'error'); return; }
			btn.disabled = true;
			api('/products/' + id + '/quick', { method: 'POST', body: body }).then(function (p) {
				items[p.id] = p; li.outerHTML = row(p); toast('Saved');
			}).catch(function (e) { btn.disabled = false; toast(e.message, 'error'); });
		}
		function load() {
			var box = $('#p-list'); box.innerHTML = spinner('Loading products…');
			api('/products', { query: { status: s.status, page: s.page, search: s.search } }).then(function (d) {
				if (!current(my)) return;
				d.items.forEach(function (p) { items[p.id] = p; });
				box.innerHTML = (d.items.length ? '<ul class="ysm-prods">' + d.items.map(row).join('') + '</ul>' : empty(s.search ? 'No products match your search' : 'No products yet', s.search ? '' : 'Tap “Add product” to create your first one.')) + pager(s.page, d.pages, d.total, d.total === 1 ? 'product' : 'products');
			}).catch(function (e) { if (current(my)) box.innerHTML = empty('Could not load products', e.message); });
		}
		load();
	}

	function getMeta(force) {
		if (state.meta && !force) return Promise.resolve(state.meta);
		return api('/meta').then(function (m) { state.meta = m; return m; });
	}

	/* ------------------------------------------------------ product editor */
	function productEdit(id) {
		title(id ? 'Edit product' : 'Add product');
		var my = seq;
		view.innerHTML = spinner('Loading…');
		Promise.all([getMeta(), id ? api('/product/' + id) : Promise.resolve(null)]).then(function (res) {
			if (current(my)) editor(res[0], res[1]);
		}).catch(function (e) { if (current(my)) fail(e); });

		function editor(meta, prod) {
			var P = fromProduct(prod), dirty = false;
			function setDirty() { if (!dirty) { dirty = true; leaveGuard = true; } }
			function attrById(aid) { for (var i = 0; i < meta.attributes.length; i++) if (meta.attributes[i].id === aid) return meta.attributes[i]; return null; }
			function termName(attr, tid) { for (var i = 0; i < attr.terms.length; i++) if (attr.terms[i].id === tid) return attr.terms[i].name; return '?'; }

			function fromProduct(p) {
				var o = { id: 0, name: '', status: 'publish', description: '', short: '', sku: '', regular: '', sale: '', stock: '', categories: [], photos: [], hasOptions: false, options: [], variants: {}, photoTax: '', tax: {}, allStock: '' };
				if (!p) { if (meta.defaultCat) o.categories = []; return o; }
				o.id = p.id; o.name = p.name; o.status = p.status === 'draft' ? 'draft' : 'publish'; o.description = p.description || ''; o.short = p.short || ''; o.sku = p.sku || '';
				o.regular = p.regular || ''; o.sale = p.sale || ''; o.stock = p.stock === null || p.stock === undefined ? '' : String(p.stock);
				o.categories = (p.categories || []).slice();
				if (p.image) o.photos.push(p.image);
				(p.gallery || []).forEach(function (g) { o.photos.push(g); });
				o.hasOptions = !!p.hasOptions;
				o.options = (p.options || []).map(function (x) { return { attributeId: x.attributeId, taxonomy: x.taxonomy, termIds: x.termIds.slice() }; });
				o.options.forEach(function (x) { var at = attrById(x.attributeId); if (at) x.termIds.sort(function (m, n) { return idx(at, m) - idx(at, n); }); });
				if (o.hasOptions) {
					var freq = {}, sfreq = {};
					p.variants.forEach(function (v) { freq[v.regular] = (freq[v.regular] || 0) + 1; sfreq[v.sale] = (sfreq[v.sale] || 0) + 1; });
					o.regular = mode(freq); o.sale = mode(sfreq);
					o.options.forEach(function (x) { var a = attrById(x.attributeId); if (a && /colou?r/i.test(a.name)) o.photoTax = x.taxonomy; });
					p.variants.forEach(function (v) {
						var k = keyStr(v.key);
						o.variants[k] = { id: v.id, key: v.key, regular: v.regular === o.regular ? '' : v.regular, sale: v.sale === o.sale ? '' : v.sale, stock: v.stock === null || v.stock === undefined ? '' : String(v.stock), enabled: v.enabled, sku: v.sku, image: v.image };
						if (o.photoTax && v.image && v.key[o.photoTax] && !o.tax[v.key[o.photoTax]]) o.tax[v.key[o.photoTax]] = v.image;
					});
				}
				return o;
			}
			function mode(f) { var best = '', n = -1; Object.keys(f).forEach(function (k) { if (f[k] > n) { n = f[k]; best = k; } }); return best; }
			function keyStr(key) { return Object.keys(key).sort().map(function (t) { return t + ':' + key[t]; }).join('|'); }

			function combos() {
				var opts = P.options.filter(function (o) { return o.termIds.length; });
				if (!opts.length) return [];
				var out = [{}];
				opts.forEach(function (o) {
					var next = [];
					out.forEach(function (c) { o.termIds.forEach(function (t) { var n = Object.assign({}, c); n[o.taxonomy] = t; next.push(n); }); });
					out = next;
				});
				return out;
			}
			function variantLabel(key) {
				return P.options.map(function (o) { var a = attrById(o.attributeId); return a ? termName(a, key[o.taxonomy]) : ''; }).filter(Boolean).join(' / ');
			}

			var catTree = (function () {
				var kids = {}; meta.categories.forEach(function (c) { (kids[c.parent] = kids[c.parent] || []).push(c); });
				var out = []; (function walk(pid, depth) { (kids[pid] || []).forEach(function (c) { out.push({ c: c, d: depth }); walk(c.id, depth + 1); }); })(0, 0);
				return out;
			})();

			view.innerHTML =
				'<div class="ysm-pagehead"><a href="#/products" class="ysm-back">' + icon('back') + ' Products</a><div class="ysm-pagehead-title"><h2>' + (P.id ? esc(P.name) : 'New product') + '</h2></div></div>' +
				'<div class="ysm-editor"><div class="ysm-ed-main">' +
				'<div class="ysm-card"><label class="ysm-label" for="pe-name">Product name</label><input id="pe-name" class="ysm-input ysm-input-lg" value="' + esc(P.name) + '" placeholder="e.g. Linen wrap dress">' +
				'<label class="ysm-label ysm-mt">Description</label><div class="ysm-rte"><div class="ysm-rte-bar"><button type="button" data-cmd="bold"><b>B</b></button><button type="button" data-cmd="italic"><i>I</i></button><button type="button" data-cmd="insertUnorderedList">• List</button></div><div id="pe-desc" class="ysm-rte-body" contenteditable="true" data-ph="Describe the product: fabric, fit, care…">' + P.description + '</div></div></div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Photos</h3><span class="ysm-muted">The first photo is the main one</span></div><div id="pe-photos" class="ysm-photos"></div></div>' +
				'<div class="ysm-card" id="pe-pricing"></div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Options</h3></div><label class="ysm-switch"><input type="checkbox" id="pe-hasopt"' + (P.hasOptions ? ' checked' : '') + '><span></span>This product comes in different options, like sizes or colours</label><div id="pe-opts"></div></div>' +
				'</div><div class="ysm-ed-side">' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Visibility</h3></div><div class="ysm-seg"><button data-status="publish" class="' + (P.status === 'publish' ? 'is-on' : '') + '">Live in shop</button><button data-status="draft" class="' + (P.status === 'draft' ? 'is-on' : '') + '">Hidden (draft)</button></div></div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Categories</h3></div><div id="pe-cats" class="ysm-catlist"></div><div class="ysm-row ysm-mt"><input id="pe-newcat" class="ysm-input" placeholder="New category"><button class="ysm-btn ysm-btn-sm" id="pe-addcat">Add</button></div></div>' +
				'<div class="ysm-card"><div class="ysm-card-head"><h3>Product code</h3></div><input id="pe-sku" class="ysm-input" value="' + esc(P.sku) + '" placeholder="SKU (optional)"></div>' +
				(P.id ? '<button class="ysm-btn ysm-btn-ghost-danger ysm-btn-block" id="pe-delete">' + icon('trash') + ' Move to trash</button>' : '') +
				'</div></div>' +
				'<div class="ysm-savebar"><span class="ysm-muted" id="pe-state">' + (P.id ? '' : 'Not saved yet') + '</span>' +
				(P.id && prod && prod.permalink ? '<a class="ysm-btn" href="' + esc(prod.permalink) + '" target="_blank" rel="noopener">' + icon('globe') + ' View</a>' : '') +
				'<button class="ysm-btn ysm-btn-primary" id="pe-save">' + icon('check') + ' Save product</button></div>';

			/* photos */
			function drawPhotos() {
				$('#pe-photos').innerHTML = P.photos.map(function (ph, i) {
					return '<div class="ysm-photo' + (i === 0 ? ' is-main' : '') + '" data-i="' + i + '"><img src="' + esc(ph.url) + '" alt="">' + (i === 0 ? '<span class="ysm-photo-tag">Main</span>' : '<button class="ysm-photo-main" data-main="' + i + '">Make main</button>') + '<button class="ysm-photo-x" data-rm="' + i + '" title="Remove">' + icon('x') + '</button></div>';
				}).join('') + '<button class="ysm-photo-add" id="pe-addphoto">' + icon('plus') + '<span>Add photos</span></button>';
			}
			function pickImages(multiple, cb, ttl) {
				if (!window.wp || !wp.media) { toast('The media library could not load. Please refresh the page.', 'error'); return; }
				var frame = wp.media({ title: ttl || 'Choose photos', multiple: multiple ? 'add' : false, library: { type: 'image' }, button: { text: 'Use ' + (multiple ? 'these photos' : 'this photo') } });
				frame.on('select', function () {
					cb(frame.state().get('selection').map(function (a) { a = a.toJSON(); var s = a.sizes || {}; return { id: a.id, url: (s.medium || s.thumbnail || s.full || a).url }; }));
				});
				frame.open();
			}

			/* pricing / stock (no options) or "price for all" (with options) */
			function drawPricing() {
				var box = $('#pe-pricing');
				if (P.hasOptions) {
					box.innerHTML = '<div class="ysm-card-head"><h3>Price</h3><span class="ysm-muted">Used for every option unless you change one below</span></div><div class="ysm-fields3">' +
						'<label>Price for all<input class="ysm-input" inputmode="decimal" data-p="regular" value="' + esc(P.regular) + '" placeholder="0.00"></label>' +
						'<label>Sale price for all<input class="ysm-input" inputmode="decimal" data-p="sale" value="' + esc(P.sale) + '" placeholder="None"></label></div>';
				} else {
					box.innerHTML = '<div class="ysm-card-head"><h3>Price &amp; stock</h3></div><div class="ysm-fields3">' +
						'<label>Price<input class="ysm-input" inputmode="decimal" data-p="regular" value="' + esc(P.regular) + '" placeholder="0.00"></label>' +
						'<label>Sale price<input class="ysm-input" inputmode="decimal" data-p="sale" value="' + esc(P.sale) + '" placeholder="None"></label>' +
						'<label>Stock<input class="ysm-input" inputmode="numeric" data-p="stock" value="' + esc(P.stock) + '" placeholder="Not tracked"></label></div><p class="ysm-hint">Leave stock empty if you don’t want to count it. With a number, the shop shows “Out of stock” automatically when it reaches 0.</p>';
				}
			}

			/* options & variations builder */
			function drawOptions() {
				var box = $('#pe-opts');
				if (!P.hasOptions) { box.innerHTML = ''; return; }
				var used = P.options.map(function (o) { return o.attributeId; });
				var unused = meta.attributes.filter(function (a) { return used.indexOf(a.id) < 0; });
				var html = P.options.map(function (o, oi) {
					var a = attrById(o.attributeId); if (!a) return '';
					return '<div class="ysm-opt"><div class="ysm-opt-head"><strong>' + esc(a.name) + '</strong><span class="ysm-muted">Tap to choose</span><button class="ysm-iconbtn ysm-iconbtn-sm" data-rmopt="' + oi + '" title="Remove ' + esc(a.name) + '">' + icon('x') + '</button></div>' +
						'<div class="ysm-chips">' + a.terms.map(function (t) { var on = o.termIds.indexOf(t.id) > -1; return '<button class="ysm-chip' + (on ? ' is-on' : '') + '" data-term="' + oi + ':' + t.id + '">' + (on ? icon('check') : '') + esc(t.name) + '</button>'; }).join('') +
						'<span class="ysm-addval"><input class="ysm-input ysm-input-sm" data-newterm="' + oi + '" placeholder="+ New ' + esc(a.name.toLowerCase()) + '"></span></div></div>';
				}).join('');
				html += '<div class="ysm-row ysm-wrap ysm-mt">' +
					(unused.length ? '<select class="ysm-input ysm-input-auto" id="pe-addopt"><option value="">+ Add an option…</option>' + unused.map(function (a) { return '<option value="' + a.id + '">' + esc(a.name) + '</option>'; }).join('') + '</select>' : '') +
					'<input class="ysm-input ysm-input-auto" id="pe-newattr" placeholder="New option type, e.g. Material"><button class="ysm-btn ysm-btn-sm" id="pe-addattr">Create</button></div>';

				var list = combos();
				if (list.length) {
					var photoOpt = P.options.filter(function (o) { return o.taxonomy === P.photoTax && o.termIds.length; })[0];
					html += '<div class="ysm-sub"><h4>Photos for each option</h4><div class="ysm-row ysm-wrap"><span class="ysm-muted">Photos change by</span><select class="ysm-input ysm-input-auto" id="pe-phototax"><option value="">— not used —</option>' +
						P.options.map(function (o) { var a = attrById(o.attributeId); return a ? '<option value="' + esc(o.taxonomy) + '"' + (o.taxonomy === P.photoTax ? ' selected' : '') + '>' + esc(a.name) + '</option>' : ''; }).join('') + '</select></div>' +
						(photoOpt ? '<div class="ysm-swatches">' + photoOpt.termIds.map(function (tid) {
							var a = attrById(photoOpt.attributeId), img = P.tax[tid];
							return '<div class="ysm-swatch"><button class="ysm-swatch-img" data-taximg="' + tid + '">' + (img ? '<img src="' + esc(img.url) + '" alt="">' : icon('image')) + '</button><span>' + esc(termName(a, tid)) + '</span>' + (img ? '<button class="ysm-link ysm-link-sm" data-taxrm="' + tid + '">Remove</button>' : '') + '</div>';
						}).join('') + '</div>' : '') + '</div>';

					html += '<div class="ysm-sub"><div class="ysm-card-head"><h4>' + list.length + ' combination' + (list.length === 1 ? '' : 's') + '</h4><span class="ysm-muted">Switch off any you don’t sell</span></div>' +
						'<div class="ysm-bulk"><span>Stock for every option</span><input class="ysm-input ysm-input-sm" inputmode="numeric" id="pe-allstock" value="' + esc(P.allStock) + '" placeholder="e.g. 5"><button class="ysm-btn ysm-btn-sm" id="pe-applystock">Apply to all</button></div>' +
						'<div class="ysm-vars"><div class="ysm-var ysm-var-head"><span></span><span>Option</span><span>Price</span><span>Sale</span><span>Stock</span></div>' +
						list.map(function (key) {
							var k = keyStr(key), v = P.variants[k];
							if (!v) v = P.variants[k] = { id: 0, key: key, regular: '', sale: '', stock: P.allStock, enabled: true, sku: '' };
							return '<div class="ysm-var' + (v.enabled ? '' : ' is-off') + '" data-k="' + esc(k) + '"><label class="ysm-switch ysm-switch-sm"><input type="checkbox" data-v="enabled"' + (v.enabled ? ' checked' : '') + '><span></span></label>' +
								'<strong>' + esc(variantLabel(key)) + '</strong>' +
								'<label><em>Price</em><input class="ysm-input ysm-input-sm" inputmode="decimal" data-v="regular" value="' + esc(v.regular) + '" placeholder="' + esc(P.regular || '0.00') + '"></label>' +
								'<label><em>Sale</em><input class="ysm-input ysm-input-sm" inputmode="decimal" data-v="sale" value="' + esc(v.sale) + '" placeholder="' + esc(P.sale || '—') + '"></label>' +
								'<label><em>Stock</em><input class="ysm-input ysm-input-sm" inputmode="numeric" data-v="stock" value="' + esc(v.stock) + '" placeholder="∞"></label></div>';
						}).join('') + '</div><p class="ysm-hint">Empty price uses the “Price for all”. Empty stock means it is not counted (always available).</p></div>';
				} else if (P.options.length) {
					html += '<p class="ysm-hint ysm-mt">Choose at least one value above to create the options.</p>';
				} else {
					html += '<p class="ysm-hint ysm-mt">Add an option such as Size or Colour, then tap the values you sell.</p>';
				}
				box.innerHTML = html;
			}

			function drawCats() {
				$('#pe-cats').innerHTML = catTree.length ? catTree.map(function (x) {
					return '<label class="ysm-check" style="padding-left:' + (x.d * 18) + 'px"><input type="checkbox" value="' + x.c.id + '"' + (P.categories.indexOf(x.c.id) > -1 ? ' checked' : '') + '> ' + esc(x.c.name) + '</label>';
				}).join('') : '<span class="ysm-muted">No categories yet</span>';
			}

			drawPhotos(); drawPricing(); drawOptions(); drawCats();

			/* events */
			view.oninput = function (e) {
				var t = e.target;
				setDirty();
				if (t.id === 'pe-name') P.name = t.value;
				else if (t.id === 'pe-sku') P.sku = t.value;
				else if (t.id === 'pe-desc') P.description = t.innerHTML;
				else if (t.id === 'pe-allstock') P.allStock = t.value.trim();
				else if (t.hasAttribute('data-p')) {
					P[t.getAttribute('data-p')] = t.value.trim();
					if (P.hasOptions) $$('.ysm-var [data-v="' + t.getAttribute('data-p') + '"]', view).forEach(function (i) { i.placeholder = t.value.trim() || (t.getAttribute('data-p') === 'sale' ? '—' : '0.00'); });
				} else if (t.hasAttribute('data-v')) {
					var row = t.closest('[data-k]'), v = P.variants[row.getAttribute('data-k')], f = t.getAttribute('data-v');
					if (f === 'enabled') { v.enabled = t.checked; row.classList.toggle('is-off', !t.checked); } else v[f] = t.value.trim();
				}
			};
			view.onchange = function (e) {
				var t = e.target;
				if (t.closest('#pe-cats')) {
					setDirty();
					P.categories = $$('#pe-cats input:checked', view).map(function (i) { return +i.value; });
				} else if (t.id === 'pe-hasopt') {
					if (!t.checked && P.id && Object.keys(P.variants).some(function (k) { return P.variants[k].id; })) {
						t.checked = true;
						ask('Turn off options?', 'All sizes/colours of this product, with their prices and stock, will be removed when you save.', 'Turn off', true).then(function (yes) { if (yes) { t.checked = false; P.hasOptions = false; setDirty(); drawPricing(); drawOptions(); } });
						return;
					}
					P.hasOptions = t.checked; setDirty(); drawPricing(); drawOptions();
				} else if (t.id === 'pe-addopt' && t.value) {
					var a = attrById(+t.value);
					P.options.push({ attributeId: a.id, taxonomy: a.taxonomy, termIds: [] });
					if (!P.photoTax && /colou?r/i.test(a.name)) P.photoTax = a.taxonomy;
					setDirty(); drawOptions();
				} else if (t.id === 'pe-phototax') { P.photoTax = t.value; setDirty(); drawOptions(); }
			};
			view.onkeydown = function (e) {
				if (e.key !== 'Enter') return;
				var t = e.target;
				if (t.hasAttribute('data-newterm')) { e.preventDefault(); addTerm(+t.getAttribute('data-newterm'), t); }
				else if (t.id === 'pe-newattr') { e.preventDefault(); addAttr(); }
				else if (t.id === 'pe-newcat') { e.preventDefault(); addCat(); }
			};
			view.addEventListener('paste', function (e) {
				if (e.target.closest && e.target.closest('#pe-desc')) {
					e.preventDefault();
					var text = (e.clipboardData || window.clipboardData).getData('text/plain');
					document.execCommand('insertText', false, text);
				}
			});
			view.onclick = function (e) {
				var b;
				if ((b = e.target.closest('[data-cmd]'))) { e.preventDefault(); $('#pe-desc').focus(); document.execCommand(b.getAttribute('data-cmd'), false, null); P.description = $('#pe-desc').innerHTML; setDirty(); return; }
				if (e.target.closest('#pe-addphoto')) { pickImages(true, function (sel) { sel.forEach(function (s) { if (!P.photos.some(function (p) { return p.id === s.id; })) P.photos.push(s); }); setDirty(); drawPhotos(); }); return; }
				if ((b = e.target.closest('[data-rm]'))) { P.photos.splice(+b.getAttribute('data-rm'), 1); setDirty(); drawPhotos(); return; }
				if ((b = e.target.closest('[data-main]'))) { var ph = P.photos.splice(+b.getAttribute('data-main'), 1)[0]; P.photos.unshift(ph); setDirty(); drawPhotos(); return; }
				if ((b = e.target.closest('[data-status]'))) { P.status = b.getAttribute('data-status'); $$('.ysm-seg button', view).forEach(function (x) { x.classList.toggle('is-on', x === b); }); setDirty(); return; }
				if ((b = e.target.closest('[data-term]'))) {
					var parts = b.getAttribute('data-term').split(':'), o = P.options[+parts[0]], tid = +parts[1], i = o.termIds.indexOf(tid);
					if (i > -1) o.termIds.splice(i, 1); else o.termIds.push(tid);
					var a = attrById(o.attributeId); o.termIds.sort(function (x, y) { return idx(a, x) - idx(a, y); });
					setDirty(); drawOptions(); return;
				}
				if ((b = e.target.closest('[data-rmopt]'))) { var rem = P.options.splice(+b.getAttribute('data-rmopt'), 1)[0]; if (rem.taxonomy === P.photoTax) P.photoTax = ''; setDirty(); drawOptions(); return; }
				if (e.target.closest('#pe-addattr')) { addAttr(); return; }
				if (e.target.closest('#pe-addcat')) { addCat(); return; }
				if ((b = e.target.closest('[data-taximg]'))) { var tid2 = +b.getAttribute('data-taximg'); pickImages(false, function (sel) { if (sel[0]) { P.tax[tid2] = sel[0]; setDirty(); drawOptions(); } }, 'Choose the photo for this option'); return; }
				if ((b = e.target.closest('[data-taxrm]'))) { delete P.tax[+b.getAttribute('data-taxrm')]; setDirty(); drawOptions(); return; }
				if (e.target.closest('#pe-applystock')) {
					var st = ($('#pe-allstock').value || '').trim(); P.allStock = st;
					Object.keys(P.variants).forEach(function (k) { P.variants[k].stock = st; });
					$$('.ysm-var [data-v="stock"]', view).forEach(function (i) { i.value = st; });
					setDirty(); toast(st === '' ? 'Stock tracking turned off for every option' : 'Stock set to ' + st + ' for every option'); return;
				}
				if (e.target.closest('#pe-save')) { save(); return; }
				if (e.target.closest('#pe-delete')) {
					ask('Move this product to the trash?', 'It disappears from your shop. It can be restored from the WordPress trash if needed.', 'Move to trash', true).then(function (yes) {
						if (!yes) return;
						api('/product/' + P.id, { method: 'DELETE' }).then(function () { leaveGuard = null; toast('Product moved to trash'); location.hash = '#/products'; }).catch(function (er) { toast(er.message, 'error'); });
					});
				}
			};
			function idx(a, tid) { for (var i = 0; i < a.terms.length; i++) if (a.terms[i].id === tid) return i; return 999; }

			function addTerm(oi, input) {
				var name = input.value.trim(), o = P.options[oi]; if (!name) return;
				input.disabled = true;
				api('/attributes/' + o.attributeId + '/terms', { method: 'POST', body: { name: name } }).then(function (t) {
					var a = attrById(o.attributeId);
					if (!a.terms.some(function (x) { return x.id === t.id; })) a.terms.push(t);
					if (o.termIds.indexOf(t.id) < 0) o.termIds.push(t.id);
					setDirty(); drawOptions(); toast('“' + t.name + '” added');
					var again = $('[data-newterm="' + oi + '"]', view); if (again) again.focus();
				}).catch(function (er) { input.disabled = false; toast(er.message, 'error'); });
			}
			function addAttr() {
				var inp = $('#pe-newattr'), name = inp.value.trim(); if (!name) { inp.focus(); return; }
				inp.disabled = true;
				api('/attributes', { method: 'POST', body: { name: name } }).then(function (m) {
					state.meta.attributes = m.attributes; meta.attributes = m.attributes;
					var a = m.attributes.filter(function (x) { return x.name.toLowerCase() === name.toLowerCase(); })[0];
					if (a && !P.options.some(function (o) { return o.attributeId === a.id; })) P.options.push({ attributeId: a.id, taxonomy: a.taxonomy, termIds: [] });
					setDirty(); drawOptions(); toast('Option “' + name + '” created. Now add its values.');
				}).catch(function (er) { inp.disabled = false; toast(er.message, 'error'); });
			}
			function addCat() {
				var inp = $('#pe-newcat'), name = inp.value.trim(); if (!name) { inp.focus(); return; }
				api('/categories', { method: 'POST', body: { name: name } }).then(function (c) {
					if (!meta.categories.some(function (x) { return x.id === c.id; })) { meta.categories.push(c); catTree.push({ c: c, d: 0 }); }
					if (P.categories.indexOf(c.id) < 0) P.categories.push(c.id);
					inp.value = ''; setDirty(); drawCats();
				}).catch(function (er) { toast(er.message, 'error'); });
			}

			function num(v) { return v === '' || !isNaN(Number(v)); }
			function save() {
				P.description = $('#pe-desc').innerHTML;
				if (!P.name.trim()) { toast('Please give the product a name.', 'error'); $('#pe-name').focus(); return; }
				var body = { id: P.id, name: P.name.trim(), status: P.status, description: P.description, short: P.short, sku: P.sku.trim(), categories: P.categories, imageId: P.photos[0] ? P.photos[0].id : 0, galleryIds: P.photos.slice(1).map(function (p) { return p.id; }), hasOptions: P.hasOptions, regular: P.regular, sale: P.sale, stock: P.stock };
				if (!num(P.regular) || !num(P.sale) || (!P.hasOptions && !/^\d*$/.test(P.stock))) { toast('Prices and stock must be numbers.', 'error'); return; }
				if (P.sale !== '' && P.regular !== '' && Number(P.sale) >= Number(P.regular)) { toast('The sale price must be lower than the price.', 'error'); return; }
				if (P.hasOptions) {
					var list = combos();
					if (!list.length) { toast('Choose at least one size, colour or other option value — or switch options off.', 'error'); return; }
					body.options = P.options.filter(function (o) { return o.termIds.length; }).map(function (o) { return { attributeId: o.attributeId, termIds: o.termIds }; });
					var bad = false, noPrice = false;
					body.variants = list.map(function (key) {
						var v = P.variants[keyStr(key)];
						if (!num(v.regular) || !num(v.sale) || !/^\d*$/.test(v.stock)) bad = true;
						if (v.enabled && v.regular === '' && P.regular === '') noPrice = true;
						var img = P.photoTax ? P.tax[key[P.photoTax]] : v.image;
						return { id: v.id, key: key, regular: v.regular, sale: v.sale, stock: v.stock, enabled: v.enabled, sku: v.sku || '', imageId: img ? img.id : 0 };
					});
					if (bad) { toast('Prices and stock must be numbers.', 'error'); return; }
					if (noPrice) { toast('Enter a “Price for all”, or a price for every option.', 'error'); return; }
				}
				var btn = $('#pe-save'); btn.disabled = true; btn.classList.add('is-busy'); $('#pe-state').textContent = 'Saving…';
				api('/product', { method: 'POST', body: body }).then(function (p) {
					leaveGuard = null; dirty = false;
					toast('Product saved');
					if (!P.id) { lastHash = '#/products/' + p.id; history.replaceState(null, '', '#/products/' + p.id); }
					editor(meta, p);
					$('#pe-state').textContent = 'All changes saved';
				}).catch(function (er) { btn.disabled = false; btn.classList.remove('is-busy'); $('#pe-state').textContent = ''; toast(er.message, 'error'); });
			}
		}
	}

	/* ---------------------------------------------------------- inventory */
	function inventory() {
		title('Inventory');
		var s = state.inv, my = seq, threshold = C.lowStock || 2, items = {}, open = s.open || {};
		s.open = open;
		view.innerHTML =
			'<div class="ysm-toolbar"><label class="ysm-search">' + icon('search') + '<input type="search" id="i-search" placeholder="Search products" value="' + esc(s.search) + '"></label></div>' +
			'<div class="ysm-tabs">' + [['all', 'Everything'], ['low', 'Running low'], ['out', 'Out of stock']].map(function (t) { return '<button class="ysm-tab' + (s.filter === t[0] ? ' is-on' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' +
			'<div class="ysm-card ysm-card-flush" id="i-list">' + spinner('Loading stock…') + '</div>' +
			'<p class="ysm-hint">Empty stock (∞) means it isn’t counted and is always available. 0 means sold out.</p>';
		$('#i-search').addEventListener('input', debounce(function () { s.search = this.value.trim(); s.page = 1; load(); }, 400));

		function level(x) {
			if (x.status === 'outofstock' || (x.manage && x.stock <= 0)) return 'out';
			if (x.manage && x.stock <= threshold) return 'low';
			return '';
		}
		function summary(p) {
			if (p.type !== 'variable') {
				var l = level(p);
				return p.manage ? (l === 'out' ? 'Sold out' : p.stock + ' in stock') : (p.status === 'outofstock' ? 'Sold out' : 'Not counted · always available');
			}
			var v = p.variations, total = 0, counted = 0, low = 0, out = 0;
			v.forEach(function (x) { if (!x.enabled) return; var l = level(x); if (x.manage) { counted++; total += Math.max(0, +x.stock || 0); } if (l === 'low') low++; if (l === 'out') out++; });
			var txt = v.length + ' option' + (v.length === 1 ? '' : 's') + ' · ' + (counted ? total + ' in stock' : 'not counted');
			return { text: txt, low: low, out: out };
		}
		function row(p) {
			var l = p.type === 'variable' ? '' : level(p), sm = summary(p), isOpen = !!open[p.id];
			var right, sub;
			if (p.type === 'variable') {
				sub = esc(sm.text);
				right = '<div class="ysm-inv-badges">' + (sm.out ? '<span class="ysm-badge ysm-st-cancelled">' + sm.out + ' sold out</span>' : '') + (sm.low ? '<span class="ysm-badge ysm-st-on-hold">' + sm.low + ' low</span>' : '') + (!sm.out && !sm.low ? '<span class="ysm-badge ysm-st-processing">OK</span>' : '') + '</div><span class="ysm-chev' + (isOpen ? ' is-open' : '') + '">' + icon('next') + '</span>';
			} else {
				sub = esc(sm);
				right = p.manage ? stepper(p) : '<button class="ysm-btn ysm-btn-sm" data-track="' + p.id + '">Count stock</button>';
			}
			return '<li class="ysm-invp' + (l ? ' is-' + l : '') + (isOpen ? ' is-open' : '') + '" data-pid="' + p.id + '">' +
				'<div class="ysm-invp-head"' + (p.type === 'variable' ? ' data-toggle="' + p.id + '" role="button" tabindex="0"' : '') + '>' + thumb(p.image, 'ysm-thumb ysm-thumb-lg') +
				'<span class="ysm-grow"><strong>' + esc(p.name) + '</strong><small>' + sub + '</small></span>' + right + '</div>' +
				(p.type === 'variable' && isOpen ? panel(p) : '') + '</li>';
		}
		function stepper(x) {
			return '<div class="ysm-stepper" data-vid="' + x.id + '"><button data-delta="-1" aria-label="One less">' + icon('minus') + '</button><input inputmode="numeric" value="' + esc(x.stock) + '" data-stock="' + x.id + '"><button data-delta="1" aria-label="One more">' + icon('plus') + '</button></div>';
		}
		function cell(x) {
			if (!x) return '<td class="is-none">—</td>';
			var l = level(x);
			return '<td class="' + (l ? 'is-' + l : '') + (x.enabled ? '' : ' is-disabled') + '"><input inputmode="numeric" data-cell="' + x.id + '" value="' + esc(x.manage ? x.stock : '') + '" placeholder="∞" title="' + esc(x.label) + (x.enabled ? '' : ' (switched off)') + '"></td>';
		}
		function panel(p) {
			var o = p.options, html = '<div class="ysm-invp-panel">';
			if (o.length === 2) {
				// Rows = the option that isn't "size"; columns = sizes (or the second option).
				var ci = /size/i.test(o[0].name) && !/size/i.test(o[1].name) ? 0 : 1, rows = o[1 - ci], cols = o[ci];
				var find = function (rv, cv) { return p.variations.filter(function (x) { return x.attrs[rows.key] === rv && x.attrs[cols.key] === cv; })[0]; };
				html += '<div class="ysm-matrix-wrap"><table class="ysm-matrix"><thead><tr><th>' + esc(rows.name) + ' \\ ' + esc(cols.name) + '</th>' + cols.values.map(function (c) { return '<th>' + esc(c.name) + '</th>'; }).join('') + '</tr></thead><tbody>' +
					rows.values.map(function (rv) { return '<tr><th>' + esc(rv.name) + '</th>' + cols.values.map(function (cv) { return cell(find(rv.slug, cv.slug)); }).join('') + '</tr>'; }).join('') + '</tbody></table></div>';
			} else {
				html += '<table class="ysm-matrix ysm-matrix-list"><tbody>' + p.variations.map(function (x) { return '<tr><th>' + esc(x.label || ('#' + x.id)) + '</th>' + cell(x) + '</tr>'; }).join('') + '</tbody></table>';
			}
			html += '<div class="ysm-invp-actions"><span>Set every option to</span><input class="ysm-input ysm-input-sm" inputmode="numeric" data-all="' + p.id + '" placeholder="e.g. 5"><button class="ysm-btn ysm-btn-sm" data-applyall="' + p.id + '">Apply</button><a class="ysm-link" href="#/products/' + p.id + '">Edit product</a></div></div>';
			return html;
		}
		function redraw(pid) {
			var li = $('li[data-pid="' + pid + '"]', view); if (!li) return;
			var n = document.createElement('div'); n.innerHTML = row(items[pid]); li.replaceWith(n.firstChild);
		}
		function findItem(id) {
			if (items[id]) return { p: items[id], x: items[id] };
			for (var k in items) { var v = (items[k].variations || []).filter(function (x) { return x.id === id; })[0]; if (v) return { p: items[k], x: v }; }
			return null;
		}
		function send(id, body, el) {
			var f = findItem(id); if (!f) return Promise.resolve();
			if (el) el.classList.add('is-busy');
			body.id = id;
			return api('/stock', { method: 'POST', body: body }).then(function (r) {
				f.x.stock = r.stock; f.x.manage = r.manage; f.x.status = r.status;
				redraw(f.p.id);
			}).catch(function (e) { if (el) el.classList.remove('is-busy'); toast(e.message, 'error'); redraw(f.p.id); });
		}
		function toggle(pid) { open[pid] = !open[pid]; redraw(pid); }

		view.onclick = function (e) {
			var t = e.target.closest('[data-tab]');
			if (t) { s.filter = t.getAttribute('data-tab'); s.page = 1; $$('.ysm-tab', view).forEach(function (b) { b.classList.toggle('is-on', b === t); }); load(); return; }
			var p = e.target.closest('[data-page]');
			if (p && !p.disabled) { s.page = +p.getAttribute('data-page'); load(); window.scrollTo(0, 0); return; }
			var d = e.target.closest('[data-delta]');
			if (d) { var st = d.closest('[data-vid]'), id = +st.getAttribute('data-vid'), f = findItem(id); if (+d.getAttribute('data-delta') < 0 && +f.x.stock <= 0) return; send(id, { delta: +d.getAttribute('data-delta') }, st); return; }
			var tr = e.target.closest('[data-track]');
			if (tr) { send(+tr.getAttribute('data-track'), { stock: 0 }, tr); return; }
			var ap = e.target.closest('[data-applyall]');
			if (ap) {
				var pid = +ap.getAttribute('data-applyall'), v = $('[data-all="' + pid + '"]', view).value.trim();
				if (!/^\d*$/.test(v)) { toast('Enter a whole number, or leave empty to stop counting.', 'error'); return; }
				ap.disabled = true;
				var list = items[pid].variations;
				list.reduce(function (pr, x) { return pr.then(function () { return api('/stock', { method: 'POST', body: { id: x.id, stock: v } }).then(function (r) { x.stock = r.stock; x.manage = r.manage; x.status = r.status; }); }); }, Promise.resolve())
					.then(function () { redraw(pid); toast(v === '' ? 'Stock counting turned off for every option' : 'Every option set to ' + v); })
					.catch(function (er) { redraw(pid); toast(er.message, 'error'); });
				return;
			}
			if (e.target.closest('a, input, button')) return;
			var tg = e.target.closest('[data-toggle]');
			if (tg) toggle(+tg.getAttribute('data-toggle'));
		};
		view.onkeydown = function (e) {
			if (e.key === 'Enter' && e.target.matches('[data-stock],[data-cell]')) { e.preventDefault(); e.target.blur(); }
			if ((e.key === 'Enter' || e.key === ' ') && e.target.hasAttribute('data-toggle')) { e.preventDefault(); toggle(+e.target.getAttribute('data-toggle')); }
		};
		view.onchange = function (e) {
			var t = e.target, id = +(t.getAttribute('data-stock') || t.getAttribute('data-cell') || 0);
			if (!id) return;
			var v = t.value.trim();
			if (t.hasAttribute('data-stock') && !/^\d+$/.test(v)) { toast('Enter a whole number.', 'error'); t.value = findItem(id).x.stock; return; }
			if (!/^\d*$/.test(v)) { toast('Enter a whole number, or leave empty for “always available”.', 'error'); var f = findItem(id); t.value = f.x.manage ? f.x.stock : ''; return; }
			send(id, { stock: v }, t.closest('td') || t.closest('[data-vid]'));
		};
		function load() {
			var box = $('#i-list'); box.innerHTML = spinner('Loading stock…');
			api('/inventory', { query: { filter: s.filter, page: s.page, search: s.search } }).then(function (d) {
				if (!current(my)) return;
				threshold = d.threshold;
				d.items.forEach(function (p) { items[p.id] = p; if (s.filter !== 'all' && p.type === 'variable' && open[p.id] === undefined) open[p.id] = true; });
				box.innerHTML = (d.items.length ? '<ul class="ysm-inv">' + d.items.map(row).join('') + '</ul>' : empty(s.filter === 'all' ? 'Nothing found' : (s.filter === 'low' ? 'Nothing is running low' : 'Nothing is out of stock'), s.filter === 'low' ? 'Products show here when stock is ' + d.threshold + ' or less.' : '')) + pager(s.page, d.pages, d.total, d.total === 1 ? 'product' : 'products');
			}).catch(function (e) { if (current(my)) box.innerHTML = empty('Could not load stock', e.message); });
		}
		load();
	}

	route();
})();
