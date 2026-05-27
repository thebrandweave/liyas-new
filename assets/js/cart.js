console.log('cart.js loaded');

document.addEventListener('DOMContentLoaded', () => {

    const cartSidebar = document.getElementById('cart-sidebar');
    const closeCartBtn = document.getElementById('close-cart-btn');
    const cartIcons = document.querySelectorAll('#cart-icon, .cart-icon-1, .cart-icon');
    const cartBody = document.querySelector('.cart-body');
    const subtotalPrice = document.getElementById('subtotal-price');

    let cart = [];

    // =========================
    // FETCH CART
    // =========================
    function fetchCart() {

        fetch(BASE_URL + '/get_cart.php')
            .then(response => response.json())
            .then(data => {

                if (data.error) {
                    console.log(data.error);
                    return;
                }

                cart = data;

                console.log('Cart data fetched:', cart);

                renderCart();

            })
            .catch(error => console.error('Error fetching cart:', error));

    }

    // =========================
    // CART ICON CLICK
    // =========================
  // =========================
// CART ICON CLICK
// =========================
if (cartIcons.length > 0) {

    cartIcons.forEach(icon => {

        icon.addEventListener('click', (e) => {

            e.preventDefault();

            console.log('Cart icon clicked');

            // OPEN CART DIRECTLY
            if (cartSidebar) {
                cartSidebar.classList.add('open');
            }

        });

    });

}

    // =========================
    // CLOSE CART
    // =========================
    if (closeCartBtn) {

        closeCartBtn.addEventListener('click', () => {

            if (cartSidebar) {
                cartSidebar.classList.remove('open');
            }

        });

    }

    // =========================
    // ADD TO CART BUTTONS
    // =========================
    const productCardButtons = document.querySelectorAll('.product-card .add-btn');

    productCardButtons.forEach(button => {

        button.addEventListener('click', () => {

            if (typeof userIsLoggedIn !== 'undefined' && userIsLoggedIn) {

                const card = button.closest('.product-card');

                const product = {
                    id: card.dataset.productId,
                    name: card.querySelector('h4').textContent,
                    price: parseFloat(
                        card.querySelector('.new-price').textContent.replace('₹', '')
                    ),
                    image: card.querySelector('img').src,
                    quantity: 1
                };

                addToCart(product);

                if (cartSidebar) {
                    cartSidebar.classList.add('open');
                }

            } else {

                window.location.href = BASE_URL + '/login';

            }

        });

    });

    // =========================
    // ADD TO CART
    // =========================
    function addToCart(product) {

        console.log('Adding to cart:', product);

        const existingItem = cart.find(
            item => item.product_id == product.id
        );

        if (existingItem) {

            existingItem.quantity++;

            updateQuantity(
                existingItem.product_id,
                existingItem.quantity
            );

        } else {

            const newCartItem = {
                ...product,
                product_id: product.id,
                quantity: 1
            };

            cart.push(newCartItem);

            renderCart();

            syncCart(newCartItem);

        }

    }

    // =========================
    // SYNC CART
    // =========================
    function syncCart(item) {

        const formData = new FormData();

        formData.append('product_id', item.product_id);
        formData.append('quantity', item.quantity);

        fetch(BASE_URL + '/add_to_cart.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {

            if (data.error) {
                console.error('Error syncing cart:', data.error);
            }

        })
        .catch(error => console.error('Error syncing cart:', error));

    }

    // =========================
    // UPDATE QUANTITY
    // =========================
    function updateQuantity(productId, quantity) {

        const formData = new FormData();

        formData.append('product_id', productId);
        formData.append('quantity', quantity);

        fetch(BASE_URL + '/update_cart_quantity.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {

            if (data.error) {

                console.error('Error updating quantity:', data.error);

            } else {

                fetchCart();

            }

        })
        .catch(error => console.error('Error updating quantity:', error));

    }

    // =========================
    // REMOVE FROM CART
    // =========================
    function removeFromCart(productId) {

        const formData = new FormData();

        formData.append('product_id', productId);

        fetch(BASE_URL + '/remove_from_cart.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {

            if (data.error) {

                console.error('Error removing item:', data.error);

            } else {

                cart = cart.filter(
                    item => item.product_id != productId
                );

                renderCart();

            }

        })
        .catch(error => console.error('Error removing item:', error));

    }

    // =========================
    // RENDER CART
    // =========================
    function renderCart() {

        if (!cartBody) return;

        cartBody.innerHTML = '';

        if (cart.length === 0) {

            cartBody.innerHTML = `
                <p class="cart-empty-message">
                    Your cart is empty.
                </p>
            `;

        } else {

            cart.forEach(item => {

                const cartItem = document.createElement('div');

                cartItem.className = 'cart-item';

                cartItem.innerHTML = `
                    <img 
                        src="${item.image}" 
                        alt="${item.name}" 
                        class="cart-item-img"
                    >

                    <div class="cart-item-details">

                        <h5 class="cart-item-title">
                            ${item.name}
                        </h5>

                        <p class="cart-item-price">
                            ₹${(parseFloat(item.price) * item.quantity).toFixed(2)}
                        </p>

                        <div class="cart-item-quantity">

                            <button 
                                class="quantity-btn minus-btn"
                                data-id="${item.product_id}"
                            >
                                -
                            </button>

                            <span class="item-quantity">
                                ${item.quantity}
                            </span>

                            <button 
                                class="quantity-btn plus-btn"
                                data-id="${item.product_id}"
                            >
                                +
                            </button>

                            <button 
                                class="remove-btn"
                                data-id="${item.product_id}"
                            >
                                ✕
                            </button>

                        </div>

                    </div>
                `;

                cartBody.appendChild(cartItem);

            });

        }

        updateSubtotal();

    }

    // =========================
    // UPDATE SUBTOTAL
    // =========================
    function updateSubtotal() {

        if (!subtotalPrice) return;

        const subtotal = cart.reduce((total, item) => {

            return total + (item.price * item.quantity);

        }, 0);

        subtotalPrice.textContent = `₹${subtotal.toFixed(2)}`;

    }

    // =========================
    // EVENT DELEGATION
    // =========================
    if (cartBody) {

        cartBody.addEventListener('click', (e) => {

            const target = e.target;

            let button = target;

            if (
                target.tagName === 'I' &&
                target.closest('button')
            ) {
                button = target.closest('button');
            }

            // MINUS / PLUS
            if (button.classList.contains('quantity-btn')) {

                const id = button.dataset.id;

                const item = cart.find(
                    item => item.product_id == id
                );

                if (item) {

                    if (button.classList.contains('minus-btn')) {

                        updateQuantity(id, item.quantity - 1);

                    } else if (
                        button.classList.contains('plus-btn')
                    ) {

                        updateQuantity(id, item.quantity + 1);

                    }

                }

            }

            // REMOVE
            else if (button.classList.contains('remove-btn')) {

                const id = button.dataset.id;

                removeFromCart(id);

            }

        });

    }

    // =========================
    // LISTEN FOR MODAL ADD TO CART
    // =========================
    document.addEventListener('addToCartFromModal', (e) => {

        console.log('Received addToCartFromModal event:', e.detail);

        const product = e.detail;

        addToCart(product);

        if (cartSidebar) {
            cartSidebar.classList.add('open');
        }

    });

    // =========================
    // INITIAL FETCH
    // =========================
    fetchCart();

});