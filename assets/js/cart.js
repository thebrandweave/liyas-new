console.log('cart.js loaded');

(function () {
    if (window.__liyasCartInitialized) {
        return;
    }
    window.__liyasCartInitialized = true;

    function apiUrl(path) {
        const base = (typeof BASE_URL !== 'undefined' ? BASE_URL : '').replace(/\/$/, '');
        const segment = path.startsWith('/') ? path : '/' + path;
        return base + segment;
    }

    function getLoginUrl() {
        return apiUrl('/login/');
    }

    function isLoggedIn() {
        return typeof userIsLoggedIn !== 'undefined' && userIsLoggedIn;
    }

    function redirectToLogin() {
        const returnTo = encodeURIComponent(window.location.pathname + window.location.search);
        window.location.href = getLoginUrl() + '?redirect=' + returnTo;
    }

    document.addEventListener('DOMContentLoaded', () => {
        const cartSidebar = document.getElementById('cart-sidebar');
        const closeCartBtn = document.getElementById('close-cart-btn');
        const cartIcons = document.querySelectorAll('[data-cart-trigger], #cart-icon, .cart-icon-1, .cart-icon');
        const cartBody = document.querySelector('.cart-body');
        const subtotalPrice = document.getElementById('subtotal-price');
        const viewCartLink = document.querySelector('#cart-sidebar .checkout-btn');

        let cart = [];

        function openCartSidebar() {
            if (!isLoggedIn()) {
                redirectToLogin();
                return;
            }
            fetchCart();
            if (cartSidebar) {
                cartSidebar.classList.add('open');
            }
        }

        function fetchCart() {
            if (!isLoggedIn()) {
                return;
            }

            fetch(apiUrl('/get_cart.php'))
                .then((response) => response.json())
                .then((data) => {
                    if (data && data.error) {
                        if (data.error === 'User not logged in') {
                            redirectToLogin();
                        }
                        return;
                    }

                    cart = Array.isArray(data) ? data : [];
                    renderCart();
                })
                .catch((error) => console.error('Error fetching cart:', error));
        }

        if (cartIcons.length > 0) {
            cartIcons.forEach((icon) => {
                icon.addEventListener('click', (e) => {
                    e.preventDefault();
                    openCartSidebar();
                });
            });
        }

        if (viewCartLink) {
            viewCartLink.addEventListener('click', (e) => {
                if (!isLoggedIn()) {
                    e.preventDefault();
                    redirectToLogin();
                }
            });
        }

        if (closeCartBtn) {
            closeCartBtn.addEventListener('click', () => {
                if (cartSidebar) {
                    cartSidebar.classList.remove('open');
                }
            });
        }

        const productCardButtons = document.querySelectorAll('.product-card .add-btn');

        productCardButtons.forEach((button) => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (!isLoggedIn()) {
                    redirectToLogin();
                    return;
                }

                const card = button.closest('.product-card');
                if (!card) {
                    return;
                }

                const priceEl = card.querySelector('.new-price');
                const imgEl = card.querySelector('img');
                const nameEl = card.querySelector('h4');

                const product = {
                    id: card.dataset.productId,
                    name: nameEl ? nameEl.textContent.trim() : '',
                    price: priceEl
                        ? parseFloat(priceEl.textContent.replace(/[₹,\s]/g, ''))
                        : 0,
                    image: imgEl ? imgEl.src : '',
                    quantity: 1,
                };

                addToCart(product);
                openCartSidebar();
            });
        });

        function addToCart(product) {
            if (!isLoggedIn()) {
                redirectToLogin();
                return;
            }

            const existingItem = cart.find((item) => item.product_id == product.id);

            if (existingItem) {
                existingItem.quantity++;
                updateQuantity(existingItem.product_id, existingItem.quantity);
            } else {
                const newCartItem = {
                    ...product,
                    product_id: product.id,
                    quantity: 1,
                };

                cart.push(newCartItem);
                renderCart();
                syncCart(newCartItem);
            }
        }

        function syncCart(item) {
            const formData = new FormData();
            formData.append('product_id', item.product_id);
            formData.append('quantity', item.quantity);

            fetch(apiUrl('/add_to_cart.php'), {
                method: 'POST',
                body: formData,
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data && data.error) {
                        console.error('Error syncing cart:', data.error);
                        if (data.error === 'User not logged in') {
                            redirectToLogin();
                        }
                    }
                })
                .catch((error) => console.error('Error syncing cart:', error));
        }

        function updateQuantity(productId, quantity) {
            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('quantity', quantity);

            fetch(apiUrl('/update_cart_quantity.php'), {
                method: 'POST',
                body: formData,
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data && data.error) {
                        console.error('Error updating quantity:', data.error);
                        if (data.error === 'User not logged in') {
                            redirectToLogin();
                        }
                    } else {
                        fetchCart();
                    }
                })
                .catch((error) => console.error('Error updating quantity:', error));
        }

        function removeFromCart(productId) {
            const formData = new FormData();
            formData.append('product_id', productId);

            fetch(apiUrl('/remove_from_cart.php'), {
                method: 'POST',
                body: formData,
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data && data.error) {
                        console.error('Error removing item:', data.error);
                    } else {
                        cart = cart.filter((item) => item.product_id != productId);
                        renderCart();
                    }
                })
                .catch((error) => console.error('Error removing item:', error));
        }

        function renderCart() {
            if (!cartBody) {
                return;
            }

            cartBody.innerHTML = '';

            if (cart.length === 0) {
                cartBody.innerHTML =
                    '<p class="cart-empty-message">Your cart is empty.</p>';
            } else {
                cart.forEach((item) => {
                    const cartItem = document.createElement('div');
                    cartItem.className = 'cart-item';
                    const linePrice = (
                        parseFloat(item.price) * item.quantity
                    ).toFixed(2);

                    cartItem.innerHTML = `
                    <img src="${item.image}" alt="${item.name}" class="cart-item-img">
                    <div class="cart-item-details">
                        <h5 class="cart-item-title">${item.name}</h5>
                        <p class="cart-item-price">₹${linePrice}</p>
                        <div class="cart-item-quantity">
                            <button class="quantity-btn minus-btn" data-id="${item.product_id}">-</button>
                            <span class="item-quantity">${item.quantity}</span>
                            <button class="quantity-btn plus-btn" data-id="${item.product_id}">+</button>
                            <button class="remove-btn" data-id="${item.product_id}">✕</button>
                        </div>
                    </div>`;

                    cartBody.appendChild(cartItem);
                });
            }

            updateSubtotal();
        }

        function updateSubtotal() {
            if (!subtotalPrice) {
                return;
            }

            const subtotal = cart.reduce((total, item) => {
                return total + parseFloat(item.price) * item.quantity;
            }, 0);

            subtotalPrice.textContent = `₹${subtotal.toFixed(2)}`;
        }

        if (cartBody) {
            cartBody.addEventListener('click', (e) => {
                let button = e.target;

                if (button.tagName === 'I' && button.closest('button')) {
                    button = button.closest('button');
                }

                if (button.classList.contains('quantity-btn')) {
                    const id = button.dataset.id;
                    const item = cart.find((entry) => entry.product_id == id);

                    if (item) {
                        if (button.classList.contains('minus-btn')) {
                            updateQuantity(id, item.quantity - 1);
                        } else if (button.classList.contains('plus-btn')) {
                            updateQuantity(id, item.quantity + 1);
                        }
                    }
                } else if (button.classList.contains('remove-btn')) {
                    removeFromCart(button.dataset.id);
                }
            });
        }

        document.addEventListener('addToCartFromModal', (e) => {
            if (!isLoggedIn()) {
                redirectToLogin();
                return;
            }

            addToCart(e.detail);
            openCartSidebar();
        });

        if (isLoggedIn()) {
            fetchCart();
        }
    });
})();
