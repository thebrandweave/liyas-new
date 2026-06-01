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
                this.loadGuestCart();
            }
        },

        loadGuestCart: function () {
            var guestCart = [];
            var match = document.cookie.match(new RegExp('(^| )liyas_guest_cart=([^;]+)'));
            if (match) {
                try {
                    guestCart = JSON.parse(decodeURIComponent(match[2]));
                } catch (e) {
                    guestCart = [];
                }
            } else {
                try {
                    var local = localStorage.getItem('liyas_guest_cart');
                    if (local) {
                        guestCart = JSON.parse(local);
                    }
                } catch (e) {
                    guestCart = [];
                }
            }
            this.cart = Array.isArray(guestCart) ? guestCart : [];
            this.render();
        },

        saveGuestCart: function () {
            var cartStr = JSON.stringify(this.cart);
            try {
                localStorage.setItem('liyas_guest_cart', cartStr);
            } catch (e) {}
            document.cookie = "liyas_guest_cart=" + encodeURIComponent(cartStr) + "; path=/; max-age=604800";
        },

        bindEvents: function () {
            var self = this;

            document.addEventListener('click', function (e) {
                var trigger = e.target.closest('[data-cart-trigger]');
                if (trigger) {
                    e.preventDefault();
                    e.stopPropagation();

                    var mobileMenu = document.getElementById('mobileMenu');
                    var mobileBtn = document.getElementById('mobileMenuBtn');

                    if (mobileMenu && mobileMenu.classList.contains('active')) {
                        mobileMenu.classList.remove('active');
                        if (mobileBtn) {
                            mobileBtn.classList.remove('active');
                        }
                        document.body.classList.remove('no-scroll');
                    }

                    self.toggle(true);
                    return;
                }
                
                var btn = e.target.closest('.add-btn');
                if (!btn) {
                    return;
                }
                
                // If it has an inline onclick handler handling the add, let that execute instead
                if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes('LiyasCart.add')) {
                    return; 
                }

                var card = btn.closest('.product-card');
                var modal = btn.closest('#productDetailModal');
                if (!card && !modal) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();

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

            var checkoutBtn = document.querySelector('#cart-sidebar .btn-checkout-primary');
            if (checkoutBtn) {
                checkoutBtn.addEventListener('click', function (e) {
                    if (!loggedIn()) {
                        e.preventDefault();
                        e.stopPropagation();
                        window.location.href = apiUrl('/login/') + '?redirect=' + encodeURIComponent('/checkout.php');
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
            if (open) {
                if (loggedIn()) {
                    this.load();
                } else {
                    this.loadGuestCart();
                }
            }

            if (this.els.sidebar) {
                this.els.sidebar.classList.toggle('open', open);
            }

            if (this.els.overlay) {
                this.els.overlay.classList.toggle('open', open);
            }

            document.body.classList.toggle('cart-open', open);
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
                            // Safe fallback to guest cart if session drops out mid-operation
                            self.loadGuestCart();
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
            var existing = null;
            var i;

            for (i = 0; i < this.cart.length; i++) {
                if (String(this.cart[i].product_id) === String(product.id)) {
                    existing = this.cart[i];
                    break;
                }
            }

            if (!loggedIn()) {
                if (existing) {
                    existing.quantity = (parseInt(existing.quantity, 10) || 1) + 1;
                } else {
                    this.cart.push({
                        product_id: product.id,
                        name: product.name,
                        price: product.price,
                        image: product.image,
                        quantity: 1
                    });
                }
                this.saveGuestCart();
                this.render();
                return;
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
                        return;
                    }
                    self.load();
                })
                .catch(function (err) {
                    console.error('Add to cart failed', err);
                });
        },

        changeQty: function (productId, delta) {
            var self = this;
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

            var currentQty = parseInt(item.quantity, 10) || 1;
            var targetQty = currentQty + delta;

            if (targetQty <= 0) {
                this.remove(productId);
                return;
            }

            if (!loggedIn()) {
                item.quantity = targetQty;
                this.saveGuestCart();
                this.render();
                return;
            }

            var fd = new FormData();
            fd.append('product_id', productId);
            fd.append('quantity', String(targetQty));
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
            var self = this;
            if (!loggedIn()) {
                this.cart = this.cart.filter(function (item) {
                    return String(item.product_id) !== String(productId);
                });
                this.saveGuestCart();
                this.render();
                return;
            }
            var fd = new FormData();
            fd.append('product_id', productId);
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
            var html = '';
            var subtotal = 0;

           if (this.cart.length === 0) {
    html = '<div class="cart-empty" style="text-align: center; padding: 3rem 1.5rem; color: #64748b;">' +
        '<div class="cart-empty-icon" style="font-size: 3.5rem; margin-bottom: 1rem; filter: grayscale(0.2);">🛒</div>' +
        '<p style="font-size: 1.1rem; margin-bottom: 1.5rem; font-weight: 500; color: #0b2e4e;">Your cart is empty</p>' +
        '<a href="' + escapeHtml(apiUrl('/products/')) + '" class="cart-shop-link" style="display: inline-block; background-color: #4ad2e2; color: #ffffff; padding: 10px 24px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 0.95rem; transition: transform 0.2s ease, background-color 0.2s ease;">Browse products</a>' +
        '</div>';
} else {
    this.cart.forEach(function (item) {
        var price = parseFloat(item.price) || 0;
        var qty = parseInt(item.quantity, 10) || 1;
        var line = price * qty;
        subtotal += line;
        
        html += '<div class="cart-item" data-id="' + escapeHtml(item.product_id) + '" style="display: flex; align-items: center; gap: 1rem; padding: 1.25rem 0; border-bottom: 1px solid #f1f5f9; min-height: 90px;">' +
            '<img class="cart-item-img" src="' + escapeHtml(item.image) + '" alt="" style="width: 70px; height: 70px; object-fit: contain; background-color: #f8fafc; border-radius: 12px; padding: 6px; flex-shrink: 0; border: 1px solid #f1f5f9;">' +
            '<div class="cart-item-details" style="flex: 1; display: flex; flex-direction: column; gap: 0.25rem; min-width: 0;">' +
            '<h5 class="cart-item-title" style="font-size: 0.95rem; font-weight: 600; color: #0b2e4e; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' + escapeHtml(item.name) + '</h5>' +
            '<p class="cart-item-price" style="font-size: 0.95rem; font-weight: 700; color: #4ad2e2; margin: 0;">' + formatMoney(line) + '</p>' +
            '<div class="cart-item-quantity" style="display: flex; align-items: center; gap: 0.75rem; margin-top: 0.35rem;">' +
            
            // Minus Button
            '<button type="button" class="quantity-btn minus-btn" data-id="' + escapeHtml(item.product_id) + '" style="width: 26px; height: 26px; border-radius: 50%; border: 1px solid #e2e8f0; background: #fff; color: #0b2e4e; font-size: 1rem; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; font-weight: 600; line-height: 1;">−</button>' +
            
            // Quantity Counter
            '<span class="item-quantity" style="font-size: 0.95rem; font-weight: 600; color: #0b2e4e; min-width: 20px; text-align: center;">' + qty + '</span>' +
            
            // Plus Button
            '<button type="button" class="quantity-btn plus-btn" data-id="' + escapeHtml(item.product_id) + '" style="width: 26px; height: 26px; border-radius: 50%; border: 1px solid #e2e8f0; background: #fff; color: #0b2e4e; font-size: 1rem; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; font-weight: 600; line-height: 1;">+</button>' +
            
            // Remove Button
            '<button type="button" class="remove-btn" data-id="' + escapeHtml(item.product_id) + '" title="Remove" style="background: none; border: none; color: #94a3b8; font-size: 0.8rem; font-weight: 500; cursor: pointer; margin-left: auto; padding: 4px 8px; border-radius: 6px; transition: color 0.2s ease;">Remove</button>' +
            
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
            var sidebarFooter = document.querySelector('#cart-sidebar .cart-footer');
            if (sidebarFooter) {
                sidebarFooter.style.display = this.cart.length > 0 ? 'block' : 'none';
            }
            this.renderCartPage();
        },

        renderCartPage: function () {
            var container = document.getElementById('cart-page-items');
            var summaryEl = document.getElementById('cart-page-total');
            if (!container) {
                return;
            }
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
                checkoutBtn.style.display = this.cart.length > 0 ? 'block' : 'none';
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