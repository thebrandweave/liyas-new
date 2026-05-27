/* Liyas cart – sidebar + add-to-cart (all public pages) */
(function () {
    'use strict';

    if (window.LiyasCart) {
        return;
    }

    function baseUrl() {
        if (typeof BASE_URL === 'undefined') {
            return '';
        }
        return String(BASE_URL).replace(/\/$/, '');
    }

    function apiUrl(path) {
        var p = path.charAt(0) === '/' ? path : '/' + path;
        return baseUrl() + p;
    }

    function loginUrl() {
        return apiUrl('/login/') + '?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
    }

    function loggedIn() {
        return typeof userIsLoggedIn !== 'undefined' && (userIsLoggedIn === true || userIsLoggedIn === 1 || userIsLoggedIn === '1');
    }

    function goLogin() {
        window.location.href = loginUrl();
    }

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    function formatMoney(n) {
        return '₹' + Number(n).toFixed(2);
    }

    window.LiyasCart = {
        cart: [],
        els: {},

        init: function () {
            this.els.sidebar = document.getElementById('cart-sidebar');
            this.els.overlay = document.getElementById('cart-overlay');
            this.els.body = document.querySelector('.cart-body');
            this.els.subtotal = document.getElementById('subtotal-price');
            this.els.badge = document.getElementById('cart-count-badge');
            this.els.close = document.getElementById('close-cart-btn');
            this.bindEvents();
            if (loggedIn()) {
                this.load();
            } else {
                this.render();
            }
        },

        bindEvents: function () {
            var self = this;

            document.querySelectorAll('[data-cart-trigger]').forEach(function (el) {
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    self.toggle(true);
                });
            });

            if (this.els.close) {
                this.els.close.addEventListener('click', function () {
                    self.toggle(false);
                });
            }

            if (this.els.overlay) {
                this.els.overlay.addEventListener('click', function () {
                    self.toggle(false);
                });
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.add-btn');
                if (!btn) {
                    return;
                }
                var card = btn.closest('.product-card');
                var modal = btn.closest('#productDetailModal');
                if (!card && !modal) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                if (!loggedIn()) {
                    goLogin();
                    return;
                }
                var product;
                if (modal) {
                    product = {
                        id: btn.getAttribute('data-product-id'),
                        name: btn.getAttribute('data-name'),
                        price: parseFloat(btn.getAttribute('data-price')) || 0,
                        image: btn.getAttribute('data-image') || '',
                    };
                } else {
                    var priceEl = card.querySelector('.new-price');
                    var imgEl = card.querySelector('img');
                    var nameEl = card.querySelector('h4');
                    product = {
                        id: card.getAttribute('data-product-id'),
                        name: nameEl ? nameEl.textContent.trim() : '',
                        price: priceEl ? parseFloat(priceEl.textContent.replace(/[^\d.]/g, '')) : 0,
                        image: imgEl ? imgEl.src : '',
                    };
                }
                self.add(product);
                self.toggle(true);
            });

            if (this.els.body) {
                this.els.body.addEventListener('click', function (e) {
                    var btn = e.target.closest('button');
                    if (!btn || !btn.dataset.id) {
                        return;
                    }
                    var id = btn.dataset.id;
                    if (btn.classList.contains('minus-btn')) {
                        self.changeQty(id, -1);
                    } else if (btn.classList.contains('plus-btn')) {
                        self.changeQty(id, 1);
                    } else if (btn.classList.contains('remove-btn')) {
                        self.remove(id);
                    }
                });
            }

            var viewLink = document.querySelector('#cart-sidebar .btn-view-cart');
            if (viewLink) {
                viewLink.addEventListener('click', function (e) {
                    if (!loggedIn()) {
                        e.preventDefault();
                        goLogin();
                    }
                });
            }
        },

        toggle: function (open) {
            if (open && !loggedIn()) {
                goLogin();
                return;
            }
            if (open) {
                this.load();
            }
            if (this.els.sidebar) {
                this.els.sidebar.classList.toggle('open', !!open);
            }
            if (this.els.overlay) {
                this.els.overlay.classList.toggle('open', !!open);
            }
            document.body.classList.toggle('cart-open', !!open);
        },

        load: function () {
            var self = this;
            if (!loggedIn()) {
                return;
            }
            fetch(apiUrl('/get_cart.php'), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.error) {
                        if (data.error === 'User not logged in') {
                            goLogin();
                        }
                        return;
                    }
                    self.cart = Array.isArray(data) ? data : [];
                    self.render();
                })
                .catch(function (err) {
                    console.error('Cart load failed', err);
                });
        },

        add: function (product) {
            var self = this;
            if (!loggedIn()) {
                goLogin();
                return;
            }
            var existing = null;
            var i;
            for (i = 0; i < this.cart.length; i++) {
                if (String(this.cart[i].product_id) === String(product.id)) {
                    existing = this.cart[i];
                    break;
                }
            }
            if (existing) {
                this.changeQty(existing.product_id, 1);
                return;
            }
            var fd = new FormData();
            fd.append('product_id', product.id);
            fd.append('quantity', '1');
            fetch(apiUrl('/add_to_cart.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.error) {
                        console.error(data.error);
                        if (data.error === 'User not logged in') {
                            goLogin();
                        }
                        return;
                    }
                    self.load();
                })
                .catch(function (err) {
                    console.error('Add to cart failed', err);
                });
        },

        changeQty: function (productId, delta) {
            var item = null;
            var i;
            for (i = 0; i < this.cart.length; i++) {
                if (String(this.cart[i].product_id) === String(productId)) {
                    item = this.cart[i];
                    break;
                }
            }
            if (!item) {
                return;
            }
            var qty = item.quantity + delta;
            var fd = new FormData();
            fd.append('product_id', productId);
            fd.append('quantity', String(qty));
            var self = this;
            fetch(apiUrl('/update_cart_quantity.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.error) {
                        console.error(data.error);
                        return;
                    }
                    self.load();
                })
                .catch(function (err) {
                    console.error('Update qty failed', err);
                });
        },

        remove: function (productId) {
            var fd = new FormData();
            fd.append('product_id', productId);
            var self = this;
            fetch(apiUrl('/remove_from_cart.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.error) {
                        console.error(data.error);
                        return;
                    }
                    self.load();
                })
                .catch(function (err) {
                    console.error('Remove failed', err);
                });
        },

        itemCount: function () {
            var n = 0;
            var i;
            for (i = 0; i < this.cart.length; i++) {
                n += parseInt(this.cart[i].quantity, 10) || 0;
            }
            return n;
        },

        updateBadge: function () {
            if (!this.els.badge) {
                return;
            }
            var n = this.itemCount();
            this.els.badge.textContent = n > 99 ? '99+' : String(n);
            this.els.badge.style.display = n > 0 ? 'flex' : 'none';
        },

        render: function () {
            if (!this.els.body) {
                return;
            }
            var self = this;
            var html = '';
            var subtotal = 0;

            if (this.cart.length === 0) {
                html = '<div class="cart-empty">' +
                    '<div class="cart-empty-icon">🛒</div>' +
                    '<p>Your cart is empty</p>' +
                    '<a href="' + escapeHtml(apiUrl('/products/')) + '" class="cart-shop-link">Browse products</a>' +
                    '</div>';
            } else {
                this.cart.forEach(function (item) {
                    var price = parseFloat(item.price) || 0;
                    var qty = parseInt(item.quantity, 10) || 1;
                    var line = price * qty;
                    subtotal += line;
                    html += '<div class="cart-item" data-id="' + escapeHtml(item.product_id) + '">' +
                        '<img class="cart-item-img" src="' + escapeHtml(item.image) + '" alt="">' +
                        '<div class="cart-item-details">' +
                        '<h5 class="cart-item-title">' + escapeHtml(item.name) + '</h5>' +
                        '<p class="cart-item-price">' + formatMoney(line) + '</p>' +
                        '<div class="cart-item-quantity">' +
                        '<button type="button" class="quantity-btn minus-btn" data-id="' + escapeHtml(item.product_id) + '">−</button>' +
                        '<span class="item-quantity">' + qty + '</span>' +
                        '<button type="button" class="quantity-btn plus-btn" data-id="' + escapeHtml(item.product_id) + '">+</button>' +
                        '<button type="button" class="remove-btn" data-id="' + escapeHtml(item.product_id) + '" title="Remove">Remove</button>' +
                        '</div></div></div>';
                });
            }

            this.els.body.innerHTML = html;
            if (this.els.subtotal) {
                this.els.subtotal.textContent = formatMoney(subtotal);
            }
            this.updateBadge();
            var drawerCount = document.getElementById('cart-drawer-count');
            if (drawerCount) {
                var c = this.itemCount();
                drawerCount.textContent = c > 0 ? '(' + c + ' items)' : '';
            }
            this.renderCartPage();
        },

        renderCartPage: function () {
            var container = document.getElementById('cart-page-items');
            var summaryEl = document.getElementById('cart-page-total');
            if (!container) {
                return;
            }
            var self = this;
            var html = '';
            var subtotal = 0;

            if (this.cart.length === 0) {
                html = '<div class="cart-page-empty"><p>Your cart is empty.</p>' +
                    '<a href="' + escapeHtml(apiUrl('/products/')) + '" class="cart-shop-link">Continue shopping</a></div>';
            } else {
                this.cart.forEach(function (item) {
                    var price = parseFloat(item.price) || 0;
                    var qty = parseInt(item.quantity, 10) || 1;
                    var line = price * qty;
                    subtotal += line;
                    html += '<div class="cart-page-item" data-id="' + escapeHtml(item.product_id) + '">' +
                        '<img src="' + escapeHtml(item.image) + '" alt="">' +
                        '<div class="cart-page-item-info"><h3>' + escapeHtml(item.name) + '</h3>' +
                        '<div class="cart-page-qty">' +
                        '<button type="button" class="quantity-btn minus-btn" data-id="' + escapeHtml(item.product_id) + '">−</button>' +
                        '<span>' + qty + '</span>' +
                        '<button type="button" class="quantity-btn plus-btn" data-id="' + escapeHtml(item.product_id) + '">+</button>' +
                        '</div>' +
                        '<button type="button" class="cart-page-remove remove-btn" data-id="' + escapeHtml(item.product_id) + '">Remove</button>' +
                        '</div>' +
                        '<div class="cart-page-item-price">' + formatMoney(line) + '</div></div>';
                });
            }
            container.innerHTML = html;
            if (summaryEl) {
                summaryEl.textContent = formatMoney(subtotal);
            }
            var checkoutBtn = document.getElementById('cart-page-checkout');
            if (checkoutBtn) {
                checkoutBtn.style.display = self.cart.length > 0 ? 'block' : 'none';
            }
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.LiyasCart.init();
        var page = document.getElementById('cart-page-items');
        if (page) {
            page.addEventListener('click', function (e) {
                var btn = e.target.closest('button');
                if (!btn || !btn.dataset.id) {
                    return;
                }
                var id = btn.dataset.id;
                if (btn.classList.contains('minus-btn')) {
                    window.LiyasCart.changeQty(id, -1);
                } else if (btn.classList.contains('plus-btn')) {
                    window.LiyasCart.changeQty(id, 1);
                } else if (btn.classList.contains('remove-btn')) {
                    window.LiyasCart.remove(id);
                }
            });
        }
    });
})();
